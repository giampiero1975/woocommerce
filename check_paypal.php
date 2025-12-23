<?php
set_time_limit(300); // Aumentiamo il tempo di esecuzione per scaricare tante pagine

// ==================================================================
// CONFIGURAZIONE CREDENZIALI
// ==================================================================
$clientId = 'AaKMyL45nw0_oFMv3xkJV72Uw7bk7DDCUkIgDyAGaY4g1gyw5WwSAG8meH8fXVeNmYzZ1YQM3FoMNG9j';
$secret   = 'EB7xdC1tjFaR86wNSItX6U5axnpn4Mnijr2qjRZF1tkoQYHJRB7s0zc-lDPet4YgzLJSfauNudaZKyHH';
$isLive   = true;

// ==================================================================
// DATE
// ==================================================================
$defaultStart = date('Y-m-d', strtotime('-5 days'));
$defaultEnd   = date('Y-m-d');
$startDateInput = $_GET['start_date'] ?? $defaultStart;
$endDateInput   = $_GET['end_date'] ?? $defaultEnd;

$apiStartTime = date('Y-m-d\TH:i:s\Z', strtotime($startDateInput . ' 00:00:00'));
$apiEndTime   = date('Y-m-d\TH:i:s\Z', strtotime($endDateInput . ' 23:59:59'));

// ==================================================================
// 1. TOKEN
// ==================================================================
$host = $isLive ? 'https://api.paypal.com' : 'https://api.sandbox.paypal.com';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $host . "/v1/oauth2/token");
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $clientId . ":" . $secret);
curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
$result = curl_exec($ch);
$json = json_decode($result);
if (empty($result) || isset($json->error)) { die("ERRORE TOKEN: " . print_r($json, true)); }
$accessToken = $json->access_token;
curl_close($ch);

// ==================================================================
// 2. CICLO DI SCARICAMENTO PAGINE (PAGINAZIONE)
// ==================================================================
$allTransactions = [];
$page = 1;
$totalPages = 1;

do {
    // Parametri per questa pagina
    $queryParams = [
        'start_date' => $apiStartTime,
        'end_date'   => $apiEndTime,
        'fields'     => 'all',
        'page_size'  => 100, // Scarichiamo blocchi da 100
        'page'       => $page
    ];
    
    $url = $host . "/v1/reporting/transactions?" . http_build_query($queryParams);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $accessToken]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);
    
    // Aggiungiamo i risultati all'array totale
    if (isset($data['transaction_details'])) {
        $allTransactions = array_merge($allTransactions, $data['transaction_details']);
        
        // Aggiorniamo il numero totale di pagine previste
        if (isset($data['total_pages'])) {
            $totalPages = $data['total_pages'];
        }
    } else {
        // Se c'è un errore o è vuoto, usciamo
        break;
    }
    
    $page++; // Passiamo alla prossima pagina
    
    // Sicurezza per non andare in loop infinito se API impazzisce
    if ($page > 50) break;
    
} while ($page <= $totalPages);

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <style>
        body { font-family: sans-serif; padding: 20px; background-color: #f4f4f4;}
        .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 8px; font-size: 13px; vertical-align: top;}
        th { background: #003087; color: white; }
        .hl { background: #e6f2ff; font-weight: bold; color: #004085; }
        .farmacia { background: #d4edda; border: 2px solid #28a745; } /* Evidenziazione */
    </style>
</head>
<body>
<div class="container">
    <h2>🔎 Analisi PayPal Reporting (Totale Pagine: <?php echo $totalPages; ?>)</h2>
    
    <form method="GET" style="background:#eef; padding:15px; border-radius:5px; margin-bottom:20px;">
        <label>Dal:</label>
        <input type="date" name="start_date" value="<?php echo $startDateInput; ?>">
        <label>Al:</label>
        <input type="date" name="end_date" value="<?php echo $endDateInput; ?>">
        <button type="submit" style="cursor:pointer; padding:5px 15px; background:#0070ba; color:white; border:none; border-radius:3px;">SCARICA TUTTO</button>
    </form>

    <?php if (count($allTransactions) > 0): ?>
        <p>Scaricate <b><?php echo count($allTransactions); ?></b> transazioni totali.</p>
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Importo</th>
                    <th class="hl">Invoice ID (Num Ordine)</th>
                    <th class="hl">Custom / Note</th>
                    <th class="hl">Subject / Item</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allTransactions as $t): ?>
                <?php 
                    $info = $t['transaction_info'];
                    $amount = $info['transaction_amount']['value'];
                    if ($amount < 0) continue; // Nascondi uscite
                    
                    // Controlli per evidenziare potenziali pagamenti Farmacia
                    $rowClass = '';
                    $rawString = json_encode($t);
                    if (stripos($rawString, 'PF-') !== false || stripos($rawString, 'Farm') !== false) {
                        $rowClass = 'farmacia';
                    }
                ?>
                <tr class="<?php echo $rowClass; ?>">
                    <td><?php echo date('d/m/Y H:i', strtotime($info['transaction_updated_date'])); ?></td>
                    <td style="color:green; font-weight:bold;"><?php echo $amount . ' ' . $info['transaction_amount']['currency_code']; ?></td>
                    
                    <td class="hl"><?php echo $info['invoice_id'] ?? '--'; ?></td>
                    
                    <td class="hl">
                        <?php 
                            if(!empty($info['custom_field'])) echo "CUST: " . $info['custom_field'] . "<br>";
                            if(!empty($info['transaction_note'])) echo "NOTE: " . $info['transaction_note'];
                        ?>
                    </td>
                    
                    <td class="hl">
                        <?php echo $info['transaction_subject'] ?? ''; ?>
                        <?php 
                            if(isset($t['cart_info']['item_details'])){
                                foreach($t['cart_info']['item_details'] as $item){
                                    echo "<br>• " . $item['item_name'];
                                }
                            }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <h3 style="color:red">Nessuna transazione trovata.</h3>
    <?php endif; ?>
</div>
</body>
</html>