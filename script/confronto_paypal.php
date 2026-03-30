<?php
/**
 * confronto_paypal.php - Interfaccia di Riconciliazione (V.5)
 * Database Centralizzato: moodle_payments e results nello stesso DB.
 */

// Credenziali Database Centralizzato
$dbConfig = [
    'host' => '192.168.11.16',
    'dbname' => 'mdlapps_moodleadmin',
    'user' => 'mdlapps',
    'pass' => 'RmnPbT78'
];

try {
    // Connessione PDO Unica
    $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Errore di connessione al database: " . $e->getMessage());
}

require_once '../config_db.php'; // Carica costanti PayPal (CLIENT_ID, SECRET)

class PayPalScanner {
    private $clientId;
    private $clientSecret;
    private $apiBase;
    private $accessToken;
    
    public function __construct() {
        $this->clientId = PAYPAL_CLIENT_ID;
        $this->clientSecret = PAYPAL_SECRET;
        $this->apiBase = (PAYPAL_ENVIRONMENT === 'PRODUCTION') ? "https://api-m.paypal.com" : "https://api-m.sandbox.paypal.com";
        $this->accessToken = $this->getAccessToken();
    }
    
    private function getAccessToken() {
        $ch = curl_init($this->apiBase . "/v1/oauth2/token");
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ":" . $this->clientSecret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $result['access_token'] ?? null;
    }
    
    public function getPayments($start, $end) {
        $url = $this->apiBase . "/v1/reporting/transactions?start_date=$start&end_date=$end&fields=all&page_size=100&transaction_status=S";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $this->accessToken]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $data['transaction_details'] ?? [];
    }
}

$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
$startDate = gmdate('Y-m-d\TH:i:s\Z', strtotime("-$days days"));
$endDate = gmdate('Y-m-d\TH:i:s\Z');

