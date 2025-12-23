<?php
/**
 * Script CRON UNIFICATO: PayPal (Logica Originale Completa) + Bonifici (Nuova Logica)
 * * 1. PayPal: Recupera transazioni. Se è un ordine WC -> Moodle. Se NO -> Tabella 'results' (RIPRISTINATO).
 * 2. Bonifici: Scansiona DB WC per ordini in processing/completed -> Moodle.
 */

// --- Impostazioni Iniziali e Include ---
date_default_timezone_set('Europe/Rome');
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
$phpErrorLogPath = __DIR__ . '/logs/php_errors.log';
ini_set('error_log', $phpErrorLogPath);

// Autoloader Composer
$autoloaderPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoloaderPath)) {
    require_once $autoloaderPath;
} else {
    error_log("ERRORE CRITICO: Autoloader Composer non trovato.");
    exit(1);
}

// Include necessari
require_once __DIR__ . '/inc/logger_init.php';
require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/woocommerce_helpers.php';

use Monolog\Logger;

// --- INIZIALIZZA LOGGER ---
$logDirectoryPath = __DIR__ . '/logs';
$logLevel = (defined('APP_MODE') && APP_MODE === 'PRODUCTION') ? Logger::INFO : Logger::DEBUG;
$log = getAppLogger('PAYPAL_CRON', 'paypal_cron', $logLevel, $logDirectoryPath);

if (isset($log)) {
    $log->info('<<<< ==== Script CRON UNIFICATO (Restore PayPal + Bonifici) AVVIATO ==== >>>>');
    $log->warning('Modalità Applicazione: ' . (defined('APP_MODE') ? APP_MODE : 'NON DEFINITA!'));
}

// =============================================================================
// --- CONFIGURAZIONE DATE ---
// =============================================================================

// =============================================================================
// --- CONFIGURAZIONE DATE (MODIFICATA PER TEST SCARICO) ---
// =============================================================================

/* test con periodo fisso
// 1. DATA FINE (Impostata al 6 Novembre per beccare il tuo ordine)
$endDate = '2025-11-06T23:59:59Z';

// 2. DATA INIZIO PAYPAL (Impostata al 1 Novembre)
$startDatePayPal = '2025-11-01T00:00:00Z';

// 3. VARIABILI OBBLIGATORIE (Non toccare! Servono per evitare l'errore Fatal)
$endDateSql = date('Y-m-d H:i:s');
$startDateBacs = date('Y-m-01 00:00:00', strtotime('first day of last month'));
$startDateBacsSql = date('Y-m-d H:i:s', strtotime($startDateBacs));
*/

// 1. FINE PERIODO (Fine giornata corrente)
$endDate = gmdate('Y-m-d') . 'T23:59:59Z';
$endDateSql = date('Y-m-d H:i:s');   // Per Query SQL Bonifici

// 2. DATA INIZIO PER PAYPAL (Ultime 48 ore - PRODUZIONE)
$startDatePayPal = gmdate('Y-m-d\TH:i:s\Z', strtotime('-48 hours'));

// Imposta la data al 1° giorno del mese SCORSO (es. se siamo a Dicembre, parte dal 1° Novembre)
$startDateBacs = date('Y-m-01 00:00:00', strtotime('first day of last month'));
$startDateBacsSql = date('Y-m-d H:i:s', strtotime($startDateBacs));

if (isset($log)) {
    $log->info("Configurazione Date:", [
        'PayPal_Start' => $startDatePayPal,
        'Bacs_Start'   => $startDateBacsSql,
        'End_Date'     => $endDate
    ]);
}

// --- CONNESSIONE DB PRINCIPALE (MoodleApps) ---
$connMdlApps = DBConnector::getMoodleAppsDb();
if (!$connMdlApps) {
    if (isset($log)) $log->critical("Connessione DB 'MoodleApps' non disponibile. Script terminato.");
    exit(1);
}

// Mappatura Istanze
$wcInstanceMapping = defined('WC_INSTANCE_MAPPING') ? WC_INSTANCE_MAPPING : [];
if (empty($wcInstanceMapping)) {
    if (isset($log)) $log->critical("Mappatura WC_INSTANCE_MAPPING vuota.");
    exit(1);
}

