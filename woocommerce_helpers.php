<?php
/**
 * File contenente funzioni helper per interagire con WooCommerce (DB),
 * Moodle DB e la tabella moodle_payments.
 * Utilizza la classe DBConnector definita in connect.php per le connessioni DB.
 * Gestisce APP_MODE per l'inserimento in moodle_payments.
 * CORRETTO: Utilizza getWpDbByName e passa dbName/dbPrefix come parametri.
 */

use Monolog\Logger; // Assicura che Logger sia accessibile se usato

// --- FUNZIONI HELPER PRINCIPALI ---

/**
 * Controlla se un ordine WC è già stato accodato in moodle_payments.
 * @param string $wooOrderId ID Ordine WooCommerce
 * @return bool True se già presente o errore, False altrimenti.
 */
function checkWooOrderAlreadyQueued(string $wooOrderId): bool {
    global $log; // Usa il logger globale
    $conn = DBConnector::getMoodleAppsDb(); // Ottieni connessione MoodleApps DB
    if (!$conn) {
        $log?->error("checkWooOrderAlreadyQueued: Impossibile ottenere connessione a MoodleApps DB.");
        return true; // Assume processato se non può controllare DB
    }
    
    // Adatta 'payment_id' e 'method' se i nomi colonna sono diversi
    $sql = "SELECT id FROM moodle_payments WHERE payment_id = ? AND method = 'woocommerce' LIMIT 1";
    $exists = true; // Assume vero in caso di errore
    $stmt = null;
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $wooOrderId);
            if ($stmt->execute()) {
                $stmt->store_result();
                $exists = ($stmt->num_rows > 0);
                $log?->debug("Controllo esistenza Ordine WC in moodle_payments", ['order_id' => $wooOrderId, 'exists' => $exists]);
            } else {
                $log?->error("Errore execute checkWooOrderAlreadyQueued", ['order_id' => $wooOrderId, 'error' => $stmt->error, 'errno' => $stmt->errno]);
                $exists = true;
            }
            $stmt->close();
        } else {
            $log?->error("Errore prepare checkWooOrderAlreadyQueued", ['order_id' => $wooOrderId, 'error' => $conn->error, 'errno' => $conn->errno]);
            $exists = true;
        }
    } catch (Exception $e) {
        $log?->error("Eccezione in checkWooOrderAlreadyQueued", ['order_id' => $wooOrderId, 'exception' => $e->getMessage()]);
        $exists = true;
        if ($stmt instanceof mysqli_stmt) { @$stmt->close();} // Chiudi statement se aperto
    }
    // Non chiudere connessione MoodleApps qui, potrebbe servire altrove
    return $exists;
}


/**
 * Legge i dettagli dell'ordine WooCommerce DIRETTAMENTE DAL DATABASE WordPress (HPOS).
 * Simula la struttura restituita dall'API REST.
 * MODIFICATO: Accetta $wpDbName e $wpPrefix come argomenti.
 *
 * @param string $wooOrderId ID Ordine WooCommerce
 * @param string $wpDbName Nome del database WordPress specifico
 * @param string $wpPrefix Prefisso delle tabelle WordPress specifico (es. 'wp_')
 * @return array|null Dettagli ordine simulati o null in caso di errore.
 */
