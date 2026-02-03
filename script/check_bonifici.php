<?php
/**
 * check_bonifici.php - VERSIONE ACCOUNTING (Fix bacs_date)
 * Cerca sia 'bacs_date' che '_bacs_date' per sicurezza.
 */

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/connect.php';

// --- CONFIGURAZIONE DATE ---
// Default: primi e ultimi giorni del mese corrente
$defaultStart = date('Y-m-01');
$defaultEnd   = date('Y-m-t');

$startDateInput = $_GET['start_date'] ?? $defaultStart;
$endDateInput   = $_GET['end_date'] ?? $defaultEnd;

// Date per SQL (formato Y-m-d)
$sqlStart = $startDateInput;
$sqlEnd   = $endDateInput;

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Ricerca Bonifici per Data Valuta</title>
    <style>
        body { font-family: sans-serif; padding: 20px; background: #f4f4f4; color:#333; }
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); max-width: 1200px; margin:0 auto; }
        h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; color:#003087; margin-top:0; }
        form { background:#eef; padding:15px; border-radius:5px; margin-bottom:20px; display:flex; gap:10px; align-items:center; border-left: 5px solid #28a745;}
        input, button { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
        button { background: #28a745; color: white; border: none; font-weight: bold; cursor: pointer; }
        button:hover { background: #218838; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #003087; color: white; text-transform: uppercase; font-size: 11px; }
        tr:nth-child(even) { background: #f9f9f9; }
        
        .badge { padding: 3px 8px; border-radius: 10px; font-size: 11px; color: white; font-weight: bold; }
        .st-completed { background: #28a745; }
        .st-processing { background: #17a2b8; }
        .st-on-hold { background: #ffc107; color: #333; }
        
        .box-sito { border: 1px solid #ccc; border-radius: 5px; margin-bottom: 30px; overflow: hidden; background:white;}
        .header-sito { background: #e9ecef; padding: 10px 15px; font-weight: bold; border-left: 5px solid #003087; display: flex; justify-content: space-between; }
        .mode-tag { font-size: 0.8em; background: #ddd; padding: 2px 6px; border-radius: 4px; color:#555; }
        
        .valuta-ok { color: #006400; font-weight:bold; background:#e6fffa; padding:4px 8px; border-radius:3px; border:1px solid #b2f5ea; display:block; text-align:center;}
        
        .info-box { font-size: 0.9em; color: #555; margin-bottom: 15px; background: #fff3cd; padding: 10px; border-radius: 4px; border: 1px solid #ffeeba; }
    </style>
</head>
<body>

<div class="container">
    <h2>🗓️ Ricerca Bonifici (Per Data Valuta)</h2>
    
    <div class="info-box">
        ⚠️ <b>Nota:</b> Mostra bonifici con data valuta (campi <code>bacs_date</code> o <code>_bacs_date</code>) nel periodo selezionato.
    </div>
    
    <form method="GET">
        <label><b>Data Valuta</b> Dal:</label>
        <input type="date" name="start_date" value="<?php echo $startDateInput; ?>">
        <label>Al:</label>
        <input type="date" name="end_date" value="<?php echo $endDateInput; ?>">
        <button type="submit">CERCA INCASSI</button>
    </form>

    <?php
    foreach (WC_INSTANCE_MAPPING as $prefix => $conf) {
        $dbName = $conf['wc_db_name'];
        $dbPrefix = $conf['wc_db_prefix'];
        
        echo "<div class='box-sito'>";
        
        $conn = DBConnector::getWpDbByName($dbName);
        if (!$conn) {
            echo "<div class='header-sito' style='border-left-color:red'>$prefix (DB: $dbName) - ERRORE CONNESSIONE</div></div>";
            continue;
        }

        // --- Rilevamento HPOS ---
        $tableHPOS = "{$dbPrefix}wc_orders";
        $checkTable = $conn->query("SHOW TABLES LIKE '$tableHPOS'");
        $existsHPOS = ($checkTable && $checkTable->num_rows > 0);
        
        $orders = [];
        $mode = "";

        // Filtro data su formato italiano d/m/Y
        $filterDate = "STR_TO_DATE(meta_bacs.meta_value, '%d/%m/%Y') BETWEEN '$sqlStart' AND '$sqlEnd'";
        // Cerchiamo entrambe le chiavi possibili
        $metaKeys = "meta_bacs.meta_key IN ('bacs_date', '_bacs_date')";

        if ($existsHPOS) {
            $mode = "HPOS";
            // Query HPOS
            $sql = "SELECT o.id, o.status, o.date_created_gmt as date_ord, o.total_amount, 
                           o.billing_email as email, meta_bacs.meta_value as bacs_date
                    FROM $tableHPOS o
                    JOIN {$dbPrefix}wc_orders_meta meta_bacs ON o.id = meta_bacs.order_id
                    WHERE $metaKeys
                    AND $filterDate
                    AND o.payment_method = 'bacs'
                    ORDER BY STR_TO_DATE(meta_bacs.meta_value, '%d/%m/%Y') DESC";
            
            $res = $conn->query($sql);
            if ($res) while ($r = $res->fetch_assoc()) { $orders[] = $r; }
        } 
        
        // Se HPOS vuoto, proviamo Legacy
        if (empty($orders)) {
            $mode = ($existsHPOS) ? "HPOS (Fallback)" : "LEGACY";
            $tablePosts = "{$dbPrefix}posts";
            $tableMeta = "{$dbPrefix}postmeta";
            
            // Query LEGACY
            $sqlLegacy = "SELECT p.ID as id, p.post_status as status, p.post_date as date_ord, 
                                 pm_total.meta_value as total_amount, 
                                 pm_email.meta_value as email, 
                                 meta_bacs.meta_value as bacs_date
                          FROM $tablePosts p
                          JOIN $tableMeta meta_bacs ON p.ID = meta_bacs.post_id
                          JOIN $tableMeta pm_method ON p.ID = pm_method.post_id AND pm_method.meta_key = '_payment_method'
                          LEFT JOIN $tableMeta pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                          LEFT JOIN $tableMeta pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
                          
                          WHERE p.post_type = 'shop_order'
                          AND pm_method.meta_value = 'bacs'
                          AND $metaKeys
                          AND $filterDate
                          ORDER BY STR_TO_DATE(meta_bacs.meta_value, '%d/%m/%Y') DESC";
            
            $resLegacy = $conn->query($sqlLegacy);
            if ($resLegacy) while ($r = $resLegacy->fetch_assoc()) { $orders[] = $r; }
        }

        echo "<div class='header-sito'>
                <span>Sito: $prefix <small>($dbName)</small></span>
                <span class='mode-tag'>$mode</span>
              </div>";
        
        if (!empty($orders)) {
            echo "<table>
                    <thead>
                        <tr>
                            <th width='60'>ID</th>
                            <th width='100'>Data Ordine</th>
                            <th width='120'>Data Valuta</th>
                            <th width='100'>Stato</th>
                            <th>Email</th>
                            <th width='90'>Totale</th>
                        </tr>
                    </thead>
                    <tbody>";
            
            foreach ($orders as $o) {
                $statusRaw = str_replace('wc-', '', $o['status']);
                $classStatus = 'st-' . $statusRaw;
                
                echo "<tr>
                        <td><strong>#{$o['id']}</strong></td>
                        <td style='color:#777;'>" . date('d/m/Y', strtotime($o['date_ord'])) . "</td>
                        <td><span class='valuta-ok'>📅 {$o['bacs_date']}</span></td>
                        <td><span class='badge $classStatus'>" . strtoupper($statusRaw) . "</span></td>
                        <td>{$o['email']}</td>
                        <td style='color:green;font-weight:bold'>€ " . number_format((float)$o['total_amount'], 2, ',', '.') . "</td>
                      </tr>";
            }
            echo "</tbody></table>";
        } else {
            echo "<div style='padding:15px; color:#666;'><i>Nessun bonifico trovato con Data Valuta nel periodo selezionato.</i></div>";
        }
        
        echo "</div>"; 
    }
    ?>

</div>
</body>
</html>