// =============================================================================
// --- CLASSE PAYPAL (RIPRISTINATA L'ORIGINALE COMPLETA) ---
// =============================================================================
class PayPal
{
    private $clientId;
    private $clientSecret;
    private $apiBase;
    private $accessToken;
    private $log;
    private $environment;
    
    public function __construct(?Monolog\Logger $logger)
    {
        $this->log = $logger;
        if (! defined('PAYPAL_CLIENT_ID') || ! defined('PAYPAL_SECRET') || ! defined('PAYPAL_ENVIRONMENT')) {
            throw new Exception("Configurazione PayPal mancante.");
        }
        $this->clientId = PAYPAL_CLIENT_ID;
        $this->clientSecret = PAYPAL_SECRET;
        $this->environment = PAYPAL_ENVIRONMENT;
        
        if ($this->environment === 'PRODUCTION') {
            $this->apiBase = "https://api-m.paypal.com";
        } else {
            $this->apiBase = "https://api-m.sandbox.paypal.com";
        }
        
        $this->accessToken = $this->getAccessToken();
    }
    
    private function getAccessToken()
    {
        $url = $this->apiBase . "/v1/oauth2/token";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ":" . $this->clientSecret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $result = json_decode($response, true);
        curl_close($ch);
        
        if (! isset($result['access_token'])) {
            throw new Exception("Token non trovato nella risposta PayPal.");
        }
        return $result['access_token'];
    }
    
    public function getReceivedPayments($startDate, $endDate)
    {
        // Nota: recupera tutte le transazioni con stato S
        $url = $this->apiBase . "/v1/reporting/transactions?start_date=" . $startDate . "&end_date=" . $endDate . "&fields=all&page_size=500&transaction_status=S";
        
        if (isset($this->log)) $this->log->info("Richiesta API PayPal", ['url' => $url]);
        
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->accessToken
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if (empty($data['transaction_details'])) {
            return [];
        }
        
        $processedTransactions = [];
        foreach ($data['transaction_details'] as $transaction) {
            
            $payerInfo = $transaction['payer_info'] ?? [];
            $transactionInfo = $transaction['transaction_info'] ?? [];
            $cartInfo = $transaction['cart_info']['item_details'][0] ?? [];
            $shippingInfo = $transaction['shipping_info'] ?? [];
            
            // Controllo importo (Originale)
            if (!isset($transactionInfo['transaction_amount']['value']) || !is_numeric($transactionInfo['transaction_amount']['value']) || (float)$transactionInfo['transaction_amount']['value'] <= 0) {
                continue;
            }
            
            // Estrazione dati completa (Originale)
            $itemName = $cartInfo['item_name'] ?? 'N/A';
            $itemCode = $cartInfo['item_code'] ?? 'N/A';
            $itemDescription = $cartInfo['item_description'] ?? 'N/A';
            $invoiceIdFromPayPal = $transactionInfo['invoice_id'] ?? null;
            
            $transactionDateStr = $transactionInfo['transaction_initiation_date'] ?? date('c');
            try {
                $date = new \DateTime($transactionDateStr);
                $formattedDate = $date->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                $formattedDate = date('Y-m-d H:i:s');
            }
            
            $fullPhone = 'N/A';
            if (isset($payerInfo['phone_number']['country_code']) && isset($payerInfo['phone_number']['national_number'])) {
                $fullPhone = '+' . $payerInfo['phone_number']['country_code'] . ' ' . $payerInfo['phone_number']['national_number'];
            }
            
            $payerName = $payerInfo['payer_name']['alternate_full_name'] ?? 'N/A';
            
            // Array completo (Originale)
            $resultItem = [
                'transaction_id' => $transactionInfo['transaction_id'] ?? 'N/A',
                'invoice_id' => $invoiceIdFromPayPal,
                'transaction_date' => $formattedDate,
                'transaction_code' => $transactionInfo['transaction_event_code'] ?? 'N/A',
                'paying_name' => $payerName,
                'paying_email' => $payerInfo['email_address'] ?? 'N/A',
                'paying_phone' => $fullPhone,
                'paying_nat' => $payerInfo['country_code'] ?? 'N/A',
                'paying_account' => $payerInfo['account_id'] ?? 'N/A',
                'billing_address' => $shippingInfo['address']['line1'] ?? 'N/A',
                'billing_city' => $shippingInfo['address']['city'] ?? 'N/A',
                'billing_prov' => $shippingInfo['address']['state'] ?? 'N/A',
                'billing_postalcode' => $shippingInfo['address']['postal_code'] ?? 'N/A',
                'amount' => $transactionInfo['transaction_amount']['value'],
                'fee_amount' => $transactionInfo['fee_amount']['value'] ?? '0.00',
                'Stato Transazione' => $transactionInfo['transaction_status'] ?? 'N/A',
                'item_purchased' => $itemName,
                'item_code' => $itemCode,
                'item_description' => $itemDescription
            ];
            $processedTransactions[] = $resultItem;
        }
        return $processedTransactions;
    }
}


