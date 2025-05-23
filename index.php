<?php
/**
 * Script CRON per recuperare transazioni PayPal, identificare l'istanza WC di origine
 * tramite prefisso invoice_id, recuperare dettagli ordine dal DB WC corretto,
 * trovare utente/corso Moodle nel DB Moodle associato, e accodare i dati.
 * Sostituito operatore nullsafe '?->' per compatibilità PHP < 8.0.
 * Aggiunto logging dettagliato per debug transazioni saltate.
 */

// --- Impostazioni Iniziali e Include ---
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
$phpErrorLogPath = __DIR__ . '/logs/php_errors.log';
ini_set('error_log', $phpErrorLogPath);

// Autoloader Composer
$autoloaderPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloaderPath)) {
    require_once $autoloaderPath;
} else {
    error_log("ERRORE CRITICO: Autoloader Composer non trovato in '$autoloaderPath'");
    exit(1); // Esce se l'autoloader manca
}

// Include necessari
require_once __DIR__ . '/inc/logger_init.php'; // Inizializzazione Logger Monolog
require_once __DIR__ . '/config_db.php';      // Configurazioni DB, API, Modalità App
require_once __DIR__ . '/connect.php';         // Gestore Connessioni DB (DBConnector)
require_once __DIR__ . '/email.php';           // Funzioni invio email
require_once __DIR__ . '/woocommerce_helpers.php'; // Funzioni specifiche WooCommerce/Moodle

use Monolog\Logger; // Assicura che la classe Logger sia importata

// --- INIZIALIZZA LOGGER ---
$logDirectoryPath = __DIR__ . '/logs';
$log = null; // Inizializza a null

// Verifica che la funzione getAppLogger esista prima di chiamarla
if (!function_exists('getAppLogger')) {
    error_log("ERRORE CRITICO: Funzione getAppLogger non definita (probabilmente fallito include logger_init.php).");
    // Potresti voler uscire qui o continuare senza logger applicativo
    // exit(1);
} else {
    // Determina il livello di log basato sulla modalità applicazione
    $logLevel = (defined('APP_MODE') && APP_MODE === 'PRODUCTION') ? Logger::INFO : Logger::DEBUG;
    // Ottieni l'istanza del logger
    $log = getAppLogger('PAYPAL_CRON', 'paypal_cron', $logLevel, $logDirectoryPath);
}

// Usa il logger solo se è stato inizializzato correttamente
if (isset($log)) {
    $log->info('<<<< ==== Script CRON PayPal AVVIATO (index2.php - Multi-Istanza con Debug Avanzato) ==== >>>>');
    $log->warning('Modalità Applicazione: ' . (defined('APP_MODE') ? APP_MODE : 'NON DEFINITA!'));
    $log->debug('PHP Error Log Path: ' . $phpErrorLogPath);
    $log->debug('App Log Directory (Monolog): ' . $logDirectoryPath);
    $log->debug('App Log Level: ' . Logger::getLevelName($logLevel));
} else {
    error_log("ERRORE CRITICO: Logger Monolog non inizializzato correttamente.");
    // Potresti voler uscire se il logging è essenziale
    // exit(1);
}


// --- CLASSE PAYPAL (Modificata per logging avanzato) ---
class PayPal
{
    private $clientId;
    private $clientSecret;
    private $apiBase;
    private $accessToken;
    private $log; // Può essere Logger o null
    private $environment;
    
