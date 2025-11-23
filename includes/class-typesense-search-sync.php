<?php
/**
 * Automatická synchronizace dat při změnách
 *
 * @package Typesense_Search
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Třída Typesense_Search_Sync
 */
class Typesense_Search_Sync {

    /**
     * Indexer pro práci s daty
     *
     * @var Typesense_Search_Indexer
     */
    private $indexer;

    /**
     * Konstruktor
     *
     * @param Typesense_Search_Indexer $indexer Indexer
     */
    public function __construct(Typesense_Search_Indexer $indexer) {
        $this->indexer = $indexer;

        // Produkty - uložení a smazání
        add_action('save_post_product', array($this, 'on_product_save'), 10, 3);
        add_action('woocommerce_product_quick_edit_save', array($this, 'on_product_save_quick_edit'), 10, 1);
        add_action('before_delete_post', array($this, 'on_product_delete'), 10, 1);
        add_action('wp_trash_post', array($this, 'on_product_delete'), 10, 1);
        add_action('untrash_post', array($this, 'on_product_restore'), 10, 1);

        // Kategorie - vytvoření, editace, smazání
        add_action('create_product_cat', array($this, 'on_category_change'), 10, 1);
        add_action('edited_product_cat', array($this, 'on_category_change'), 10, 1);
        add_action('delete_product_cat', array($this, 'on_category_delete'), 10, 1);

        // Značky - vytvoření, editace, smazání
        add_action('create_product_brand', array($this, 'on_brand_change'), 10, 1);
        add_action('edited_product_brand', array($this, 'on_brand_change'), 10, 1);
        add_action('delete_product_brand', array($this, 'on_brand_delete'), 10, 1);
    }

    /**
     * Při uložení produktu
     *
     * @param int     $post_id ID příspěvku
     * @param WP_Post $post    Objekt příspěvku
     * @param bool    $update  Zda jde o update
     */
    public function on_product_save($post_id, $post, $update) {
        // Ignorovat revize a autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        // Ignorovat pokud to není produkt
        if ($post->post_type !== 'product') {
            return;
        }

        // Ignorovat pokud produkt není publikovaný (pokud byl a už není, řeší se to, 
        // ale indexer by měl umět handled "ne-publikované" tím že je buď smaže nebo ignoruje.
        // Zde to pošleme indexeru a ten rozhodne (v prepare_product_document).
        // Ale momentálně indexer načítá vše. Pokud produkt změní status na "draft", měli bychom ho smazat z indexu.
        
        if ($post->post_status !== 'publish') {
            $this->indexer->delete_document('products', (string)$post_id);
            return;
        }

        // Indexovat produkt
        $this->indexer->index_product((int)$post_id);
    }

    /**
     * Při uložení produktu v Quick Edit (kde nejsou všechny argumenty jako v save_post)
     * 
     * @param WC_Product $product
     */
    public function on_product_save_quick_edit($product) {
        if (!is_a($product, 'WC_Product')) {
             $post_id = $product; // Někdy to vrací ID
        } else {
             $post_id = $product->get_id();
        }
        
        if ($post_id) {
             $this->indexer->index_product((int)$post_id);
        }
    }

    /**
     * Při smazání produktu (nebo přesunu do koše)
     *
     * @param int $post_id ID příspěvku
     */
    public function on_product_delete($post_id) {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        // Voláme delete metodu indexeru (kterou musíme přidat, pokud chybí)
        // Indexer zatím nemá delete_product, tak použijeme klienta přes indexer nebo přidáme metodu.
        // Nejlepší je přidat metodu do Indexeru pro konzistenci.
        if (method_exists($this->indexer, 'delete_product')) {
            $this->indexer->delete_product((int)$post_id);
        }
    }
    
    /**
     * Při obnovení produktu z koše
     *
     * @param int $post_id ID příspěvku
     */
    public function on_product_restore($post_id) {
        if (get_post_type($post_id) !== 'product') {
            return;
        }
        
        $this->indexer->index_product((int)$post_id);
    }

    /**
     * Při změně kategorie
     *
     * @param int $term_id ID kategorie
     */
    public function on_category_change($term_id) {
        $this->indexer->index_category((int)$term_id);
    }

    /**
     * Při smazání kategorie
     *
     * @param int $term_id ID kategorie
     */
    public function on_category_delete($term_id) {
        if (method_exists($this->indexer, 'delete_category')) {
            $this->indexer->delete_category((int)$term_id);
        }
    }

    /**
     * Při změně značky
     *
     * @param int $term_id ID značky
     */
    public function on_brand_change($term_id) {
        $this->indexer->index_brand((int)$term_id);
    }

    /**
     * Při smazání značky
     *
     * @param int $term_id ID značky
     */
    public function on_brand_delete($term_id) {
        if (method_exists($this->indexer, 'delete_brand')) {
            $this->indexer->delete_brand((int)$term_id);
        }
    }
}