function getWooCommerceOrderDetails_FROM_DB(string $wooOrderId, string $wpDbName, string $wpPrefix): ?array {
    global $log;
    $log?->info("Recupero dettagli ordine WC DAL DATABASE WP", ['order_id' => $wooOrderId, 'db_name' => $wpDbName, 'prefix' => $wpPrefix]);
    
    // Ottieni la connessione allo specifico DB WordPress
    // *** CORREZIONE CHIAVE: Usa getWpDbByName con il nome DB passato ***
    $connWp = DBConnector::getWpDbByName($wpDbName);
    if (!$connWp) {
        $log?->error("getWooCommerceOrderDetails_FROM_DB: Impossibile ottenere connessione a WordPress DB", ['db_name' => $wpDbName]);
        return null;
    }
    
    $orderDetails = null;
    $stmtOrder = $stmtBilling = $stmtMeta = $stmtItems = null; // Inizializza statements a null
    
    try {
        // 1. Recupera dati ordine principale
        // *** CORREZIONE CHIAVE: Usa $wpPrefix passato ***
        $sqlOrder = "SELECT o.id, o.status, o.date_created_gmt, o.date_updated_gmt, o.total_amount, o.customer_id "
                    ."FROM {$wpPrefix}wc_orders o "
                    ."WHERE o.id = ? LIMIT 1";
        $stmtOrder = $connWp->prepare($sqlOrder);
        if(!$stmtOrder) throw new mysqli_sql_exception("Prepare fallito (order): ".$connWp->error, $connWp->errno);
        $stmtOrder->bind_param("i", $wooOrderId);
        if(!$stmtOrder->execute()) throw new mysqli_sql_exception("Execute fallito (order): ".$stmtOrder->error, $stmtOrder->errno);
        $resultOrder = $stmtOrder->get_result();
        $orderData = $resultOrder->fetch_assoc();
        $stmtOrder->close(); // Chiudi subito lo statement
        if (!$orderData) { throw new Exception("Ordine WC $wooOrderId non trovato nel DB WP '$wpDbName'."); }
        $orderDetails = $orderData; // Inizia a costruire l'array dei dettagli
        
        // 2. Recupera indirizzo di fatturazione (se l'ID è stato trovato)
        $billingAddress = [];
        if (!empty($orderData['billing_address_id'])) {
            // *** CORREZIONE CHIAVE: Usa $wpPrefix passato ***
            $sqlBilling = "SELECT first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone
                           FROM {$wpPrefix}wc_order_addresses WHERE id = ? AND address_type = 'billing' LIMIT 1";
            $stmtBilling = $connWp->prepare($sqlBilling);
            if(!$stmtBilling) throw new mysqli_sql_exception("Prepare fallito (billing): ".$connWp->error, $connWp->errno);
            $stmtBilling->bind_param("i", $orderData['billing_address_id']);
            if(!$stmtBilling->execute()) throw new mysqli_sql_exception("Execute fallito (billing): ".$stmtBilling->error, $stmtBilling->errno);
            $resultBilling = $stmtBilling->get_result();
            $billingAddress = $resultBilling->fetch_assoc() ?: [];
            $stmtBilling->close(); // Chiudi subito lo statement
        } else {
            $log?->warning("Nessun billing_address_id trovato per ordine WC", ['order_id' => $wooOrderId]);
        }
        $orderDetails['billing'] = $billingAddress;
        
        // 3. Recupera metadati ordine (incluso il CF)
        $metaData = [];
        $orderDetails['billing']['cf'] = null; // Inizializza CF a null
        $cfMetaKey = 'billing_cf'; // *** ASSICURATI CHE SIA IL NOME CORRETTO DEL META KEY PER IL CF ***
        // *** CORREZIONE CHIAVE: Usa $wpPrefix passato ***
        $sqlMeta = "SELECT meta_key, meta_value FROM {$wpPrefix}wc_orders_meta WHERE order_id = ?";
        $stmtMeta = $connWp->prepare($sqlMeta);
        if(!$stmtMeta) throw new mysqli_sql_exception("Prepare fallito (meta): ".$connWp->error, $connWp->errno);
        $stmtMeta->bind_param("i", $wooOrderId);
        if(!$stmtMeta->execute()) throw new mysqli_sql_exception("Execute fallito (meta): ".$stmtMeta->error, $stmtMeta->errno);
        $resultMeta = $stmtMeta->get_result();
        while ($rowMeta = $resultMeta->fetch_assoc()) {
            $metaData[] = ['id' => 0, 'key' => $rowMeta['meta_key'], 'value' => $rowMeta['meta_value']];
            if ($rowMeta['meta_key'] === $cfMetaKey) {
                $orderDetails['billing']['cf'] = $rowMeta['meta_value']; // Estrai il CF
            }
        }
        $stmtMeta->close(); // Chiudi subito lo statement
        $orderDetails['meta_data'] = $metaData; // Aggiungi tutti i metadati per completezza
        
        // 4. Recupera righe prodotto dalla tabella lookup
        $lineItems = [];
        // *** CORREZIONE CHIAVE: Usa $wpPrefix passato ***
        $sqlItems = "SELECT order_item_id, product_id, product_qty, product_net_revenue, tax_amount
                     FROM {$wpPrefix}wc_order_product_lookup WHERE order_id = ?";
        $stmtItems = $connWp->prepare($sqlItems);
        if(!$stmtItems) throw new mysqli_sql_exception("Prepare fallito (items): ".$connWp->error, $connWp->errno);
        $stmtItems->bind_param("i", $wooOrderId);
        if(!$stmtItems->execute()) throw new mysqli_sql_exception("Execute fallito (items): ".$stmtItems->error, $stmtItems->errno);
        $resultItems = $stmtItems->get_result();
        while ($rowItem = $resultItems->fetch_assoc()) {
            $lineTotal = (float)($rowItem['product_net_revenue'] ?? 0) + (float)($rowItem['tax_amount'] ?? 0);
            $lineItems[] = [
                'id' => (int)$rowItem['order_item_id'],
                 //'name' => $rowItem['order_item_name'],
                'product_id' => (int)$rowItem['product_id'],
                'quantity' => (int)$rowItem['product_qty'],
                'total' => number_format($lineTotal, 2, '.', ''), // Formatta come stringa a 2 decimali
                'total_tax' => number_format((float)$rowItem['tax_amount'], 2, '.', ''),
            ];
        }
        $stmtItems->close(); // Chiudi subito lo statement
        $orderDetails['line_items'] = $lineItems;
        
        // 5. Aggiungi/Formatta campi principali per simulare API REST di WooCommerce
        $orderDetails['id'] = (int)$orderData['id'];
        $orderDetails['status'] = str_replace('wc-', '', $orderData['status']); // Rimuovi prefisso 'wc-' dallo stato
        $orderDetails['date_created_gmt'] = $orderData['date_created_gmt'];
        $orderDetails['date_updated_gmt'] = $orderData['date_updated_gmt']; // Potrebbe essere null
        $orderDetails['total'] = number_format((float)$orderData['total_amount'], 2, '.', ''); // Formatta come stringa a 2 decimali
        
        // Pulisci campi interni non necessari nella simulazione API
        unset($orderDetails['billing_address_id'], $orderDetails['customer_id'], $orderDetails['total_amount']);
        
    } catch (mysqli_sql_exception $e) {
        $log?->error("Errore DB getWooCommerceOrderDetails_FROM_DB", ['order_id' => $wooOrderId, 'db_name' => $wpDbName, 'error_code' => $e->getCode(), 'exception' => $e->getMessage()]);
        $orderDetails = null; // Resetta a null in caso di errore DB
    } catch (Exception $e) {
        $log?->error("Errore generico getWooCommerceOrderDetails_FROM_DB", ['order_id' => $wooOrderId, 'db_name' => $wpDbName, 'exception' => $e->getMessage()]);
        $orderDetails = null; // Resetta a null per altri errori
    } finally {
        // Non chiudere la connessione $connWp qui, potrebbe servire in `findMoodleCourseId`
        if(isset($log)) $log->debug("Blocco finally di getWooCommerceOrderDetails_FROM_DB eseguito.");
    }
    
    if($orderDetails) {
        $log?->info("Recuperati dettagli ordine WC da DB WP", ['order_id' => $wooOrderId, 'db_name' => $wpDbName]);
        $log?->debug("Dettagli Ordine WC recuperati da DB", ['order_details' => $orderDetails]);
    }
    return $orderDetails; // Ritorna l'array dei dettagli o null
}


