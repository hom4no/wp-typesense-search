<?php
/**
 * Frontend třída pro Typesense Search
 *
 * @package Typesense_Search
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Třída Typesense_Search_Frontend
 */
class Typesense_Search_Frontend {
    
    /**
     * Typesense klient
     *
     * @var Typesense_Search_Client
     */
    private $client;

    /**
     * Uložení výsledků posledního vyhledávání pro filtry
     * @var array
     */
    private $last_search_results = null;
    
    /**
     * Konstruktor
     *
     * @param Typesense_Search_Client $client Typesense klient
     */
    public function __construct(Typesense_Search_Client $client) {
        $this->client = $client;
        
        add_shortcode('typesense_search', array($this, 'render_search_bar'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handler pro vyhledávání
        add_action('wp_ajax_typesense_search', array($this, 'ajax_search'));
        add_action('wp_ajax_nopriv_typesense_search', array($this, 'ajax_search'));
        
        // AJAX handler pro doporučené produkty
        add_action('wp_ajax_typesense_get_recommended', array($this, 'ajax_get_recommended_products'));
        add_action('wp_ajax_nopriv_typesense_get_recommended', array($this, 'ajax_get_recommended_products'));
        
        // Hook pro modifikaci hlavního WP Query (integrace do šablon/Bricks)
        add_action('pre_get_posts', array($this, 'modify_search_query'));

        // Použijeme the_posts pro spolehlivější nastavení found_posts po dotazu
        add_filter('the_posts', array($this, 'adjust_query_results'), 10, 2);
    }

    /**
     * Nastavit found_posts a max_num_pages přímo v query objektu po provedení dotazu
     */
    public function adjust_query_results($posts, $query) {
        if ($query->is_main_query() && $query->is_search() && !empty($this->last_search_results)) {
            
            // Obnovíme původní paged, pokud jsme ho schovali (pro UI)
            if (isset($query->query_vars['_original_paged'])) {
                 $query->set('paged', $query->query_vars['_original_paged']);
                 $query->query_vars['paged'] = $query->query_vars['_original_paged'];
            }

            $found_posts = $this->last_search_results['found'];
            $per_page = $query->get('posts_per_page');
            
            // Pokud je per_page < 1 (např. -1), pak je jen 1 stránka
            if ($per_page < 1) {
                $max_pages = 1;
            } else {
                $max_pages = ceil($found_posts / $per_page);
            }
            
            // Ručně nastavit vlastnosti query objektu
            $query->found_posts = $found_posts;
            $query->max_num_pages = $max_pages;
        }
        return $posts;
    }
    
    /**
     * Modifikuje hlavní WP Query pro vyhledávání
     * 
     * @param WP_Query $query
     */
    public function modify_search_query($query) {
        // Resetovat uložené výsledky při novém hlavním dotazu
        if ($query->is_main_query()) {
            $this->last_search_results = null;
        }

        // Aplikovat pouze na frontend, hlavní query a vyhledávání
        if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return;
        }
        
        // Aplikovat pouze pokud hledáme produkty (nebo všeobecně, pokud chceme)
        // Pokud je nastaven post_type a není to product, ignorujeme
        $post_type = $query->get('post_type');
        if ($post_type && $post_type !== 'product' && (!is_array($post_type) || !in_array('product', $post_type))) {
             // Pokud explicitně hledá něco jiného než produkty, necháme to na WP (např. příspěvky)
             // Pokud post_type není nastaven, WP hledá ve všem. My chceme vnutit produkty z Typesense.
             return; 
        }

        $search_query = $query->get('s');
        if (empty($search_query)) {
            return;
        }

        // Získat parametry pro stránkování
        $paged = $query->get('paged') ? $query->get('paged') : 1;
        $posts_per_page = $query->get('posts_per_page');
        
        // Pokud není v query, zkusíme globální nastavení, jinak fallback na 24
        if (!$posts_per_page) {
            $posts_per_page = get_option('posts_per_page');
        }
        
        // Pojistka: pokud je hodnota příliš malá, nastavíme rozumný default pro e-shop
        // Vynutit minimálně 12 produktů na stránku vyhledávání
        if ($posts_per_page == -1) {
            $posts_per_page = 60; // Limit pro "vše"
        } elseif (empty($posts_per_page) || $posts_per_page < 12) {
            $posts_per_page = 12;
        }

        // Zavolat Typesense API - ZDE JE KLÍČ: Získáme přesně ty produkty, které chceme zobrazit pro TUTO stránku.
        $results = $this->get_product_results($search_query, [
            'page' => $paged,
            'per_page' => $posts_per_page
        ]);
        
        // Uložit výsledky pro pozdější použití
        $this->last_search_results = $results;

        // Získat ID produktů
        $post_ids = [];
        if (!empty($results['hits'])) {
            foreach ($results['hits'] as $hit) {
                $post_ids[] = intval($hit['id']);
            }
        }

        // Pokud nemáme výsledky, nastavíme nesmyslné ID, aby WP vrátil prázdný výsledek
        if (empty($post_ids)) {
            $post_ids = [0];
        }

        // Upravit Query
        $query->set('post__in', $post_ids);
        $query->set('orderby', 'post__in');
        $query->set('post_type', 'product'); // Vnutit typ produkt
        
        // --- HYDRATION TRICK PRO PAGINACI ---
        // Protože $post_ids obsahuje už vyfiltrované produkty JEN pro aktuální stránku (z Typesense),
        // musíme WordPressu říct, aby je prostě zobrazil všechny a nesnažil se v nich znovu stránkovat.
        // Jinak by WP aplikoval OFFSET na náš malý seznam a vrátil by prázdno na stránce 2+.
        
        // Uložíme si původní paged, abychom ho obnovili pro UI v adjust_query_results
        $query->set('_original_paged', $paged);
        
        // Pro SQL nastavíme paged na 1 (žádný offset)
        $query->set('paged', 1);
        
        // Nastavíme počet příspěvků na stránku tak, aby se zobrazily všechny naše IDčka
        // (nebo prostě ponecháme naše číslo, pokud je count($post_ids) <= $posts_per_page, což by mělo být)
        $query->set('posts_per_page', $posts_per_page);
        
        // Ignorovat sticky posts, aby nerozbily pořadí
        $query->set('ignore_sticky_posts', true);
        
        // Odstranit SQL fulltextové hledání, protože jsme IDčka už našli přes Typesense
        add_filter('posts_search', function($search, $wp_query) use ($query) {
            if ($wp_query === $query) {
                return ''; // Vyprázdní WHERE (post_title LIKE ...) část dotazu
            }
            return $search;
        }, 10, 2);
    }

    /**
     * Načíst skripty a styly
     */
    public function enqueue_scripts(): void {
        // Načíst skripty pouze pokud je shortcode na stránce
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'typesense_search')) {
            $this->enqueue_search_scripts();
        }
        
