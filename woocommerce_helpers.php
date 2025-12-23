<?php
/**
 * woocommerce_helpers.php
 * Gestisce interazioni con DB WooCommerce e Moodle Payments.
 * FIX: Supporto dinamico per chiave CF (es. cf_user) e gestione corretta date.
 */
use Monolog\Logger;

// --- FUNZIONI HELPER ---

function checkWooOrderAlreadyQueued(string $wooOrderId): bool
{
    global $log;
    $conn = DBConnector::getMoodleAppsDb();
    if (! $conn) return true;
    
    $sql = "SELECT id FROM moodle_payments WHERE payment_id = ? LIMIT 1";
    $exists = true;
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $wooOrderId);
            if ($stmt->execute()) {
                $stmt->store_result();
                $exists = ($stmt->num_rows > 0);
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        if (isset($log)) $log->error("ECCEZIONE SQL CheckWooOrder: " . $e->getMessage());
        $exists = true;
    }
    return $exists;
}

function getBacsOrderIdsFromDB(string $wpDbName, string $wpPrefix, string $startDate, string $endDate, array $statuses = ['wc-processing']): array
{
    global $log;
    $orderIds = [];
    $connWp = DBConnector::getWpDbByName($wpDbName);
    if (! $connWp) return [];
    
    $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT id FROM {$wpPrefix}wc_orders
            WHERE payment_method = 'bacs'
            AND date_created_gmt BETWEEN ? AND ?
            AND status IN ($statusPlaceholders)";
    
    try {
        $stmt = $connWp->prepare($sql);
        if ($stmt) {
            $types = "ss" . str_repeat("s", count($statuses));
            $params = array_merge([$startDate, $endDate], $statuses);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $orderIds[] = $row['id'];
            }
            $stmt->close();
        }
        if (! empty($orderIds)) {
            $log?->info("Trovati " . count($orderIds) . " bonifici in $wpDbName.");
        }
    } catch (Exception $e) {
        $log?->error("Errore ricerca bonifici in $wpDbName: " . $e->getMessage());
    }
    return $orderIds;
}

/**
 * Recupera i dettagli dell'ordine e cerca il CF nella chiave specificata ($targetCfKey).
 */
function getWooCommerceOrderDetails_FROM_DB(string $wooOrderId, string $wpDbName, string $wpPrefix, string $targetCfKey = 'billing_cf'): ?array
{
    global $log;
    $connWp = DBConnector::getWpDbByName($wpDbName);
    if (! $connWp) return null;
    
    $orderDetails = null;
    try {
        // 1. Ordine
        $sqlOrder = "SELECT id, status, date_created_gmt, date_updated_gmt, total_amount FROM {$wpPrefix}wc_orders WHERE id = ? LIMIT 1";
        $stmtOrder = $connWp->prepare($sqlOrder);
        
        if (! $stmtOrder) { throw new Exception("Errore SQL Prepare (Order): " . $connWp->error); }
        
        $stmtOrder->bind_param("i", $wooOrderId);
        $stmtOrder->execute();
        $resultOrder = $stmtOrder->get_result();
        $orderData = $resultOrder->fetch_assoc();
        $stmtOrder->close();
        
        if (! $orderData) {
            $log?->warning("Ordine $wooOrderId non trovato in {$wpPrefix}wc_orders.");
            return null;
        }
        
        $orderDetails = $orderData;
        $orderDetails['status'] = str_replace('wc-', '', $orderData['status']);
        $orderDetails['total'] = number_format((float) $orderData['total_amount'], 2, '.', '');
        $orderDetails['date_updated_gmt'] = $orderData['date_updated_gmt'] ?? null;
        
        // 2. Metadati (CF e Data Bonifico)
        $orderDetails['billing']['cf'] = null;
        $orderDetails['bacs_date'] = null;
        
        $sqlMeta = "SELECT meta_key, meta_value FROM {$wpPrefix}wc_orders_meta WHERE order_id = ?";
        $stmtMeta = $connWp->prepare($sqlMeta);
        
        if ($stmtMeta) {
            $stmtMeta->bind_param("i", $wooOrderId);
            $stmtMeta->execute();
            $resultMeta = $stmtMeta->get_result();
            
            while ($rowMeta = $resultMeta->fetch_assoc()) {
                
                // A. Cerca la chiave passata da config (es. 'cf_user')
                if ($rowMeta['meta_key'] === $targetCfKey) {
                    $orderDetails['billing']['cf'] = $rowMeta['meta_value'];
                }
                
                // B. Fallback: cerca 'billing_cf' standard se non l'ha ancora trovato
                if (empty($orderDetails['billing']['cf']) && $rowMeta['meta_key'] === 'billing_cf') {
                    $orderDetails['billing']['cf'] = $rowMeta['meta_value'];
                }
                
                // C. Data Bonifico
                if ($rowMeta['meta_key'] === 'bacs_date') {
                    $orderDetails['bacs_date'] = $rowMeta['meta_value'];
                }
            }
            $stmtMeta->close();
        }
        
        // 3. Items
        $lineItems = [];
        $sqlItems = "SELECT product_id, product_qty, product_net_revenue, tax_amount FROM {$wpPrefix}wc_order_product_lookup WHERE order_id = ?";
        $stmtItems = $connWp->prepare($sqlItems);
        
        if ($stmtItems) {
            $stmtItems->bind_param("i", $wooOrderId);
            $stmtItems->execute();
            $resultItems = $stmtItems->get_result();
            while ($rowItem = $resultItems->fetch_assoc()) {
                $lineTotal = (float) ($rowItem['product_net_revenue'] ?? 0) + (float) ($rowItem['tax_amount'] ?? 0);
                $lineItems[] = [
                    'product_id' => (int) $rowItem['product_id'],
                    'quantity' => (int) $rowItem['product_qty'],
                    'total' => number_format($lineTotal, 2, '.', '')
                ];
            }
            $stmtItems->close();
        }
        $orderDetails['line_items'] = $lineItems;
        
    } catch (Exception $e) {
        $log?->error("Errore dettagli ordine $wooOrderId: " . $e->getMessage());
        return null;
    }
    return $orderDetails;
}