    // Costruttore accetta Logger o null
    public function __construct(?Monolog\Logger $logger)
    {
        $this->log = $logger;
        if (! defined('PAYPAL_CLIENT_ID') || ! defined('PAYPAL_SECRET') || ! defined('PAYPAL_ENVIRONMENT')) {
            if (isset($this->log)) {
                $this->log->critical("Costanti PayPal non definite!");
            } else {
                error_log("CRITICAL: Costanti PayPal non definite!");
            }
            throw new Exception("Configurazione PayPal mancante.");
        }
        $this->clientId = PAYPAL_CLIENT_ID;
        $this->clientSecret = PAYPAL_SECRET;
        $this->environment = PAYPAL_ENVIRONMENT;
        
        if ($this->environment === 'PRODUCTION') {
            $this->apiBase = "https://api-m.paypal.com";
        } else {
            $this->apiBase = "https://api-m.sandbox.paypal.com";
            if (isset($this->log)) $this->log->warning("PayPal API punta a SANDBOX.");
        }
        
        try {
            $this->accessToken = $this->getAccessToken();
            if (isset($this->log)) $this->log->info("PayPal Token ottenuto.", ['environment' => $this->environment]);
        } catch (Exception $e) {
            if (isset($this->log)) {
                $this->log->critical("Errore getAccessToken", ['exception' => $e->getMessage()]);
            } else {
                error_log("CRITICAL: Errore getAccessToken - " . $e->getMessage());
            }
            throw $e; // Rilancia l'eccezione per fermare lo script se non si ottiene il token
        }
    }
    
