<?php

/**
 * Classe WooCommerceModel
 * Gestisce la comunicazione con le API REST di diverse istanze di WooCommerce.
 */
class WooCommerceModel {
    
    private $wc_url;
    private $consumer_key;
    private $consumer_secret;
    
    /**
     * Il costruttore riceve un identificatore e carica le credenziali corrette
     * dalla configurazione centrale in config.php.
     * @param string $instance_identifier L'identificatore dell'istanza (es. 'mdl_formazioneoss')
     */
    public function __construct($instance_identifier) {
        // Carica la configurazione delle costanti
        $config = new costanti();
        
        // Controlla se l'istanza richiesta esiste nella configurazione
        if (isset($config::WOOCOMMERCE_INSTANCES[$instance_identifier])) {
            $instance_config = $config::WOOCOMMERCE_INSTANCES[$instance_identifier];
            
            // Imposta le proprietà della classe con i dati corretti
            $this->wc_url = $instance_config['url'] . '/wp-json/wc/v3/orders';
            $this->consumer_key = $instance_config['key'];
            $this->consumer_secret = $instance_config['secret'];
        } else {
            // Se la configurazione non esiste, lancia un'eccezione per bloccare il processo
            throw new Exception("Configurazione WooCommerce non trovata per l'istanza: " . $instance_identifier);
        }
    }
    
    /**
     * Recupera i dettagli di un singolo ordine. La logica interna non cambia.
     * @param int $order_id L'ID dell'ordine di WooCommerce.
     * @return array|null
     */
    public function getOrderById($order_id) {
        $url = $this->wc_url . '/' . $order_id;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->consumer_key . ":" . $this->consumer_secret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $orderData = json_decode($response, true);
            
            // --- INIZIO MODIFICA CON REGEX ---
            // Usiamo una regular expression per rimuovere QUALSIASI carattere di spaziatura
            // (spazi, tabulazioni, a capo, ecc.) da qualsiasi punto della stringa.
            if (isset($orderData['meta_data']) && is_array($orderData['meta_data'])) {
                foreach ($orderData['meta_data'] as $index => $meta_item) {
                    // Controlla le chiavi più comuni per il Codice Fiscale
                    if (isset($meta_item['key']) && in_array($meta_item['key'], ['_billing_cf', 'billing_cf', 'CF', 'codice_fiscale'])) {
                        if (isset($meta_item['value']) && is_string($meta_item['value'])) {
                            // La regex '/\s+/' trova ogni occorrenza di uno o più caratteri di spaziatura
                            // e li sostituisce con una stringa vuota ''.
                            $orderData['meta_data'][$index]['value'] = preg_replace('/\s+/', '', $meta_item['value']);
                        }
                        // Esci dal ciclo una volta trovato il campo per ottimizzare
                        break;
                    }
                }
            }
            // --- FINE MODIFICA ---
            
            return $orderData;
            
        } else {
            return null;
        }
    }
    
    /**
     * Wrapper: trasforma il dump grezzo di WooCommerce in un array sintetico
     * focalizzato sui dati fiscali, articoli e costi per il log e le verifiche.
     * * @param array $orderData L'array restituito da getOrderById()
     * @return array Sintesi dei dati vitali
     */
    public function getSyntheticOrder(array $orderData) {
        $sintesi = [
            'INFO_BASE' => [
                'ID'     => $orderData['id'] ?? 'N/D',
                'STATO'  => $orderData['status'] ?? 'N/D',
                'TOTALE' => $orderData['total'] ?? '0.00',
                'METODO' => $orderData['payment_method_title'] ?? 'N/D',
            ],
            'BILLING_FULL' => $orderData['billing'] ?? [],
            'META_FISCALI' => [],
            'ARTICOLI'     => [],
            'BOLLI_FEES'   => []
        ];
        
        // Estrazione selettiva dei meta-dati (Fiscali e Bancari)
        if (isset($orderData['meta_data']) && is_array($orderData['meta_data'])) {
            // Cerchiamo solo le chiavi che contengono queste parole
            $filtro = ['billing', 'cf', 'piva', 'sdi', 'pec', 'bacs'];
            foreach ($orderData['meta_data'] as $meta) {
                foreach ($filtro as $word) {
                    if (isset($meta['key']) && strpos(strtolower($meta['key']), $word) !== false) {
                        $sintesi['META_FISCALI'][$meta['key']] = $meta['value'];
                    }
                }
            }
        }
        
        // Sintesi Line Items (Prodotti)
        if (isset($orderData['line_items']) && is_array($orderData['line_items'])) {
            foreach ($orderData['line_items'] as $item) {
                $sintesi['ARTICOLI'][] = [
                    'SKU'    => $item['sku'] ?? 'MANCANTE',
                    'NOME'   => $item['name'] ?? 'N/D',
                    'QTY'    => $item['quantity'] ?? 0,
                    'NETTO'  => $item['total'] ?? '0.00'
                ];
            }
        }
        
        // Sintesi Fee Lines (Bollo, ecc.)
        if (!empty($orderData['fee_lines']) && is_array($orderData['fee_lines'])) {
            foreach ($orderData['fee_lines'] as $fee) {
                $sintesi['BOLLI_FEES'][] = [
                    'NOME'   => $fee['name'] ?? 'N/D',
                    'IMPORTO'=> $fee['total'] ?? '0.00'
                ];
            }
        }
        
        return $sintesi;
    }
}
?>