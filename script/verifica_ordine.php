<?php
/**
 * verifica_ordine.php - VERSIONE DEFINITIVA
 * Obiettivo: Record unico nel DB + Dettaglio nomi da WooCommerce
 */
require_once '../config_db.php';
require_once '../connect.php';
require_once '../woocommerce_helpers.php';

$orderId = $_GET['payment_id'] ?? $_GET['id'] ?? '7344';
$prefix  = $_GET['site'] ?? 'PF';

$instanceConfig = WC_INSTANCE_MAPPING[$prefix];
$wcConn = DBConnector::getWpDbByName($instanceConfig['wc_db_name']);
$mdlConn = DBConnector::getMoodleAppsDb();

echo "<body style='font-family: sans-serif; padding: 20px; background: #f4f7f6;'>";
echo "<h1>ðŸ”� Verifica Ordine #$orderId ($prefix)</h1>";

// 1. ANAGRAFICA UTENTE (Sempre da WooCommerce Usermeta)
$uIdQuery = $mdlConn->query("SELECT userid FROM moodle_payments WHERE payment_id = '$orderId' LIMIT 1");
$uIdRow = $uIdQuery->fetch_assoc();
$uId = $uIdRow['userid'] ?? 0;

$nomeCompleto = "N/D";
if ($uId > 0) {
    $sqlUser = "SELECT
        MAX(CASE WHEN meta_key = 'first_name' THEN meta_value END) as nome,
        MAX(CASE WHEN meta_key = 'last_name' THEN meta_value END) as cognome
        FROM wpmei_usermeta WHERE user_id = '$uId'";
    $resUser = $wcConn->query($sqlUser);
    $uData = $resUser->fetch_assoc();
    $nomeCompleto = trim(($uData['nome'] ?? '') . " " . ($uData['cognome'] ?? ''));
}

// 2. TABELLA DATABASE LOCALE (Record Unico)
echo "<h3>âœ… Record in Database Locale (paypal):</h3>";
$resMdl = $mdlConn->query("SELECT id, userid, courseid, cost FROM moodle_payments WHERE payment_id = '$orderId'");

echo "<table border='1' cellpadding='10' style='border-collapse:collapse; width:100%; background:white;'>";
echo "<tr style='background:#004085; color:white;'><th>Ticket ID</th><th>Utente</th><th>Course ID</th><th>Costo Registrato</th></tr>";

while($row = $resMdl->fetch_assoc()) {
    echo "<tr>
            <td align='center'>#{$row['id']}</td>
            <td><b>$nomeCompleto</b> (ID: {$row['userid']})</td>
            <td>{$row['courseid']}</td>
            <td align='right'><b>" . number_format($row['cost'], 2) . " â‚¬</b></td>
          </tr>";
}
echo "</table>";

// 3. DETTAGLIO COMPOSIZIONE (Esplosione da WooCommerce)
echo "<h3>ðŸ“¦ Dettaglio Composizione (Da WooCommerce Sorgente):</h3>";
$tItems = $instanceConfig['wc_db_prefix'] . 'woocommerce_order_items';
$tMeta  = $instanceConfig['wc_db_prefix'] . 'woocommerce_order_itemmeta';

$sqlWc = "SELECT oi.order_item_name, oim_qty.meta_value as qta, oim_total.meta_value as totale
          FROM $tItems oi
          LEFT JOIN $tMeta oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
          LEFT JOIN $tMeta oim_total ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
          WHERE oi.order_id = '$orderId' AND oi.order_item_type = 'line_item'";

$resWc = $wcConn->query($sqlWc);
echo "<div style='background:white; padding:15px; border: 1px solid #ccc; border-radius:5px;'>";
while($item = $resWc->fetch_assoc()) {
    $q = $item['qta'] ?: 1;
    echo "â€¢ <b>{$item['order_item_name']}</b>: QuantitÃ  x $q (Totale riga: {$item['totale']} â‚¬)<br>";
}
echo "</div>";

echo "<br><a href='index.php'>â¬…ï¸� Torna al Cron</a></body>";