$scanner = new PayPalScanner();
$paypalTxs = $scanner->getPayments($startDate, $endDate);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Riconciliazione PayPal Centralizzata</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; padding: 20px; }
        h1 { color: #1a3a5a; border-bottom: 2px solid #1a3a5a; padding-bottom: 10px; }
        .stats { margin-bottom: 15px; font-weight: bold; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 4px 8px rgba(0,0,0,0.1); font-size: 13px; }
        th { background: #1a3a5a; color: white; padding: 12px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        tr:hover { background-color: #f8f9fa; }
        code { background: #eee; padding: 2px 4px; border-radius: 4px; color: #d63384; font-weight: bold; }
        .badge { padding: 3px 7px; border-radius: 4px; font-weight: bold; display: inline-block; font-size: 10px; text-transform: uppercase; }
        
        .badge-container { display: flex; gap: 5px; justify-content: center; align-items: center; }
        
        .source-moodle { background: #28a745; color: white; }
        .source-woo { background: #fd7e14; color: white; }
        .source-none { background: #dc3545; color: white; }
        
        .table-label { background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6; }
        
        .btn-detail { background: #007bff; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; }
        .btn-detail:hover { background: #0056b3; }
        
        .detail-row { display: none; background: #fefefe; }
        .detail-content { padding: 15px; border: 1px solid #007bff; border-top: none; display: flex; gap: 40px; }
        .detail-box b { color: #1a3a5a; display: block; margin-bottom: 5px; font-size: 11px; text-transform: uppercase; }
        .detail-box p { margin: 0; font-size: 13px; color: #333; }
    </style>
    <script>
        function toggleDetails(id) {
            var row = document.getElementById('details-' + id);
            row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
        }
    </script>
</head>
<body>

    <h1>📊 Pannello Controllo PayPal Centralizzato</h1>
    <div class="stats">
        Periodo: <?php echo date('d/m/Y', strtotime("-$days days")); ?> ➔ Oggi | 
        Transazioni PayPal: <?php echo count($paypalTxs); ?>
    </div>

    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Transaction ID</th>
                <th>Prodotto / Causale</th>
                <th>Cliente</th>
                <th style="text-align:right">Importo</th>
                <th style="text-align:center">Stato Sistema</th>
                <th style="text-align:center">Azione</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($paypalTxs as $index => $tx): 
                $info = $tx['transaction_info'];
                $payerData = $tx['payer_info'];
                $txId = trim($info['transaction_id']);
                $amt = (float)$info['transaction_amount']['value'];
                $currency = $info['transaction_amount']['currency_code'];
                $date = date('d/m/Y H:i', strtotime($info['transaction_initiation_date']));
                $itemName = $tx['cart_info']['item_details'][0]['item_name'] ?? 'N/A';
                $payerFullName = $payerData['payer_name']['alternate_full_name'] ?? 'N/D';
                $payerEmail = $payerData['email_address'] ?? 'N/D';
                
                // Indirizzo
                $addr = $tx['shipping_info']['address'] ?? [];
                $fullAddr = ($addr['line1'] ?? '') . ", " . ($addr['city'] ?? '') . " (" . ($addr['state'] ?? '') . ") " . ($addr['postal_code'] ?? '');

                // LOGICA WATERFALL SEMPLIFICATA
                $fonte = "";
                $classeFonte = "";
                $tabella = "";

                // STEP 1: Cerca in moodle_payments
                $stmt1 = $pdo->prepare("SELECT mdl FROM moodle_payments WHERE transaction_id LIKE :txId LIMIT 1");
                $stmt1->execute(['txId' => $txId]);
                $res1 = $stmt1->fetch();

                if ($res1) {
                    $fonte = "MOODLE";
                    $classeFonte = "source-moodle";
                    $tabella = "moodle_payments (" . ($res1['mdl'] ?: 'N/A') . ")";
                } else {
                    // STEP 2: Cerca in results
                    $stmt2 = $pdo->prepare("SELECT id FROM results WHERE transaction_id LIKE :txId LIMIT 1");
                    $stmt2->execute(['txId' => $txId]);
                    $res2 = $stmt2->fetch();

                    if ($res2) {
                        $fonte = "WOOCOMMERCE";
                        $classeFonte = "source-woo";
                        $tabella = "results";
                    } else {
                        // FALLBACK: Non Trovato
                        $fonte = "ASSENTE";
                        $classeFonte = "source-none";
                        $tabella = "Nessun Match";
                    }
                }
            ?>
            <tr id="row-<?php echo $index; ?>">
                <td><?php echo $date; ?></td>
                <td><code><?php echo $txId; ?></code></td>
                <td><?php echo $itemName; ?></td>
                <td><?php echo $payerFullName; ?></td>
                <td align="right"><strong><?php echo number_format($amt, 2); ?> <?php echo $currency; ?></strong></td>
                <td align="center">
                    <div class="badge-container">
                        <span class="badge <?php echo $classeFonte; ?>"><?php echo $fonte; ?></span>
                        <span class="badge table-label"><?php echo $tabella; ?></span>
                    </div>
                </td>
                <td align="center">
                    <button class="btn-detail" onclick="toggleDetails(<?php echo $index; ?>)">🔍 Dettagli</button>
                </td>
            </tr>
            <tr id="details-<?php echo $index; ?>" class="detail-row">
                <td colspan="7">
                    <div class="detail-content">
                        <div class="detail-box">
                            <b>Contatti Cliente</b>
                            <p>📧 <?php echo $payerEmail; ?></p>
                            <p>📞 <?php if (isset($payerData['phone_number'])) echo "+" . ($payerData['phone_number']['country_code'] ?? '') . " " . ($payerData['phone_number']['national_number'] ?? ''); else echo 'N/D'; ?></p>
                        </div>
                        <div class="detail-box">
                            <b>Indirizzo di Spedizione</b>
                            <p>🏠 <?php echo $fullAddr; ?></p>
                        </div>
                        <div class="detail-box">
                            <b>Dati Tecnici PayPal</b>
                            <p>ID Account: <code><?php echo $payerData['account_id'] ?? 'N/D'; ?></code></p>
                            <p>Invoice ID: <code><?php echo $info['invoice_id'] ?? 'N/A'; ?></code></p>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top: 20px;">
        <a href="?days=7">Ultima settimana</a> | <a href="?days=30">Ultimo mese</a>
    </p>

</body>
</html>