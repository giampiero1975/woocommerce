<?php
/**
 * audit_allineamento_definitivo.php
 * Script per il confronto strutturale basato sulla Master Maps Array.
 */

require_once '../config_db.php'; // Carica le istanze
require_once '../connect.php';   // Carica il DBConnector

// =========================================================================
// 1. DEFINIZIONE MASTER MAPS (Il Modello Unico di Confronto)
// =========================================================================
$MASTER_MAPS = [
    'ANAGRAFICA_GLOBALE (wpmei_usermeta)' => [
        'first_name'            => 'Nome Profilo',
        'last_name'             => 'Cognome Profilo',
        'billing_cf'            => 'Codice Fiscale Globale'
    ],
    'BILLING_FULL (Anagrafica Ordine)' => [
        'first_name'            => 'Nome',
        'last_name'             => 'Cognome',
        'company'               => 'Azienda',
        'address_1'             => 'Indirizzo 1',
        'address_2'             => 'Indirizzo 2',
        'city'                  => 'Città',
        'state'                 => 'Provincia',
        'postcode'              => 'CAP',
        'country'               => 'Nazione',
        'email'                 => 'Email',
        'phone'                 => 'Telefono'
    ],
    'META_FISCALI (Dati Fatturazione)' => [
        'billing_cf'            => 'Codice Fiscale',
        'billing_piva'          => 'Partita IVA',
        'billing_pec'           => 'PEC',
        'billing_sdi'           => 'Codice SDI/Univoco'
    ],
    'CONFIG_CORSO (PostMeta)' => [
        'moodle_course_id'      => 'ID Moodle',
        '_sku'                  => 'SKU Prodotto'
    ]
];

// =========================================================================
// 2. LOGICA DI AUDIT E CONFRONTO
// =========================================================================

echo "<h1>📊 Audit Strutturale: Master Map vs Sottositi</h1>";

foreach (WC_INSTANCE_MAPPING as $tag => $conf) {
    $dbName = $conf['wc_db_name']; //
    $prefix = $conf['wc_db_prefix']; //
    
    echo "<h2>Verifica Istanza: $tag (Prefisso: $prefix)</h2>";
    $conn = DBConnector::getWpDbByName($dbName); //
    
    if (!$conn) {
        echo "<p style='color:red;'>Errore connessione al database $dbName</p>";
        continue;
    }
    
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse; width:100%; font-family:sans-serif;'>";
    echo "<tr style='background:#333; color:#fff;'>
            <th>Gruppo</th>
            <th>Chiave Master (Attesa)</th>
            <th>Chiave Reale nel DB</th>
            <th>Stato</th>
          </tr>";
    
    foreach ($MASTER_MAPS as $area => $campi) {
        foreach ($campi as $masterKey => $label) {
            $foundKey = "---";
            $status = "<b style='color:red;'>❌ NON TROVATO</b>";
            $style = "style='background:#fce8e6;'";
            
            if ($area === 'ANAGRAFICA_GLOBALE (wpmei_usermeta)') {
                // Controllo nella tabella meta globale utenti
                $res = $conn->query("SELECT meta_key FROM wpmei_usermeta WHERE meta_key = '$masterKey' LIMIT 1");
                if ($res && $res->num_rows > 0) {
                    $foundKey = $masterKey;
                    $status = "<b style='color:green;'>✅ OK (Globale)</b>";
                    $style = "";
                }
            }
            elseif ($area === 'BILLING_FULL (Anagrafica Ordine)') {
                // Controllo colonne nella tabella locale indirizzi
                $res = $conn->query("SHOW COLUMNS FROM {$prefix}wc_order_addresses LIKE '$masterKey'");
                if ($res && $res->num_rows > 0) {
                    $foundKey = $masterKey;
                    $status = "<b style='color:green;'>✅ OK (Colonna)</b>";
                    $style = "";
                }
            }
            elseif ($area === 'META_FISCALI (Dati Fatturazione)') {
                // Ricerca varianti reali (es: billing_business_cf vs billing_cf)
                $varianti = [
                    'billing_cf'  => "('billing_cf', 'billing_business_cf', 'cf_user', 'agenas_cf')",
                    'billing_sdi' => "('billing_sdi', 'billing_codiceunivoco', 'billing_business_sdi')",
                    'billing_pec' => "('billing_pec', '_billing_pec')",
                    'billing_piva'=> "('billing_piva', '_billing_piva')"
                ];
                $lookup = $varianti[$masterKey] ?? "('$masterKey')";
                
                $sql = "SELECT DISTINCT meta_key FROM {$prefix}wc_orders_meta WHERE meta_key IN $lookup ORDER BY id DESC LIMIT 1";
                $res = $conn->query($sql);
                if ($res && $row = $res->fetch_assoc()) {
                    $foundKey = $row['meta_key'];
                    if ($foundKey === $masterKey) {
                        $status = "<b style='color:green;'>✅ OK</b>";
                        $style = "";
                    } else {
                        $status = "<b style='color:orange;'>⚠️ DISCREPANZA</b>";
                        $style = "style='background:#fff3cd;'";
                    }
                }
            }
            elseif ($area === 'CONFIG_CORSO (PostMeta)') {
                // Controllo configurazione prodotti
                $res = $conn->query("SELECT meta_key FROM {$prefix}postmeta WHERE meta_key = '$masterKey' LIMIT 1");
                if ($res && $res->num_rows > 0) {
                    $foundKey = $masterKey;
                    $status = "<b style='color:green;'>✅ OK</b>";
                    $style = "";
                }
            }
            
            echo "<tr $style>
                    <td><small>$area</small></td>
                    <td><code>$masterKey</code><br><small>$label</small></td>
                    <td><code>$foundKey</code></td>
                    <td>$status</td>
                  </tr>";
        }
    }
    echo "</table><br>";
}
?>