    // Metodo per ottenere l'Access Token (invariato rispetto a prima)
    private function getAccessToken()
    {
        if (isset($this->log)) $this->log->debug("Richiesta Access Token PayPal...");
        $url = $this->apiBase . "/v1/oauth2/token";
        $headers = [
            "Accept: application/json",
            "Accept-Language: en_US"
        ];
        $postFields = "grant_type=client_credentials";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ":" . $this->clientSecret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Timeout connessione 10s
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout totale 30s
        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if (isset($this->log)) $this->log->error("cURL Error durante getAccessToken", ['curl_error' => $curlError, 'url' => $url]);
            else error_log("ERROR: cURL Error getAccessToken - " . $curlError);
            throw new Exception("Impossibile ottenere Access Token (cURL error: " . $curlError . ")");
        }
        if ($httpStatus !== 200 && $httpStatus !== 201) {
            if (isset($this->log)) $this->log->error("Errore HTTP durante getAccessToken", ['http_status' => $httpStatus, 'response' => $response, 'url' => $url]);
            else error_log("ERROR: HTTP Error getAccessToken - Status: $httpStatus - Response: " . $response);
            throw new Exception("Autenticazione fallita (HTTP $httpStatus) - Response: " . $response);
        }
        $result = json_decode($response, true);
        if (! isset($result['access_token'])) {
            if (isset($this->log)) $this->log->error("Access Token non trovato nella risposta", ['response' => $response, 'url' => $url]);
            else error_log("ERROR: Access Token non trovato nella risposta PayPal.");
            throw new Exception("Token non trovato nella risposta PayPal.");
        }
        if (isset($this->log)) $this->log->debug("Access Token ottenuto con successo.");
        return $result['access_token'];
    }
    
    // Metodo per recuperare i pagamenti (CON LOGGING DETTAGLIATO AGGIUNTO)
    public function getReceivedPayments($startDate, $endDate)
    {
        // Nota: Il filtro transaction_type=T0006 non è presente qui, recupera tutte le transazioni con stato S
        $url = $this->apiBase . "/v1/reporting/transactions?start_date=" . $startDate . "&end_date=" . $endDate . "&fields=all&page_size=500&transaction_status=S";
        if (isset($this->log)) $this->log->info("Chiamata API PayPal per transazioni", ['url_fragment' => substr($url, 0, strpos($url, '?') ?: strlen($url))]);
        
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->accessToken
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // Timeout connessione
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);       // Timeout totale
        
        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            if (isset($this->log)) $this->log->error("PayPal getReceivedPayments cURL Error", ['curl_error' => $curlError, 'url' => $url]);
            else error_log("ERROR: PayPal getReceivedPayments cURL Error - " . $curlError);
            return []; // Ritorna array vuoto in caso di errore cURL
        }
        if ($httpStatus !== 200) {
            $responsePreview = substr($response, 0, 500) . (strlen($response) > 500 ? '...' : '');
            if (isset($this->log)) $this->log->error("PayPal getReceivedPayments HTTP Error", ['http_status' => $httpStatus, 'response_preview' => $responsePreview, 'url' => $url]);
            else error_log("ERROR: PayPal getReceivedPayments HTTP Error - Status: $httpStatus - Response: " . $responsePreview);
            return []; // Ritorna array vuoto in caso di errore HTTP
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (isset($this->log)) $this->log->error("Errore JSON decode risposta PayPal", ['json_error' => json_last_error_msg(), 'response_preview' => substr($response, 0, 500) . '...']);
            else error_log("ERROR: JSON decode risposta PayPal - " . json_last_error_msg());
            return []; // Ritorna array vuoto in caso di errore JSON
        }
        
        // Controlla se l'API ha restituito un errore strutturato
        if (! isset($data['transaction_details'])) {
            if (isset($data['name']) && isset($data['message'])) {
                if (isset($this->log)) $this->log->error("Errore API PayPal restituito", ['error_name' => $data['name'], 'error_message' => $data['message']]);
                else error_log("ERROR: API PayPal Error - " . $data['name'] . ": " . $data['message']);
            } else {
                // Caso in cui la risposta è 200 OK ma 'transaction_details' è vuoto o mancante (nessuna transazione trovata)
                if (isset($this->log)) $this->log->info("Nessuna transazione trovata nel periodo specificato ('transaction_details' mancante o vuoto nella risposta).");
            }
            return []; // Ritorna array vuoto se non ci sono dettagli transazione
        }
        
        // Controllo specifico per array vuoto di transazioni
        if (empty($data['transaction_details'])) {
            if (isset($this->log)) $this->log->info("Nessuna transazione trovata nel periodo specificato (l'array 'transaction_details' è vuoto).");
            return [];
        }
        
        $processedTransactions = [];
        foreach ($data['transaction_details'] as $transaction) {
            
            // <<< NUOVO BLOCCO DI LOGGING >>>
            if (isset($this->log)) {
                $this->log->debug("--- Inizio Iterazione Loop ---");
                $this->log->debug("Dati Transazione Grezza da PayPal API", ['raw_transaction_data' => $transaction]); // Logga TUTTA la transazione ricevuta
            }
            // <<< FINE NUOVO BLOCCO >>>
            
            
            // Estrazione dati esistente
            $payerInfo = $transaction['payer_info'] ?? [];
            $transactionInfo = $transaction['transaction_info'] ?? [];
            $cartInfo = $transaction['cart_info']['item_details'][0] ?? []; // Primo articolo
            $shippingInfo = $transaction['shipping_info'] ?? [];
            
            
            // <<< AGGIUNGI LOGGING ANCHE QUI per vedere i dati estratti >>>
            if (isset($this->log)) {
                // Logga specificamente l'array transaction_info estratto
                $this->log->debug("Array 'transaction_info' Estratto", ['extracted_transactionInfo' => $transactionInfo]);
                // Logga il valore dell'importo, se esiste
                $amountValueLog = 'NON IMPOSTATO o CHIAVE MANCANTE';
                if (isset($transactionInfo['transaction_amount']['value'])) {
                    $amountValueLog = $transactionInfo['transaction_amount']['value'] . ' (Tipo: ' . gettype($transactionInfo['transaction_amount']['value']) . ')';
                } elseif(isset($transactionInfo['transaction_amount'])) {
                    $amountValueLog = 'CHIAVE "value" MANCANTE in transaction_amount';
                } elseif(!isset($transactionInfo['transaction_amount'])) {
                    $amountValueLog = 'CHIAVE "transaction_amount" MANCANTE in transaction_info';
                }
                $this->log->debug("Valore Importo Estratto Prima del Controllo", ['amount_value_for_check' => $amountValueLog]);
            }
            // <<< FINE LOGGING AGGIUNTIVO >>>
            
            
            // ---- Controllo sull'importo (VERSIONE MENO RESTRITTIVA, come index.php) ----
            // Controlla se 'value' esiste e se è maggiore di 0. Accetta anche stringhe numeriche.
            if (!isset($transactionInfo['transaction_amount']['value']) || !is_numeric($transactionInfo['transaction_amount']['value']) || (float)$transactionInfo['transaction_amount']['value'] <= 0) {
                if (isset($this->log)) {
                    $this->log->debug("Transazione saltata (importo <= 0, non numerico o non presente)", [
                        'tx_id' => $transactionInfo['transaction_id'] ?? 'N/A',
                        'amount_value' => $transactionInfo['transaction_amount']['value'] ?? 'Non impostato'
                    ]);
                }
                continue; // Salta questa transazione
            }
            // ---- Fine controllo importo ----
            
            // Estrazione altri dati
            $itemName = $cartInfo['item_name'] ?? 'N/A';
            $itemCode = $cartInfo['item_code'] ?? 'N/A'; // Codice Articolo
            $itemDescription = $cartInfo['item_description'] ?? 'N/A'; // Descrizione Articolo
            
            $invoiceIdFromPayPal = $transactionInfo['invoice_id'] ?? null; // Può essere null
            $transactionDateStr = $transactionInfo['transaction_initiation_date'] ?? date('c'); // Usa data corrente come fallback
            try {
                $date = new \DateTime($transactionDateStr);
                $formattedDate = $date->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $formattedDate = date('Y-m-d H:i:s'); // Fallback in caso di data non valida
                if (isset($this->log)) $this->log->warning("Formato data transazione non valido", ['date_string' => $transactionDateStr, 'tx_id' => $transactionInfo['transaction_id'] ?? 'N/A']);
            }
            
            $fullPhone = 'N/A';
            if (isset($payerInfo['phone_number']['country_code']) && isset($payerInfo['phone_number']['national_number'])) {
                $fullPhone = '+' . $payerInfo['phone_number']['country_code'] . ' ' . $payerInfo['phone_number']['national_number'];
            }
            
            $payerName = $payerInfo['payer_name']['alternate_full_name'] ?? 'N/A';
            
            
            // Crea l'array del risultato per questa transazione
            $resultItem = [
                'transaction_id' => $transactionInfo['transaction_id'] ?? 'N/A',
                'invoice_id' => $invoiceIdFromPayPal, // Potrebbe essere null
                'transaction_date' => $formattedDate,
                'transaction_code' => $transactionInfo['transaction_event_code'] ?? 'N/A',
                'paying_name' => $payerName,
                'paying_email' => $payerInfo['email_address'] ?? 'N/A',
                'paying_phone' => $fullPhone,
                'paying_nat' => $payerInfo['country_code'] ?? 'N/A',
                'paying_account' => $payerInfo['account_id'] ?? 'N/A',
                'Stato Account PayPal' => isset($payerInfo['payer_status']) ? ($payerInfo['payer_status'] === 'Y' ? 'Verificato' : 'Non verificato') : 'N/A',
                'Stato Indirizzo' => isset($payerInfo['address_status']) ? ($payerInfo['address_status'] === 'Y' ? 'Confermato' : 'Non confermato') : 'N/A',
                'billing_address' => $shippingInfo['address']['line1'] ?? 'N/A',
                'billing_city' => $shippingInfo['address']['city'] ?? 'N/A',
                'billing_prov' => $shippingInfo['address']['state'] ?? 'N/A',
                'billing_postalcode' => $shippingInfo['address']['postal_code'] ?? 'N/A',
                'Indirizzo di Spedizione' => $shippingInfo['name'] ?? 'N/A',
                'amount' => $transactionInfo['transaction_amount']['value'], // Qui sappiamo che è > 0 e numerico
                'fee_amount' => $transactionInfo['fee_amount']['value'] ?? '0.00',
                'Stato Transazione' => $transactionInfo['transaction_status'] ?? 'N/A', // Dovrebbe essere 'S'
                'item_purchased' => $itemName,
                'item_code' => $itemCode, // Aggiunto
                'item_description' => $itemDescription // Aggiunto
            ];
            $processedTransactions[] = $resultItem;
        }
        
        if (isset($this->log)) {
            $count = count($processedTransactions);
            $this->log->info("Recuperate $count transazioni ('S' completate) da PayPal dopo il filtro interno.");
        }
        return $processedTransactions;
    }
} // <-- Fine Classe PayPal


