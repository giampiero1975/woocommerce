<?php
/**
 * check_order_debug.php - Versione Finale con Scomposizione Economica Corretta
 * Percorso: C:\laragon\www\woocommerce\script\check_order_debug.php
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/inc/config.php';
require_once $rootDir . '/WooCommerceModel.php';

$orderId = $_GET['id'] ?? null;
if (!$orderId) { die("Uso: ?id=10621"); }

// 1. Configurazione Log Specifico per ID Ordine
$logPath = $rootDir . '/logs';
if (!is_dir($logPath)) { mkdir($logPath, 0777, true); }
$logFile = $logPath . "/order_{$orderId}.log";

$ts = "[" . date('Y-m-d D H:i:s') . "]";

try {
    $configObj = new costanti();
    $foundOrder = null;
    $instanceKey = null;
    $wcModel = null;
    
    // 2. Ricerca dell'ordine su tutte le istanze configurate
    foreach ($configObj::WOOCOMMERCE_INSTANCES as $slug => $conf) {
        try {
            $tempModel = new WooCommerceModel($slug);
            $order = $tempModel->getOrderById($orderId);
            if ($order && isset($order['id'])) {
                $foundOrder = $order;
                $instanceKey = $slug;
                $wcModel = $tempModel;
                break;
            }
        } catch (Exception $e) { continue; }
    }
    
    if (!$foundOrder) { die("<h1>Ordine #$orderId non trovato.</h1>"); }
    
    // 3. Elaborazione Dati
    $nomeCorso = $configObj->instances[$instanceKey]['corso'] ?? "Sito: $instanceKey";
    $sintesi = $wcModel->getSyntheticOrder($foundOrder);
    $billing = $foundOrder['billing'];
    
    // Gestione Metadati Fiscali (Azienda vs Persona)
    $meta = $sintesi['META_FISCALI'];
    $cfAzienda = $meta['billing_business_cf'] ?? $meta['_billing_business_cf'] ?? '';
    $cfPersona = $meta['billing_cf'] ?? $meta['_billing_cf'] ?? $meta['cf_user'] ?? '';
    $bacsDate  = $meta['bacs_date'] ?? $meta['_bacs_date'] ?? null;
    
    // --- SCOMPOSIZIONE ECONOMICA PRECISA ---
    $totalePagato = (float)($foundOrder['total'] ?? 0);
    $totaleIva    = (float)($foundOrder['total_tax'] ?? 0);
    $bollo        = (float)($sintesi['BOLLI_FEES'][0]['IMPORTO'] ?? 0);
    // L'imponibile netto degli articoli (senza IVA e senza bolli)
    $imponibileNetto = $totalePagato - $totaleIva - $bollo;
    
    // Scrittura Log per Disamina
    $logContent = "$ts ANALISI #$orderId\nCORSO: $nomeCorso\n" . var_export($sintesi, true);
    file_put_contents($logFile, $logContent, FILE_APPEND);
    
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f4f7f6; padding: 20px; color: #333; }
            .container { max-width: 1000px; margin: 0 auto; }
            .box { background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; overflow: hidden; border-left: 5px solid #004085; }
            .box-header { background: #004085; color: white; padding: 10px 20px; font-weight: bold; display: flex; justify-content: space-between; }
            .box-content { padding: 15px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; width: 35%; color: #777; padding: 6px 0; border-bottom: 1px solid #eee; font-size: 0.9em; }
            td { padding: 6px 0; border-bottom: 1px solid #eee; font-weight: 500; }
            .price-total { color: #28a745; font-size: 1.3em; font-weight: bold; }
            .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.8em; background: #28a745; color: white; }
            .bacs-info { color: #d9534f; font-weight: bold; font-size: 0.9em; }
        </style>
    </head>
    <body>
    <div class="container">
        <h1>🔍 Debug Ordine #<?php echo $orderId; ?></h1>

        <div class="box" style="border-left-color: #ffc107;">
            <div class="box-header" style="background: #ffc107; color: #333;">🎓 CORSO E METODO</div>
            <div class="box-content">
                <table>
                    <tr><th>Corso</th><td><?php echo $nomeCorso; ?></td></tr>
                    <tr><th>Istanza / Stato</th><td><code><?php echo $instanceKey; ?></code> / <span class="badge"><?php echo strtoupper($foundOrder['status']); ?></span></td></tr>
                    <tr><th>Pagamento</th>
                        <td><?php echo $foundOrder['payment_method_title']; ?> 
                            <?php if ($foundOrder['payment_method'] === 'bacs'): ?>
                                <span class="bacs-info"> (Data Valuta: <?php echo $bacsDate ?: 'NON INSERITA'; ?>)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="box">
            <div class="box-header">👤 ANAGRAFICA E FISCALE</div>
            <div class="box-content">
                <table>
                    <tr><th>Cliente</th><td><?php echo strtoupper($billing['first_name'] . ' ' . $billing['last_name']); ?></td></tr>
                    <tr><th>Ragione Sociale</th><td><?php echo strtoupper($billing['company'] ?: 'Privato'); ?></td></tr>
                    <tr><th>CF Azienda (business_cf)</th><td><code><?php echo $cfAzienda ?: '---'; ?></code></td></tr>
                    <tr><th>CF Persona (billing_cf)</th><td><code><?php echo $cfPersona ?: '---'; ?></code></td></tr>
                    <tr><th>Partita IVA / SDI</th><td><?php echo $meta['billing_piva'] ?? '---'; ?> / <?php echo $meta['billing_codiceunivoco'] ?? '---'; ?></td></tr>
                </table>
            </div>
        </div>

        <div class="box" style="border-left-color: #17a2b8;">
            <div class="box-header" style="background: #17a2b8;">💰 SINTESI ECONOMICA</div>
            <div class="box-content">
                <table>
                    <tr><th>Imponibile Netto Articoli</th><td>€ <?php echo number_format($imponibileNetto, 2); ?></td></tr>
                    <tr><th>Totale IVA</th><td>€ <?php echo number_format($totaleIva, 2); ?></td></tr>
                    <tr><th>Marca da Bollo</th><td>€ <?php echo number_format($bollo, 2); ?></td></tr>
                    <tr style="background: #f9f9f9; border-top: 2px solid #ddd;">
                        <th style="font-size: 1.1em; color: #333;">TOTALE ORDINE (Grand Total)</th>
                        <td class="price-total">€ <?php echo number_format($totalePagato, 2); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="box" style="border-left-color: #28a745;">
            <div class="box-header" style="background: #28a745;">📦 DETTAGLIO RIGHE ORDINE (<?php echo count($sintesi['ARTICOLI']); ?>)</div>
            <div class="box-content">
                <table>
                    <tr style="color: #999; font-size: 0.8em;"><th>DESCRIZIONE</th><th>SKU</th><th style="text-align: center;">QTY</th><th style="text-align: right;">NETTO UNITARIO</th></tr>
                    <?php foreach ($sintesi['ARTICOLI'] as $item): ?>
                    <tr>
                        <td style="font-size: 0.9em;"><?php echo $item['NOME']; ?></td>
                        <td><code><?php echo $item['SKU']; ?></code></td>
                        <td style="text-align: center;">x <?php echo $item['QTY']; ?></td>
                        <td style="text-align: right;">€ <?php echo number_format($item['NETTO'] / $item['QTY'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php

} catch (Exception $e) { echo "<h1>Errore: " . $e->getMessage() . "</h1>"; }