// =========================================================================
// --- FASE 1: ELABORAZIONE PAYPAL (CODICE ORIGINALE RIPRISTINATO) ---
// =========================================================================
if (isset($log)) $log->info("--- FASE 1: Controllo PayPal (Originale) ---");

$receivedPayments = [];
try {
    $paypal = new PayPal($log);
    $receivedPayments = $paypal->getReceivedPayments($startDatePayPal, $endDate);
    if (isset($log)) $log->info("PayPal: Recuperate " . count($receivedPayments) . " transazioni.");
} catch (Exception $e) {
    if (isset($log)) $log->critical("Errore PayPal Init: " . $e->getMessage());
    // Non usciamo, proviamo a fare almeno i bonifici
}

// Loop originale
foreach ($receivedPayments as $tx) {
    $transaction_id = $tx['transaction_id'] ?? 'N/A';
    $paypal_invoice_id = $tx['invoice_id'] ?? null;
    
    // Identifica se è un ordine WooCommerce
    $wooOrderId = null;
    $instanceConfig = null;
    
    if (!empty($paypal_invoice_id)) {
        foreach ($wcInstanceMapping as $prefix => $config) {
            if (strpos($paypal_invoice_id, $prefix) === 0) {
                $potentialOrderId = substr($paypal_invoice_id, strlen($prefix));
                if (ctype_digit($potentialOrderId)) {
                    $wooOrderId = $potentialOrderId;
                    $instanceConfig = $config;
                    $instanceConfig['prefix'] = $prefix;
                    
                    // --- DEBUG AGGIUNTO ---
                    if (isset($log)) {
                        $log->warning("DEBUG MATCH: Trovato prefisso '$prefix'. DB Moodle configurato: " . $instanceConfig['moodle_db_name']);
                    }
                    // ----------------------
                    
                    break;
                }
            }
        }
    }
    
    // CASO A: È UN ORDINE WOOCOMMERCE
    if ($instanceConfig && $wooOrderId) {
        if (isset($log)) $log->info("PayPal: Rilevato Ordine WC", ['order' => $wooOrderId, 'prefix' => $instanceConfig['prefix']]);
        
        try {
            if (!checkWooOrderAlreadyQueued($wooOrderId)) {
                
                // *** FIX IMPORTANTE: Definiamo che questo è un flusso PayPal ***
                $instanceConfig['is_paypal'] = true;
                
                $resultQueue = queueWooOrderForProcessing($wooOrderId, $instanceConfig);
                
                if ($resultQueue['success']) {
                    if (isset($log)) $log->info("PayPal: Ordine $wooOrderId accodato OK.");
                } else {
                    if (isset($log)) $log->error("PayPal: Errore accodamento $wooOrderId: " . ($resultQueue['error'] ?? 'N/D'));
                }
            } else {
                if (isset($log)) $log->info("PayPal: Ordine $wooOrderId già processato. Salto.");
            }
        } catch (Exception $e) {
            if (isset($log)) $log->error("Eccezione PayPal WC $wooOrderId: " . $e->getMessage());
        }
        
    } else {
        // CASO B: NON È WOOCOMMERCE -> SALVA IN RESULTS
        if (isset($log)) $log->info("PayPal: Transazione NON-WC (o non riconosciuta). Salvataggio in 'results'.", ['tx_id' => $transaction_id]);
        
        try {
            $check_sql = "SELECT id FROM results WHERE transaction_id = ? LIMIT 1";
            $stmt_check = $connMdlApps->prepare($check_sql);
            $stmt_check->bind_param("s", $transaction_id);
            $stmt_check->execute();
            $stmt_check->store_result();
            $exists = ($stmt_check->num_rows > 0);
            $stmt_check->close();
            
            if (!$exists && defined('APP_MODE') && APP_MODE === 'PRODUCTION') {
                $insert_sql = "INSERT INTO results (
                     transaction_id, date_transaction, paying_name, paying_email, paying_phone,
                     paying_nat, paying_account, billing_address, billing_city, billing_prov,
                     billing_postalcode, amount, fee_amount, item_purchased,
                     item_code, item_description
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $connMdlApps->prepare($insert_sql);
                $stmt->bind_param("sssssssssssddsss",
                    $tx['transaction_id'], $tx['transaction_date'], $tx['paying_name'], $tx['paying_email'],
                    $tx['paying_phone'], $tx['paying_nat'], $tx['paying_account'], $tx['billing_address'],
                    $tx['billing_city'], $tx['billing_prov'], $tx['billing_postalcode'],
                    $tx['amount'], $tx['fee_amount'], $tx['item_purchased'], $tx['item_code'], $tx['item_description']
                    );
                
                if ($stmt->execute()) {
                    if (isset($log)) $log->info("Salvato in 'results' OK.");
                    if (function_exists('sendNotificationEmail')) {
                        sendNotificationEmail($tx);
                    }
                } else {
                    if (isset($log)) $log->error("Errore insert 'results': " . $stmt->error);
                }
                $stmt->close();
            }
        } catch (Exception $e) {
            if (isset($log)) $log->error("Eccezione 'results': " . $e->getMessage());
        }
    }
}


