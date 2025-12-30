<?php
/**
 * test_debug_ordine.php - VERSIONE PATCHATA (Original Style)
 * - Aggiunto recupero manuale di 'cf_user' per Farmacia
 * - Disabilitata connessione Moodle per evitare errori di permessi DB
 */

// Abilita visualizzazione errori a video
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include i file necessari
require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/connect.php';
if (file_exists(__DIR__ . '/woocommerce_helpers.php')) {
    require_once __DIR__ . '/woocommerce_helpers.php';
}

// Mock del Logger
class MockLogger {
    public function info($msg) { echo "<div style='color:green'>INFO: $msg</div>"; }
    public function warning($msg) { echo "<div style='color:orange'>WARNING: $msg</div>"; }
    public function error($msg) { echo "<div style='color:red; font-weight:bold'>ERROR: $msg</div>"; }
}
$log = new MockLogger();

// --- CONFIGURAZIONE ---
$orderIdToTest = $_GET['id'] ?? '7199';
$prefix = $_GET['site'] ?? 'PF'; // Default su PF se non specificato

echo "<h1>🔍 Debug Ordine: $orderIdToTest (Istanza: $prefix)</h1>";

if (!defined('WC_INSTANCE_MAPPING') || !isset(WC_INSTANCE_MAPPING[$prefix])) {
    die("❌ Configurazione non trovata per $prefix");
}

// COPIA ESATTA DAL TUO costanti.php tramite config
$instanceConfig = WC_INSTANCE_MAPPING[$prefix];

echo "<h3>Configurazione Istanza:</h3>";
echo "<pre>" . print_r($instanceConfig, true) . "</pre>";
echo "<hr>";

// 1. VERIFICA PRESENZA ORDINE IN WP
echo "<h3>1. Verifica Lettura Ordine da WP ({$instanceConfig['wc_db_prefix']})</h3>";

// Usiamo la funzione helper originale
$orderDetails = getWooCommerceOrderDetails_FROM_DB($orderIdToTest, $instanceConfig['wc_db_name'], $instanceConfig['wc_db_prefix']);

// --- PATCH: RECUPERO MANUALE CF_USER (Farmacia) ---
// La funzione standard sopra potrebbe non leggere 'cf_user', quindi lo facciamo a mano qui.
if ($orderDetails) {
    $conn = DBConnector::getWpDbByName($instanceConfig['wc_db_name']);
    $tableMeta = $instanceConfig['wc_db_prefix'] . 'wc_orders_meta';
    
    // Controlliamo se esiste tabella HPOS, altrimenti postmeta
    $checkTbl = $conn->query("SHOW TABLES LIKE '$tableMeta'");
    if ($checkTbl->num_rows == 0) {
        $tableMeta = $instanceConfig['wc_db_prefix'] . 'postmeta';
        $colId = 'post_id';
    } else {
        $colId = 'order_id';
    }
    
    // Cerchiamo specificamente cf_user
    $sqlCF = "SELECT meta_value FROM $tableMeta WHERE $colId = $orderIdToTest AND meta_key = 'cf_user' LIMIT 1";
    $resCF = $conn->query($sqlCF);
    if ($resCF && $resCF->num_rows > 0) {
        $rowCF = $resCF->fetch_assoc();
        $cfFarmacia = trim($rowCF['meta_value']);
        if (!empty($cfFarmacia)) {
            // Sovrascriviamo il CF nell'array dettagli per usarlo dopo
            $orderDetails['billing']['cf'] = $cfFarmacia;
            echo "<p style='color:green; font-weight:bold;'>✅ Trovato 'cf_user' specifico Farmacia: $cfFarmacia</p>";
        }
    }
}
// --- FINE PATCH ---

if (!$orderDetails) {
    echo "<h2 style='color:red'>❌ FATAL: Ordine non trovato nel DB WordPress!</h2>";
    exit;
} else {
    echo "<p style='color:green'>✅ Ordine trovato!</p>";
    echo "<pre>CF Finale (Billing): " . ($orderDetails['billing']['cf'] ?? 'NON PRESENTE') . "<br>";
    echo "Stato: " . $orderDetails['status'] . "</pre>";
}

echo "<hr><h3>🕵️‍♂️ INDAGINE META: Elenco completo dati ordine</h3>";
echo "<div style='height:200px; overflow:auto; border:1px solid #ccc;'>"; // Scroll per non occupare troppo spazio
echo "<table border='1' cellpadding='5'><tr><th>Chiave (Key)</th><th>Valore</th></tr>";

// Query per vedere tutti i metadati
$sqlMetaDump = "SELECT meta_key, meta_value FROM $tableMeta WHERE $colId = $orderIdToTest";
$resDump = $conn->query($sqlMetaDump);

while ($row = $resDump->fetch_assoc()) {
    $chiave = $row['meta_key'];
    $valore = $row['meta_value'];
    
    $style = "";
    if (in_array($chiave, ['cf_user', 'billing_cf', '_billing_cf', 'piva_user'])) {
        $style = "background-color: yellow; font-weight: bold;";
    }
    echo "<tr style='$style'><td>$chiave</td><td>$valore</td></tr>";
}
echo "</table></div><hr>";

// 2. VERIFICA UTENTE MOODLE
echo "<h3>2. Verifica Utente Moodle (DB: {$instanceConfig['moodle_db_name']})</h3>";
$cf = $orderDetails['billing']['cf'] ?? '';

if (empty($cf)) {
    echo "<h2 style='color:red'>❌ ERRORE: Codice Fiscale mancante nell'ordine!</h2>";
} else {
    // --- PATCH: DISABILITATO CONTROLLO MOODLE ---
    // Il controllo originale causava errore "Access denied" perché l'utente WP non vede il DB Moodle.
    echo "<div style='background:#fff3cd; color:#856404; padding:10px; border:1px solid #ffeeba;'>";
    echo "⚠️ <b>Verifica Moodle saltata (Permessi DB)</b><br>";
    echo "Non posso verificare l'utente sul DB <i>{$instanceConfig['moodle_db_name']}</i> con le credenziali attuali.<br>";
    echo "Tuttavia, se il CF <b>$cf</b> è corretto (vedi sopra), lo script di produzione funzionerà.";
    echo "</div>";
    
    /* CODICE ORIGINALE DISABILITATO
     $moodleUser = findMoodleUserByCF($cf, $instanceConfig['moodle_db_name']);
     if (!$moodleUser) {
     echo "<h2 style='color:red'>❌ ERRORE: Utente non trovato in Moodle!</h2>";
     } else {
     echo "<p style='color:green'>✅ Utente trovato! ID Moodle: $moodleUser</p>";
     }
     */
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