function findMoodleCourseId(int $productId, string $wpDbName, string $wpPrefix): ?int
{
    global $log;
    $connWp = DBConnector::getWpDbByName($wpDbName);
    if (! $connWp) return null;
    
    $courseId = null;
    try {
        $sql = "SELECT meta_value FROM {$wpPrefix}postmeta WHERE post_id = ? AND meta_key = 'moodle_course_id' LIMIT 1";
        $stmt = $connWp->prepare($sql);
        $stmt->bind_param("i", $productId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && is_numeric($row['meta_value'])) {
            $courseId = (int) $row['meta_value'];
        }
        $stmt->close();
    } catch (Exception $e) {}
    return $courseId;
}

function findMoodleUserByCF(string $cf, string $moodleDbName): ?int
{
    global $log;
    if (! defined('MOODLE_CF_SHORTNAME')) return null;
    $connMoodle = DBConnector::getMoodleDbByName($moodleDbName);
    if (! $connMoodle) return null;
    
    $userId = null;
    try {
        $sqlField = "SELECT id FROM mdl_user_info_field WHERE shortname = ? LIMIT 1";
        $stmtField = $connMoodle->prepare($sqlField);
        $cfShort = MOODLE_CF_SHORTNAME;
        $stmtField->bind_param("s", $cfShort);
        $stmtField->execute();
        $fieldRow = $stmtField->get_result()->fetch_assoc();
        $stmtField->close();
        
        if ($fieldRow) {
            $fieldId = $fieldRow['id'];
            $sqlData = "SELECT userid FROM mdl_user_info_data WHERE fieldid = ? AND UPPER(data) = UPPER(?) LIMIT 1";
            $stmtData = $connMoodle->prepare($sqlData);
            $stmtData->bind_param("is", $fieldId, $cf);
            $stmtData->execute();
            $dataRow = $stmtData->get_result()->fetch_assoc();
            if ($dataRow) $userId = $dataRow['userid'];
            $stmtData->close();
        }
    } catch (Exception $e) {}
    return $userId;
}

// Inserimento Helper
function insertIntoMoodlePayments(int $userId, int $courseId, string $dbName, string $paymentId, float $cost, string $method, ?string $customDate = null): bool
{
    $appMode = defined('APP_MODE') ? APP_MODE : 'PRODUCTION';
    if ($appMode === 'TEST') {
        return insertIntoMoodlePayments_TEST_CSV($userId, $courseId, $dbName, $paymentId, $cost, $method, $customDate);
    } else {
        return insertIntoMoodlePayments_PROD($userId, $courseId, $dbName, $paymentId, $cost, $method, $customDate);
    }
}