// --- Inizio Script Principale ---
if (isset($log)) $log->info("Inizio esecuzione script principale.");

// Imposta le date di inizio e fine per la ricerca delle transazioni
// Modifica queste date secondo le tue necessità
// $startDate = "2025-04-01T00:00:00Z"; // Esempio: Inizio mese corrente
$startDate = "2025-04-01T00:00:00Z"; // Esempio: Inizio mese corrente
$endDate = "2025-04-30T23:59:59Z";   // Esempio: Fine mese corrente
// $endDate = date("Y-m-d\TH:i:s\Z"); // Data/ora attuale

if (isset($log)) $log->info("Recupero transazioni PayPal", ['start_date' => $startDate, 'end_date' => $endDate]);

// Ottieni connessione DB MoodleApps (per tabella 'results')
$connMdlApps = DBConnector::getMoodleAppsDb();
if (! $connMdlApps) {
    if (isset($log)) $log->critical("Connessione DB 'MoodleApps' non disponibile. Script terminato.");
    else error_log("CRITICAL: Connessione DB 'MoodleApps' non disponibile. Script terminato.");
    exit(1); // Termina se non può connettersi al DB principale
}
if (isset($log)) $log->info("Connessione DB 'MoodleApps' (per tabella 'results' e 'moodle_payments') attiva.");


