<?php
/**
 * Plugin Name: Typesense Search
 * Plugin URI: https://github.com/hom4no/typesense-search
 * Description: Základní plugin pro připojení k Typesense vyhledávacímu serveru
 * Version: 1.0.0
 * Author: Ondřej Homan
 * Author URI: https://github.com/hom4no
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: typesense-search
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Integrace Plugin Update Checker pro automatické aktualizace z GitHubu
require 'includes/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/hom4no/typesense-search',
    __FILE__,
    'typesense-search'
);

// Nastavení větve na 'main' (protože GitHub defaultně používá main, ale PUC může hledat master)
$myUpdateChecker->setBranch('main');

// Povolit stažení release assets (zip soubory z Releases)
$myUpdateChecker->getVcsApi()->enableReleaseAssets();

// Definice konstant
define('TYPESENSE_SEARCH_VERSION', '1.0.0');
define('TYPESENSE_SEARCH_PLUGIN_FILE', __FILE__);
define('TYPESENSE_SEARCH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TYPESENSE_SEARCH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TYPESENSE_SEARCH_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Hlavní třída pluginu
 */
class Typesense_Search {
    
    /**
     * Instance pluginu
     *
     * @var Typesense_Search
     */
    private static $instance = null;
    
    /**
     * Typesense klient
     *
     * @var Typesense_Search_Client
     */
    public $client;
    
    /**
     * Admin rozhraní
     *
     * @var Typesense_Search_Admin
     */
    public $admin;
    
    /**
     * Indexer
     *
     * @var Typesense_Search_Indexer
     */
    public $indexer;
    
    /**
     * Sync
     *
     * @var Typesense_Search_Sync
     */
    public $sync;
    
    /**
     * Frontend
     *
     * @var Typesense_Search_Frontend
     */
    public $frontend;
    
    
    /**
     * Získat instanci pluginu
     *
     * @return Typesense_Search
     */
    public static function get_instance(): Typesense_Search {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Konstruktor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Inicializace pluginu
     */
    private function init(): void {
        // Načtení závislostí
        $this->load_dependencies();
        
        // Inicializace komponent
        $this->client = new Typesense_Search_Client();
        $this->admin = new Typesense_Search_Admin($this->client);
        $this->frontend = new Typesense_Search_Frontend($this->client);
        
        // Inicializace indexeru pouze pokud je WooCommerce aktivní
        if (class_exists('WooCommerce')) {
            $this->indexer = new Typesense_Search_Indexer($this->client);
            $this->sync = new Typesense_Search_Sync($this->indexer);
        }
    }
    
    /**
     * Načtení závislostí
     */
    private function load_dependencies(): void {
        require_once TYPESENSE_SEARCH_PLUGIN_DIR . 'includes/class-typesense-search-client.php';
        require_once TYPESENSE_SEARCH_PLUGIN_DIR . 'includes/class-typesense-search-admin.php';
        require_once TYPESENSE_SEARCH_PLUGIN_DIR . 'includes/class-typesense-search-indexer.php';
        require_once TYPESENSE_SEARCH_PLUGIN_DIR . 'includes/class-typesense-search-sync.php';
        require_once TYPESENSE_SEARCH_PLUGIN_DIR . 'includes/class-typesense-search-frontend.php';
    }
}

/**
 * Spuštění pluginu
 */
function typesense_search() {
    // Kontrola WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
             $class = 'notice notice-error';
             $message = __('Typesense Search vyžaduje aktivní plugin WooCommerce!', 'typesense-search');
             printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
        });
        return null;
    }
    return Typesense_Search::get_instance();
}

// Spuštění pluginu
register_activation_hook(__FILE__, 'typesense_search_activate');
add_action('plugins_loaded', 'typesense_search', 20);

/**
 * Aktivace pluginu - vytvoření tabulky pro logy
 */
function typesense_search_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'typesense_search_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        query varchar(255) NOT NULL,
        results_count int(9) NOT NULL,
        user_id bigint(20) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Globální pomocná funkce pro vyhledávání
 * 
 * @param string $query Hledaný výraz
 * @param array $args Argumenty (per_page, page, filter_by...)
 * @return array Výsledky
 */
function typesense_get_search_results(string $query, array $args = []): array {
    $plugin = typesense_search();
    if ($plugin && isset($plugin->frontend)) {
        return $plugin->frontend->get_product_results($query, $args);
    }
    return ['hits' => [], 'found' => 0];
}

/**
 * Pomocná funkce pro Bricks Query Loop (Type: PHP)
 * Vrací pole WP_Post objektů podle aktuálního vyhledávání.
 * 
 * Použití v Bricks:
 * 1. Přidejte Container s Query Loop
 * 2. Query Type: PHP (Function)
 * 3. Function name: typesense_bricks_query_provider
 * 
 * @param object|null $bricks_query_obj Volitelný objekt Bricks Query pro nastavení paginace
 */