function insertIntoMoodlePayments_TEST_CSV(int $userId, int $courseId, string $dbName, string $paymentId, float $cost, string $method, ?string $customDate = null): bool
{
    if (! defined('TEST_OUTPUT_FILE')) return false;
    $timestampToWrite = $customDate ? $customDate : date('Y-m-d H:i:s');
    $logLine = [$timestampToWrite, $userId, $courseId, $dbName, $paymentId, number_format($cost, 2, '.', ''), $method, '0'];
    $fileHandle = fopen(TEST_OUTPUT_FILE, 'a');
    if ($fileHandle === false) return false;
    fputcsv($fileHandle, $logLine, ';');
    fclose($fileHandle);
    return true;
}

function insertIntoMoodlePayments_PROD($userId, $courseId, $moodleDbName, $paymentId, $cost, $method, $dateToUse = null): bool
{
    global $log;
    $conn = DBConnector::getMoodleAppsDb();
    if (! $conn) {
        $log?->error("PROD INSERT: Connessione DB persa/nulla.");
        return false;
    }
    
    $finalDate = $dateToUse ? $dateToUse : date('Y-m-d H:i:s');
    $sql = "INSERT INTO moodle_payments (userid, courseid, payment_id, cost, method, data_ins, mdl) VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    try {
        $stmt = $conn->prepare($sql);
        if (! $stmt) {
            $log?->error("ERRORE SQL PREPARE (Ordine $paymentId): " . $conn->error);
            return false;
        }
        
        $userId = (int) $userId;
        $courseId = (int) $courseId;
        $paymentId = (string) $paymentId;
        $cost = (float) $cost;
        $method = (string) $method;
        $finalDate = (string) $finalDate;
        $moodleDbName = (string) $moodleDbName;
        
        $stmt->bind_param("iisdsss", $userId, $courseId, $paymentId, $cost, $method, $finalDate, $moodleDbName);
        
        if ($stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            $log?->error("ERRORE SQL EXECUTE (Ordine $paymentId, Corso $courseId): " . $stmt->error);
            $stmt->close();
            return false;
        }
    } catch (Exception $e) {
        $log?->error("ECCEZIONE Insert PROD: " . $e->getMessage());
        return false;
    }
}