// Carica mappatura Istanze WC <-> Moodle
if (! defined('WC_INSTANCE_MAPPING') || ! is_array(WC_INSTANCE_MAPPING) || empty(WC_INSTANCE_MAPPING)) {
    if (isset($log)) $log->critical("Mappatura WC_INSTANCE_MAPPING non definita/vuota/non array in config_db.php!");
    else error_log("CRITICAL: Mappatura WC_INSTANCE_MAPPING non definita/vuota/non array!");
    DBConnector::closeAllConnections(); // Chiudi connessioni aperte prima di uscire
    exit(1);
}
$wcInstanceMapping = WC_INSTANCE_MAPPING;
if (isset($log)) $log->info("Caricata mappatura per " . count($wcInstanceMapping) . " istanze WooCommerce.");


// Recupera i pagamenti da PayPal
$receivedPayments = [];
try {
    $paypal = new PayPal($log); // Passa il logger (che potrebbe essere null)
    $receivedPayments = $paypal->getReceivedPayments($startDate, $endDate);
} catch (Exception $e) {
    if (isset($log)) $log->critical("Terminazione script: errore durante inizializzazione PayPal o recupero token.", ['exception_message' => $e->getMessage()]);
    else error_log("CRITICAL: Errore API PayPal o init - " . $e->getMessage());
    DBConnector::closeAllConnections();
    exit(1); // Termina se l'API PayPal fallisce gravemente all'inizio
}


// Gestisci caso senza transazioni valide trovate dalla classe PayPal
if (empty($receivedPayments)) {
    if (isset($log)) $log->info("Nessuna transazione PayPal valida trovata nel periodo o errore API gestito dalla classe PayPal.");
    DBConnector::closeAllConnections();
    exit(0); // Uscita normale se non ci sono transazioni valide da processare
}

// --- Elaborazione delle transazioni recuperate ---
if (isset($log)) $log->info("Inizio elaborazione " . count($receivedPayments) . " transazioni recuperate da PayPal.");
$processedCount = 0;
$wooCommerceCount = 0; // Contatore ordini identificati come WC
$otherPaymentsCount = 0; // Contatore pagamenti non identificati come WC
$skippedCount = 0; // Contatore transazioni saltate per varie ragioni (es. già processate)
$errorCount = 0;   // Contatore errori durante l'elaborazione