function typesense_bricks_query_provider($bricks_query_obj = null) {
    $search_query = get_search_query();
    
    // Pokud není vyhledávání, vrátíme prázdné pole (nebo fallback)
    if (empty($search_query)) {
        return [];
    }

    // Zkusíme získat číslo stránky z Bricks objektu, jinak z globálního WP
    $paged = 1;
    if ($bricks_query_obj && isset($bricks_query_obj->query_vars['paged'])) {
        $paged = max(1, (int)$bricks_query_obj->query_vars['paged']);
    } elseif (get_query_var('paged')) {
        $paged = max(1, (int)get_query_var('paged'));
    }

    $posts_per_page = get_option('posts_per_page');
    
    // Pojistka: pokud je hodnota příliš malá, nastavíme rozumný default pro e-shop
    // Vynutit minimálně 12 produktů na stránku vyhledávání
    if ($posts_per_page == -1) {
        $posts_per_page = 60; // Limit pro "vše"
    } elseif (empty($posts_per_page) || $posts_per_page < 12) {
        $posts_per_page = 12;
    }
    
    // Načíst výsledky z Typesense
    $results = typesense_get_search_results($search_query, [
        'page' => $paged,
        'per_page' => $posts_per_page
    ]);
    
    $post_ids = [];
    if (!empty($results['hits'])) {
        foreach ($results['hits'] as $hit) {
            $post_ids[] = (int) $hit['id'];
        }
    }
    
    // Nastavení paginace pro Bricks Query Object
    if ($bricks_query_obj && isset($results['found'])) {
        $found_posts = (int)$results['found'];
        $max_num_pages = ceil($found_posts / $posts_per_page);
        
        // Bricks potřebuje tyto hodnoty pro vykreslení paginace
        $bricks_query_obj->found_posts = $found_posts;
        $bricks_query_obj->max_num_pages = $max_num_pages;
        // Ujistíme se, že i query_vars mají správná data
        $bricks_query_obj->query_vars['posts_per_page'] = $posts_per_page;
    }

    // Záložní hack pro globální WP Query (pokud by Bricks bral paginaci odtud)
    global $wp_query;
    if ($wp_query->is_search() && isset($results['found'])) {
        $found_posts = (int)$results['found'];
        $wp_query->found_posts = $found_posts;
        $wp_query->max_num_pages = ceil($found_posts / $posts_per_page);
    }
    
    if (empty($post_ids)) {
        return [];
    }
    
    // Načíst WP_Post objekty pro Bricks
    $args = [
        'post_type' => 'product',
        'post__in' => $post_ids,
        'orderby' => 'post__in',
        'posts_per_page' => -1, // Načíst všechny IDčka, co nám vrátilo Typesense (stránkování už řešilo Typesense)
        'ignore_sticky_posts' => true,
        'no_found_rows' => true // Optimalizace
    ];
    
    $query = new WP_Query($args);
    
    // Dvojitá kontrola: Seřadíme výsledky ručně v PHP podle pořadí IDček v $post_ids,
    // protože MySQL někdy nerespektuje post__in pokud jsou tam duplicity nebo jiné vlivy.
    $posts = $query->posts;
    
    // Mapa ID -> Post objekt
    $post_map = [];
    foreach ($posts as $post) {
        $post_map[$post->ID] = $post;
    }
    
    // Seřadit podle původního pole IDček z Typesense
    $ordered_posts = [];
    foreach ($post_ids as $id) {
        if (isset($post_map[$id])) {
            $ordered_posts[] = $post_map[$id];
        }
    }
    
    return $ordered_posts;
}

/**
 * BRICKS BUILDER INTEGRACE - Custom Query Type
 * 
 * Přidá možnost "Typesense Search Results" přímo do dropdownu Query Type v Bricks Builderu.
 * Podle návodu: https://brickslabs.com/adding-any-custom-wp_query-loop-to-bricks-query-loop/
 */

/* 1. Přidat nový typ do dropdownu */
add_filter( 'bricks/setup/control_options', 'typesense_bricks_add_query_type');
function typesense_bricks_add_query_type( $control_options ) {
    $control_options['queryTypes']['typesense_search_query'] = esc_html__( 'Typesense Search Results', 'typesense-search' );
    return $control_options;
};

/* 2. Spustit logiku pro získání postů, pokud je vybrán náš typ */
add_filter( 'bricks/query/run', 'typesense_bricks_run_query', 10, 2);
function typesense_bricks_run_query( $results, $query_obj ) {
    if ( $query_obj->object_type !== 'typesense_search_query' ) {
        return $results;
    }

    // Předáme $query_obj do providera, aby mohl nastavit max_num_pages
    $posts = typesense_bricks_query_provider($query_obj);
    
    // Bricks očekává pole výsledků
    return $posts;
};

/* 3. Nastavit globální post data pro každý objekt v loopu */
add_filter( 'bricks/query/loop_object', 'typesense_bricks_setup_post_data', 10, 3);
function typesense_bricks_setup_post_data( $loop_object, $loop_key, $query_obj ) {
    if ( $query_obj->object_type !== 'typesense_search_query' ) {
        return $loop_object;
    }

    global $post;
    $post = get_post( $loop_object );
    setup_postdata( $post );

    return $loop_object;
};
