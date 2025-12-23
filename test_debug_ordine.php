<?php
/**
 * test_debug_ordine.php
 * Script diagnostico per capire PERCHÉ un ordine specifico viene scartato.
 */

// Abilita visualizzazione errori a video
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include i file necessari (adatta i percorsi se serve)
require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/woocommerce_helpers.php';

// Mock del Logger per vedere gli errori a video invece che su file
class MockLogger {
    public function info($msg) { echo "<div style='color:green'>INFO: $msg</div>"; }
    public function warning($msg) { echo "<div style='color:orange'>WARNING: $msg</div>"; }
    public function error($msg) { echo "<div style='color:red; font-weight:bold'>ERROR: $msg</div>"; }
}
$log = new MockLogger();

// --- CONFIGURAZIONE DA TESTARE ---
// Inserisci qui l'ID dell'ordine che non viene importato
$orderIdToTest = $_GET['id'] ?? '7183'; // Cambia 7183 con il tuo ID o usa ?id=XXXX

// Configurazione manuale dell'istanza "PF" (COPIA ESATTA DAL TUO costanti.php)
$instanceConfig = [
    'wc_db_name'     => 'wp_mei',
    'wc_db_prefix'   => 'wpmei_4_',              // <--- Tabelle Farmacia
    'moodle_db_name' => 'mdl_professionefarmacia', // <--- DB Moodle Farmacia
    'is_paypal'      => true                     // Simuliamo che venga da PayPal
];

echo "<h1>🔍 Debug Ordine: $orderIdToTest</h1>";
echo "<h3>Configurazione Istanza:</h3>";
echo "<pre>" . print_r($instanceConfig, true) . "</pre>";
echo "<hr>";

// 1. VERIFICA PRESENZA ORDINE IN WP
echo "<h3>1. Verifica Lettura Ordine da WP ({$instanceConfig['wc_db_prefix']})</h3>";
$orderDetails = getWooCommerceOrderDetails_FROM_DB($orderIdToTest, $instanceConfig['wc_db_name'], $instanceConfig['wc_db_prefix']);

if (!$orderDetails) {
    echo "<h2 style='color:red'>❌ FATAL: Ordine non trovato nel DB WordPress!</h2>";
    echo "<p>Possibili cause:</p><ul>";
    echo "<li>L'ID $orderIdToTest non esiste nella tabella <b>{$instanceConfig['wc_db_prefix']}wc_orders</b> (o posts).</li>";
    echo "<li>Il prefisso tabelle è sbagliato.</li>";
    echo "</ul>";
    exit;
} else {
    echo "<p style='color:green'>✅ Ordine trovato!</p>";
    echo "<pre>CF (Billing): " . ($orderDetails['billing']['cf'] ?? 'NON PRESENTE') . "<br>";
    echo "Stato: " . $orderDetails['status'] . "</pre>";
}

// ... dopo la sezione 1 ...

echo "<hr><h3>🕵️‍♂️ INDAGINE META: Elenco completo dati ordine</h3>";
echo "<table border='1' cellpadding='5'><tr><th>Chiave (Key)</th><th>Valore</th></tr>";

// Query per vedere tutti i metadati
$sqlMetaDump = "SELECT meta_key, meta_value FROM {$instanceConfig['wc_db_prefix']}wc_orders_meta WHERE order_id = $orderIdToTest";
$stmtDump = DBConnector::getWpDbByName($instanceConfig['wc_db_name'])->prepare($sqlMetaDump);
$stmtDump->execute();
$resDump = $stmtDump->get_result();

while ($row = $resDump->fetch_assoc()) {
    $chiave = $row['meta_key'];
    $valore = $row['meta_value'];
    
    // Evidenziamo se sembra un codice fiscale
    $style = "";
    if (strpos($chiave, 'cf') !== false || strpos($chiave, 'codice') !== false || strlen($valore) == 16) {
        $style = "background-color: yellow; font-weight: bold;";
    }
    
    echo "<tr style='$style'><td>$chiave</td><td>$valore</td></tr>";
}
echo "</table><hr>";

// ... prosegue con la sezione 2 ...
// 2. VERIFICA UTENTE MOODLE
echo "<h3>2. Verifica Utente Moodle (DB: {$instanceConfig['moodle_db_name']})</h3>";
$cf = $orderDetails['billing']['cf'] ?? '';
if (empty($cf)) {
    echo "<h2 style='color:red'>❌ ERRORE: Codice Fiscale mancante nell'ordine!</h2>";
} else {
    $moodleUser = findMoodleUserByCF($cf, $instanceConfig['moodle_db_name']);
    if (!$moodleUser) {
        echo "<h2 style='color:red'>❌ ERRORE: Utente non trovato in Moodle!</h2>";
        echo "<p>Nessun utente con CF <b>$cf</b> trovato nel database <b>{$instanceConfig['moodle_db_name']}</b>.</p>";
    } else {
        echo "<p style='color:green'>✅ Utente trovato! ID Moodle: $moodleUser</p>";
    }
}

// 3. VERIFICA PRODOTTI E CORSI
echo "<h3>3. Verifica Prodotti e Mapping Corsi</h3>";
$items = $orderDetails['line_items'] ?? [];
foreach ($items as $item) {
    $prodId = $item['product_id'];
    echo "Analisi Prodotto ID WP: <b>$prodId</b>... ";
    
    $courseId = findMoodleCourseId($prodId, $instanceConfig['wc_db_name'], $instanceConfig['wc_db_prefix']);
    
    if ($courseId) {
        echo "<span style='color:green'>✅ Mappato su Corso Moodle ID: $courseId</span><br>";
    } else {
        echo "<span style='color:red'>❌ ERRORE: Meta 'moodle_course_id' non trovato per questo prodotto!</span><br>";
    }
}

echo "<hr>";
echo "<h3>4. Tentativo di Elaborazione Completa (Simulazione)</h3>";

// Proviamo a lanciare la funzione reale
$result = queueWooOrderForProcessing($orderIdToTest, $instanceConfig);

echo "<b>Risultato Funzione:</b><br>";
if ($result['success']) {
    echo "<h2 style='color:green'>✅ SUCCESSO: L'ordine verrebbe inserito!</h2>";
} else {
    echo "<h2 style='color:red'>❌ FALLIMENTO: " . ($result['error'] ?? 'Errore sconosciuto') . "</h2>";
}
?>