// =========================================================================
// --- FASE 2: ELABORAZIONE BONIFICI (BACS) - CODICE NUOVO MANTENUTO ---
// =========================================================================
if (isset($log)) $log->info("--- FASE 2: Controllo Bonifici su DB ---");

$bacsStatuses = ['wc-processing', 'wc-completed'];

foreach ($wcInstanceMapping as $prefix => $config) {
    $wpDbName = $config['wc_db_name'];
    $wpPrefix = $config['wc_db_prefix'];
    
    // Scansione DB usando data LUNGA
    $bacsOrderIds = getBacsOrderIdsFromDB($wpDbName, $wpPrefix, $startDateBacsSql, $endDateSql, $bacsStatuses);
    
    if (empty($bacsOrderIds)) {
        continue;
    }
    
    if (isset($log)) $log->info("Bonifici trovati in $wpDbName: " . count($bacsOrderIds));
    
    foreach ($bacsOrderIds as $wooOrderId) {
        $currentConfig = $config;
        $currentConfig['prefix'] = $prefix;
        
        // Controllo se già processato
        if (checkWooOrderAlreadyQueued((string)$wooOrderId)) {
            continue;
        }
        
        if (isset($log)) $log->info("Bonifico: Processando Ordine #$wooOrderId ($prefix)");
        
        $res = queueWooOrderForProcessing((string)$wooOrderId, $currentConfig);
        
        if ($res['success']) {
            if (isset($log)) $log->info("Bonifico: Ordine $wooOrderId accodato OK.");
        } else {
            if (isset($log)) $log->error("Bonifico: Errore ordine $wooOrderId: " . ($res['error'] ?? 'N/D'));
        }
    }
}

// --- CHIUSURA ---
if (isset($log)) {
    $log->info('<<<< ==== Script CRON COMPLETATO ==== >>>>');
}
DBConnector::closeAllConnections();
exit(0);
?>