// Ciclo principale sulle transazioni ricevute da PayPal
foreach ($receivedPayments as $tx) {
    $transaction_id = $tx['transaction_id'] ?? 'N/A';
    $paypal_invoice_id = $tx['invoice_id'] ?? null; // Può essere null
    
    // Salta subito se manca ID transazione o lo stato non è 'S' (Success)
    if ($transaction_id === 'N/A' || ($tx['Stato Transazione'] ?? '') !== 'S') {
        if (isset($log)) $log->warning("Transazione saltata (ID mancante o stato non 'S')", ['tx_data_preview' => array_slice($tx, 0, 3)]); // Log preview
        $skippedCount++;
        continue;
    }
    
    if (isset($log)) $log->info("Processando PayPal TX ID: $transaction_id", ['invoice_id' => $paypal_invoice_id ?? 'N/D', 'amount' => $tx['amount'] ?? 'N/D']);
    
    // Identifica se è un ordine WooCommerce
    $wooOrderId = null;
    $instanceConfig = null; // Contiene la configurazione dell'istanza WC trovata
    if (!empty($paypal_invoice_id)) {
        foreach ($wcInstanceMapping as $prefix => $config) {
            if (strpos($paypal_invoice_id, $prefix) === 0) {
                $potentialOrderId = substr($paypal_invoice_id, strlen($prefix));
                // Verifica che l'ID ordine estratto sia numerico
                if (ctype_digit($potentialOrderId)) {
                    $wooOrderId = $potentialOrderId;
                    $instanceConfig = $config;
                    $instanceConfig['prefix'] = $prefix; // Aggiungi il prefisso per riferimento
                    if (isset($log)) $log->info("Identificato Ordine WooCommerce", ['tx_id' => $transaction_id, 'invoice_id' => $paypal_invoice_id, 'wc_order_id' => $wooOrderId, 'instance_prefix' => $prefix]);
                    $wooCommerceCount++;
                    break; // Trovato, esci dal loop interno
                } else {
                    if (isset($log)) $log->warning("Invoice ID inizia con prefisso '$prefix' ma l'ID ordine '$potentialOrderId' non è numerico.", ['tx_id' => $transaction_id, 'invoice_id' => $paypal_invoice_id]);
                }
            }
        }
    }
    
    // Se non è stato identificato un ordine WC valido
    if (! $instanceConfig || ! $wooOrderId) {
        if (isset($log)) $log->info("Transazione non identificata come WooCommerce (invoice_id mancante, non corrispondente o parte non numerica).", ['tx_id' => $transaction_id, 'invoice_id' => $paypal_invoice_id ?? 'N/D']);
        $otherPaymentsCount++;
        // Qui gestisci la logica per i pagamenti NON WooCommerce
    }
    
    
    // --- Gestione Logica Specifica ---
    if ($instanceConfig && $wooOrderId) {
        // ** LOGICA ORDINI WOOCOMMERCE **
        if (isset($log)) $log->info("Avvio elaborazione specifica per Ordine WC", ['order_id' => $wooOrderId, 'instance_prefix' => $instanceConfig['prefix']]);
        
        // Verifica esistenza funzioni helper
        if (! function_exists('checkWooOrderAlreadyQueued') || ! function_exists('queueWooOrderForProcessing')) {
            if (isset($log)) $log->critical("Funzioni helper WooCommerce (checkWooOrderAlreadyQueued o queueWooOrderForProcessing) non definite!", ['order_id' => $wooOrderId]);
            else error_log("CRITICAL: Funzioni helper WooCommerce non definite!");
            $errorCount++;
            continue; // Salta questa transazione
        }
        
        try {
            // Controlla se l'ordine WC è già stato accodato (in moodle_payments o nel CSV di test)
            $alreadyQueued = checkWooOrderAlreadyQueued($wooOrderId);
            
            if (!$alreadyQueued) {
                if (isset($log)) $log->info("Ordine WC $wooOrderId NON ancora accodato. Tento accodamento...");
                
                // Funzione che legge da DB WC, trova utente Moodle e accoda (scrive su DB o CSV)
                // Passa l'ID ordine e $instanceConfig
                $resultQueue = queueWooOrderForProcessing($wooOrderId, $instanceConfig);
                
                if ($resultQueue['success']) {
                    if (isset($log)) $log->info("Ordine WC $wooOrderId accodato con successo.", ['moodleUserId' => $resultQueue['moodleUserId'], 'moodleCourseId' => $resultQueue['moodleCourseId']]);
                } else {
                    $errorMsg = $resultQueue['error'] ?? "Errore generico durante l'accodamento dell'ordine WC $wooOrderId";
                    if (isset($log)) $log->error("Fallito accodamento Ordine WC", ['order_id' => $wooOrderId, 'error_message' => $errorMsg]);
                    // Invia notifica admin se fallisce l'accodamento
                    if (function_exists('sendAdminNotification')) {
                        sendAdminNotification("Fallito accodamento Ordine WC $wooOrderId", ['error' => $errorMsg, 'paypal_tx_id' => $transaction_id]);
                    }
                    $errorCount++;
                }
            } else {
                if (isset($log)) $log->info("Ordine WooCommerce $wooOrderId già presente nella coda di processamento (DB o CSV).", ['order_id' => $wooOrderId]);
                $skippedCount++; // Incrementa saltati perché già processato/accodato
            }
        } catch (Exception $e) {
            if (isset($log)) $log->error("Eccezione durante elaborazione Ordine WC", ['order_id' => $wooOrderId, 'exception_message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            else error_log("ERROR Exception Ordine WC $wooOrderId: " . $e->getMessage());
            // Invia notifica admin per eccezione
            if (function_exists('sendAdminNotification')) {
                sendAdminNotification("Eccezione accodamento Ordine WC $wooOrderId", ['exception' => $e->getMessage(), 'paypal_tx_id' => $transaction_id]);
            }
            $errorCount++;
        }
        
    } else {
        // ** LOGICA ALTRI PAGAMENTI PAYPAL -> TABELLA 'results' **
        if (isset($log)) $log->info("Transazione non WooCommerce (o non identificata), processo per tabella 'results'...", ['tx_id' => $transaction_id]);
        
        // Verifica connessione DB MoodleApps (già fatta all'inizio, ma doppia sicurezza)
        if (!$connMdlApps) {
            if (isset($log)) $log->error("Connessione DB MoodleApps non disponibile per inserimento in 'results'.", ['tx_id' => $transaction_id]);
            $errorCount++;
            continue;
        }
        
        try {
            // Controlla se la transazione PayPal è già in 'results'
            $check_sql = "SELECT id FROM results WHERE transaction_id = ? LIMIT 1";
            $stmt_check = $connMdlApps->prepare($check_sql);
            if ($stmt_check === false) {
                if (isset($log)) $log->error("Errore prepare check_sql per 'results'", ['tx_id' => $transaction_id, 'db_error' => $connMdlApps->error]);
                $errorCount++;
                continue;
            }
            $stmt_check->bind_param("s", $transaction_id);
            $stmt_check->execute();
            if ($stmt_check->errno) {
                if (isset($log)) $log->error("Errore execute check_sql per 'results'", ['tx_id' => $transaction_id, 'db_error' => $stmt_check->error, 'errno' => $stmt_check->errno]);
                $stmt_check->close();
                $errorCount++;
                continue;
            }
            $stmt_check->store_result();
            $already_in_results = ($stmt_check->num_rows > 0);
            $stmt_check->close();
            
            if (!$already_in_results) {
                // Se non è già presente, inserisci (solo in PRODUCTION)
                if (defined('APP_MODE') && APP_MODE === 'PRODUCTION') {
                    if (isset($log)) $log->debug("Modalità PRODUCTION: Tentativo inserimento in 'results'", ['tx_id' => $transaction_id]);
                    
                    // *** ADATTA I CAMPI E I TIPI BINDING ALLA TUA TABELLA 'results' ***
                    $insert_sql = "INSERT INTO results (
                         transaction_id, date_transaction, paying_name, paying_email, paying_phone,
                         paying_nat, paying_account, billing_address, billing_city, billing_prov,
                         billing_postalcode, amount, fee_amount, item_purchased,
                         item_code, item_description
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"; // Aggiunti item_code, item_description
                    
                    $insert_stmt = $connMdlApps->prepare($insert_sql);
                    if ($insert_stmt === false) {
                        if (isset($log)) $log->error("Errore prepare insert_sql per 'results'", ['tx_id' => $transaction_id, 'db_error' => $connMdlApps->error]);
                        $errorCount++;
                        continue;
                    }
                    
                    // Assicurati che i tipi (s=string, d=double) corrispondano ai placeholder (?) e ai dati in $tx
                    $insert_stmt->bind_param("sssssssssssddsss",
                        $tx['transaction_id'],
                        $tx['transaction_date'], // Già formattata
                        $tx['paying_name'],
                        $tx['paying_email'],
                        $tx['paying_phone'],
                        $tx['paying_nat'],
                        $tx['paying_account'],
                        $tx['billing_address'],
                        $tx['billing_city'],
                        $tx['billing_prov'],
                        $tx['billing_postalcode'],
                        $tx['amount'], // tipo 'd' (double)
                        $tx['fee_amount'], // tipo 'd' (double)
                        $tx['item_purchased'],
                        $tx['item_code'],        // Aggiunto - tipo 's'
                        $tx['item_description']  // Aggiunto - tipo 's'
                        );
                    
                    if ($insert_stmt->execute()) {
                        if (isset($log)) $log->info("Nuovo pagamento salvato in 'results'", ['tx_id' => $transaction_id]);
                        // Invia email di notifica solo se l'inserimento va a buon fine
                        if (function_exists('sendNotificationEmail')) {
                            try {
                                sendNotificationEmail($tx);
                                if (isset($log)) $log->info("Email di notifica standard inviata per transazione non-WC.", ['tx_id' => $transaction_id]);
                            } catch (Exception $e) {
                                if (isset($log)) $log->error("Errore invio email standard per transazione non-WC", ['tx_id' => $transaction_id, 'error' => $e->getMessage()]);
                            }
                        }
                    } else {
                        if (isset($log)) $log->error("Errore salvataggio in 'results'", ['tx_id' => $transaction_id, 'db_error' => $insert_stmt->error, 'errno' => $insert_stmt->errno]);
                        $errorCount++;
                    }
                    $insert_stmt->close();
                    
                } else {
                    // Modalità TEST: Logga ma non inserire/inviare email
                    if (isset($log)) $log->info("Modalità TEST: Inserimento in 'results' saltato.", ['tx_id' => $transaction_id]);
                    if (isset($log)) $log->info("Modalità TEST: Invio email di notifica standard saltato.", ['tx_id' => $transaction_id]);
                }
            } else {
                if (isset($log)) $log->info("Transazione $transaction_id già presente in 'results'.", ['tx_id' => $transaction_id]);
                $skippedCount++; // Incrementa saltati perché già presente in results
            }
        } catch (Exception $e) {
            if (isset($log)) $log->error("Eccezione durante elaborazione per 'results'", ['tx_id' => $transaction_id, 'exception_message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            else error_log("ERROR Exception 'results' TX $transaction_id: " . $e->getMessage());
            $errorCount++;
        }
    } // Fine logica ORDINI NON WC
    
    $processedCount++;
    if (isset($log)) $log->debug("-------------------- Fine TX $transaction_id --------------------");
    
} // Fine foreach ($receivedPayments as $tx)

    // --- Riepilogo Finale ---
if (isset($log)) {
    $log->info("Elaborazione transazioni completata.", [
        'totali_recuperate' => count($receivedPayments),
        'elaborate_con_logica' => $processedCount,
        'identificate_wc' => $wooCommerceCount,
        'altri_pagamenti' => $otherPaymentsCount,
        'saltate_gia_processate' => $skippedCount,
        'errori_elaborazione' => $errorCount,
        'app_mode' => defined('APP_MODE') ? APP_MODE : 'NON DEFINITA'
    ]);
}

// Chiudi tutte le connessioni DB
DBConnector::closeAllConnections();
if (isset($log)) $log->info('<<<< ==== Script CRON PayPal COMPLETATO (index2.php - Multi-Istanza con Debug Avanzato) ==== >>>>');

exit(0); // Uscita standard

?>