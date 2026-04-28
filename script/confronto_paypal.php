<?php
/**
 * confronto_paypal.php - Interfaccia di Riconciliazione (V.5)
 * Database Centralizzato: moodle_payments e results nello stesso DB.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Connessione 1 (Moodle New)
try {
    $pdo_new = new PDO("mysql:host=192.168.11.16;dbname=mdlapps_moodleadmin;charset=utf8mb4", 'mdlapps', 'RmnPbT78', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Errore connessione Moodle New: " . $e->getMessage());
}

// Connessione 2 (Moodle Old)
try {
    $pdo_old = new PDO("mysql:host=dbmoodle.met.dmz;dbname=mdlapps_moodleadmin;charset=utf8mb4", 'moodle', 'RmnPbT78', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Errore connessione Moodle Old: " . $e->getMessage());
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
    
    public function getPayments($start, $end, $page = 1, $pageSize = 20) {
        $url = $this->apiBase . "/v1/reporting/transactions?start_date=$start&end_date=$end&fields=all&page_size=$pageSize&page=$page&transaction_status=S";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $this->accessToken]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $data;
    }

    public function getAllPayments($start, $end) {
        // Scarica TUTTE le transazioni in un colpo (PayPal supporta max 500 per pagina)
        $url = $this->apiBase . "/v1/reporting/transactions?start_date=$start&end_date=$end&fields=all&page_size=500&page=1&transaction_status=S";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $this->accessToken]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $data['transaction_details'] ?? [];
    }
}

// LOGICA FILTRO DATE (Specifiche Utente)
$startDateRaw = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$endDateRaw = $_GET['end'] ?? date('Y-m-d');

// Converti in ISO-8601 per l'API PayPal
// Se è oggi, usa l'ora attuale UTC (Z), altrimenti fine giornata (23:59:59Z)
$startDate = $startDateRaw . 'T00:00:00Z';
if ($endDateRaw === date('Y-m-d')) {
    $endDate = gmdate('Y-m-d\TH:i:s\Z');
} else {
    $endDate = $endDateRaw . 'T23:59:59Z';
}

// Paginazione e Filtri
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$pageSize = 20;
$filterHost = $_GET['host'] ?? 'All';
$sortOrder  = in_array($_GET['sort'] ?? '', ['asc', 'desc']) ? $_GET['sort'] : 'desc';
$filterTxnId = trim($_GET['txn_id'] ?? '');

$scanner = new PayPalScanner();

// Scarica SEMPRE tutti i record (max 500) per garantire ordinamento e paginazione globale corretti
$paypalTxs = $scanner->getAllPayments($startDate, $endDate);
$totalItems = count($paypalTxs);
$totalPages = 1; // verrà ricalcolato dopo filtro + ordinamento

// PRE-PROCESSING: Riconciliazione DB su tutte le transazioni caricate
// (indispensabile quando il filtro host è attivo, per poter paginare sui risultati filtrati)
$checkDb = function($pdo, $table, $txId) {
    if ($table === 'moodle_payments') {
        $stmt = $pdo->prepare("SELECT mp.id, mp.sales, mp.logfile, i.nfattura 
                               FROM moodle_payments mp 
                               LEFT JOIN invoice i ON mp.id = i.moodle_payment_id 
                               WHERE mp.transaction_id = :txId LIMIT 1");
    } else {
        $stmt = $pdo->prepare("SELECT id FROM $table WHERE transaction_id = :txId LIMIT 1");
    }
    $stmt->execute(['txId' => $txId]);
    return $stmt->fetch();
};

$processedTxs = [];
foreach ($paypalTxs as $tx) {
    $info    = $tx['transaction_info'];
    $txId    = trim($info['transaction_id']);
    $itemName = $tx['cart_info']['item_details'][0]['item_name'] ?? 'N/A';

    $tabella    = '';
    $hostTrovato = '';

    $matchData = null;
    if ($matchData = $checkDb($pdo_new, 'moodle_payments', $txId)) {
        $tabella = 'moodle_payments'; $hostTrovato = 'Moodle New';
    } elseif ($matchData = $checkDb($pdo_old, 'moodle_payments', $txId)) {
        $tabella = 'moodle_payments'; $hostTrovato = 'Moodle Old';
    } elseif ($matchData = $checkDb($pdo_new, 'results', $txId)) {
        $tabella = 'results'; $hostTrovato = 'Moodle New';
    } elseif ($matchData = $checkDb($pdo_old, 'results', $txId)) {
        $tabella = 'results'; $hostTrovato = 'Moodle Old';
    } else {
        $tabella = 'Nessun Match'; $hostTrovato = 'N/A';
    }

    // FILTRO TIPO TRANSAZIONE: Includiamo solo i pagamenti ricevuti (Entrate)
    // T0006 = Checkout payment (standard)
    // T0005 = Personal payment receipt (studenti diretto)
    // T0007 = Website Payments Pro (Carta Credito)
    $type = $info['transaction_event_code'] ?? '';
    $amt  = (float)($info['transaction_amount']['value'] ?? 0);
    $inflowTypes = ['T0005', 'T0006', 'T0007'];
    if (!in_array($type, $inflowTypes) || $amt <= 0) continue;

    // Regola d'oro etichetta
    if ($tabella !== 'Nessun Match') {
        $isOld = ($hostTrovato === 'Moodle Old');
        if (!$isOld) {
            foreach (WC_INSTANCE_MAPPING as $prefix => $mapped) {
                if (str_starts_with($itemName, $prefix) && $mapped['host'] === 'dbmoodle.met.dmz') {
                    $isOld = true; break;
                }
            }
        }
        $fonte      = $isOld ? 'MOODLE' : 'WOOCOMMERCE';
        $classeFonte = $isOld ? 'source-moodle' : 'source-woo';
    } else {
        $fonte = 'ASSENTE'; $classeFonte = 'source-none';
    }

    // Applica filtro host
    if ($filterHost === 'ASSENTE'   && $hostTrovato !== 'N/A')       continue;
    if ($filterHost === 'Moodle New' && $hostTrovato !== 'Moodle New') continue;
    if ($filterHost === 'Moodle Old' && $hostTrovato !== 'Moodle Old') continue;
    if ($filterHost === 'ERRORE'     && ($tabella !== 'moodle_payments' || ($matchData['sales'] ?? 1) != 0)) continue;

    // Applica filtro Transaction ID (Backend search)
    if ($filterTxnId !== '' && stripos($txId, $filterTxnId) === false) continue;

    $processedTxs[] = array_merge($tx, [
        '_tabella'    => $tabella,
        '_hostTrovato' => $hostTrovato,
        '_fonte'      => $fonte,
        '_classeFonte' => $classeFonte,
        '_nfattura'    => $matchData['nfattura'] ?? '',
        '_sales'       => $matchData['sales'] ?? null,
        '_logfile'     => $matchData['logfile'] ?? null,
        '_db_id'       => $matchData['id'] ?? null,
    ]);
}

// Ordinamento globale per data PRIMA della paginazione
usort($processedTxs, function($a, $b) use ($sortOrder) {
    $ta = strtotime($a['transaction_info']['transaction_initiation_date']);
    $tb = strtotime($b['transaction_info']['transaction_initiation_date']);
    return $sortOrder === 'asc' ? $ta - $tb : $tb - $ta;
});

// --- LOGICA ESPORTAZIONE EXCEL ---
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Riconciliazione PayPal');

    // Intestazioni
    $headers = ['Data', 'Transaction ID', 'Prodotto / Causale', 'Cliente', 'Email', 'Importo', 'Valuta', 'N. Fattura', 'Stato', 'Sistema', 'Tabella'];
    foreach ($headers as $i => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . '1';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true);
    }

    $rowNum = 2;
    foreach ($processedTxs as $tx) {
        $info = $tx['transaction_info'];
        $payerData = $tx['payer_info'];
        
        // Calcolo Stato (Invoice/Evasione)
        $labelStato = '-';
        if ($tx['_tabella'] === 'moodle_payments') {
            $nf = $tx['_nfattura'];
            $sales = $tx['_sales'];
            $logs = $tx['_logfile'];
            if ($sales == 1) $labelStato = $nf ?: 'EVASO';
            elseif ($sales == 0 && empty($logs)) $labelStato = 'DA EVADERE';
            elseif ($sales == 0 && !empty($logs)) $labelStato = 'ERRORE';
        }

        $sheet->setCellValue('A' . $rowNum, date('d/m/Y H:i', strtotime($info['transaction_initiation_date'])));
        $sheet->setCellValue('B' . $rowNum, trim($info['transaction_id']));
        $sheet->setCellValue('C' . $rowNum, $tx['cart_info']['item_details'][0]['item_name'] ?? 'N/A');
        $sheet->setCellValue('D' . $rowNum, $payerData['payer_name']['alternate_full_name'] ?? 'N/D');
        $sheet->setCellValue('E' . $rowNum, $payerData['email_address'] ?? 'N/D');
        $sheet->setCellValue('F' . $rowNum, (float)$info['transaction_amount']['value']);
        $sheet->setCellValue('G' . $rowNum, $info['transaction_amount']['currency_code']);
        $sheet->setCellValue('H' . $rowNum, $tx['_nfattura'] ?? '');
        $sheet->setCellValue('I' . $rowNum, $labelStato);
        $sheet->setCellValue('J' . $rowNum, $tx['_fonte']);
        $sheet->setCellValue('K' . $rowNum, $tx['_tabella']);
        
        $rowNum++;
    }

    // Auto-size colonne
    foreach (range('A', 'K') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="confronto_paypal_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// Ricalcola paginazione sui risultati filtrati e ordinati
$totalFiltered = count($processedTxs);
$totalPages    = max(1, (int)ceil($totalFiltered / $pageSize));
$totalItems    = $totalFiltered;
// Clamp pagina corrente al range valido
if ($page > $totalPages) $page = $totalPages;
// Slice della pagina corrente
$processedTxs = array_slice($processedTxs, ($page - 1) * $pageSize, $pageSize);
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
        
        .badge-container { display: flex; gap: 5px; justify-content: flex-start; align-items: center; padding-left: 15px; }
        
        .source-moodle { background: #28a745; color: white; }
        .source-woo { background: #fd7e14; color: white; }
        .source-none { background: #dc3545; color: white; }
        
        .table-label { background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6; }
        .table-label-green { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .table-label-yellow { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .table-label-red { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .btn-detail { background: #007bff; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 11px; }
        .btn-detail:hover { background: #0056b3; }
        
        .detail-row { display: none; background: #fefefe; }
        .detail-content { padding: 15px; border: 1px solid #007bff; border-top: none; display: flex; gap: 40px; }
        .detail-box b { color: #1a3a5a; display: block; margin-bottom: 5px; font-size: 11px; text-transform: uppercase; }
        .detail-box p { margin: 0; font-size: 13px; color: #333; }

        /* Filtri & Paginazione */
        .filter-bar { background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; gap: 15px; align-items: flex-end; }
        .filter-bar label { display: block; font-size: 11px; font-weight: bold; color: #666; margin-bottom: 5px; }
        .filter-bar input { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .btn-filter { background: #1a3a5a; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; }
        .btn-filter:hover { background: #2c5179; }

        .pagination { margin-top: 20px; display: flex; justify-content: center; gap: 5px; }
        .page-link { padding: 5px 10px; border: 1px solid #ddd; background: white; text-decoration: none; color: #1a3a5a; border-radius: 4px; }
        .page-link.active { background: #1a3a5a; color: white; border-color: #1a3a5a; }
        .page-link:hover:not(.active) { background: #f0f2f5; }

        .btn-sap { background: #28a745; color: white; text-decoration: none; padding: 5px 10px; border-radius: 4px; font-size: 11px; display: inline-block; margin-top: 5px; }
        .btn-sap:hover { background: #218838; color: white; }
        .btn-log { background: #6c757d; color: white; text-decoration: none; padding: 5px 10px; border-radius: 4px; font-size: 11px; display: inline-block; margin-top: 5px; }
        .btn-log:hover { background: #5a6268; color: white; }
    </style>
    <script>
        function toggleDetails(id) {
            var row = document.getElementById('details-' + id);
            row.style.display = (row.style.display === 'table-row') ? 'none' : 'table-row';
        }

        // Filtro Real-time (Autocomplete style)
        function liveFilter() {
            var input = document.getElementById('txn_id_input');
            var filter = input.value.toUpperCase();
            var table = document.querySelector('table tbody');
            var trs = table.querySelectorAll('tr:not(.detail-row)');

            trs.forEach(function(tr) {
                var txIdCell = tr.querySelector('td:nth-child(2) code');
                if (txIdCell) {
                    var txtValue = txIdCell.textContent || txIdCell.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr.style.display = "";
                    } else {
                        tr.style.display = "none";
                        // Nascondi anche i dettagli se aperti
                        var detailRow = document.getElementById('details-' + tr.id.replace('row-', ''));
                        if (detailRow) detailRow.style.display = "none";
                    }
                }
            });
        }
    </script>
</head>
<body>

    <h1>📊 Pannello Controllo PayPal Centralizzato</h1>
    
    <form class="filter-bar" method="GET">
        <div>
            <label>Data Inizio</label>
            <input type="date" name="start" value="<?php echo $startDateRaw; ?>">
        </div>
        <div>
            <label>Data Fine</label>
            <input type="date" name="end" value="<?php echo $endDateRaw; ?>">
        </div>
        <div>
            <label>Mostra Solo</label>
            <select name="host" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; background: white; min-width: 150px;">
                <option value="All" <?php echo $filterHost === 'All' ? 'selected' : ''; ?>>Tutto</option>
                <option value="ASSENTE" <?php echo $filterHost === 'ASSENTE' ? 'selected' : ''; ?>>Assenti (ROSSO)</option>
                <option value="Moodle New" <?php echo $filterHost === 'Moodle New' ? 'selected' : ''; ?>>Moodle New</option>
                <option value="Moodle Old" <?php echo $filterHost === 'Moodle Old' ? 'selected' : ''; ?>>Moodle Old</option>
                <option value="ERRORE"     <?php echo $filterHost === 'ERRORE'     ? 'selected' : ''; ?>>Errori</option>
            </select>
        </div>
        <div>
            <label>Ordinamento Data</label>
            <select name="sort" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; background: white; min-width: 160px;">
                <option value="desc" <?php echo $sortOrder === 'desc' ? 'selected' : ''; ?>>⬇ Decrescente (Default)</option>
                <option value="asc"  <?php echo $sortOrder === 'asc'  ? 'selected' : ''; ?>>⬆ Crescente</option>
            </select>
        </div>
        <div>
            <label>Cerca Transaction ID</label>
            <input type="text" id="txn_id_input" name="txn_id" placeholder="Es. 8A964985H..." 
                   value="<?php echo htmlspecialchars($filterTxnId); ?>" 
                   oninput="liveFilter()" 
                   style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-width: 180px;">
        </div>
        <button type="submit" class="btn-filter">Filtra Transazioni</button>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>" class="btn-filter" style="text-decoration:none; background:#28a745;">💾 Scarica Excel</a>
        <div style="margin-left: auto; font-size: 12px; color: #666;">
            Transazioni totali (PayPal): <strong><?php echo $totalItems; ?></strong> | Pagina <?php echo $page; ?> di <?php echo $totalPages; ?>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Transaction ID</th>
                <th>Prodotto / Causale</th>
                <th>Cliente</th>
                <th style="text-align:right">Importo</th>
                <th style="text-align:center">N. Fattura</th>
                <th style="text-align:center">Stato Sistema</th>
                <th style="text-align:center">Azione</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($processedTxs as $index => $tx):
                $info        = $tx['transaction_info'];
                $payerData   = $tx['payer_info'];
                $txId        = trim($info['transaction_id']);
                $amt         = (float)$info['transaction_amount']['value'];
                $currency    = $info['transaction_amount']['currency_code'];
                $date        = date('d/m/Y H:i', strtotime($info['transaction_initiation_date']));
                $itemName    = $tx['cart_info']['item_details'][0]['item_name'] ?? 'N/A';
                $payerFullName = $payerData['payer_name']['alternate_full_name'] ?? 'N/D';
                $payerEmail  = $payerData['email_address'] ?? 'N/D';
                $addr        = $tx['shipping_info']['address'] ?? [];
                $fullAddr    = ($addr['line1'] ?? '') . ", " . ($addr['city'] ?? '') . " (" . ($addr['state'] ?? '') . ") " . ($addr['postal_code'] ?? '');

                // Valori già calcolati nel pre-processing
                $tabella     = $tx['_tabella'];
                $hostTrovato = $tx['_hostTrovato'];
                $fonte       = $tx['_fonte'];
                $classeFonte = $tx['_classeFonte'];
            ?>
            <tr id="row-<?php echo $index; ?>">
                <td><?php echo $date; ?></td>
                <td><code><?php echo $txId; ?></code></td>
                <td><?php echo $itemName; ?></td>
                <td><?php echo $payerFullName; ?></td>
                <td align="right"><strong><?php echo number_format($amt, 2); ?> <?php echo $currency; ?></strong></td>
                <td align="center">
                    <?php 
                    if ($tabella === 'moodle_payments'):
                        $nf = $tx['_nfattura'];
                        $sales = $tx['_sales'];
                        $logs = $tx['_logfile'];

                        if ($sales == 1):
                            $label = $nf ?: 'EVASO';
                            $class = 'table-label-green';
                        elseif ($sales == 0 && empty($logs)):
                            $label = 'DA EVADERE';
                            $class = 'table-label-yellow';
                        elseif ($sales == 0 && !empty($logs)):
                            $label = 'ERRORE';
                            $class = 'table-label-red';
                        else:
                            $label = '-'; $class = '';
                        endif;
                    ?>
                        <?php if ($class): ?>
                            <span class="badge <?php echo $class; ?>" style="font-size: 10px;">
                                <?php echo ($nf ? '📄 ' : '') . $label; ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #ccc;">-</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color: #ccc;">-</span>
                    <?php endif; ?>
                </td>
                <td align="center">
                    <div class="badge-container">
                        <span class="badge <?php echo $classeFonte; ?>"><?php echo $fonte; ?></span>
                        <?php if ($tabella !== 'Nessun Match'): ?>
                        <span class="badge <?php echo $tabella === 'moodle_payments' ? 'table-label-green' : 'table-label-yellow'; ?>"><?php echo $tabella; ?></span>
                        <?php endif; ?>
                    </div>
                </td>
                <td align="center">
                    <button class="btn-detail" onclick="toggleDetails(<?php echo $index; ?>)">🔍 Dettagli</button>
                </td>
            </tr>
            <tr id="details-<?php echo $index; ?>" class="detail-row">
                <td colspan="8">
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
                        <div class="detail-box">
                            <b>Azioni Operative</b>
                            <?php if ($tx['_tabella'] === 'moodle_payments' && $tx['_sales'] == 0 && $tx['_db_id']): ?>
                                <a href="http://moodlesapwoocommerce.metmi.lan/index.php/sap/ins?id=<?php echo $tx['_db_id']; ?>" target="_blank" class="btn-sap">🚀 Rilancio SAP</a>
                            <?php endif; ?>
                            <?php if (!empty($tx['_logfile']) && $tx['_tabella'] === 'moodle_payments' && $tx['_sales'] == 0): ?>
                                <a href="http://moodlesapwoocommerce.metmi.lan/logs/<?php echo $tx['_logfile']; ?>" target="_blank" class="btn-log">📋 Log Errore</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?start=<?php echo $startDateRaw; ?>&end=<?php echo $endDateRaw; ?>&host=<?php echo urlencode($filterHost); ?>&sort=<?php echo $sortOrder; ?>&txn_id=<?php echo urlencode($filterTxnId); ?>&p=<?php echo $page - 1; ?>" class="page-link">« Precedente</a>
        <?php endif; ?>

        <?php 
        $start_page = max(1, $page - 2);
        $end_page = min($totalPages, $page + 2);
        
        for ($i = $start_page; $i <= $end_page; $i++): ?>
            <a href="?start=<?php echo $startDateRaw; ?>&end=<?php echo $endDateRaw; ?>&host=<?php echo urlencode($filterHost); ?>&sort=<?php echo $sortOrder; ?>&txn_id=<?php echo urlencode($filterTxnId); ?>&p=<?php echo $i; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?start=<?php echo $startDateRaw; ?>&end=<?php echo $endDateRaw; ?>&host=<?php echo urlencode($filterHost); ?>&sort=<?php echo $sortOrder; ?>&txn_id=<?php echo urlencode($filterTxnId); ?>&p=<?php echo $page + 1; ?>" class="page-link">Successiva »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <p style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px; font-size: 11px; color: #888;">
        Link Rapidi: 
        <a href="?start=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end=<?php echo date('Y-m-d'); ?>&host=<?php echo urlencode($filterHost); ?>&sort=<?php echo $sortOrder; ?>&txn_id=<?php echo urlencode($filterTxnId); ?>">Ultimi 7gg</a> | 
        <a href="?start=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end=<?php echo date('Y-m-d'); ?>&host=<?php echo urlencode($filterHost); ?>&sort=<?php echo $sortOrder; ?>&txn_id=<?php echo urlencode($filterTxnId); ?>">Ultimi 30gg</a>
    </p>

</body>
</html>