<?php
/**
 * Indexer pro WooCommerce data
 *
 * @package Typesense_Search
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Třída Typesense_Search_Indexer
 */
class Typesense_Search_Indexer {
    
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
    }
    
    /**
     * Indexovat všechny produkty
     *
     * @param int $batch_size Počet produktů na dávku
     * @return array|WP_Error
     */
    public function index_all_products(int $batch_size = 50) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_active', __('WooCommerce není aktivní.', 'typesense-search'));
        }
        
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'paged' => 1,
        );
        
        $total_indexed = 0;
        $errors = array();
        $collection_name = $this->client->get_collection_name('products');
        
        do {
            $query = new WP_Query($args);
            $products = array();
            
            if ($query->have_posts()) {
                while ($query->have_posts()) {
                    $query->the_post();
                    $product = wc_get_product(get_the_ID());
                    
                    if (!$product) {
                        continue;
                    }
                    
                    $document = $this->prepare_product_document($product);
                    if (!is_wp_error($document)) {
                        $products[] = $document;
                    } else {
                        $errors[] = $document->get_error_message();
                    }
                }
                
                if (!empty($products)) {
                    $result = $this->client->index_documents_batch($collection_name, $products);
                    if (is_wp_error($result)) {
                        $errors[] = $result->get_error_message();
                    } else {
                        $total_indexed += count($products);
                    }
                }
            }
            
            wp_reset_postdata();
            $args['paged']++;
            
        } while ($query->have_posts());
        
        return array(
            'success' => true,
            'total_indexed' => $total_indexed,
            'errors' => $errors,
            'message' => sprintf(__('Indexováno %d produktů.', 'typesense-search'), $total_indexed),
        );
    }
    
    /**
     * Připravit dokument produktu pro Typesense
     *
     * @param WC_Product $product WooCommerce produkt
     * @return array|WP_Error
     */
    private function prepare_product_document($product) {
        if (!$product) {
            return new WP_Error('invalid_product', __('Neplatný produkt.', 'typesense-search'));
        }
        
        // Kategorie
        $categories = array();
        $category_ids = array();
        $product_categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'all'));
        foreach ($product_categories as $category) {
            $categories[] = $category->name;
            $category_ids[] = (int) $category->term_id;
        }
        
        // Značky
        $brands = array();
        $brand_ids = array();
        $product_brands = wp_get_post_terms($product->get_id(), 'product_brand', array('fields' => 'all'));
        foreach ($product_brands as $brand) {
            $brands[] = $brand->name;
            $brand_ids[] = (int) $brand->term_id;
        }
        
        // Tagy
        $tags = array();
        $product_tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
        if (!is_wp_error($product_tags)) {
            $tags = $product_tags;
        }
        
        // Obrázek
        $image_url = '';
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
        }
        
        // Cena
        $price = $product->get_price();
        $regular_price = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        
        // Pokud je produkt ve slevě, ale sale_price není nastaveno, použít price jako sale_price
        if ($product->is_on_sale() && empty($sale_price) && $price && $regular_price) {
            $sale_price = $price;
        }
        
        // Převést na float a vyčistit prázdné hodnoty
        $price_float = $price ? (float) $price : null;
        $regular_price_float = $regular_price ? (float) $regular_price : null;
        $sale_price_float = $sale_price ? (float) $sale_price : null;
        
        // Zjistit, zda je produkt ve slevě
        $is_on_sale = $product->is_on_sale();
        
        // Nastavit boost hodnotu pro produkty se slevou (načíst z nastavení)
        $boost_sale = get_option('typesense_search_boost_sale', 1.5);
        $sale_boost = $is_on_sale ? (float) $boost_sale : 1.0;
        
        // Výrobce - použít první značku nebo custom meta
        $manufacturer = '';
        if (!empty($brands)) {
            $manufacturer = $brands[0];
        } else {
            // Zkusit custom meta pole pro výrobce
            $manufacturer = $product->get_meta('_manufacturer');
            if (empty($manufacturer)) {
                $manufacturer = $product->get_meta('manufacturer');
            }
        }
        
        // Sklad - množství na skladě
        $stock_quantity = null;
        if ($product->managing_stock()) {
            $stock_quantity = $product->get_stock_quantity();
        }
        
        $document = array(
            'id' => (string) $product->get_id(),
            'name' => $product->get_name(),
            'description' => $product->get_description() ?: '',
            'short_description' => $product->get_short_description() ?: '',
            'permalink' => $product->get_permalink(),
            'image' => $image_url ?: '',
            'price' => $price_float,
            'regular_price' => $regular_price_float,
            'sale_price' => $sale_price_float,
            'sku' => $product->get_sku() ?: '',
            'stock_status' => $product->get_stock_status(),
            'stock_quantity' => $stock_quantity !== null ? (int) $stock_quantity : null,
            'categories' => $categories,
            'category_ids' => $category_ids,
            'brands' => $brands,
            'brand_ids' => $brand_ids,
            'tags' => $tags,
            'status' => $product->get_status(),
            'is_on_sale' => $is_on_sale,
            'sale_boost' => $sale_boost,
            'manufacturer' => $manufacturer ?: '',
        );
        
        return $document;
    }
    
    /**
     * Indexovat všechny kategorie
     *
     * @return array|WP_Error
     */
    public function index_all_categories() {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_active', __('WooCommerce není aktivní.', 'typesense-search'));
        }
        
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($categories)) {
            return $categories;
        }
        
        $documents = array();
        foreach ($categories as $category) {
            $document = $this->prepare_category_document($category);
            if (!is_wp_error($document)) {
                $documents[] = $document;
            }
        }
        
        if (empty($documents)) {
            return array(
                'success' => true,
                'total_indexed' => 0,
                'message' => __('Žádné kategorie k indexování.', 'typesense-search'),
            );
        }
        
        $collection_name = $this->client->get_collection_name('categories');
        $result = $this->client->index_documents_batch($collection_name, $documents);
        if (is_wp_error($result)) {
            return $result;
        }
        
        return array(
            'success' => true,
            'total_indexed' => count($documents),
            'message' => sprintf(__('Indexováno %d kategorií.', 'typesense-search'), count($documents)),
        );
    }
    
    /**
     * Připravit dokument kategorie pro Typesense
     *
     * @param WP_Term $category Kategorie
     * @return array|WP_Error
     */
    private function prepare_category_document($category) {
        if (!$category || is_wp_error($category)) {
            return new WP_Error('invalid_category', __('Neplatná kategorie.', 'typesense-search'));
        }
        
        // Obrázek kategorie
        $image_url = '';
        $image_id = get_term_meta($category->term_id, 'thumbnail_id', true);
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
        }
        
        $document = array(
            'id' => (string) $category->term_id,
            'name' => $category->name,
            'description' => $category->description ?: '',
            'permalink' => get_term_link($category),
            'image' => $image_url ?: '',
            'parent_id' => $category->parent ? (int) $category->parent : null,
            'count' => (int) $category->count,
        );
        
        return $document;
    }
    
    /**
     * Indexovat všechny značky
     *
     * @return array|WP_Error
     */
    public function index_all_brands() {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_active', __('WooCommerce není aktivní.', 'typesense-search'));
        }
        
        $brands = get_terms(array(
            'taxonomy' => 'product_brand',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($brands)) {
            return $brands;
        }
        
        $documents = array();
        foreach ($brands as $brand) {
            $document = $this->prepare_brand_document($brand);
            if (!is_wp_error($document)) {
                $documents[] = $document;
            }
        }
        
        if (empty($documents)) {
            return array(
                'success' => true,
                'total_indexed' => 0,
                'message' => __('Žádné značky k indexování.', 'typesense-search'),
            );
        }
        
        $collection_name = $this->client->get_collection_name('brands');
        $result = $this->client->index_documents_batch($collection_name, $documents);
        if (is_wp_error($result)) {
            return $result;
        }
        
        return array(
            'success' => true,
            'total_indexed' => count($documents),
            'message' => sprintf(__('Indexováno %d značek.', 'typesense-search'), count($documents)),
        );
    }
    
    /**
     * Připravit dokument značky pro Typesense
     *
     * @param WP_Term $brand Značka
     * @return array|WP_Error
     */
    private function prepare_brand_document($brand) {
        if (!$brand || is_wp_error($brand)) {
            return new WP_Error('invalid_brand', __('Neplatná značka.', 'typesense-search'));
        }
        
        // Obrázek značky
        $image_url = '';
        $image_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'medium');
        }
        
        $document = array(
            'id' => (string) $brand->term_id,
            'name' => $brand->name,
            'description' => $brand->description ?: '',
            'permalink' => get_term_link($brand),
            'image' => $image_url ?: '',
            'count' => (int) $brand->count,
        );
        
        return $document;
    }
    
    /**
     * Indexovat jeden produkt
     *
     * @param int $product_id ID produktu
     * @return array|WP_Error
     */
    public function index_product(int $product_id) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_not_active', __('WooCommerce není aktivní.', 'typesense-search'));
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('product_not_found', __('Produkt nebyl nalezen.', 'typesense-search'));
        }
        
        $document = $this->prepare_product_document($product);
        if (is_wp_error($document)) {
            return $document;
        }
        
        $collection_name = $this->client->get_collection_name('products');
        return $this->client->index_document($collection_name, $document);
    }
    
    /**
     * Indexovat jednu kategorii
     *
     * @param int $category_id ID kategorie
     * @return array|WP_Error
     */
    public function index_category(int $category_id) {
        $category = get_term($category_id, 'product_cat');
        if (!$category || is_wp_error($category)) {
            return new WP_Error('category_not_found', __('Kategorie nebyla nalezena.', 'typesense-search'));
        }
        
        $document = $this->prepare_category_document($category);
        if (is_wp_error($document)) {
            return $document;
        }
        
        $collection_name = $this->client->get_collection_name('categories');
        return $this->client->index_document($collection_name, $document);
    }
    
    /**
     * Indexovat jednu značku
     *
     * @param int $brand_id ID značky
     * @return array|WP_Error
     */
    public function index_brand(int $brand_id) {
        $brand = get_term($brand_id, 'product_brand');
        if (!$brand || is_wp_error($brand)) {
            return new WP_Error('brand_not_found', __('Značka nebyla nalezena.', 'typesense-search'));
        }
        
        $document = $this->prepare_brand_document($brand);
        if (is_wp_error($document)) {
            return $document;
        }
        
        $collection_name = $this->client->get_collection_name('brands');
        return $this->client->index_document($collection_name, $document);
    }
    
    /**
     * Smazat dokument z kolekce
     *
     * @param string $collection_type Typ kolekce (products, categories, brands)
     * @param string $document_id ID dokumentu
     * @return array|WP_Error
     */
    public function delete_document(string $collection_type, string $document_id) {
        $collection_name = $this->client->get_collection_name($collection_type);
        // Zde musíme zavolat metodu klienta pro smazání dokumentu, kterou ale klient zatím nemá.
        // Musíme ji přidat do klienta nebo použít make_request přímo.
        // Pro čistotu přidáme metodu do klienta později, ale zde použijeme make_request přes klienta.
        
        return $this->client->make_request('DELETE', '/collections/' . $collection_name . '/documents/' . $document_id);
    }
    
    /**
     * Smazat produkt
     *
     * @param int $product_id ID produktu
     * @return array|WP_Error
     */
    public function delete_product(int $product_id) {
        return $this->delete_document('products', (string)$product_id);
    }
    
    /**
     * Smazat kategorii
     *
     * @param int $category_id ID kategorie
     * @return array|WP_Error
     */
    public function delete_category(int $category_id) {
        return $this->delete_document('categories', (string)$category_id);
    }
    
    /**
     * Smazat značku
     *
     * @param int $brand_id ID značky
     * @return array|WP_Error
     */
    public function delete_brand(int $brand_id) {
        return $this->delete_document('brands', (string)$brand_id);
    }
}