// --- Funzioni Moodle (usano DBConnector) ---

/**
 * Trova l'ID del Corso Moodle corrispondente a un Product ID WooCommerce.
 * Legge dal metadato '_moodle_course_id' del post prodotto nel DB WordPress specifico.
 * MODIFICATO: Accetta $wpDbName e $wpPrefix come argomenti.
 *
 * @param int $productId Product ID di WooCommerce
 * @param string $wpDbName Nome del database WordPress specifico
 * @param string $wpPrefix Prefisso delle tabelle WordPress specifico
 * @return int|null L'ID del corso Moodle se trovato, altrimenti null.
 */
function findMoodleCourseId(int $productId, string $wpDbName, string $wpPrefix): ?int {
    global $log;
    $log?->debug("Ricerca Moodle Course ID per Prodotto WC", ['product_id' => $productId, 'wp_db' => $wpDbName, 'wp_prefix' => $wpPrefix]);
    
    // *** CORREZIONE CHIAVE: Usa getWpDbByName con il nome DB passato ***
    $connWp = DBConnector::getWpDbByName($wpDbName);
    if (!$connWp) {
        $log?->error("findMoodleCourseId: Connessione WP DB non disponibile.", ['db_name' => $wpDbName]);
        return null;
    }
    
    // Chiave metadato standard
    $metaKey = 'moodle_course_id';
    
    $courseId = null;
    $stmt = null; // Inizializza statement a null
    
    try {
        // *** CORREZIONE CHIAVE: Usa $wpPrefix passato ***
        $sql = "SELECT meta_value FROM {$wpPrefix}postmeta WHERE post_id = ? AND meta_key = ? LIMIT 1";
        $stmt = $connWp->prepare($sql);
        if (!$stmt) throw new mysqli_sql_exception("Prepare fallito (findMoodleCourseId): ".$connWp->error, $connWp->errno);
        
        $stmt->bind_param("is", $productId, $metaKey); // i per post_id (product id), s per meta_key
        if (!$stmt->execute()) throw new mysqli_sql_exception("Execute fallito (findMoodleCourseId): ".$stmt->error, $stmt->errno);
        
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if ($row && !empty($row['meta_value']) && is_numeric($row['meta_value'])) {
            $courseId = (int)$row['meta_value'];
            $log?->info("Trovato Moodle Course ID da metadato WP", ['product_id' => $productId, 'course_id' => $courseId, 'wp_db' => $wpDbName]);
        } else {
            $log?->warning("Metadato '$metaKey' non trovato o non valido per Prodotto WC", ['product_id' => $productId, 'wp_db' => $wpDbName, 'meta_value_found' => $row['meta_value'] ?? 'N/D']);
        }
        $stmt->close(); // Chiudi subito lo statement
    } catch (Exception $e) {
        $log?->error("Errore DB findMoodleCourseId", ['product_id' => $productId, 'wp_db' => $wpDbName, 'exception' => $e->getMessage()]);
        if ($stmt instanceof mysqli_stmt) { @$stmt->close();} // Chiudi statement se aperto
        $courseId = null; // Assicura null in caso di errore
    }
    // Non chiudere $connWp qui
    
    if ($courseId === null) {
        $log?->error("Mappatura Corso Moodle NON trovata nel DB WP per Prodotto", ['product_id' => $productId, 'wp_db' => $wpDbName]);
        // La notifica admin verrà gestita dalla funzione chiamante (queueWooOrderForProcessing)
    }
    return $courseId;
}

/**
 * Trova l'UserID Moodle basato sul Codice Fiscale nello specifico DB Moodle.
 *
 * @param string $cf Codice Fiscale da cercare
 * @param string $moodleDbName Nome del database Moodle specifico dove cercare
 * @return int|null ID utente Moodle se trovato, altrimenti null.
 */
