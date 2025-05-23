<?php
/**
 * File: connect.php
 * Gestisce le connessioni ai database necessari (MoodleApps, Specifici Moodle, Specifici WP).
 * Include la configurazione delle credenziali comuni da config_db.php.
 */

// Include le costanti di configurazione e credenziali DB
require_once __DIR__ . '/config_db.php';

/**
 * Classe DBConnector
 * Gestisce le connessioni ai database tramite pattern Singleton.
 */
class DBConnector {
    
    // Array statico per memorizzare le connessioni attive [chiave => oggetto mysqli]
    private static $connections = [];
    
    // Costruttore privato per impedire istanziazione diretta
    private function __construct() {}
    
    /**
     * Ottiene una connessione mysqli, creandola se non esiste o non è attiva.
     */
    private static function getConnection(string $key, string $host, string $user, string $pass, string $db): ?mysqli {
        if (!isset(self::$connections[$key]) || !(self::$connections[$key] instanceof mysqli) || !self::$connections[$key]->ping()) {
            mysqli_report(MYSQLI_REPORT_OFF);
            error_log("DBConnector: Tentativo connessione DB '$key' ($db @ $host)...");
            self::$connections[$key] = new mysqli($host, $user, $pass, $db);
            
            if (self::$connections[$key]->connect_error) {
                error_log("DBConnector: ERRORE connessione DB '$key' ($db @ $host): (" . self::$connections[$key]->connect_errno . ") " . self::$connections[$key]->connect_error);
                self::$connections[$key] = null;
                return null;
            }
            
            if (!self::$connections[$key]->set_charset("utf8mb4")) {
                error_log("DBConnector: Attenzione - Errore impostando charset utf8mb4 per DB '$key': " . self::$connections[$key]->error);
            }
            error_log("DBConnector: Connessione DB stabilita/verificata per '$key'.");
        }
        return self::$connections[$key];
    }
    
    // --- Metodi Pubblici per Connessioni Specifiche ---
    
    /**
     * Ottiene la connessione al DB WordPress specifico per un'istanza.
     * Usa le credenziali WP comuni definite in config_db.php.
     * @param string $dbName Nome del database WordPress specifico.
     * @return mysqli|null
     */
    public static function getWpDbByName(string $dbName): ?mysqli {
        if (empty($dbName)) {
            error_log("DBConnector: Richiesta connessione DB WP senza specificare dbName.");
            return null;
        }
        // Verifica che le costanti comuni WP siano definite
        if (!defined('WP_DB_HOST') || !defined('WP_DB_USER') || !defined('WP_DB_PASS')) {
            error_log("DBConnector: Costanti DB WordPress comuni (WP_DB_HOST, etc.) non definite in config_db.php.");
            return null;
        }
        // Usa una chiave univoca per questa connessione specifica
        $connectionKey = 'wp_' . $dbName;
        return self::getConnection($connectionKey, WP_DB_HOST, WP_DB_USER, WP_DB_PASS, $dbName);
    }
    
    /** Ottiene la connessione al DB Moodle Apps Admin. */
    public static function getMoodleAppsDb(): ?mysqli {
        if (!defined('DB_HOST_MDLAPPS') || !defined('DB_USER_MDLAPPS') || !defined('DB_PASS_MDLAPPS') || !defined('DB_NAME_MDLAPPS')) {
            error_log("DBConnector: Costanti DB MoodleApps (DB_HOST_MDLAPPS, etc.) non definite in config_db.php.");
            return null;
        }
        return self::getConnection('mdlapps', DB_HOST_MDLAPPS, DB_USER_MDLAPPS, DB_PASS_MDLAPPS, DB_NAME_MDLAPPS);
    }
    
    /**
     * Ottiene la connessione a un DB Moodle specifico basato sul nome.
     * Usa le credenziali Moodle comuni definite in config_db.php.
     * @param string $dbName Nome del DB Moodle (es. 'mdl_ati14')
     * @return mysqli|null
     */
    public static function getMoodleDbByName(string $dbName): ?mysqli {
        if (empty($dbName)) {
            error_log("DBConnector: Richiesta connessione DB Moodle senza specificare dbName.");
            return null;
        }
        // Verifica che le costanti comuni Moodle siano definite
        if (!defined('MOODLE_DB_HOST') || !defined('MOODLE_DB_USER') || !defined('MOODLE_DB_PASS')) {
            error_log("DBConnector: Costanti DB Moodle comuni (MOODLE_DB_HOST, etc.) non definite in config_db.php.");
            return null;
        }
        // Usa una chiave univoca per questa connessione specifica
        $connectionKey = 'moodle_' . $dbName;
        return self::getConnection($connectionKey, MOODLE_DB_HOST, MOODLE_DB_USER, MOODLE_DB_PASS, $dbName);
    }
    
    /** Chiude tutte le connessioni aperte gestite da questa classe. */
    public static function closeAllConnections(): void {
        error_log("DBConnector: Chiusura di tutte le connessioni DB gestite...");
        $closedCount = 0;
        foreach (self::$connections as $key => $conn) {
            if ($conn instanceof mysqli && isset($conn->thread_id) && $conn->thread_id) {
                $conn->close();
                error_log("DBConnector: Connessione DB '$key' chiusa.");
                $closedCount++;
            }
        }
        if ($closedCount === 0) { error_log("DBConnector: Nessuna connessione attiva da chiudere."); }
        self::$connections = [];
    }
    
    // --- RIMOSSA Logica Mappa Dinamica Corsi Moodle ---
    
} // --- FINE CLASSE DBCONNECTOR ---

?>
