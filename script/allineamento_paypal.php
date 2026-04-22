<?php
/**
 * allineamento_paypal.php
 * Sola Lettura & Standalone (No index.php)
 * 
 * Obiettivo: Analisi transazioni PayPal vs database moodle_payments.
 * Data inizio: 2026-03-01T00:00:00Z
 */

// 1. DIAGNOSTICA
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. DIPENDENZE
require_once '../config_db.php';
require_once '../connect.php';

header('Content-Type: text/html; charset=utf-8');

// --- CONFIGURAZIONE DATE ---
$apiStartTime = '2026-03-01T00:00:00Z';
$apiEndTime   = gmdate('Y-m-d\TH:i:s\Z');

echo "<style>
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #eef2f3; padding: 25px; color: #444; }
    .header { background: #003087; color: white; padding: 20px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    th { background: #0070ba; color: white; padding: 15px; text-align: left; }
    td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
    .badge { padding: 4px 10px; border-radius: 6px; font-weight: bold; font-size: 12px; }
    .badge-ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .badge-missing { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
    .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 30px; }
    .summary-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-top: 4px solid #003087; }
    .summary-card h3 { margin: 0; color: #003087; font-size: 16px; }
</style>";

echo "<div class='header'>
    <h1>🛡️ Standalone PayPal Sync Monitor</h1>
    <span>Analisi transazioni dal <b>March 2026</b> ad oggi.</span>
</div>";

try {
    // --- 3. PAYPAL OAUTH2 TOKEN ---
    // Correzione: PAYPAL_ENVIRONMENT può essere 'LIVE' o 'PRODUCTION'
    $env = defined('PAYPAL_ENVIRONMENT') ? strtoupper(PAYPAL_ENVIRONMENT) : 'SANDBOX';
    $isLive = ($env === 'LIVE' || $env === 'PRODUCTION');
    $host = $isLive ? 'https://api.paypal.com' : 'https://api.sandbox.paypal.com';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $host . "/v1/oauth2/token");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, PAYPAL_CLIENT_ID . ":" . PAYPAL_SECRET);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
    $tokenResult = curl_exec($ch);
    $tokenJson = json_decode($tokenResult);
    
    if (empty($tokenResult) || isset($tokenJson->error)) {
        throw new Exception("ERRORE PAYPAL TOKEN: " . ($tokenJson->error_description ?? 'L\'autenticazione è fallita. Verifica le credenziali e l\'environment in config_db.php (' . $env . ').'));
    }
    $accessToken = $tokenJson->access_token;
    curl_close($ch);

    // --- 4. REPORTING API ---
    $query = http_build_query([
        'start_date' => $apiStartTime,
        'end_date'   => $apiEndTime,
        'fields'     => 'all',
        'page_size'  => 100
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $host . "/v1/reporting/transactions?" . $query);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $accessToken]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $reportResult = curl_exec($ch);
    $reportData = json_decode($reportResult, true);
    curl_close($ch);

    if (!isset($reportData['transaction_details'])) {
        throw new Exception("ERRORE PAYPAL REPORTING: Nessuna transazione trovata o errore API.");
    }

    $transactions = $reportData['transaction_details'];
    
    // --- 5. DATABASE & LOGICA MATCHING ---
    $conn = DBConnector::getMoodleAppsDb();
    if (!$conn) {
        throw new Exception("IMPOSSIBILE CONNETTERSI AL DATABASE moodle_apps.");
    }
    
    echo "<table>
        <thead>
            <tr>
                <th>PayPal TX ID</th>
                <th>Invoice ID</th>
                <th>Order ID</th>
                <th>Stato DB</th>
            </tr>
        </thead>
        <tbody>";

    $stats = ['total' => count($transactions), 'aligned' => 0, 'missing' => 0];

    foreach ($transactions as $t) {
        $info = $t['transaction_info'];
        $txId = $info['transaction_id'];
        $invoiceId = $info['invoice_id'] ?? '--';
        
        $wooOrderId = null;
        if ($invoiceId !== '--') {
            // Mappatura dinamica prefissi (WC_INSTANCE_MAPPING definito in config_db.php)
            foreach (WC_INSTANCE_MAPPING as $prefix => $config) {
                if (strpos($invoiceId, $prefix) === 0) {
                    $candidateId = substr($invoiceId, strlen($prefix));
                    if (ctype_digit($candidateId)) {
                        $wooOrderId = $candidateId;
                        break;
                    }
                }
            }
        }

        $dbStatus = "❓ N/D (ID non identificato)";
        if ($wooOrderId) {
            $stmt = $conn->prepare("SELECT transaction_id FROM moodle_payments WHERE payment_id = ? LIMIT 1");
            $stmt->bind_param("s", $wooOrderId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row) {
                if (!empty($row['transaction_id'])) {
                    $dbStatus = "<span class='badge badge-ok'>✅ Già Allineato</span>";
                    $stats['aligned']++;
                } else {
                    $dbStatus = "<span class='badge badge-missing'>⚠️ ID MANCANTE</span>";
                    $stats['missing']++;
                }
            } else {
                $dbStatus = "🔍 Non trovato in DB";
            }
            $stmt->close();
        }

        echo "<tr>
            <td><code>$txId</code></td>
            <td><code>$invoiceId</code></td>
            <td><b>" . ($wooOrderId ?? '--') . "</b></td>
            <td>$dbStatus</td>
        </tr>";
    }

    echo "</tbody></table>";

    // --- 6. BOX RIEPILOGO ---
    echo "<div class='summary'>
        <div class='summary-card'><h3>📦 Transazioni Totali</h3><p>{$stats['total']}</p></div>
        <div class='summary-card'><h3>✅ Già Allineate</h3><p>{$stats['aligned']}</p></div>
        <div class='summary-card'><h3>🚨 ID Mancanti</h3><p style='color:#d9534f; font-weight:bold;'>{$stats['missing']}</p></div>
    </div>";

} catch (Exception $e) {
    echo "<div style='background:#f8d7da; color:#721c24; padding:20px; border-radius:10px; border:1px solid #f5c6cb; margin-top:20px;'>
        <strong>⚠️ Errore durante l'esecuzione stand-alone:</strong><br>" . $e->getMessage() . "
    </div>";
}