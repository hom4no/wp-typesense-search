<?php
/**
 * Typesense Client
 *
 * @package Typesense_Search
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Třída pro komunikaci s Typesense API
 */
class Typesense_Search_Client {
    
    /**
     * Hostitel Typesense serveru
     *
     * @var string
     */
    private $host;
    
    /**
     * Port Typesense serveru
     *
     * @var string
     */
    private $port;
    
    /**
     * Protokol (http/https)
     *
     * @var string
     */
    private $protocol;
    
    /**
     * API klíč
     *
     * @var string
     */
    private $api_key;

    /**
     * Prefix kolekcí
     *
     * @var string
     */
    private $collection_prefix;
    
    /**
     * Základní URL pro API požadavky
     *
     * @var string
     */
    private $base_url;
    
    /**
     * Konstruktor
     */
    public function __construct() {
        $this->host = get_option('typesense_search_host', 'localhost');
        $this->port = get_option('typesense_search_port', '8108');
        $this->protocol = get_option('typesense_search_protocol', 'http');
        $this->api_key = get_option('typesense_search_api_key', '');
        $this->collection_prefix = get_option('typesense_search_collection_prefix', '');
        
        $this->base_url = $this->protocol . '://' . $this->host . ':' . $this->port;
    }

    /**
     * Získá název kolekce včetně prefixu
     *
     * @param string $base_name Základní název kolekce
     * @return string
     */
    public function get_collection_name(string $base_name): string {
        if (empty($this->collection_prefix)) {
            return $base_name;
        }
        return $this->collection_prefix . $base_name;
    }
    
