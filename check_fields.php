<?php
// check_fields.php
require_once __DIR__ . '/wp-load.php'; // Carica WordPress/WooCommerce

$order_id = $_GET['id'] ?? 0;
if (!$order_id) die("Specifica ?id=ORDINE");

$order = wc_get_order($order_id);
if (!$order) die("Ordine non trovato");

echo "<h1>Radiografia Campi Ordine #$order_id</h1>";
echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%; font-family:monospace;'>";
echo "<tr style='background:#eee'><th>Nome Campo (Meta Key)</th><th>Valore</th><th>Note</th></tr>";

// Elenco campi standard che ci aspettiamo
$standard_fields = [
    'billing_first_name', 'billing_last_name', 'billing_company',
    'billing_address_1', 'billing_city', 'billing_postcode',
    'billing_email', 'billing_phone', 'billing_cf', 'billing_piva',
    'billing_pec', 'billing_codiceunivoco', 'billing_pa_code'
];

$meta_data = $order->get_meta_data();

foreach ($meta_data as $meta) {
    $key = $meta->key;
    $val = $meta->value;
    
    // Ignora campi tecnici interni (quelli che iniziano con _)
    // MA tieni quelli che sembrano indirizzi o dati fiscali
    if (strpos($key, '_') === 0 && strpos($key, 'billing') === false) continue;
    
    $highlight = "";
    $note = "";
    
    // Evidenzia campi "strani" o critici
    if (in_array($key, $standard_fields)) {
        $note = "✅ Standard WC";
    } elseif (strpos($key, 'cf') !== false || strpos($key, 'piva') !== false || strpos($key, 'vat') !== false || strpos($key, 'pec') !== false || strpos($key, 'sdi') !== false || strpos($key, 'code') !== false) {
        $highlight = "background-color: #fffacd; font-weight:bold;"; // Giallo
        $note = "⚠️ POSSIBILE CUSTOM FIELD";
    }
    
    if (is_array($val)) $val = print_r($val, true);
    
    echo "<tr style='$highlight'><td>$key</td><td>$val</td><td>$note</td></tr>";
}
echo "</table>";