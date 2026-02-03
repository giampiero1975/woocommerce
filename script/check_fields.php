<?php
/**
 * check_fields.php - Versione Integrata
 * Usa la stessa configurazione di index.php per analizzare i campi ordine.
 */

// 1. CARICAMENTO DIPENDENZE (Esattamente come index.php)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$configFile = __DIR__ . '/config_db.php';
$connectFile = __DIR__ . '/connect.php';

if (!file_exists($configFile) || !file_exists($connectFile)) {
    die("<h3>ERRORE CRITICO:</h3> <p>Impossibile trovare <b>config_db.php</b> o <b>connect.php</b>.<br>Assicurati che questo file sia nella stessa cartella di index.php.</p>");
}

require_once $configFile;
require_once $connectFile;

// 2. CONFIGURAZIONE
// $targetPrefix = 'MeiOSS-'; // L'istanza che vogliamo analizzare (Professione Farmacia)
$targetPrefix = 'PF'; // L'istanza che vogliamo analizzare (Professione Farmacia)
$orderId = $_GET['id'] ?? 0;

if (!$orderId) {
    die("<h3>Istruzioni:</h3> <p>Chiama questo script aggiungendo l'ID dell'ordine nell'URL.<br>Esempio: <a href='?id=7183'>check_fields.php?id=7183</a></p>");
}

echo "<h1>🔍 Analisi Ordine #$orderId (Istanza: $targetPrefix)</h1>";

// 3. RECUPERO CONFIGURAZIONE DAL TUO FILE
if (!defined('WC_INSTANCE_MAPPING') || !isset(WC_INSTANCE_MAPPING[$targetPrefix])) {
    die("❌ Errore: Istanza '$targetPrefix' non trovata in WC_INSTANCE_MAPPING (vedi config_db.php).");
}

$config = WC_INSTANCE_MAPPING[$targetPrefix];
$dbName = $config['wc_db_name'];
$dbPrefix = $config['wc_db_prefix'];

echo "<p>Database: <b>$dbName</b> | Prefisso Tabelle: <b>$dbPrefix</b></p><hr>";

// 4. CONNESSIONE AL DB (Usa il tuo DBConnector)
$conn = DBConnector::getWpDbByName($dbName);

if (!$conn) {
    die("❌ Errore di connessione al database <b>$dbName</b>. Controlla i log o le credenziali.");
}

// 5. RICERCA DATI (Prova sia HPOS che PostMeta)
$found = false;
$tablesToCheck = [
    "{$dbPrefix}wc_orders_meta" => 'order_id', // Nuova struttura Woo
    "{$dbPrefix}postmeta"       => 'post_id'    // Vecchia struttura Woo
    ];

foreach ($tablesToCheck as $tableName => $idColumn) {
    // Verifica se la tabella esiste
    $check = $conn->query("SHOW TABLES LIKE '$tableName'");
    if ($check->num_rows == 0) continue;
    
    echo "<p>Controllo tabella: <i>$tableName</i> ... ";
    
    $sql = "SELECT meta_key, meta_value FROM $tableName WHERE $idColumn = $orderId";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        echo "<b style='color:green'>TROVATI DATI!</b></p>";
        renderResults($result);
        $found = true;
        break; // Trovato, ci fermiamo qui
    } else {
        echo "Nessun dato per questo ID.</p>";
    }
}

if (!$found) {
    echo "<h2 style='color:red'>❌ Nessun metadato trovato.</h2>";
    echo "<ul><li>L'ordine esiste?</li><li>L'ID è corretto?</li><li>È nel database $dbName?</li></ul>";
}

// --- FUNZIONE DI STAMPA ---
function renderResults($res) {
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse; width:100%; font-family: sans-serif;'>";
    echo "<tr style='background:#333; color:white;'><th>Chiave (Meta Key)</th><th>Valore</th><th>Note</th></tr>";
    
    while ($row = $res->fetch_assoc()) {
        $k = $row['meta_key'];
        $v = $row['meta_value'];
        
        // Evidenziazione intelligente
        $style = "";
        $note = "";
        
        // Campi Fiscali e Critici
        if (preg_match('/(cf_|piva|vat|pec|sdi|code|fiscale|billing_cf)/i', $k)) {
            $style = "background-color: #ffeb3b; font-weight:bold;";
            $note = "👀 <b>DA VERIFICARE</b>";
        }
        
        // Campi Standard noti (così li riconosci subito)
        if (in_array($k, ['_billing_cf', 'billing_cf', '_billing_email', 'billing_email'])) {
            $style = "";
            $note = "✅ Standard";
        }
        
        echo "<tr style='$style'>";
        echo "<td>$k</td>";
        echo "<td style='word-break:break-all;'>" . htmlspecialchars(substr($v, 0, 200)) . "</td>";
        echo "<td>$note</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>