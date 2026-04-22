<?php
require 'c:/laragon/www/woocommerce/script/config_db.php';

function getAccessToken() {
    $apiBase = (PAYPAL_ENVIRONMENT === 'PRODUCTION') ? "https://api-m.paypal.com" : "https://api-m.sandbox.paypal.com";
    $ch = curl_init($apiBase . "/v1/oauth2/token");
    curl_setopt($ch, CURLOPT_USERPWD, PAYPAL_CLIENT_ID . ":" . PAYPAL_SECRET);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $result['access_token'] ?? null;
}

$token = getAccessToken();
if (!$token) die("No token");

$txId = '7FN769633D219344J';
$start = '2026-03-24T00:00:00Z';
$end = '2026-03-31T23:59:59Z';

$url = "https://api-m.paypal.com/v1/reporting/transactions?start_date=$start&end_date=$end&fields=all&transaction_id=$txId";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "Authorization: Bearer " . $token]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$data = json_decode(curl_exec($ch), true);
curl_close($ch);

print_r($data);