function findMoodleUserByCF(string $cf, string $moodleDbName): ?int {
    global $log;
    $userId = null;
    
    // Validazione input base
    if (empty($cf) || empty($moodleDbName)) {
        $log?->error("findMoodleUserByCF chiamato con CF o DbName vuoto.", ['cf' => $cf, 'db' => $moodleDbName]);
        return null;
    }
    // Assicurati che la costante per lo shortname del campo CF sia definita
    if (!defined('MOODLE_CF_SHORTNAME')) {
        $log?->critical("Costante MOODLE_CF_SHORTNAME non definita in config_db.php!");
        return null;
    }
    $cfShortname = MOODLE_CF_SHORTNAME;
    
    $log?->debug("Ricerca Utente Moodle per CF", ['cf' => $cf, 'db' => $moodleDbName, 'cf_field_shortname' => $cfShortname]);
    
    // Ottieni connessione allo specifico DB Moodle
    $connMoodle = DBConnector::getMoodleDbByName($moodleDbName);
    if (!$connMoodle) {
        $log?->error("findMoodleUserByCF: Impossibile connettersi a DB Moodle", ['db_name' => $moodleDbName]);
        return null;
    }
    
    $fieldId = null; // Field ID trovato dal prepared statement
    $testFieldId = null; // Field ID trovato dal test diretto
    
    // <<< INIZIO TEST DIRETTO DENTRO PHP >>>
    $testCfShortnameLiteral = 'CF'; // Usiamo il valore letterale
    // Nota: Usiamo real_escape_string per sicurezza minima, anche se qui non serve
    $testSql = "SELECT id FROM mdl_user_info_field WHERE shortname = '" . $connMoodle->real_escape_string($testCfShortnameLiteral) . "' LIMIT 1";
    $testResult = $connMoodle->query($testSql); // Esegui query diretta
    if ($testResult) {
        $testRow = $testResult->fetch_assoc();
        $testFieldId = $testRow ? (int)$testRow['id'] : null; // Ottieni l'ID
        $log?->debug("!!! RISULTATO TEST DIRETTO field ID !!!", ['sql_eseguito' => $testSql, 'id_trovato_diretto' => $testFieldId]);
        $testResult->free(); // Libera risultato
    } else {
        $log?->error("!!! TEST DIRETTO field ID FALLITO !!!", ['sql_tentato' => $testSql, 'error' => $connMoodle->error]);
    }
    // <<< FINE TEST DIRETTO DENTRO PHP >>>
    
    $stmtField = null;
    
    try {
        // 1. Trova l'ID del campo profilo 'CF' usando lo shortname
        $sqlField = "SELECT id FROM mdl_user_info_field WHERE shortname = ? LIMIT 1";
        $stmtField = $connMoodle->prepare($sqlField);
        if (!$stmtField) throw new mysqli_sql_exception("Prepare fallito (findMoodleUserByCF - field): ".$connMoodle->error, $connMoodle->errno);
        $stmtField->bind_param("s", $cfShortname);
        if (!$stmtField->execute()) throw new mysqli_sql_exception("Execute fallito (findMoodleUserByCF - field): ".$stmtField->error, $stmtField->errno);
        $resultField = $stmtField->get_result();
        $fieldRow = $resultField->fetch_assoc();
        $stmtField->close(); // Chiudi subito
        
        if (!$fieldRow || empty($fieldRow['id'])) {
            throw new Exception("Campo profilo Moodle con shortname '$cfShortname' non trovato nel DB '$moodleDbName'.");
        }
        $fieldId = (int)$fieldRow['id'];
        $log?->debug("Trovato ID campo profilo CF", ['field_id' => $fieldId, 'db' => $moodleDbName]);
        
        // 2. Trova l'utente usando l'ID del campo e il valore del CF (confronto case-insensitive)
        $sqlData = "SELECT userid FROM mdl_user_info_data WHERE fieldid = ? AND UPPER(data) = UPPER(?) LIMIT 1";
        $stmtData = $connMoodle->prepare($sqlData);
        if (!$stmtData) throw new mysqli_sql_exception("Prepare fallito (findMoodleUserByCF - data): ".$connMoodle->error, $connMoodle->errno);
        $stmtData->bind_param("is", $fieldId, $cf); // i per fieldid, s per cf (data)
        if (!$stmtData->execute()) throw new mysqli_sql_exception("Execute fallito (findMoodleUserByCF - data): ".$stmtData->error, $stmtData->errno);
        $resultData = $stmtData->get_result();
        $dataRow = $resultData->fetch_assoc();
        $stmtData->close(); // Chiudi subito
        
        if ($dataRow && !empty($dataRow['userid'])) {
            $userId = (int)$dataRow['userid'];
        }
        
    } catch (Exception $e) {
        $log?->error("Errore durante findMoodleUserByCF", ['cf' => $cf, 'db' => $moodleDbName, 'exception' => $e->getMessage()]);
        if ($stmtField instanceof mysqli_stmt) { @$stmtField->close();}
        if ($stmtData instanceof mysqli_stmt) { @$stmtData->close();}
        $userId = null; // Assicura null in caso di errore
    }
    // Non chiudere $connMoodle qui
    
    if (!$userId) {
        $log?->warning("Utente Moodle NON trovato per CF", ['cf' => $cf, 'db' => $moodleDbName]);
        // La notifica admin verrà gestita dalla funzione chiamante (queueWooOrderForProcessing)
    } else {
        $log?->info("Trovato Utente Moodle per CF", ['user_id' => $userId, 'cf' => $cf, 'db' => $moodleDbName]);
    }
    return $userId;
}


/**
 * Inserisce (o simula l'inserimento) di un record nella tabella moodle_payments.
 * La logica effettiva dipende da APP_MODE.
 *
 * @param int $userId ID Utente Moodle
 * @param int $courseId ID Corso Moodle
 * @param string $dbName Nome DB Moodle (per riferimento/log)
 * @param string $paymentId ID pagamento originale (es. ID ordine WC)
 * @param float $cost Costo associato a questa iscrizione/record
 * @param string $method Metodo di pagamento (es. 'woocommerce')
 * @return bool True se l'operazione (reale o test) è riuscita, False altrimenti.
 */
function insertIntoMoodlePayments(int $userId, int $courseId, string $dbName, string $paymentId, float $cost, string $method): bool
{
    global $log;
    
    // Verifica APP_MODE, default a PRODUCTION se non definita
    $appMode = defined('APP_MODE') ? APP_MODE : 'PRODUCTION';
    
    if ($appMode === 'TEST') {
        // --- LOGICA MODALITÀ TEST: Scrittura su CSV ---
        return insertIntoMoodlePayments_TEST_CSV($userId, $courseId, $dbName, $paymentId, $cost, $method);
    } else {
        // --- LOGICA MODALITÀ PRODUCTION: Inserimento nel DB ---
        return insertIntoMoodlePayments_PROD($userId, $courseId, $dbName, $paymentId, $cost, $method);
    }
}


/**
 * !!! FUNZIONE DI TEST !!!
 * SCRIVE SU FILE CSV invece di inserire in moodle_payments.
 * Definisce il percorso del file CSV tramite la costante TEST_OUTPUT_FILE.
 *
 * @param int $userId ID Utente Moodle
 * @param int $courseId ID Corso Moodle
 * @param string $dbName Nome DB Moodle (per riferimento/log)
 * @param string $paymentId ID pagamento originale (es. ID ordine WC)
 * @param float $cost Costo associato a questa iscrizione/record
 * @param string $method Metodo di pagamento (es. 'woocommerce')
 * @return bool True se la scrittura su CSV è riuscita, False altrimenti.
 */
