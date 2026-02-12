<?php
/**
 * test_debug_ordine.php - VERSIONE DEFINITIVA BASATA SU QUERY FUNZIONANTE
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config_db.php';
require_once '../connect.php';
require_once '../woocommerce_helpers.php';

$orderIdToTest = $_GET['id'] ?? '7397';
$prefix = $_GET['site'] ?? 'PF';

if (!isset(WC_INSTANCE_MAPPING[$prefix])) {
    die("Istanza $prefix non trovata nel mapping.");
}

$instanceConfig = WC_INSTANCE_MAPPING[$prefix];
$conn = DBConnector::getWpDbByName($instanceConfig['wc_db_name']);

echo "<body style='font-family: sans-serif; padding: 20px; background: #f4f7f6;'>";
echo "<h1>ðŸ”� Analisi Esplosa Ordine #$orderIdToTest ($prefix)</h1>";

// 1. RECUPERO TESTATA (Sempre necessaria per il totale SAP)
$tableOrders = $instanceConfig['wc_db_prefix'] . 'wc_orders';
$resOrder = $conn->query("SELECT total_amount FROM $tableOrders WHERE id = $orderIdToTest");
$orderBase = $resOrder->fetch_assoc();

echo "<div style='background: #004085; color: white; padding: 20px; border-radius: 8px; margin-bottom: 25px;'>";
echo "<h2 style='margin:0;'>ðŸ’° Totale Ordine: " . number_format($orderBase['total_amount'], 2) . " â‚¬</h2>";
echo "</div>";

// 2. LA TUA QUERY FUNZIONANTE (Esplosione Articoli)
echo "<h3>ðŸ“¦ Dettaglio Articoli (Da cosa Ã¨ composto il totale?)</h3>";

$tItems = $instanceConfig['wc_db_prefix'] . 'woocommerce_order_items';
$tMeta  = $instanceConfig['wc_db_prefix'] . 'woocommerce_order_itemmeta';

$sql = "SELECT
            oi.order_item_name AS Prodotto,
            oim_qty.meta_value AS Quantita,
            oim_total.meta_value AS Totale_Netto,
            oi.order_item_type AS Tipo
        FROM $tItems oi
        LEFT JOIN $tMeta oim_qty
            ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
        LEFT JOIN $tMeta oim_total
            ON oi.order_item_id = oim_total.order_item_id AND oim_total.meta_key = '_line_total'
        WHERE oi.order_id = $orderIdToTest";

$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    echo "<table border='1' cellpadding='10' style='width:100%; border-collapse: collapse; background: white;'>";
    echo "<tr style='background: #eee;'><th>Tipo</th><th>Articolo</th><th align='center'>QuantitÃ </th><th align='right'>Importo</th></tr>";
    
    while ($item = $res->fetch_assoc()) {
        $importo = (float)$item['Totale_Netto'];
        echo "<tr>
                <td><small>{$item['Tipo']}</small></td>
                <td><b>" . htmlspecialchars($item['Prodotto']) . "</b></td>
                <td align='center'>" . ($item['Quantita'] ? "x ".$item['Quantita'] : "---") . "</td>
                <td align='right'><b>" . number_format($importo, 2) . " â‚¬</b></td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'>Nessun dettaglio trovato per l'ID $orderIdToTest. Errore SQL: " . $conn->error . "</p>";
}

// 3. DATI FISCALI (Quelli che giÃ  vedevi bene)
echo "<h3>ðŸ“‘ Dati Fiscali</h3>";
$tableMetaOrders = $instanceConfig['wc_db_prefix'] . 'wc_orders_meta';
$resMeta = $conn->query("SELECT meta_key, meta_value FROM $tableMetaOrders WHERE order_id = $orderIdToTest");
$meta = [];
while($row = $resMeta->fetch_assoc()) { $meta[$row['meta_key']] = $row['meta_value']; }

echo "<ul>
        <li><b>Ragione Sociale:</b> " . ($meta['billing_company'] ?? '---') . "</li>
        <li><b>CF/P.IVA:</b> " . ($meta['billing_business_cf'] ?? $meta['cf_user'] ?? '---') . "</li>
        <li><b>SDI:</b> " . ($meta['billing_codiceunivoco'] ?? '---') . "</li>
      </ul>";

echo "<br><a href='index.php'>â¬…ï¸� Torna al Cron</a>";
echo "</body>";