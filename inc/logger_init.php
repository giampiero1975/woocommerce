<?php
// File: inc/logger_init.php
/**
 * Inizializza e restituisce un'istanza di Monolog Logger.
 * Adattato per usare le costanti Logger::LEVEL (Monolog 1.x / 2.x).
 */

// *** INCLUDE AUTOLOADER ALL'INIZIO ***
// __DIR__ è C:\laragon\www\paypal\inc
// dirname(__DIR__) è C:\laragon\www\paypal
$autoloaderPath = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoloaderPath)) {
    $errorMsg = "ERRORE CRITICO: Autoloader Composer non trovato in '$autoloaderPath'. Esegui 'composer install' in C:\\laragon\\www\\paypal\\";
    error_log($errorMsg);
    // Definisci una funzione fittizia se l'autoloader manca
    if (!function_exists('getAppLogger')) {
        function getAppLogger($c='NULL',$f='null',$l=0) { return new class { public function __call($n,$a){} }; }
    }
    return; // Esce dall'inclusione di questo file
}
require_once $autoloaderPath; // Include l'autoloader

// Importa le classi DOPO aver incluso l'autoloader
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\NullHandler;

// Definisci la funzione per ottenere il logger
if (!function_exists('getAppLogger')) {
    /**
     * Crea e configura un'istanza di Monolog Logger.
     *
     * @param string $channelName Nome del canale
     * @param string $fileName Nome file log
     * @param int $logLevel Livello minimo log (costante Logger::LEVEL)
     * @param string $logDirectory Percorso directory log
     * @return Logger Istanza Monolog.
     */
    function getAppLogger(string $channelName = 'APP', string $fileName = 'app', int $logLevel = Logger::DEBUG, string $logDirectory = ''): Logger
    {
        if (empty($logDirectory)) {
            // Log nella root del progetto (C:\laragon\www\logs)
            $logDirectory = dirname(dirname(dirname(__DIR__))) . '/logs';
            // Se preferisci i log DENTRO paypal (C:\laragon\www\paypal\logs)
            // $logDirectory = dirname(__DIR__) . '/logs';
        }
        $filePath = rtrim($logDirectory, '/') . '/' . $fileName . '.log';
        $logger = new Logger($channelName);
        
        try {
            if (!is_dir($logDirectory)) { if (!@mkdir($logDirectory, 0775, true)) { throw new \RuntimeException("Impossibile creare dir log: {$logDirectory}"); } }
            if (!is_writable($logDirectory)) { throw new \RuntimeException("Dir log non scrivibile: {$logDirectory}"); }
            
            $outputFormat = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
            $dateFormat = "Y-m-d H:i:s";
            $formatter = new LineFormatter($outputFormat, $dateFormat, false, true);
            $handler = new RotatingFileHandler($filePath, 7, $logLevel); // Usa la costante INT
            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);
            error_log("(logger_init) Monolog inizializzato per '$channelName' su '$filePath' con livello " . Logger::getLevelName($logLevel)); // Usa Logger::getLevelName
            
        } catch (\Exception $e) {
            $errorMessage = "(logger_init) Errore inizializzazione Monolog per '$channelName': " . $e->getMessage();
            error_log($errorMessage);
            $logger = new Logger($channelName);
            $logger->pushHandler(new NullHandler());
            // if (function_exists('sendAdminNotification')) { sendAdminNotification("Errore Monolog", ['error' => $errorMessage]); }
        }
        return $logger;
    }
}
?>