function insertIntoMoodlePayments_TEST_CSV(int $userId, int $courseId, string $dbName, string $paymentId, float $cost, string $method): bool {
    global $log;
    // Assicurati che la costante sia definita
    if (!defined('TEST_OUTPUT_FILE')) {
        $log?->critical("Costante TEST_OUTPUT_FILE non definita in config_db.php!");
        return false;
    }
    $filePath = TEST_OUTPUT_FILE;
    $logLine = [
        date('Y-m-d H:i:s'), // Timestamp della scrittura
        $userId,
        $courseId,
        $dbName,
        $paymentId,
        number_format($cost, 2, '.', ''), // Formatta costo a 2 decimali
        $method,
        '0' // Flag 'processed' (0 = non processato da SAP) - Adatta se necessario
    ];
    
    // Controllo esistenza e scrittura header (se necessario)
    $writeHeader = !file_exists($filePath) || filesize($filePath) === 0;
    
    // Apre il file in modalità append ('a')
    $fileHandle = fopen($filePath, 'a'); // Usa @ per sopprimere warning se fallisce (loggato dopo)
    if ($fileHandle === false) {
        $error = error_get_last();
        $log?->critical("Impossibile aprire il file di test CSV per scrittura", ['file' => $filePath, 'error' => $error['message'] ?? 'N/D']);
        return false;
    }
    
    // Scrive l'intestazione se il file è nuovo o vuoto
    if ($writeHeader) {
        // Assicurati che le intestazioni corrispondano ai dati in $logLine
        fputcsv($fileHandle, ['timestamp', 'moodle_user_id', 'moodle_course_id', 'moodle_db_name', 'payment_id', 'cost', 'method', 'processed'], ';');
    }
    
    // Scrive la riga di dati usando ; come delimitatore
    $result = fputcsv($fileHandle, $logLine, ';');
    fclose($fileHandle); // Chiude il file
    
    if ($result === false) {
        $log?->error("Errore scrittura su file di test CSV", ['file' => $filePath]);
        return false;
    } else {
        $log?->info("TEST MODE: Dati accodamento WC scritti su CSV", ['payment_id' => $paymentId, 'user_id' => $userId, 'course_id' => $courseId, 'file' => $filePath]);
        return true;
    }
}

/**
 * !!! FUNZIONE DI PRODUZIONE !!!
 * INSERISCE il record nella tabella moodle_payments del DB MoodleApps.
 *
 * @param int $userId ID Utente Moodle
 * @param int $courseId ID Corso Moodle
 * @param string $dbName Nome DB Moodle (per riferimento/log)
 * @param string $paymentId ID pagamento originale (es. ID ordine WC)
 * @param float $cost Costo associato a questa iscrizione/record
 * @param string $method Metodo di pagamento (es. 'woocommerce')
 * @return bool True se l'inserimento (o rilevamento duplicato) è riuscito, False altrimenti.
 */
function insertIntoMoodlePayments_PROD(int $userId, int $courseId, string $dbName, string $paymentId, float $cost, string $method): bool {
    global $log;
    $log?->info("PRODUCTION MODE: Tentativo inserimento in DB moodle_payments", ['payment_id' => $paymentId, 'user_id' => $userId, 'course_id' => $courseId]);
    
    $conn = DBConnector::getMoodleAppsDb(); // Connessione al DB MoodleApps (dove risiede moodle_payments)
    if (!$conn) {
        $log?->error("insertIntoMoodlePayments_PROD: Impossibile ottenere connessione a MoodleApps DB.");
        return false;
    }
    
    // *** ADATTA LA QUERY E I CAMPI ALLA STRUTTURA ESATTA DELLA TUA TABELLA 'moodle_payments' ***
    $sql = "INSERT INTO moodle_payments
                (moodle_user_id, moodle_course_id, moodle_db_name, payment_id, cost, method, processed, timestamp)
            VALUES
                (?, ?, ?, ?, ?, ?, 0, NOW())"; // Assumiamo processed=0 e timestamp=NOW()
    
    $stmt = null; // Inizializza statement a null
    $success = false;
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            // Tipi: i=integer, s=string, d=double/decimal
            // Assicurati che l'ordine e i tipi corrispondano ai placeholder (?)
            $stmt->bind_param("iisids",
                $userId,        // i (moodle_user_id)
                $courseId,      // i (moodle_course_id)
                $dbName,        // s (moodle_db_name)
                $paymentId,     // s (payment_id - es. ID Ordine WC, che è una stringa)
                $cost,          // d (cost)
                $method         // s (method - es. 'woocommerce')
                );
            
            if ($stmt->execute()) {
                $log?->info("PRODUCTION MODE: Record inserito con successo in moodle_payments", ['payment_id' => $paymentId, 'user_id' => $userId, 'course_id' => $courseId, 'affected_rows' => $stmt->affected_rows]);
                $success = true;
            } else {
                // Controlla specificamente l'errore di chiave duplicata (1062)
                if ($stmt->errno == 1062) {
                    $log?->warning("PRODUCTION MODE: Tentativo di inserire duplicato in moodle_payments (probabilmente già inserito in esecuzione precedente)", ['payment_id' => $paymentId, 'user_id' => $userId, 'course_id' => $courseId, 'error' => $stmt->error]);
                    $success = true; // Consideriamo successo anche il rilevamento di un duplicato
                } else {
                    // Logga altri errori SQL
                    $log?->error("PRODUCTION MODE: Errore execute insertIntoMoodlePayments_PROD", ['payment_id' => $paymentId, 'error' => $stmt->error, 'errno' => $stmt->errno]);
                }
            }
            $stmt->close(); // Chiudi lo statement
        } else {
            // Errore nella preparazione dello statement
            $log?->error("PRODUCTION MODE: Errore prepare insertIntoMoodlePayments_PROD", ['payment_id' => $paymentId, 'error' => $conn->error, 'errno' => $conn->errno]);
        }
    } catch (Exception $e) {
        $log?->error("PRODUCTION MODE: Eccezione in insertIntoMoodlePayments_PROD", ['payment_id' => $paymentId, 'exception' => $e->getMessage()]);
        if ($stmt instanceof mysqli_stmt) { @$stmt->close();} // Chiudi se aperto
    }
    // Non chiudere $conn qui
    return $success;
}


