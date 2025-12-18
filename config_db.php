<?php
// File: config_db.php

// --- Modalità Applicazione ---
define('APP_MODE', 'PRODUCTION'); // Opzioni: 'TEST', 'PRODUCTION'

// --- Credenziali Database Comuni ---
// Credenziali per accedere ai database WordPress (devono avere accesso a tutti i DB WP elencati sotto)
define('WP_DB_HOST', '192.168.11.16'); // Host comune DB WordPress
define('WP_DB_USER', 'wordpress');    // Utente comune DB WordPress
define('WP_DB_PASS', '$$!.w0rDpr3sS.!$$'); // Password comune DB WordPress <- SOSTITUISCI!

// Credenziali per accedere ai database Moodle (devono avere accesso a tutti i DB Moodle elencati sotto)
define('MOODLE_DB_HOST', '192.168.11.16'); // Host comune DB Moodle
define('MOODLE_DB_USER', 'moodle');       // Utente comune DB Moodle
define('MOODLE_DB_PASS', 'RmnPbT78');    // Password comune DB Moodle <- SOSTITUISCI!

// Database Moodle Apps Admin (per tabella 'results' e 'moodle_payments') - Potrebbe essere diverso
/*
define('DB_HOST_MDLAPPS', '192.168.11.16');
define('DB_USER_MDLAPPS', 'mdlapps');
define('DB_PASS_MDLAPPS', 'RmnPbT78');
define('DB_NAME_MDLAPPS', 'mdlapps_moodleadmin');
*/
define('DB_HOST_MDLAPPS', 'localhost');
define('DB_USER_MDLAPPS', 'root');
define('DB_PASS_MDLAPPS', '');
define('DB_NAME_MDLAPPS', 'paypal');

// --- Mappatura Istanza WooCommerce -> Moodle ---
// !!! IMPORTANTE: Compila questo array con i dati corretti per OGNI tua istanza WC !!!
// Chiave: Prefisso univoco dell'Invoice ID PayPal (DEVE terminare con '-')
// Valore: Array associativo con:
//    'wc_db_name'   => Nome del database WordPress per questa istanza
//    'wc_db_prefix' => Prefisso delle tabelle WordPress per questa istanza (DEVE terminare con '_')
//    'moodle_db_name' => Nome del database Moodle associato a questa istanza WC

define('WC_INSTANCE_MAPPING', [
    'MeiOSS-' => [ // Prefisso per la prima istanza WC
        'wc_db_name'   => 'wp_mei', // Esempio: DB WP per MeiOSS
        'wc_db_prefix' => 'wpmei_33_',      // Esempio: Prefisso tabelle per MeiOSS
        'moodle_db_name' => 'mdl_formazioneoss' // Esempio: DB Moodle associato
    ],
    /*
    'SITEB-' => [ // Prefisso per la seconda istanza WC
        'wc_db_name'   => 'db_siteb',       // Esempio: DB WP per Sito B
        'wc_db_prefix' => 'wpb_',           // Esempio: Prefisso tabelle per Sito B
        'moodle_db_name' => 'mdl_altra_istanza' // Esempio: DB Moodle associato
    ],
    */
    // Aggiungi qui una voce per ogni istanza WooCommerce che usa questo script/PayPal
]);


// --- Credenziali PayPal ---
define('PAYPAL_ENVIRONMENT', 'PRODUCTION'); // O 'SANDBOX'
define('PAYPAL_CLIENT_ID', 'AaKMyL45nw0_oFMv3xkJV72Uw7bk7DDCUkIgDyAGaY4g1gyw5WwSAG8meH8fXVeNmYzZ1YQM3FoMNG9j'); // <- SOSTITUISCI SE NECESSARIO
define('PAYPAL_SECRET', 'EB7xdC1tjFaR86wNSItX6U5axnpn4Mnijr2qjRZF1tkoQYHJRB7s0zc-lDPet4YgzLJSfauNudaZKyHH'); // <- SOSTITUISCI!

// --- Credenziali SMTP (per email.php) ---
define('SMTP_HOST', 'mail.metmi.it');
define('SMTP_USER', 'giampiero.digregorio@metmi.it');
define('SMTP_PASS', '20ero$rio14'); // Password SMTP <- SOSTITUISCI!
define('SMTP_SECURE', 'tls');
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'giampiero.digregorio@metmi.it');
define('SMTP_FROM_NAME', 'Notifiche Pagamenti Express');

// --- Configurazioni Applicazione ---
// Costante Campo CF Moodle
define('MOODLE_CF_SHORTNAME', 'CF');

// Path file CSV di test (ora in paypal/logs)
define('TEST_OUTPUT_FILE', __DIR__ . '/logs/moodle_payments_test_output.csv');

// API Moodle SAP
// CK: ck_9df991309d606d2c5a11dea2d4de9f025450ff4e
// CS: cs_02d937e9e11f3d0c943a0e6c04654b5f7685a358
?>
