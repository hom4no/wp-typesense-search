<?php
/**
 * Admin rozhraní pro Typesense Search
 *
 * @package Typesense_Search
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Třída Typesense_Search_Admin
 */
class Typesense_Search_Admin {
    
    /**
     * Typesense klient
     *
     * @var Typesense_Search_Client
     */
    private $client;
    
    /**
     * Konstruktor
     *
     * @param Typesense_Search_Client $client Typesense klient
     */
    public function __construct(Typesense_Search_Client $client) {
        $this->client = $client;
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlery
        add_action('wp_ajax_typesense_search_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_typesense_search_create_collection', array($this, 'ajax_create_collection'));
        add_action('wp_ajax_typesense_search_delete_collection', array($this, 'ajax_delete_collection'));
        add_action('wp_ajax_typesense_search_sync_data', array($this, 'ajax_sync_data'));
        add_action('wp_ajax_typesense_search_get_collections_status', array($this, 'ajax_get_collections_status'));
    }
    
    /**
     * Načíst admin skripty a styly
     */
    public function enqueue_admin_scripts($hook): void {
        // Načíst pouze na stránce našeho pluginu
        if (strpos($hook, 'typesense-search') === false) {
            return;
        }
        
        // WordPress Color Picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
    
    /**
     * Přidat admin menu
     */
    public function add_admin_menu(): void {
        // Hlavní menu - vede na nastavení
        add_menu_page(
            __('Typesense Search', 'typesense-search'),
            __('Typesense Search', 'typesense-search'),
            'manage_options',
            'typesense-search',
            array($this, 'render_connection_page'),
            'dashicons-search',
            30
        );

        // Submenu - Nastavení (stejné jako hlavní)
        add_submenu_page(
            'typesense-search',
            __('Nastavení připojení', 'typesense-search'),
            __('Nastavení', 'typesense-search'),
            'manage_options',
            'typesense-search',
            array($this, 'render_connection_page')
        );

        // Submenu - Kolekce
        add_submenu_page(
            'typesense-search',
            __('Správa kolekcí', 'typesense-search'),
            __('Kolekce', 'typesense-search'),
            'manage_options',
            'typesense-search-collections',
            array($this, 'render_collections_page')
        );
    }
    
    /**
     * Registrovat nastavení
     */
    public function register_settings(): void {
        register_setting('typesense_search_settings', 'typesense_search_host');
        register_setting('typesense_search_settings', 'typesense_search_port');
        register_setting('typesense_search_settings', 'typesense_search_protocol');
        register_setting('typesense_search_settings', 'typesense_search_api_key');
        register_setting('typesense_search_settings', 'typesense_search_collection_prefix');
        
        // Color settings
        register_setting('typesense_search_settings', 'typesense_search_primary_color');
        register_setting('typesense_search_settings', 'typesense_search_primary_hover_color');
        register_setting('typesense_search_settings', 'typesense_search_input_bg_color');
        register_setting('typesense_search_settings', 'typesense_search_input_border_color');
        register_setting('typesense_search_settings', 'typesense_search_text_color');
        register_setting('typesense_search_settings', 'typesense_search_border_radius');
        
        // Mobile settings
        register_setting('typesense_search_settings', 'typesense_search_mobile_breakpoint');
        register_setting('typesense_search_settings', 'typesense_search_enable_mobile_fullscreen');
        register_setting('typesense_search_settings', 'typesense_search_mobile_icon_style');
        register_setting('typesense_search_settings', 'typesense_search_mobile_icon_size');
    }
    
    /**
     * Renderovat stránku nastavení připojení
     */
    public function render_connection_page(): void {
        // Uložení nastavení
        if (isset($_POST['submit']) && check_admin_referer('typesense_search_settings')) {
            update_option('typesense_search_host', sanitize_text_field($_POST['typesense_search_host']));
            update_option('typesense_search_port', sanitize_text_field($_POST['typesense_search_port']));
            update_option('typesense_search_protocol', sanitize_text_field($_POST['typesense_search_protocol']));
            update_option('typesense_search_api_key', sanitize_text_field($_POST['typesense_search_api_key']));
            update_option('typesense_search_collection_prefix', sanitize_text_field($_POST['typesense_search_collection_prefix']));
            
            // Save color settings
            update_option('typesense_search_primary_color', sanitize_hex_color($_POST['typesense_search_primary_color']));
            update_option('typesense_search_primary_hover_color', sanitize_hex_color($_POST['typesense_search_primary_hover_color']));
            update_option('typesense_search_input_bg_color', sanitize_hex_color($_POST['typesense_search_input_bg_color']));
            update_option('typesense_search_input_border_color', sanitize_hex_color($_POST['typesense_search_input_border_color']));
            update_option('typesense_search_text_color', sanitize_hex_color($_POST['typesense_search_text_color']));
            update_option('typesense_search_border_radius', sanitize_text_field($_POST['typesense_search_border_radius']));
            
            // Save mobile settings
            update_option('typesense_search_mobile_breakpoint', absint($_POST['typesense_search_mobile_breakpoint']));
            update_option('typesense_search_enable_mobile_fullscreen', isset($_POST['typesense_search_enable_mobile_fullscreen']) ? '1' : '0');
            update_option('typesense_search_mobile_icon_style', sanitize_text_field($_POST['typesense_search_mobile_icon_style']));
            update_option('typesense_search_mobile_icon_size', absint($_POST['typesense_search_mobile_icon_size']));
            
            echo '<div class="notice notice-success"><p>' . esc_html__('Nastavení uloženo.', 'typesense-search') . '</p></div>';
        }
        
        $host = get_option('typesense_search_host', 'localhost');
        $port = get_option('typesense_search_port', '8108');
        $protocol = get_option('typesense_search_protocol', 'http');
        $api_key = get_option('typesense_search_api_key', '');
        $collection_prefix = get_option('typesense_search_collection_prefix', '');
        
        // Color settings with defaults
        $primary_color = get_option('typesense_search_primary_color', '#064740');
        $primary_hover_color = get_option('typesense_search_primary_hover_color', '#146154');
        $input_bg_color = get_option('typesense_search_input_bg_color', '#f1f5f9');
        $input_border_color = get_option('typesense_search_input_border_color', '#cbd5e1');
        $text_color = get_option('typesense_search_text_color', '#334155');
        $border_radius = get_option('typesense_search_border_radius', 'm');
        
        // Mobile settings with default
        $mobile_breakpoint = get_option('typesense_search_mobile_breakpoint', '1050');
        $enable_mobile_fullscreen = get_option('typesense_search_enable_mobile_fullscreen', '1');
        $mobile_icon_style = get_option('typesense_search_mobile_icon_style', 'round');
        $mobile_icon_size = get_option('typesense_search_mobile_icon_size', '24');
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Nastavení připojení Typesense', 'typesense-search'); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('typesense_search_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_host"><?php esc_html_e('Host', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="typesense_search_host" name="typesense_search_host" value="<?php echo esc_attr($host); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Adresa Typesense serveru (např. localhost)', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_port"><?php esc_html_e('Port', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="typesense_search_port" name="typesense_search_port" value="<?php echo esc_attr($port); ?>" class="small-text" />
                            <p class="description"><?php esc_html_e('Port Typesense serveru (výchozí: 8108)', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_protocol"><?php esc_html_e('Protocol', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <select id="typesense_search_protocol" name="typesense_search_protocol">
                                <option value="http" <?php selected($protocol, 'http'); ?>>HTTP</option>
                                <option value="https" <?php selected($protocol, 'https'); ?>>HTTPS</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_api_key"><?php esc_html_e('API Key', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="typesense_search_api_key" name="typesense_search_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('API klíč pro Typesense server', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_collection_prefix"><?php esc_html_e('Prefix kolekcí', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="typesense_search_collection_prefix" name="typesense_search_collection_prefix" value="<?php echo esc_attr($collection_prefix); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Prefix pro názvy kolekcí (např. "myshop_"). Užitečné při použití jednoho serveru pro více webů.', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Nastavení barev', 'typesense-search'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_primary_color"><?php esc_html_e('Primární barva tlačítka', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="typesense_search_primary_color" name="typesense_search_primary_color" value="<?php echo esc_attr($primary_color); ?>" class="color-picker" data-default-color="#064740" />
                            <p class="description"><?php esc_html_e('Barva tlačítka vyhledávání', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_primary_hover_color"><?php esc_html_e('Barva tlačítka při najetí', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="typesense_search_primary_hover_color" name="typesense_search_primary_hover_color" value="<?php echo esc_attr($primary_hover_color); ?>" class="color-picker" data-default-color="#146154" />
                            <p class="description"><?php esc_html_e('Barva tlačítka při hover efektu', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_input_bg_color"><?php esc_html_e('Pozadí inputu', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="typesense_search_input_bg_color" name="typesense_search_input_bg_color" value="<?php echo esc_attr($input_bg_color); ?>" class="color-picker" data-default-color="#f1f5f9" />
                            <p class="description"><?php esc_html_e('Barva pozadí vyhledávacího pole', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_input_border_color"><?php esc_html_e('Barva rámečku inputu', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="typesense_search_input_border_color" name="typesense_search_input_border_color" value="<?php echo esc_attr($input_border_color); ?>" class="color-picker" data-default-color="#cbd5e1" />
                            <p class="description"><?php esc_html_e('Barva rámečku kolem vyhledávacího pole', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_text_color"><?php esc_html_e('Barva textu', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="typesense_search_text_color" name="typesense_search_text_color" value="<?php echo esc_attr($text_color); ?>" class="color-picker" data-default-color="#334155" />
                            <p class="description"><?php esc_html_e('Barva textu ve vyhledávacím poli', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_border_radius"><?php esc_html_e('Zakulacení rohů (Border Radius)', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <select id="typesense_search_border_radius" name="typesense_search_border_radius">
                                <option value="0" <?php selected($border_radius, '0'); ?>>0 (Žádné)</option>
                                <option value="xs" <?php selected($border_radius, 'xs'); ?>>XS</option>
                                <option value="s" <?php selected($border_radius, 's'); ?>>S</option>
                                <option value="m" <?php selected($border_radius, 'm'); ?>>M</option>
                                <option value="l" <?php selected($border_radius, 'l'); ?>>L</option>
                                <option value="xl" <?php selected($border_radius, 'xl'); ?>>XL</option>
                                <option value="full" <?php selected($border_radius, 'full'); ?>>Full (Pill)</option>
                            </select>
                            <p class="description"><?php esc_html_e('Nastavení zakulacení rohů pro vyhledávací pole a výsledky.', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <h2><?php esc_html_e('Mobilní zobrazení', 'typesense-search'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_enable_mobile_fullscreen"><?php esc_html_e('Mobilní fullscreen režim', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="typesense_search_enable_mobile_fullscreen" name="typesense_search_enable_mobile_fullscreen" value="1" <?php checked($enable_mobile_fullscreen, '1'); ?> />
                                <?php esc_html_e('Povolit fullscreen vyhledávání na mobilech', 'typesense-search'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Pokud je zapnuto, na mobilních zařízeních se zobrazí ikona, která otevře fullscreen vyhledávání. Pokud je vypnuto, zůstane klasický search bar.', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_mobile_breakpoint"><?php esc_html_e('Breakpoint pro mobil', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="typesense_search_mobile_breakpoint" name="typesense_search_mobile_breakpoint" value="<?php echo esc_attr($mobile_breakpoint); ?>" class="small-text" min="320" max="2000" step="1" />
                            <span>px</span>
                            <p class="description"><?php esc_html_e('Šířka obrazovky, pod kterou se použije mobilní zobrazení. Výchozí: 1050px', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_mobile_icon_style"><?php esc_html_e('Styl mobilní ikony', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <select id="typesense_search_mobile_icon_style" name="typesense_search_mobile_icon_style">
                                <option value="round" <?php selected($mobile_icon_style, 'round'); ?>><?php esc_html_e('Kulaté pozadí', 'typesense-search'); ?></option>
                                <option value="simple" <?php selected($mobile_icon_style, 'simple'); ?>><?php esc_html_e('Jednoduché (bez pozadí)', 'typesense-search'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Vzhled ikony lupy a křížku na mobilu.', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="typesense_search_mobile_icon_size"><?php esc_html_e('Velikost mobilní ikony', 'typesense-search'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="typesense_search_mobile_icon_size" name="typesense_search_mobile_icon_size" value="<?php echo esc_attr($mobile_icon_size); ?>" class="small-text" min="16" max="64" step="1" />
                            <span>px</span>
                            <p class="description"><?php esc_html_e('Velikost ikony (šířka/výška). Výchozí: 24px (pro jednoduchý styl) nebo 48px (pro kulatý styl).', 'typesense-search'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button button-primary" value="<?php esc_attr_e('Uložit nastavení', 'typesense-search'); ?>" />
                    <button type="button" id="test-connection" class="button button-secondary" style="margin-left: 10px;"><?php esc_html_e('Testovat připojení', 'typesense-search'); ?></button>
                </p>
            </form>
            
            <div id="test-result" style="margin-top: 20px;"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Initialize WordPress color picker
            $('.color-picker').wpColorPicker();
            
            var ajaxNonce = '<?php echo wp_create_nonce('typesense_search_test'); ?>';
            
            // Test připojení
            $('#test-connection').on('click', function() {
                var $button = $(this);
                var originalText = $button.text();
                
                $button.prop('disabled', true).text('<?php esc_html_e('Testuji...', 'typesense-search'); ?>');
                $('#test-result').html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'typesense_search_test_connection',
                        _ajax_nonce: ajaxNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#test-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        } else {
                            $('#test-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#test-result').html('<div class="notice notice-error"><p><?php esc_html_e('Došlo k chybě při testování.', 'typesense-search'); ?></p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Renderovat stránku správy kolekcí
     */
    public function render_collections_page(): void {
        $collection_prefix = get_option('typesense_search_collection_prefix', '');
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Správa kolekcí a dat', 'typesense-search'); ?></h1>
            
            <div id="global-message"></div>

            <?php if (class_exists('WooCommerce')): ?>
            
            <div class="card" style="max-width: 100%; margin-top: 20px; padding: 0;">
                <div style="padding: 20px; border-bottom: 1px solid #ccd0d4; display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="margin: 0;"><?php esc_html_e('Přehled kolekcí', 'typesense-search'); ?></h2>
                    <div>
                        <?php if (!empty($collection_prefix)): ?>
                            <span class="description" style="margin-right: 15px;">Prefix: <strong><?php echo esc_html($collection_prefix); ?></strong></span>
                        <?php endif; ?>
                        <button type="button" id="refresh-status" class="button"><span class="dashicons dashicons-update" style="line-height: 28px;"></span> <?php esc_html_e('Obnovit stav', 'typesense-search'); ?></button>
                    </div>
                </div>
                
                <table class="wp-list-table widefat fixed striped table-view-list">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Kolekce', 'typesense-search'); ?></th>
                            <th><?php esc_html_e('Název v Typesense', 'typesense-search'); ?></th>
                            <th><?php esc_html_e('Stav', 'typesense-search'); ?></th>
                            <th><?php esc_html_e('Dokumenty', 'typesense-search'); ?></th>
                            <th style="text-align: right;"><?php esc_html_e('Akce', 'typesense-search'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="collections-table-body">
                        <tr id="row-products">
                            <td><strong><?php esc_html_e('Produkty', 'typesense-search'); ?></strong></td>
                            <td class="col-name"><span class="spinner is-active" style="float: none; margin: 0;"></span></td>
                            <td class="col-status">-</td>
                            <td class="col-docs">-</td>
                            <td class="col-actions" style="text-align: right;">
                                <button type="button" class="button action-create" data-type="products" disabled><?php esc_html_e('Vytvořit', 'typesense-search'); ?></button>
                                <button type="button" class="button action-sync" data-type="products" disabled><?php esc_html_e('Synchronizovat', 'typesense-search'); ?></button>
                                <button type="button" class="button action-delete" data-type="products" style="color: #b32d2e;" disabled><?php esc_html_e('Smazat', 'typesense-search'); ?></button>
                            </td>
                        </tr>
                        <tr id="row-categories">
                            <td><strong><?php esc_html_e('Kategorie', 'typesense-search'); ?></strong></td>
                            <td class="col-name"><span class="spinner is-active" style="float: none; margin: 0;"></span></td>
                            <td class="col-status">-</td>
                            <td class="col-docs">-</td>
                            <td class="col-actions" style="text-align: right;">
                                <button type="button" class="button action-create" data-type="categories" disabled><?php esc_html_e('Vytvořit', 'typesense-search'); ?></button>
                                <button type="button" class="button action-sync" data-type="categories" disabled><?php esc_html_e('Synchronizovat', 'typesense-search'); ?></button>
                                <button type="button" class="button action-delete" data-type="categories" style="color: #b32d2e;" disabled><?php esc_html_e('Smazat', 'typesense-search'); ?></button>
                            </td>
                        </tr>
                        <tr id="row-brands">
                            <td><strong><?php esc_html_e('Značky', 'typesense-search'); ?></strong></td>
                            <td class="col-name"><span class="spinner is-active" style="float: none; margin: 0;"></span></td>
                            <td class="col-status">-</td>
                            <td class="col-docs">-</td>
                            <td class="col-actions" style="text-align: right;">
                                <button type="button" class="button action-create" data-type="brands" disabled><?php esc_html_e('Vytvořit', 'typesense-search'); ?></button>
                                <button type="button" class="button action-sync" data-type="brands" disabled><?php esc_html_e('Synchronizovat', 'typesense-search'); ?></button>
                                <button type="button" class="button action-delete" data-type="brands" style="color: #b32d2e;" disabled><?php esc_html_e('Smazat', 'typesense-search'); ?></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="padding: 20px; background: #f9f9f9; border-top: 1px solid #ccd0d4;">
                    <button type="button" id="create-all" class="button button-primary"><?php esc_html_e('Vytvořit vše', 'typesense-search'); ?></button>
                    <button type="button" id="sync-all" class="button button-primary"><?php esc_html_e('Synchronizovat vše', 'typesense-search'); ?></button>
                    <button type="button" id="delete-all" class="button button-secondary" style="color: #b32d2e; float: right;"><?php esc_html_e('Smazat vše', 'typesense-search'); ?></button>
                </div>
            </div>

            <!-- Modal/Log area -->
            <div id="process-log-container" style="display: none; margin-top: 20px;" class="card">
                <h3 style="padding: 10px 20px; margin: 0; border-bottom: 1px solid #eee;">
                    <?php esc_html_e('Průběh operace', 'typesense-search'); ?>
                    <span class="spinner" style="float: right;"></span>
                </h3>
                <div id="process-log" style="padding: 20px; max-height: 300px; overflow-y: auto; background: #f0f0f1; font-family: monospace;"></div>
            </div>
            
            <?php else: ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('WooCommerce není aktivní. Funkce pro vytváření kolekcí a synchronizaci dat nejsou dostupné.', 'typesense-search'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var ajaxNonce = '<?php echo wp_create_nonce('typesense_search_test'); ?>';
            
            // Načíst stav při startu
            loadCollectionsStatus();
            
            $('#refresh-status').on('click', loadCollectionsStatus);

            function log(message, type = 'info') {
                var color = '#000';
                if (type === 'success') color = 'green';
                if (type === 'error') color = 'red';
                $('#process-log').append('<div style="color: ' + color + ';">' + message + '</div>');
                var logDiv = document.getElementById('process-log');
                logDiv.scrollTop = logDiv.scrollHeight;
            }

            function loadCollectionsStatus() {
                $('.spinner').addClass('is-active');
                $('.action-create, .action-sync, .action-delete').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'typesense_search_get_collections_status',
                        _ajax_nonce: ajaxNonce
                    },
                    success: function(response) {
                        if (response.success) {
                            updateTable(response.data);
                        } else {
                            $('#global-message').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    complete: function() {
                        $('.spinner').removeClass('is-active');
                    }
                });
            }

            function updateTable(data) {
                $.each(data, function(key, info) {
                    var $row = $('#row-' + key);
                    $row.find('.col-name').text(info.name);
                    
                    if (info.exists) {
                        $row.find('.col-status').html('<span style="color: green; font-weight: bold;">&#10003; <?php esc_html_e('Vytvořena', 'typesense-search'); ?></span>');
                        $row.find('.col-docs').text(info.documents + ' <?php esc_html_e('dokumentů', 'typesense-search'); ?>');
                        $row.find('.action-create').hide();
                        $row.find('.action-sync').show().prop('disabled', false);
                        $row.find('.action-delete').show().prop('disabled', false);
                    } else {
                        $row.find('.col-status').html('<span style="color: #d63638;"><?php esc_html_e('Neexistuje', 'typesense-search'); ?></span>');
                        $row.find('.col-docs').text('-');
                        $row.find('.action-create').show().prop('disabled', false);
                        $row.find('.action-sync').hide();
                        $row.find('.action-delete').hide();
                    }
                });
            }

            // Handlery pro jednotlivé akce
            $(document).on('click', '.action-create', function() {
                var type = $(this).data('type');
                runOperation('create', [type]);
            });

            $(document).on('click', '.action-sync', function() {
                var type = $(this).data('type');
                runOperation('sync', [type]);
            });

            $(document).on('click', '.action-delete', function() {
                var type = $(this).data('type');
                if (confirm('<?php esc_html_e('Opravdu chcete smazat tuto kolekci? Všechna data budou ztracena.', 'typesense-search'); ?>')) {
                    runOperation('delete', [type]);
                }
            });

            // Hromadné akce
            $('#create-all').on('click', function() {
                runOperation('create', ['products', 'categories', 'brands']);
            });

            $('#sync-all').on('click', function() {
                runOperation('sync', ['products', 'categories', 'brands']);
            });

            $('#delete-all').on('click', function() {
                if (confirm('<?php esc_html_e('POZOR: Opravdu chcete smazat VŠECHNY kolekce? Tato akce je nevratná.', 'typesense-search'); ?>')) {
                    runOperation('delete', ['products', 'categories', 'brands']);
                }
            });

            function runOperation(operation, types) {
                $('#process-log-container').show();
                $('#process-log').html('');
                $('#process-log-container .spinner').addClass('is-active');
                
                // Zakázat všechna tlačítka během operace
                $('.button').prop('disabled', true);

                var currentIndex = 0;
                
                function processNext() {
                    if (currentIndex >= types.length) {
                        log('<?php esc_html_e('Dokončeno.', 'typesense-search'); ?>', 'success');
                        $('#process-log-container .spinner').removeClass('is-active');
                        loadCollectionsStatus(); // Obnovit tabulku
                        $('.button').prop('disabled', false); // Povolit tlačítka (loadCollectionsStatus si je pak nastaví podle stavu)
                        return;
                    }

                    var type = types[currentIndex];
                    var actionName = '';
                    var data = {
                        _ajax_nonce: ajaxNonce
                    };

                    if (operation === 'create') {
                        actionName = 'typesense_search_create_collection';
                        data.action = actionName;
                        data.collection_type = type;
                        log('<?php esc_html_e('Vytvářím kolekci:', 'typesense-search'); ?> ' + type + '...');
                    } else if (operation === 'sync') {
                        actionName = 'typesense_search_sync_data';
                        data.action = actionName;
                        data.sync_type = type;
                        log('<?php esc_html_e('Synchronizuji:', 'typesense-search'); ?> ' + type + '...');
                    } else if (operation === 'delete') {
                        // Pro mazání musíme poslat "název kolekce", ale AJAX delete_collection bere název včetně prefixu (z klienta)
                        // Zde v JS posíláme 'products', 'categories' atd.
                        // ALE POZOR: ajax_delete_collection bere 'collection_name', ale čeká, že mu pošleme už prefixovaný název nebo ne?
                        // Podívejme se na implementaci ajax_delete_collection v PHP.
                        // PHP volá $this->client->delete_collection($collection_name).
                        // Client bere jméno a smaže ho. Client NEDOPLŇUJE prefix v delete_collection.
                        // Ale my jsme neupravili delete_collection v klientovi aby doplňoval prefix?
                        // Krok 1 upravil create, search, index, ale delete_collection ne! 
                        // Počkat, Client::delete_collection v kroku 1 nevolal get_collection_name.
                        // Musíme upravit Client::delete_collection nebo poslat správný název z PHP.
                        // Zde v JS nemáme přístup k prefixu přímo (máme ho v UI).
                        // LEPŠÍ: Upravím PHP ajax handler aby si prefix přidal sám (resp. použil get_collection_name).
                        
                        // Prozatím v JS:
                        actionName = 'typesense_search_delete_collection';
                        data.action = actionName;
                        data.collection_type = type; // Nový parametr pro PHP handler
                        log('<?php esc_html_e('Mažu kolekci:', 'typesense-search'); ?> ' + type + '...');
                    }

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: data,
                        success: function(response) {
                            if (response.success) {
                                log('OK: ' + response.data.message, 'success');
                            } else {
                                log('CHYBA: ' + response.data.message, 'error');
                            }
                            currentIndex++;
                            processNext();
                        },
                        error: function() {
                            log('<?php esc_html_e('Chyba komunikace se serverem.', 'typesense-search'); ?>', 'error');
                            currentIndex++;
                            processNext();
                        }
                    });
                }

                processNext();
            }
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler pro získání stavu kolekcí
     */
    public function ajax_get_collections_status(): void {
        check_ajax_referer('typesense_search_test', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnění.', 'typesense-search')));
            return;
        }

        // Načíst všechny kolekce ze serveru
        $result = $this->client->retrieve_collections();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Mapování názvů na objekty
        $server_collections = array();
        if (is_array($result)) {
            foreach ($result as $col) {
                $server_collections[$col['name']] = $col;
            }
        }

        $types = ['products', 'categories', 'brands'];
        $status = array();

        foreach ($types as $type) {
            $real_name = $this->client->get_collection_name($type);
            $exists = isset($server_collections[$real_name]);
            $documents = 0;
            if ($exists) {
                $documents = $server_collections[$real_name]['num_documents'] ?? 0;
            }

            $status[$type] = array(
                'name' => $real_name,
                'exists' => $exists,
                'documents' => $documents
            );
        }

        wp_send_json_success($status);
    }
    
    /**
     * AJAX handler pro testování připojení
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('typesense_search_test', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnění.', 'typesense-search')));
            return;
        }
        
        $result = $this->client->test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * AJAX handler pro vytváření kolekce
     */
    public function ajax_create_collection(): void {
        check_ajax_referer('typesense_search_test', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnění.', 'typesense-search')));
            return;
        }
        
        $collection_type = isset($_POST['collection_type']) ? sanitize_text_field($_POST['collection_type']) : '';
        
        if (empty($collection_type)) {
            wp_send_json_error(array('message' => __('Nebyl zadán typ kolekce.', 'typesense-search')));
            return;
        }
        
        $method_name = 'create_' . $collection_type . '_collection';
        if (!method_exists($this->client, $method_name)) {
            wp_send_json_error(array('message' => __('Neplatný typ kolekce.', 'typesense-search')));
            return;
        }
        
        $result = $this->client->$method_name();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * AJAX handler pro mazání kolekce
     */
    public function ajax_delete_collection(): void {
        check_ajax_referer('typesense_search_test', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnění.', 'typesense-search')));
            return;
        }
        
        // Podpora pro collection_type (aby to fungovalo s JS logikou)
        $collection_type = isset($_POST['collection_type']) ? sanitize_text_field($_POST['collection_type']) : '';
        
        if (!empty($collection_type)) {
            // Získáme reálný název s prefixem
            $collection_name = $this->client->get_collection_name($collection_type);
        } else {
            // Fallback pro přímý název (pokud by bylo potřeba)
            $collection_name = isset($_POST['collection_name']) ? sanitize_text_field($_POST['collection_name']) : '';
        }
        
        if (empty($collection_name)) {
            wp_send_json_error(array('message' => __('Nebyl zadán název kolekce.', 'typesense-search')));
            return;
        }
        
        $result = $this->client->delete_collection($collection_name);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * AJAX handler pro synchronizaci dat
     */
    public function ajax_sync_data(): void {
        check_ajax_referer('typesense_search_test', '_ajax_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Nemáte oprávnění.', 'typesense-search')));
            return;
        }
        
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => __('WooCommerce není aktivní.', 'typesense-search')));
            return;
        }
        
        $sync_type = isset($_POST['sync_type']) ? sanitize_text_field($_POST['sync_type']) : '';
        
        if (empty($sync_type)) {
            wp_send_json_error(array('message' => __('Nebyl zadán typ synchronizace.', 'typesense-search')));
            return;
        }
        
        $plugin = typesense_search();
        if (!isset($plugin->indexer)) {
            wp_send_json_error(array('message' => __('Indexer není dostupný.', 'typesense-search')));
            return;
        }
        
        $indexer = $plugin->indexer;
        $method_name = 'index_all_' . $sync_type;
        
        if (!method_exists($indexer, $method_name)) {
            wp_send_json_error(array('message' => __('Neplatný typ synchronizace.', 'typesense-search')));
            return;
        }
        
        $result = $indexer->$method_name();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success($result);
        }
    }
}