/**
 * Funzione wrapper per accodare un ordine WC.
 * 1. Verifica la configurazione dell'istanza passata.
 * 2. Legge i dettagli dell'ordine dal DB WP specifico.
 * 3. Estrae CF e articoli.
 * 4. Trova l'utente Moodle corrispondente (basandosi sul primo articolo valido).
 * 5. Per ogni articolo/quantità, trova il corso Moodle e accoda il record (DB o CSV).
 * Gestisce APP_MODE.
 *
 * @param string $wooOrderId ID Ordine WooCommerce da processare
 * @param array $instanceConfig Array di configurazione per l'istanza WC (da WC_INSTANCE_MAPPING)
 * @return array Risultato con 'success' (bool), 'error' (string|null), 'moodleUserId' (int|null), 'moodleCourseId' (int|null)
 */
function queueWooOrderForProcessing(string $wooOrderId, array $instanceConfig): array {
    global $log;
    $appMode = defined('APP_MODE') ? APP_MODE : 'PRODUCTION';
    $log?->info("Avvio accodamento Ordine WC ($appMode)", ['order_id' => $wooOrderId, 'instance_prefix' => $instanceConfig['prefix'] ?? 'N/D']);
    $result = ['success' => false, 'error' => null, 'moodleUserId' => null, 'moodleCourseId' => null];
    
    // 1. Verifica che la configurazione dell'istanza sia completa
    if (empty($instanceConfig['wc_db_name']) || empty($instanceConfig['wc_db_prefix'])) {
        $log?->error("Configurazione istanza WC (wc_db_name o wc_db_prefix) incompleta.", ['order_id' => $wooOrderId, 'prefix' => $instanceConfig['prefix'] ?? 'N/D', 'config_received' => $instanceConfig]);
        $result['error'] = "Configurazione istanza WC incompleta per ordine $wooOrderId.";
        return $result; // Esce se la configurazione manca
    }
    $wpDbName = $instanceConfig['wc_db_name'];
    $wpPrefix = $instanceConfig['wc_db_prefix'];
    
    // 2. Recupera dettagli ordine dal DB WP specifico passando nome e prefisso
    $orderDetails = getWooCommerceOrderDetails_FROM_DB($wooOrderId, $wpDbName, $wpPrefix);
    
    // Controlla se il recupero dettagli ha fallito
    if (!$orderDetails) {
        // Errore già loggato da getWooCommerceOrderDetails_FROM_DB
        $result['error'] = "Impossibile recuperare dettagli Ordine WC $wooOrderId da DB '$wpDbName'.";
        // Qui NON inviamo notifica admin, è già gestita da getWooCommerceOrderDetails_FROM_DB se necessario
        return $result; // Esce se non si possono leggere i dettagli ordine
    }
    
    // 3. Estrai CF e articoli
    $billing_cf_raw = $orderDetails['billing']['cf'] ?? null;
    
    // <<< AGGIUNGI QUESTA RIGA PER PULIRE IL CF >>>
    $billing_cf = isset($billing_cf_raw) ? trim($billing_cf_raw) : null;
    // Optional: Logga se è stato necessario pulire
    if(isset($log) && $billing_cf !== $billing_cf_raw) {
        $log?->warning("Rimosso/i spazio/i bianco/i iniziale/finale dal CF estratto", ['order_id' => $wooOrderId, 'cf_grezzo' => $billing_cf_raw, 'cf_pulito' => $billing_cf]);
    }
    // <<< FINE AGGIUNTA >>>
    
    $lineItems = $orderDetails['line_items'] ?? [];
    
    // Controlli preliminari su CF e articoli
    if (empty($billing_cf)) {
        $errorMsg = "CF (billing_cf) non trovato nei dati DB dell'ordine WC $wooOrderId (DB: $wpDbName). Verifica il meta key.";
        $log?->error($errorMsg, ['order_id' => $wooOrderId, 'billing_data' => $orderDetails['billing']]);
        if (function_exists('sendAdminNotification')) sendAdminNotification("CF Mancante in Ordine WC (DB)", ['order_id' => $wooOrderId, 'db_name' => $wpDbName]);
        $result['error'] = $errorMsg;
        return $result; // Esce se manca il CF
    }
    if (empty($lineItems)) {
        $result['error'] = "Nessun articolo trovato (line_items) nell'ordine WC $wooOrderId letto da DB '$wpDbName'.";
        $log?->error($result['error'], ['order_id' => $wooOrderId]);
        return $result; // Esce se non ci sono articoli
    }
    $log?->debug("CF e Articoli estratti da Ordine WC", ['order_id' => $wooOrderId, 'cf' => $billing_cf, 'num_line_items' => count($lineItems)]);
    
    // 4. Trova Utente Moodle (basato sul primo prodotto valido per cui si trova corso e utente)
    $moodleUserId = null;
    $firstValidCourseId = null;
    $moodleDbName = null; // DB Moodle associato all'utente/corso trovato
    
    foreach ($lineItems as $item) {
        $productId = $item['product_id'] ?? null;
        if ($productId) {
            $log?->debug("Tentativo ricerca corso per prodotto", ['order_id' => $wooOrderId, 'product_id' => $productId]);
            // Trova l'ID corso Moodle usando il DB WP corretto
            $courseId = findMoodleCourseId($productId, $wpDbName, $wpPrefix);
            if ($courseId) {
                $log?->debug("Trovato courseId, tentativo determinazione DB Moodle", ['order_id' => $wooOrderId, 'product_id' => $productId, 'course_id' => $courseId]);
                // Determina il DB Moodle associato al corso
                $moodleDbName = $instanceConfig['moodle_db_name'] ?? null;
                
                if ($moodleDbName) {
                    $log?->debug("DB Moodle determinato dall'istanza WC", ['order_id' => $wooOrderId, 'course_id' => $courseId, 'moodle_db' => $moodleDbName, 'instance_prefix' => $instanceConfig['prefix'] ?? 'N/D']);
                    // Cerca l'utente in quel DB Moodle usando il CF
                    $userId = findMoodleUserByCF($billing_cf, $moodleDbName);
                    if ($userId) {
                        // --- Utente Trovato! ---
                        $moodleUserId = $userId;
                        $firstValidCourseId = $courseId;
                        // $moodleDbName è già impostato correttamente qui
                        $log?->info("Trovato utente Moodle valido per l'ordine (usando DB da istanza WC)", ['order_id' => $wooOrderId, 'user_id' => $moodleUserId, 'based_on_product_id' => $productId, 'based_on_course_id' => $firstValidCourseId, 'moodle_db_name' => $moodleDbName]);
                        break; // Esci dal loop, abbiamo trovato l'utente
                    } // else: Utente non trovato (già loggato da findMoodleUserByCF)
                } else {
                    // Errore grave: il nome DB Moodle non era nella configurazione dell'istanza!
                    $log?->error("Nome DB Moodle ('moodle_db_name') non trovato nella configurazione dell'istanza WC!", ['order_id' => $wooOrderId, 'instance_prefix' => $instanceConfig['prefix'] ?? 'N/D']);
                    // Qui potresti voler impostare un flag di errore o interrompere l'elaborazione dell'ordine
                    $allQueuedSuccessfully = false; // Segna errore
                    break; // Esci dal loop degli item per questo ordine
                }
                // --- FINE MODIFICA ---
            } // else: Mappatura corso non trovata (già loggato da findMoodleCourseId)
        } else {
            $log?->warning("Riga ordine saltata durante ricerca utente: product_id mancante.", ['order_id' => $wooOrderId, 'item_name' => $item['name'] ?? 'N/D']);
        }
    } // fine loop ricerca utente
    
    // Se, dopo aver controllato tutti gli articoli, non è stato trovato nessun utente Moodle valido
    if (!$moodleUserId || !$moodleDbName) {
        $errorMsg = "Impossibile trovare utente Moodle (tramite CF: $billing_cf) associato a uno dei prodotti nell'ordine WC $wooOrderId (controllato DB WP: $wpDbName). Verifica mappature corsi e presenza utente in Moodle.";
        $log?->error($errorMsg, ['order_id' => $wooOrderId, 'cf' => $billing_cf, 'line_items_product_ids' => array_column($lineItems, 'product_id')]);
        if (function_exists('sendAdminNotification')) sendAdminNotification("Utente Moodle non trovato per Ordine WC", ['order_id' => $wooOrderId, 'cf' => $billing_cf, 'wp_db' => $wpDbName]);
        $result['error'] = $errorMsg;
        return $result; // Esce se non si trova l'utente
    }
    $result['moodleUserId'] = $moodleUserId; // Salva l'ID utente trovato
    
    // 5. Processa ogni riga/quantità e scrivi su CSV o DB (moodle_payments)
    $allQueuedSuccessfully = true;
    $lastProcessedCourseId = null; // Per il valore di ritorno
    
    foreach ($lineItems as $item) {
        $product_id = $item['product_id'] ?? null;
        // Assicura che la quantità sia almeno 1, anche se mancante o 0
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $cost_per_item_total = (float)($item['total'] ?? 0); // Costo totale della riga
        
        if (!$product_id) {
            $log?->warning("Riga ordine saltata durante accodamento: product_id mancante.", ['order_id' => $wooOrderId, 'item_name' => $item['name'] ?? 'N/D']);
            continue; // Salta questa riga articolo
        }
        
        // Trova il corso Moodle per questo specifico prodotto (di nuovo, per sicurezza)
        $moodleCourseId = findMoodleCourseId($product_id, $wpDbName, $wpPrefix);
        if (!$moodleCourseId) {
            $log?->error("Mappatura corso Moodle non trovata per prodotto durante accodamento. Salto riga.", ['order_id' => $wooOrderId, 'product_id' => $product_id]);
            // Già notificata da findMoodleCourseId
            $allQueuedSuccessfully = false; // Segna che qualcosa è andato storto
            continue; // Salta questa riga articolo
        }
        
        $lastProcessedCourseId = $moodleCourseId; // Aggiorna ultimo corso processato
        
        // Determina il DB Moodle per questo specifico corso
        $currentItemDbName = $moodleDbName;
        // Verifica se $moodleDbName è valido (potrebbe essere null se l'errore è avvenuto prima)
        if (!$currentItemDbName) {
            $log?->error("Nome DB Moodle non disponibile dall'istanza WC durante accodamento. Salto riga.", ['order_id' => $wooOrderId, 'course_id' => $moodleCourseId]);
            $allQueuedSuccessfully = false;
            continue;
        }
        
        // Calcola il costo per singolo record (per unità di quantità)
        // Evita divisione per zero se quantità fosse 0 (anche se abbiamo messo max(1,...))
        $costForThisRecord = ($quantity > 0) ? round($cost_per_item_total / $quantity, 2) : 0;
        $log?->debug("Calcolato costo per unità", ['order_id' => $wooOrderId, 'product_id' => $product_id, 'total_line_cost' => $cost_per_item_total, 'quantity' => $quantity, 'cost_per_unit' => $costForThisRecord]);
        
        // Accoda un record per ogni unità di quantità
        for ($q = 0; $q < $quantity; $q++) {
            $log?->debug("Tentativo accodamento record", ['order_id' => $wooOrderId, 'user_id' => $moodleUserId, 'course_id' => $moodleCourseId, 'moodle_db' => $currentItemDbName, 'quantity_index' => $q+1, 'total_quantity' => $quantity]);
            // Chiama la funzione che gestisce TEST/PROD
            $success = insertIntoMoodlePayments(
                $moodleUserId,      // ID utente trovato
                $moodleCourseId,    // ID corso di questo prodotto
                $currentItemDbName, // DB Moodle di questo corso
                $wooOrderId,        // ID ordine WC come ID pagamento
                $costForThisRecord, // Costo per questa unità
                'woocommerce'       // Metodo
                );
            // Se anche un solo inserimento fallisce, segna l'ordine come non completamente accodato
            if (!$success) {
                // Errore già loggato da insertIntoMoodlePayments o dalle sue sottofunzioni
                $errorMsg = "Fallito accodamento per Ordine WC $wooOrderId, Prodotto $product_id (Quantità " . ($q+1) . "/$quantity). Controllare log per dettagli.";
                // Aggiungi all'errore esistente, se c'è
                $result['error'] = ($result['error'] ? $result['error'] . "; " : "") . $errorMsg;
                $allQueuedSuccessfully = false;
                // Decidiamo di NON interrompere il loop for della quantità, ma potresti volerlo fare
                // break; // Decommenta per fermarti al primo fallimento per questo articolo
            }
        } // fine for quantity
        
        // Se un articolo ha fallito, potresti voler interrompere il loop degli articoli
        // if (!$allQueuedSuccessfully) { break; } // Decommenta per fermarti al primo articolo fallito
        
    } // fine foreach lineItems
    
    $result['success'] = $allQueuedSuccessfully; // Il successo generale dipende da tutti gli inserimenti
    $result['moodleCourseId'] = $lastProcessedCourseId; // Restituisce l'ID dell'ultimo corso tentato
    $log?->info("Risultato accodamento Ordine WC $wooOrderId ($appMode)", ['success' => $result['success'], 'moodle_user_id' => $result['moodleUserId'], 'last_processed_course_id' => $result['moodleCourseId'], 'error_summary' => $result['error']]);
    return $result;
}