    /**
     * Test připojení k Typesense
     *
     * @return bool|WP_Error
     */
    public function test_connection() {
        $response = $this->make_request('GET', '/health');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['ok']) && $body['ok'] == true) {
            return true;
        }
        
        return new WP_Error('connection_failed', __('Nepodařilo se připojit k Typesense serveru.', 'typesense-search'));
    }

    /**
     * Načte seznam všech kolekcí ze serveru
     *
     * @return array|WP_Error
     */
    public function retrieve_collections() {
        $response = $this->make_request('GET', '/collections');

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code === 200) {
            return json_decode($body, true);
        }

        return new WP_Error(
            'retrieve_collections_failed',
            sprintf(
                __('Nepodařilo se načíst kolekce. Status code: %d', 'typesense-search'),
                $status_code
            )
        );
    }
    
    /**
     * Vytvořit kolekci produktů
     *
     * @return array|WP_Error
     */
    public function create_products_collection() {
        $schema = array(
            'name' => $this->get_collection_name('products'),
            'fields' => array(
                array('name' => 'id', 'type' => 'string'),
                array('name' => 'name', 'type' => 'string', 'sort' => true),
                array('name' => 'description', 'type' => 'string', 'optional' => true),
                array('name' => 'short_description', 'type' => 'string', 'optional' => true),
                array('name' => 'permalink', 'type' => 'string'),
                array('name' => 'image', 'type' => 'string', 'optional' => true),
                array('name' => 'price', 'type' => 'float', 'optional' => true, 'sort' => true),
                array('name' => 'regular_price', 'type' => 'float', 'optional' => true),
                array('name' => 'sale_price', 'type' => 'float', 'optional' => true),
                array('name' => 'sku', 'type' => 'string', 'optional' => true),
                array('name' => 'stock_status', 'type' => 'string', 'facet' => true, 'sort' => true),
                array('name' => 'categories', 'type' => 'string[]', 'facet' => true),
                array('name' => 'category_ids', 'type' => 'int32[]', 'facet' => true),
                array('name' => 'brands', 'type' => 'string[]', 'facet' => true),
                array('name' => 'brand_ids', 'type' => 'int32[]', 'facet' => true),
                array('name' => 'tags', 'type' => 'string[]', 'facet' => true),
                array('name' => 'status', 'type' => 'string', 'facet' => true),
                array('name' => 'is_on_sale', 'type' => 'bool', 'facet' => true, 'sort' => true),
                array('name' => 'sale_boost', 'type' => 'float', 'optional' => true, 'sort' => true),
                array('name' => 'manufacturer', 'type' => 'string', 'optional' => true),
                array('name' => 'stock_quantity', 'type' => 'int32', 'optional' => true),
            ),
            'default_sorting_field' => 'name',
        );
        
        return $this->create_collection($schema);
    }
    
    /**
     * Vytvořit kolekci pro kategorie
     *
     * @return array|WP_Error
     */
    public function create_categories_collection() {
        $schema = array(
            'name' => $this->get_collection_name('categories'),
            'fields' => array(
                array('name' => 'id', 'type' => 'string'),
                array('name' => 'name', 'type' => 'string', 'sort' => true),
                array('name' => 'description', 'type' => 'string', 'optional' => true),
                array('name' => 'permalink', 'type' => 'string'),
                array('name' => 'image', 'type' => 'string', 'optional' => true),
                array('name' => 'parent_id', 'type' => 'int32', 'optional' => true),
                array('name' => 'count', 'type' => 'int32', 'optional' => true),
            ),
            'default_sorting_field' => 'name',
        );
        
        return $this->create_collection($schema);
    }
    
    /**
     * Vytvořit kolekci pro značky
     *
     * @return array|WP_Error
     */
    public function create_brands_collection() {
        $schema = array(
            'name' => $this->get_collection_name('brands'),
            'fields' => array(
                array('name' => 'id', 'type' => 'string'),
                array('name' => 'name', 'type' => 'string', 'sort' => true),
                array('name' => 'description', 'type' => 'string', 'optional' => true),
                array('name' => 'permalink', 'type' => 'string'),
                array('name' => 'image', 'type' => 'string', 'optional' => true),
                array('name' => 'count', 'type' => 'int32', 'optional' => true),
            ),
            'default_sorting_field' => 'name',
        );
        
        return $this->create_collection($schema);
    }
    
    /**
     * Vytvořit kolekci
     *
     * @param array $schema Schéma kolekce
     * @return array|WP_Error
     */
    public function create_collection(array $schema) {
        $response = $this->make_request('POST', '/collections', $schema);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 201 || $status_code === 200) {
            return json_decode($body, true);
        }
        
        return new WP_Error(
            'create_collection_failed',
            sprintf(
                __('Nepodařilo se vytvořit kolekci. Status code: %d, Response: %s', 'typesense-search'),
                $status_code,
                $body
            )
        );
    }
    
    /**
     * Smazat kolekci
     *
     * @param string $collection_name Název kolekce
     * @return array|WP_Error
     */
    public function delete_collection(string $collection_name) {
        $response = $this->make_request('DELETE', '/collections/' . $collection_name);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            return json_decode($body, true);
        }
        
        if ($status_code === 404) {
            // Kolekce neexistuje, to je v pořádku
            return array('status' => 'deleted');
        }
        
        return new WP_Error(
            'delete_collection_failed',
            sprintf(
                __('Nepodařilo se smazat kolekci. Status code: %d, Response: %s', 'typesense-search'),
                $status_code,
                $body
            )
        );
    }
    
    /**
     * Získat informace o kolekci
     *
     * @param string $collection_name Název kolekce
     * @return array|WP_Error
     */
    public function get_collection(string $collection_name) {
        $response = $this->make_request('GET', '/collections/' . $collection_name);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            return json_decode($body, true);
        }
        
        return new WP_Error(
            'get_collection_failed',
            sprintf(
                __('Nepodařilo se získat kolekci. Status code: %d, Response: %s', 'typesense-search'),
                $status_code,
                $body
            )
        );
    }
    
    /**
     * Vytvořit nebo aktualizovat Preset (uložené nastavení vyhledávání)
     *
     * @param string $preset_name Název presetu
     * @param array $value Konfigurace presetu
     * @return array|WP_Error
     */
    public function upsert_preset(string $preset_name, array $value) {
        // Preset jméno může obsahovat prefix
        $preset_id = $this->get_collection_name($preset_name);
        
        $data = array(
            'value' => $value
        );
        
        $response = $this->make_request('PUT', '/presets/' . $preset_id, $data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200 || $status_code === 201) {
            return json_decode($body, true);
        }
        
        return new WP_Error(
            'upsert_preset_failed',
            sprintf(
                __('Nepodařilo se uložit preset. Status code: %d, Response: %s', 'typesense-search'),
                $status_code,
                $body
            )
        );
    }

    /**
     * Získat Preset
     * 
     * @param string $preset_name Název presetu
     * @return array|WP_Error
     */
    public function retrieve_preset(string $preset_name) {
        $preset_id = $this->get_collection_name($preset_name);
        $response = $this->make_request('GET', '/presets/' . $preset_id);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            return json_decode($body, true);
        }
        
        return new WP_Error(
            'retrieve_preset_failed',
            sprintf(
                __('Nepodařilo se načíst preset. Status code: %d', 'typesense-search'),
                $status_code
            )
        );
    }

    /**
     * Indexovat dokument (vytvořit nebo aktualizovat)
     *
     * @param string $collection_name Název kolekce
     * @param array $document Dokument
     * @return array|WP_Error
     */
    public function index_document(string $collection_name, array $document) {
        $response = $this->make_request('POST', '/collections/' . $collection_name . '/documents?action=upsert', $document);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 201 || $status_code === 200) {
            return json_decode($body, true);
        }
        
        return new WP_Error(
            'index_document_failed',
            sprintf(
                __('Nepodařilo se indexovat dokument. Status code: %d, Response: %s', 'typesense-search'),
                $status_code,
                $body
            )
        );
    }
    
    /**
     * Indexovat více dokumentů najednou (batch)
     *
     * @param string $collection_name Název kolekce
     * @param array $documents Pole dokumentů
     * @return array|WP_Error
     */
    public function index_documents_batch(string $collection_name, array $documents) {
        $json_lines = '';
        foreach ($documents as $document) {
            $json_lines .= json_encode($document) . "\n";
        }
        
        $response = $this->make_request('POST', '/collections/' . $collection_name . '/documents/import?action=upsert', $json_lines, false);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            // Typesense vrací JSON pro každý řádek. Zkontrolujeme, zda tam nejsou chyby.
            // Příklad odpovědi: {"success": true}\n{"success": false, "error": "..."}
            
            $lines = explode("\n", trim($body));
            $errors = array();
            $success_count = 0;
            
            foreach ($lines as $line) {
                $result = json_decode($line, true);
                if (isset($result['success']) && $result['success'] === false) {
                    $errors[] = $result['error'] ?? 'Unknown error';
                } else {
                    $success_count++;
                }
            }
            
            if (!empty($errors)) {
                // Pokud máme chyby, vrátíme je.
                // Limitujeme počet chyb na 5 pro přehlednost
                $error_msg = sprintf(
                    __('Nahráno %d dokumentů, ale %d selhalo. První chyba: %s', 'typesense-search'),
                    $success_count,
                    count($errors),
                    $errors[0]
                );
                return new WP_Error('import_partial_error', $error_msg);
            }
            
            return true;
        }
        
        return new WP_Error(
            'index_batch_failed',
            sprintf(
                __('Nepodařilo se indexovat dokumenty. Status code: %d, Response: %s', 'typesense-search'),
                $status_code,
                $body
            )
        );
    }
    
    /**
     * Vyhledávání v produktech
     */
    public function search_products(string $query, array $params = array()) {
        return $this->search($this->get_collection_name('products'), $query, $params);
    }
    
    /**
     * Vyhledávání v kategoriích
     */
    public function search_categories(string $query, array $params = array()) {
        return $this->search($this->get_collection_name('categories'), $query, $params);
    }
    
    /**
     * Vyhledávání ve značkách
     */
    public function search_brands(string $query, array $params = array()) {
        return $this->search($this->get_collection_name('brands'), $query, $params);
    }
    
    /**
     * Obecné vyhledávání
     *
     * @param string $collection_name Název kolekce
     * @param string $query Vyhledávací dotaz
     * @param array $params Další parametry vyhledávání
     * @return array|WP_Error
     */
    public function search(string $collection_name, string $query, array $params = array()) {
        $default_params = array(
            'q' => $query,
            'per_page' => 10,
            'page' => 1,
            'prioritize_exact_match' => true,
            'prioritize_token_position' => true,
            'typo_tolerance' => 'default',
        );
        
        // Pokud není použit preset, nastavíme výchozí query_by.
        // Pokud je použit preset, necháme query_by na presetu (jinak by ho toto přepsalo).
        if (!isset($params['preset'])) {
            $default_params['query_by'] = 'name,description';
        }
        
        $search_params = array_merge($default_params, $params);
        
        $response = wp_remote_get(
            $this->base_url . '/collections/' . $collection_name . '/documents/search?' . http_build_query($search_params),
            array(
                'timeout' => 30,
                'headers' => array(
                    'X-TYPESENSE-API-KEY' => $this->api_key,
                ),
            )
        );
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code === 200) {
            return json_decode($body, true);
        }
        
        return new WP_Error(
            'search_failed',
            sprintf(
                __('Nepodařilo se vyhledat. Status code: %d, Response: %s', 'typesense-search'),
                $status_code,
                $body
            )
        );
    }
    
    /**
     * Provést HTTP požadavek
     *
     * @param string $method Metoda (GET, POST, DELETE)
     * @param string $endpoint Endpoint API (např. /collections)
     * @param mixed $data Data k odeslání (pole nebo string)
     * @param bool $json_encode Zda data zakódovat do JSONu (pokud je to pole)
     * @return array|WP_Error
     */
    public function make_request(string $method, string $endpoint, $data = null, bool $json_encode = true) {
        $url = $this->base_url . $endpoint;
        
        $args = array(
            'method' => $method,
            'timeout' => 30,
            'headers' => array(
                'X-TYPESENSE-API-KEY' => $this->api_key,
                'Content-Type' => 'application/json',
            ),
        );
        
        if ($data !== null) {
            if ($json_encode && is_array($data)) {
                $args['body'] = json_encode($data);
            } else {
                $args['body'] = $data;
            }
        }
        
        return wp_remote_request($url, $args);
    }
}