        // Na stránce produktu načíst skript pro uložení do historie
        if (is_product()) {
            $this->enqueue_product_tracking_script();
        }
    }
    
    /**
     * Načíst skript pro sledování návštěv produktů
     */
    private function enqueue_product_tracking_script(): void {
        // Get product from WooCommerce
        $product = wc_get_product(get_the_ID());
        
        if (!$product || !is_a($product, 'WC_Product')) {
            return;
        }
        
        wp_enqueue_script(
            'typesense-product-tracking',
            TYPESENSE_SEARCH_PLUGIN_URL . 'assets/js/product-tracking.js',
            array('jquery'),
            TYPESENSE_SEARCH_VERSION,
            true
        );
        
        // Předat data produktu do JavaScriptu
        wp_localize_script('typesense-product-tracking', 'typesenseProductData', array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'image' => get_the_post_thumbnail_url($product->get_id(), 'woocommerce_thumbnail'),
            'permalink' => get_permalink($product->get_id()),
        ));
    }
    
    /**
     * Načíst skripty pro search bar
     */
    private function enqueue_search_scripts(): void {
        wp_enqueue_style(
            'typesense-search-frontend',
            TYPESENSE_SEARCH_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            TYPESENSE_SEARCH_VERSION . '.' . time() // Force cache bust
        );
        
        // Add inline CSS for custom colors
        $primary_color = get_option('typesense_search_primary_color', '#064740');
        $primary_hover_color = get_option('typesense_search_primary_hover_color', '#146154');
        $input_bg_color = get_option('typesense_search_input_bg_color', '#f1f5f9');
        $input_border_color = get_option('typesense_search_input_border_color', '#cbd5e1');
        $text_color = get_option('typesense_search_text_color', '#334155');
        $mobile_breakpoint = get_option('typesense_search_mobile_breakpoint', '1050');
        $enable_mobile_fullscreen = get_option('typesense_search_enable_mobile_fullscreen', '1');
        
        // Border Radius Settings
        $border_radius_key = get_option('typesense_search_border_radius', 'm');
        $radius_values = [
            '0' => '0px',
            'xs' => 'clamp(0.4rem, calc(0vw + 0.4rem), 0.4rem)',
            's' => 'clamp(0.6rem, calc(-0.21vw + 0.87rem), 0.8rem)',
            'm' => 'clamp(1rem, calc(-0.21vw + 1.27rem), 1.2rem)',
            'l' => 'clamp(1.6rem, calc(-0.42vw + 2.13rem), 2rem)',
            'xl' => 'clamp(2.6rem, calc(-0.63vw + 3.4rem), 3.2rem)',
            'full' => '999rem'
        ];
        $radius = $radius_values[$border_radius_key] ?? $radius_values['m'];
        
        // Nové volby pro ikony
        $mobile_icon_style = get_option('typesense_search_mobile_icon_style', 'round');
        $mobile_icon_size = get_option('typesense_search_mobile_icon_size', '24');
        
        // Nastavení stylů podle voleb
        if ($mobile_icon_style === 'round') {
            $btn_bg = '#f5f5f5';
            $btn_bg_hover = '#e5e5e5';
            $btn_radius = '50%';
            // Pro kulaté tlačítko vypočítáme celkovou velikost (ikona + padding)
            // Např. 24px ikona -> 48px tlačítko (přidáme 24px)
            $btn_width = ((int)$mobile_icon_size + 24) . 'px';
            $btn_height = $btn_width;
            $btn_padding = '0';
        } else {
            // Simple styl
            $btn_bg = 'transparent';
            $btn_bg_hover = 'transparent';
            $btn_radius = '0';
            $btn_width = 'auto';
            $btn_height = 'auto';
            $btn_padding = '10px';
        }
        
        $custom_css = "
            .typesense-search-wrapper {
                background: {$input_bg_color} !important;
                border-color: {$input_border_color} !important;
                border-radius: {$radius} !important;
                overflow: hidden !important;
            }
            /* Input radius fix */
            .typesense-search-wrapper .typesense-search-input,
            .typesense-search-container input[type='text'].typesense-search-input {
                color: {$text_color} !important;
                border-top-left-radius: {$radius} !important;
                border-bottom-left-radius: {$radius} !important;
                border-top-right-radius: 0 !important;
                border-bottom-right-radius: 0 !important;
            }
            /* Button radius fix */
            .typesense-search-wrapper .typesense-search-button {
                background: {$primary_color} !important;
                border-top-right-radius: {$radius} !important;
                border-bottom-right-radius: {$radius} !important;
                border-top-left-radius: 0 !important;
                border-bottom-left-radius: 0 !important;
            }
            
            .typesense-search-button:hover {
                background: {$primary_hover_color} !important;
            }
            /* Search results hover border */
            .typesense-search-product-card {
                border-radius: {$radius} !important;
                overflow: hidden !important;
            }
            .typesense-search-product-image-wrapper,
            .typesense-search-product-image {
                border-radius: {$radius} !important;
            }
            .typesense-search-product-card:hover {
                border-color: {$primary_color} !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
            }
            .typesense-product-item {
                border-radius: {$radius} !important;
            }
            .typesense-product-item:hover {
                border-color: {$primary_color} !important;
            }
            .typesense-category-item,
            .typesense-brand-item {
                border-radius: {$radius} !important;
            }
            .typesense-category-item:hover,
            .typesense-brand-item:hover {
                border-color: {$primary_color} !important;
            }
            /* Active category/brand */
            .typesense-category-item.active,
            .typesense-brand-item.active {
                border-color: {$primary_color} !important;
                background-color: {$primary_color}10 !important;
            }
            /* History tags and products */
            .typesense-search-history-tag {
                border-radius: {$radius} !important;
            }
            .typesense-search-history-tag:hover {
                border-color: {$primary_color} !important;
                color: {$primary_color} !important;
            }
            .typesense-search-history-tag:hover svg {
                color: {$primary_color} !important;
            }
            .typesense-search-history-product {
                border-radius: {$radius} !important;
            }
            .typesense-search-history-product:hover {
                border-color: {$primary_color} !important;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1) !important;
            }
            /* Links and accents */
            .typesense-search-results {
                border-radius: {$radius} !important;
            }
            .typesense-search-results a:hover {
                color: {$primary_color} !important;
            }
            
            /* Mobile trigger button - hidden on desktop */
            .typesense-search-mobile-trigger {
                display: none !important;
            }
            
            .typesense-search-mobile-trigger svg {
                width: 18px;
                height: 18px;
            }
            
            /* Close button - hidden on desktop */
            .typesense-search-close {
                display: none !important;
            }
            
            .typesense-search-close svg {
                width: 24px;
                height: 24px;
            }
            
            /* Responsive layout (always applied based on breakpoint) */
            @media (max-width: {$mobile_breakpoint}px) {
                /* Layout zobrazení na mobilu - produkty nahoře, kategorie dole */
                .typesense-search-results-content {
                    display: flex !important;
                    flex-direction: column !important;
                }
                
                .typesense-search-main {
                    order: 1 !important;
                    padding: 0 !important;
                }
                
                .typesense-search-sidebar {
                    order: 2 !important;
                    padding: 0 !important;
                    margin-top: 20px !important;
                    border-left: none !important;
                    border-top: 1px solid #e5e7eb !important;
                    padding-top: 20px !important;
                    width: 100% !important; /* Full width for sidebar */
                    border-right: none !important; /* Remove sidebar right border */
                }
                
                /* Remove padding from section titles */
                .typesense-search-main .typesense-search-section-title {
                    padding-left: 0px !important;
                    padding-right: 0px !important;
                }
                
                /* Remove padding from products grid */
                .typesense-search-main .typesense-search-products-grid {
                    padding-right: 0px !important;
                    padding-left: 0px !important;
                    grid-template-columns: 1fr !important; /* 1 column grid on mobile */
                }
                
                /* Remove padding from sidebar section titles */
                .typesense-search-sidebar .typesense-search-section-title {
                    padding-right: 0px !important;
                    padding-left: 0px !important;
                }
                
                /* Remove padding from sidebar links */
                .typesense-search-sidebar a {
                    padding-right: 0px !important;
                    padding-left: 0px !important;
                }
                
                /* Remove padding from history sections */
                .typesense-search-history {
                    padding: 0px !important;
                }
                
                .typesense-search-history-section {
                    padding-left: 0px !important;
                    padding-right: 0px !important;
                }
                
                .typesense-search-history-title {
                    padding-left: 0px !important;
                    padding-right: 0px !important;
                }
                
                .typesense-search-history-tags {
                    padding-left: 0px !important;
                    padding-right: 0px !important;
                }
                
                .typesense-search-history-products {
                    padding-left: 0px !important;
                    padding-right: 0px !important;
                }
                
                /* Prevent iOS zoom on input focus */
                .typesense-search-input,
                .typesense-search-container input[type='text'].typesense-search-input {
                    font-size: 16px !important;
                }
            }
        ";
        
        // Add mobile fullscreen styles only if enabled
        if ($enable_mobile_fullscreen === '1') {
            $custom_css .= "
            /* Mobile styles - Fullscreen mode */
            @media (max-width: {$mobile_breakpoint}px) {
                /* Show mobile trigger button */
                .typesense-search-mobile-trigger {
                    display: flex !important;
                    width: {$btn_width} !important;
                    height: {$btn_height} !important;
                    background: {$btn_bg} !important;
                    border: none !important;
                    border-radius: {$btn_radius} !important;
                    cursor: pointer !important;
                    color: #333 !important;
                    transition: all 0.2s ease !important;
                    align-items: center !important;
                    justify-content: center !important;
                    padding: {$btn_padding} !important;
                    position: relative !important;
                    z-index: 9999 !important;
                }
                
                .typesense-search-mobile-trigger:hover {
                    background: {$btn_bg_hover} !important;
                    color: {$primary_color} !important;
                }

                .typesense-search-mobile-trigger svg {
                    width: {$mobile_icon_size}px !important;
                    height: {$mobile_icon_size}px !important;
                }
                
                /* Search container - hidden by default, shown as fullscreen when active */
                .typesense-search-container {
                    display: none !important;
                    position: fixed !important;
                    top: 0 !important;
                    left: 0 !important;
                    right: 0 !important;
                    bottom: 0 !important;
                    width: 100% !important;
                    max-width: 100% !important;
                    height: 100vh !important;
                    background: #fff !important;
                    z-index: 9999 !important;
                    margin: 0 !important;
                    padding: 20px !important;
                    overflow-y: auto !important;
                }
                
                .typesense-search-container.active {
                    display: block !important;
                }
                
                .typesense-search-header {
                    display: flex !important;
                    gap: 10px !important;
                    align-items: center !important;
                    margin-bottom: 20px !important;
                }
                
                .typesense-search-close {
                    display: flex !important;
                    width: {$btn_width} !important;
                    height: {$btn_height} !important;
                    background: {$btn_bg} !important;
                    border: none !important;
                    border-radius: {$btn_radius} !important;
                    cursor: pointer !important;
                    color: #333 !important;
                    transition: all 0.2s ease !important;
                    align-items: center !important;
                    justify-content: center !important;
                    flex-shrink: 0 !important;
                    padding: {$btn_padding} !important;
                }
                
                .typesense-search-close:hover {
                    background: {$btn_bg_hover} !important;
                    color: {$primary_color} !important;
                }
                
                .typesense-search-close svg {
                    width: {$mobile_icon_size}px !important;
                    height: {$mobile_icon_size}px !important;
                }
                
                .typesense-search-wrapper {
                    max-width: 100% !important;
                    flex: 1 !important;
                }
                
                .typesense-search-results {
                    position: static !important;
                    transform: none !important;
                    left: auto !important;
                    width: 100% !important;
                    max-width: 100% !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    max-height: calc(100vh - 100px) !important;
                    border: none !important;
                    box-shadow: none !important;
                }
                
                .typesense-search-overlay.active {
                    display: none !important;
                }
            }
            ";
        }
        
        wp_add_inline_style('typesense-search-frontend', $custom_css);
        
        wp_enqueue_script(
            'typesense-search-frontend',
            TYPESENSE_SEARCH_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            TYPESENSE_SEARCH_VERSION . '.' . time(), // Force cache bust
            true
        );
        
        wp_localize_script('typesense-search-frontend', 'typesenseSearch', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('typesense_search_frontend'),
            'homeUrl' => home_url('/'),
        ));
    }
    
    /**
     * Renderovat search bar
     *
     * @param array $atts Atributy shortcode
     * @return string
     */
    public function render_search_bar(array $atts = array()): string {
        // Načíst skripty když je shortcode použit
        $this->enqueue_search_scripts();
        
        $default_placeholder = get_option('typesense_search_placeholder_text', __('Hledat...', 'typesense-search'));
        
        $atts = shortcode_atts(array(
            'placeholder' => $default_placeholder,
            'per_page' => 10,
        ), $atts);
        
        $enable_mobile_fullscreen = get_option('typesense_search_enable_mobile_fullscreen', '1');
        
        ob_start();
        ?>
        <?php if ($enable_mobile_fullscreen === '1') : ?>
        <button type="button" class="typesense-search-mobile-trigger" aria-label="Otevřít vyhledávání">
            <svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M16.25 15.75L12.995 12.495M14.75 8.25C14.75 11.5637 12.0637 14.25 8.75 14.25C5.43629 14.25 2.75 11.5637 2.75 8.25C2.75 4.93629 5.43629 2.25 8.75 2.25C12.0637 2.25 14.75 4.93629 14.75 8.25Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
        <?php endif; ?>
        <div class="typesense-search-container">
            <div class="typesense-search-header">
                <div class="typesense-search-wrapper">
                    <input 
                        type="text" 
                        class="typesense-search-input" 
                        placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                        autocomplete="off"
                    />
                    <button type="button" class="typesense-search-button" aria-label="Hledat">
                        <svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M16.25 15.75L12.995 12.495M14.75 8.25C14.75 11.5637 12.0637 14.25 8.75 14.25C5.43629 14.25 2.75 11.5637 2.75 8.25C2.75 4.93629 5.43629 2.25 8.75 2.25C12.0637 2.25 14.75 4.93629 14.75 8.25Z" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <div class="typesense-search-loader" style="display: none;">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" stroke-dasharray="31.416" stroke-dashoffset="31.416" fill="none">
                                <animate attributeName="stroke-dasharray" dur="2s" values="0 31.416;15.708 15.708;0 31.416;0 31.416" repeatCount="indefinite"/>
                                <animate attributeName="stroke-dashoffset" dur="2s" values="0;-15.708;-31.416;-31.416" repeatCount="indefinite"/>
                            </circle>
                        </svg>
                    </div>
                </div>
                <?php if ($enable_mobile_fullscreen === '1') : ?>
                <button type="button" class="typesense-search-close" aria-label="Zavřít vyhledávání">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>
            <div class="typesense-search-results"></div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX handler pro načtení doporučených produktů
     */
    public function ajax_get_recommended_products(): void {
        try {
            // Kontrola nonce
            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            if (!wp_verify_nonce($nonce, 'typesense_search_frontend')) {
                wp_send_json_error(array(
                    'message' => __('Neplatný bezpečnostní token.', 'typesense-search'),
                    'code' => 'invalid_nonce'
                ));
                return;
            }
            
            // Získat doporučené produkty - můžete upravit dotaz podle vašich potřeb
            // Například: nejprodávanější, nové, vybrané, nebo náhodné
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => 6,
                'post_status' => 'publish',
                'meta_query' => array(
                    array(
                        'key' => '_stock_status',
                        'value' => 'instock',
                    ),
                ),
                'orderby' => 'rand', // Nebo 'date', 'meta_value_num' pro nejprodávanější
                'order' => 'DESC',
            );
            
            $query = new \WP_Query($args);
            $products = array();
            
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $product_id = get_the_ID();
                    $product = wc_get_product($product_id);
                    
                    if ($product) {
                        $products[] = array(
                            'id' => $product_id,
                            'name' => $product->get_name(),
                            'permalink' => get_permalink($product_id),
                            'image' => get_the_post_thumbnail_url($product_id, 'woocommerce_thumbnail'),
                            'price' => $product->get_price(),
                            'regular_price' => $product->get_regular_price(),
                            'sale_price' => $product->get_sale_price(),
                        );
                    }
                }
                wp_reset_postdata();
            }
            
            wp_send_json_success(array('products' => $products));
            
        } catch (\Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
                'code' => 'exception'
            ));
        }
    }
    
    /**
     * Veřejné API pro vyhledávání produktů (použitelné v PHP šablonách)
     * 
     * @param string $query Hledaný výraz
     * @param array $args Volitelné parametry (per_page, page, atd.)
     * @return array Výsledky vyhledávání
     */
    public function get_product_results(string $query, array $args = []): array {
        // Výchozí nastavení
        $defaults = array(
            'per_page' => 12,
            'page' => 1,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // BYPASS PRESETŮ: Abychom zajistili okamžitou funkčnost podle vašeho JSON příkladu
        // a eliminovali chyby v nastavení presetu na serveru.
        
        // Definice parametrů přímo podle funkčního vzoru
        $search_params = array(
            'q' => $query,
            'per_page' => $args['per_page'],
            'page' => $args['page'],
            // Použijeme pole z vašeho příkladu + přidáme důležité e-shop pole (sku, brands, manufacturer)
            // Odstranili jsme váhy, aby to odpovídalo vašemu funkčnímu příkladu (Typesense použije pořadí polí jako prioritu)
            'query_by' => 'name,sku,categories,tags,brands,manufacturer,short_description,description',
            'exhaustive_search' => true, // Podle vašeho příkladu
            'num_typos' => 2,
            'min_len_1typo' => 3,
            'min_len_2typo' => 4,
            'prioritize_exact_match' => true,
        );

        // Možnost přidat filter_by z argumentů
        if (isset($args['filter_by'])) {
            $search_params['filter_by'] = $args['filter_by'];
        }
        
        $products_result = $this->client->search_products($query, $search_params);
        
        if (is_wp_error($products_result)) {
            error_log('Typesense Search API Error: ' . $products_result->get_error_message());
            return array(
                'hits' => [],
                'found' => 0,
                'page' => $args['page'],
                'total_pages' => 0
            );
        } elseif (isset($products_result['hits'])) {
            return array(
                'hits' => $this->format_products($products_result['hits']),
                'found' => $products_result['found'] ?? 0,
                'page' => $products_result['page'] ?? 1,
                'total_pages' => ceil(($products_result['found'] ?? 0) / $args['per_page'])
            );
        }
        
        return array('hits' => [], 'found' => 0, 'page' => 1, 'total_pages' => 0);
    }



    /**
     * AJAX handler pro vyhledávání
     */
    public function ajax_search(): void {
        try {
            // Kontrola nonce
            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            if (!wp_verify_nonce($nonce, 'typesense_search_frontend')) {
                wp_send_json_error(array(
                    'message' => __('Neplatný bezpečnostní token.', 'typesense-search'),
                    'code' => 'invalid_nonce'
                ));
                return;
            }
            
            $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
            $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';
            
            if (empty($query)) {
                wp_send_json_success(array(
                    'products' => array(),
                    'categories' => array(),
                    'brands' => array(),
                ));
                return;
            }
            
            $results = array();
        
            // Vyhledat produkty
            if ($type === 'all' || $type === 'products') {
                // Použití nové API metody pro konzistentní výsledky
                $api_results = $this->get_product_results($query, ['per_page' => 6]);
                $results['products'] = $api_results['hits'];

                // LOGOVÁNÍ - ODSTRANĚNO (Nyní řeší frontend přes lightweight REST API)
                // if (!empty($query) && strlen($query) > 2) { ... }
            }
            
            // Vyhledat kategorie
        if ($type === 'all' || $type === 'categories') {
            $categories_result = $this->client->search_categories($query, array(
                'per_page' => 5,
                'query_by' => 'name,description',
            ));
            
            if (is_wp_error($categories_result)) {
                error_log('Typesense Categories Search Error: ' . $categories_result->get_error_message());
                $results['categories'] = array();
            } elseif (isset($categories_result['hits'])) {
                $results['categories'] = $this->format_categories($categories_result['hits']);
            } else {
                $results['categories'] = array();
            }
        }
        
        // Vyhledat značky
        if ($type === 'all' || $type === 'brands') {
            $brands_result = $this->client->search_brands($query, array(
                'per_page' => 5,
                'query_by' => 'name,description',
            ));
            
            if (is_wp_error($brands_result)) {
                error_log('Typesense Brands Search Error: ' . $brands_result->get_error_message());
                $results['brands'] = array();
            } elseif (isset($brands_result['hits'])) {
                $results['brands'] = $this->format_brands($brands_result['hits']);
            } else {
                $results['brands'] = array();
            }
        }
        
        wp_send_json_success($results);
        
        } catch (\Exception $e) {
            $error_msg = $e->getMessage();
            $error_file = $e->getFile();
            $error_line = $e->getLine();
            $error_trace = $e->getTraceAsString();
            
            error_log('Typesense Search Fatal Error: ' . $error_msg);
            error_log('File: ' . $error_file . ' on line ' . $error_line);
            error_log('Trace: ' . $error_trace);
            
            wp_send_json_error(array(
                'message' => __('Došlo k chybě při vyhledávání. Zkuste to prosím znovu.', 'typesense-search'),
                'code' => 'fatal_error',
                'debug' => WP_DEBUG ? array(
                    'message' => $error_msg,
                    'file' => $error_file,
                    'line' => $error_line
                ) : null
            ));
        } catch (\Error $e) {
            $error_msg = $e->getMessage();
            $error_file = $e->getFile();
            $error_line = $e->getLine();
            $error_trace = $e->getTraceAsString();
            
            error_log('Typesense Search Fatal Error: ' . $error_msg);
            error_log('File: ' . $error_file . ' on line ' . $error_line);
            error_log('Trace: ' . $error_trace);
            
            wp_send_json_error(array(
                'message' => __('Došlo k chybě při vyhledávání. Zkuste to prosím znovu.', 'typesense-search'),
                'code' => 'fatal_error',
                'debug' => WP_DEBUG ? array(
                    'message' => $error_msg,
                    'file' => $error_file,
                    'line' => $error_line
                ) : null
            ));
        }
    }
    
    /**
     * Formátovat produkty
     *
     * @param array $hits Výsledky z Typesense
     * @return array
     */
    private function format_products(array $hits): array {
        $products = array();
        
        foreach ($hits as $hit) {
            $document = $hit['document'];
            // Výrobce - použít manufacturer nebo první značku z brands
            $manufacturer = $document['manufacturer'] ?? '';
            if (empty($manufacturer) && !empty($document['brands']) && is_array($document['brands'])) {
                $manufacturer = $document['brands'][0] ?? '';
            }
            
            $products[] = array(
                'id' => $document['id'],
                'name' => $document['name'],
                'permalink' => $document['permalink'],
                'image' => $document['image'] ?? '',
                'price' => $document['price'] ?? null,
                'regular_price' => $document['regular_price'] ?? null,
                'sale_price' => $document['sale_price'] ?? null,
                'stock_status' => $document['stock_status'] ?? 'instock',
                'stock_quantity' => $document['stock_quantity'] ?? null,
                'manufacturer' => $manufacturer,
                'brands' => $document['brands'] ?? array(),
            );
        }
        
        return $products;
    }
    
    /**
     * Formátovat kategorie
     *
     * @param array $hits Výsledky z Typesense
     * @return array
     */
    private function format_categories(array $hits): array {
        $categories = array();
        
        foreach ($hits as $hit) {
            $document = $hit['document'];
            $categories[] = array(
                'id' => $document['id'],
                'name' => $document['name'],
                'permalink' => $document['permalink'],
                'image' => $document['image'] ?? '',
            );
        }
        
        return $categories;
    }
    
    /**
     * Formátovat značky
     *
     * @param array $hits Výsledky z Typesense
     * @return array
     */
    private function format_brands(array $hits): array {
        $brands = array();
        
        foreach ($hits as $hit) {
            $document = $hit['document'];
            $brands[] = array(
                'id' => $document['id'],
                'name' => $document['name'],
                'permalink' => $document['permalink'],
                'image' => $document['image'] ?? '',
            );
        }
        
        return $brands;
    }
}