/**
 * Placeholder/Implementazione base per inviare notifiche all'admin.
 * Attualmente logga un warning o error.
 *
 * @param string $subject Oggetto della notifica.
 * @param array $details Dettagli aggiuntivi (array associativo).
 */
if (!function_exists('sendAdminNotification')) {
    function sendAdminNotification(string $subject, array $details = []): void {
        global $log; // Usa il logger globale, se disponibile
        
        // Prepara stringa dettagli per il log
        $detailsString = !empty($details) ? print_r($details, true) : 'Nessun dettaglio aggiuntivo.';
        // Crea messaggio di log completo
        $logMessage = "NOTIFICA ADMIN RICHIESTA: [$subject] - Dettagli: $detailsString";
        
        // Determina il livello di log (ERROR per problemi, WARNING per avvisi)
        $logLevel = Logger::WARNING; // Default a WARNING
        if (stripos($subject, 'errore') !== false || stripos($subject, 'fallito') !== false || stripos($subject, 'eccezione') !== false || stripos($subject, 'critico') !== false || stripos($subject, 'impossibile') !== false) {
            $logLevel = Logger::ERROR;
        }
        
        // Usa il logger se disponibile, altrimenti usa error_log di PHP
        if(isset($log)) {
            $log->log($logLevel, $logMessage); // Logga con il livello determinato
        } else {
            // Fallback su error_log se Monolog non è disponibile
            $levelName = Logger::getLevelName($logLevel);
            error_log("ADMIN NOTIFICATION (Logger N/D) [$levelName]: " . $logMessage);
        }
        
        // --- QUI: Implementa l'invio effettivo dell'email ---
        /*
         // Assicurati che le costanti per l'email siano definite in config_db.php
         if (defined('ADMIN_EMAIL_TO') && defined('ADMIN_EMAIL_FROM')) {
         $to = ADMIN_EMAIL_TO;
         $from = ADMIN_EMAIL_FROM;
         $headers = 'From: Notifiche Script PayPal <' . $from . '>' . "\r\n" .
         'Reply-To: ' . $from . "\r\n" .
         'Content-Type: text/plain; charset=utf-8' . "\r\n" .
         'X-Mailer: PHP/' . phpversion();
         $emailBody = "Notifica dallo script PayPal:\n\n";
         $emailBody .= "Oggetto: $subject\n\n";
         $emailBody .= "Dettagli:\n$detailsString\n\n";
         $emailBody .= "Timestamp: " . date('Y-m-d H:i:s');
         
         // Usa la funzione mail() di PHP
         // Attenzione: richiede un server SMTP configurato correttamente su Laragon/server
         // if (!mail($to, "Notifica Script PayPal: " . $subject, $emailBody, $headers)) {
         //     // Logga fallimento invio email
         //     $log?->error("Fallito invio email di notifica admin a $to.");
         // } else {
         //     $log?->info("Inviata email di notifica admin a $to per: $subject");
         // }
         } else {
         $log?->warning("Costanti ADMIN_EMAIL_TO o ADMIN_EMAIL_FROM non definite. Impossibile inviare email admin.");
         }
         */
        // -----------------------------------------------------
        
    }
}

?>