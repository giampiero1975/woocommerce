<?php
/**
 * check_order_debug.php - Versione Integrale con Layout Allargato
 * Percorso: C:\laragon\www\woocommerce\script\check_order_debug.php
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/inc/config.php';
require_once $rootDir . '/WooCommerceModel.php';

try {
    $configObj = new costanti();
    
    // 1. MODALITÀ LISTA ISTANZE
    if (isset($_GET['list'])) {
        echo "<h1>Istanze WooCommerce configurate:</h1><ul>";
        foreach ($configObj::WOOCOMMERCE_INSTANCES as $slug => $conf) {
            echo "<li><strong>Slug:</strong> <code>$slug</code> | <strong>URL:</strong> {$conf['url']}</li>";
        }
        echo "</ul>";
        exit;
    }
    
    $orderId = $_GET['id'] ?? null;
    $targetInst = $_GET['inst'] ?? null;
    
    if (!$orderId) {
        die("<h1>Errore: ID mancante.</h1><p>Uso: <code>?id=7503&inst=mdl_professionefarmacia</code></p>");
    }
    
    // 2. RICERCA ORDINE CON FILTRO ISTANZA
    $foundOrder = null;
    $instanceKey = null;
    $wcModel = null;
    
    foreach ($configObj::WOOCOMMERCE_INSTANCES as $slug => $conf) {
        if ($targetInst && $slug !== $targetInst) continue;
        
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
    
    if (!$foundOrder) {
        die("<h1>Ordine #$orderId non trovato nell'istanza '$targetInst'.</h1>");
    }
    
    // 3. ELABORAZIONE DATI
    $nomeCorso = $configObj->instances[$instanceKey]['corso'] ?? "Sito: $instanceKey";
    $sintesi = $wcModel->getSyntheticOrder($foundOrder);
    $billing = $foundOrder['billing'];
    $meta = $sintesi['META_FISCALI'];
    
    // Identificazione campi fiscali
    $cfAzienda = $meta['billing_business_cf'] ?? $meta['_billing_business_cf'] ?? '';
    $cfPersona = $meta['billing_cf'] ?? $meta['_billing_cf'] ?? $meta['cf_user'] ?? '';
    
    // Scomposizione Economica
    $totalePagato = (float)($foundOrder['total'] ?? 0);
    $totaleIva    = (float)($foundOrder['total_tax'] ?? 0);
    $bollo        = (float)($sintesi['BOLLI_FEES'][0]['IMPORTO'] ?? 0);
    $imponibileNetto = $totalePagato - $totaleIva - $bollo;
    
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <title>Debug Ordine #<?php echo $orderId; ?></title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; padding: 30px; color: #333; }
            /* Larghezza allargata a 1200px per migliore leggibilità */
            .container { max-width: 1200px; margin: 0 auto; }
            .box { background: white; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); margin-bottom: 25px; overflow: hidden; border-left: 6px solid #004085; }
            .box-header { background: #004085; color: white; padding: 12px 25px; font-weight: bold; font-size: 1.1em; }
            /* Padding interno aumentato */
            .box-content { padding: 25px; }
            table { width: 100%; border-collapse: collapse; }
            th { text-align: left; width: 30%; color: #666; padding: 12px 0; border-bottom: 1px solid #eee; font-size: 0.95em; text-transform: uppercase; letter-spacing: 0.5px; }
            td { padding: 12px 0; border-bottom: 1px solid #eee; font-weight: 500; font-size: 1em; }
            .price-total { color: #28a745; font-size: 1.5em; font-weight: bold; }
            .badge { padding: 5px 12px; border-radius: 6px; font-size: 0.85em; background: #28a745; color: white; font-weight: bold; }
            .qty-text { color: #d63384; font-weight: bold; font-size: 1.2em; }
            code { background: #f1f3f5; padding: 3px 6px; border-radius: 4px; font-family: 'Courier New', Courier, monospace; color: #e83e8c; }
            
            /* Stile specifico per la tabella prodotti per evitare affollamento */
            .table-products th { width: auto; }
            .table-products td { vertical-align: middle; }
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
                    <tr><th>Pagamento</th><td><?php echo $foundOrder['payment_method_title']; ?></td></tr>
                </table>
            </div>
        </div>

        <div class="box">
            <div class="box-header">👤 ANAGRAFICA E FISCALE</div>
            <div class="box-content">
                <table>
                    <tr><th>Cliente</th><td><?php echo strtoupper($billing['first_name'] . ' ' . $billing['last_name']); ?></td></tr>
                    <tr><th>Ragione Sociale</th><td><?php echo strtoupper($billing['company'] ?: 'Privato'); ?></td></tr>
                    <tr><th>CF Azienda</th><td><code><?php echo $cfAzienda ?: '---'; ?></code></td></tr>
                    <tr><th>CF Persona</th><td><code><?php echo $cfPersona ?: '---'; ?></code></td></tr>
                    <tr><th>Partita IVA / SDI</th><td><?php echo ($meta['billing_piva'] ?? '---') . ' / ' . ($meta['billing_codiceunivoco'] ?? '---'); ?></td></tr>
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
                    <tr style="background: #fcfcfc; border-top: 2px solid #eee;">
                        <th style="font-size: 1.1em; color: #333; padding-top: 20px;">TOTALE ORDINE (Grand Total)</th>
                        <td class="price-total" style="padding-top: 20px;">€ <?php echo number_format($totalePagato, 2); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="box" style="border-left-color: #28a745;">
            <div class="box-header" style="background: #28a745;">📦 DETTAGLIO RIGHE ORDINE (<?php echo count($sintesi['ARTICOLI']); ?>)</div>
            <div class="box-content">
                <table class="table-products">
                    <tr style="color: #888; font-size: 0.85em;">
                        <th style="text-align: left;">DESCRIZIONE</th>
                        <th style="text-align: left;">SKU</th>
                        <th style="text-align: center;">QTY</th>
                        <th style="text-align: right;">UNITARIO NETTO</th>
                        <th style="text-align: right;">TOTALE RIGA</th>
                    </tr>
                    <?php foreach ($sintesi['ARTICOLI'] as $item): ?>
                    <tr>
                        <td style="font-size: 0.95em; width: 45%;"><?php echo $item['NOME']; ?></td>
                        <td style="width: 15%;"><code><?php echo $item['SKU']; ?></code></td>
                        <td style="text-align: center; width: 10%;" class="qty-text">x <?php echo $item['QTY']; ?></td>
                        <td style="text-align: right; width: 15%;">€ <?php echo number_format($item['NETTO'] / $item['QTY'], 2); ?></td>
                        <td style="text-align: right; width: 15%; font-weight: bold;">€ <?php echo number_format((float)$item['NETTO'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php

} catch (Exception $e) { 
    echo "<div style='background:#fff5f5; color:#c0392b; padding:20px; border-radius:8px; border:1px solid #f5c6cb; font-family:sans-serif;'>";
    echo "<h2>❌ Errore di Sistema</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}