function queueWooOrderForProcessing(string $wooOrderId, array $instanceConfig): array
{
    global $log;
    $result = ['success' => false, 'error' => null, 'moodleUserId' => null, 'moodleCourseId' => null];
    
    if (empty($instanceConfig['wc_db_name']) || empty($instanceConfig['wc_db_prefix'])) {
        $result['error'] = "Configurazione incompleta.";
        return $result;
    }
    
    // 1. Recuperiamo la chiave dal config (default 'billing_cf' se manca)
    $cfMetaKey = $instanceConfig['cf_meta_key'] ?? 'billing_cf';
    
    // 2. CHIAMATA CORRETTA ALLA FUNZIONE (Passiamo il parametro 4)
    $orderDetails = getWooCommerceOrderDetails_FROM_DB(
        $wooOrderId,
        $instanceConfig['wc_db_name'],
        $instanceConfig['wc_db_prefix'],
        $cfMetaKey // <--- FONDAMENTALE: Passiamo la chiave (es 'cf_user')
        );
    
    if (! $orderDetails) {
        $result['error'] = "Dettagli ordine non trovati.";
        return $result;
    }
    
    // --- LOGICA GESTIONE DATE ---
    $dateToUse = null;
    $isPayPal = isset($instanceConfig['is_paypal']) && $instanceConfig['is_paypal'] === true;
    
    if (! empty($orderDetails['bacs_date'])) {
        // CASO 1: BONIFICO con Data Manuale
        $dateObj = DateTime::createFromFormat('d/m/Y', $orderDetails['bacs_date']);
        if ($dateObj) {
            $limiteTemporale = new DateTime('first day of last month');
            $limiteTemporale->setTime(0, 0, 0);
            $checkDate = clone $dateObj;
            $checkDate->setTime(0, 0, 0);
            
            if ($checkDate < $limiteTemporale) {
                $errorMsg = "SKIP: Data Bonifico ({$orderDetails['bacs_date']}) troppo vecchia.";
                if (isset($log)) $log->info($errorMsg);
                $result['error'] = $errorMsg;
                return $result;
            }
            
            $hour = 12; $min = 0; $sec = 0;
            if (! empty($orderDetails['date_updated_gmt'])) {
                try {
                    $updatedObj = new DateTime($orderDetails['date_updated_gmt']);
                    $hour = (int) $updatedObj->format('H');
                    $min = (int) $updatedObj->format('i');
                    $sec = (int) $updatedObj->format('s');
                } catch (Exception $e) {}
            }
            $dateObj->setTime($hour, $min, $sec);
            $dateToUse = $dateObj->format('Y-m-d H:i:s');
        } else {
            $errorMsg = "Data bonifico formato errato ($wooOrderId): " . $orderDetails['bacs_date'];
            $log?->error($errorMsg);
            $result['error'] = $errorMsg;
            return $result;
        }
    } else {
        // CASO 2: NO DATA MANUALE (PayPal o Bonifico incompleto)
        if (! $isPayPal) {
            $errorMsg = "Data Bonifico mancante per ordine $wooOrderId. Processo bloccato.";
            $log?->warning($errorMsg);
            $result['error'] = $errorMsg;
            return $result;
        }
        if ($isPayPal && ! empty($orderDetails['date_updated_gmt'])) {
            $dateToUse = $orderDetails['date_updated_gmt'];
        }
    }
    
    $billing_cf = trim($orderDetails['billing']['cf'] ?? '');
    if (empty($billing_cf)) {
        $result['error'] = "CF mancante (Cercato in: $cfMetaKey)";
        return $result;
    }
    
    $lineItems = $orderDetails['line_items'] ?? [];
    if (empty($lineItems)) {
        $result['error'] = "Nessun articolo";
        return $result;
    }
    
    // Ricerca Utente Moodle
    $moodleUserId = null;
    $moodleDbName = $instanceConfig['moodle_db_name'] ?? null;
    
    foreach ($lineItems as $item) {
        if (! empty($item['product_id'])) {
            $courseId = findMoodleCourseId($item['product_id'], $instanceConfig['wc_db_name'], $instanceConfig['wc_db_prefix']);
            if ($courseId && $moodleDbName) {
                $userId = findMoodleUserByCF($billing_cf, $moodleDbName);
                if ($userId) {
                    $moodleUserId = $userId;
                    break;
                }
            }
        }
    }
    
    if (! $moodleUserId) {
        $result['error'] = "Utente Moodle non trovato per CF $billing_cf su DB $moodleDbName";
        return $result;
    }
    $result['moodleUserId'] = $moodleUserId;
    
    $methodLabel = $isPayPal ? 'woocommerce' : 'manual';
    $allQueuedSuccessfully = true;
    
    foreach ($lineItems as $item) {
        $productId = $item['product_id'] ?? 0;
        if (! $productId) continue;
        
        $moodleCourseId = findMoodleCourseId($productId, $instanceConfig['wc_db_name'], $instanceConfig['wc_db_prefix']);
        if (! $moodleCourseId) {
            $allQueuedSuccessfully = false;
            $errorMsg = "Corso Moodle non trovato per Prodotto ID: $productId (Ordine $wooOrderId)";
            $result['error'] = $errorMsg;
            $log?->error($errorMsg);
            continue;
        }
        
        $result['moodleCourseId'] = $moodleCourseId;
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        $costPerUnit = ($qty > 0) ? round(((float) $item['total']) / $qty, 2) : 0;
        
        for ($q = 0; $q < $qty; $q ++) {
            $success = insertIntoMoodlePayments($moodleUserId, $moodleCourseId, $moodleDbName, $wooOrderId, $costPerUnit, $methodLabel, date('Y-m-d H:i:s'));
            if (! $success) {
                $allQueuedSuccessfully = false;
                $errorMsg = "Errore insertIntoMoodlePayments per Ordine $wooOrderId, Corso $moodleCourseId";
                $result['error'] = $errorMsg;
                $log?->error($errorMsg);
            }
        }
    }
    
    $result['success'] = $allQueuedSuccessfully;
    return $result;
}
?>