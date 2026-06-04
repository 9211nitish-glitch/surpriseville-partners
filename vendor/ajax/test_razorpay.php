<?php
// vendor/ajax/test_razorpay.php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/razorpay_config.php';

echo json_encode([
    'debug_key_id' => $razorpay_key_id,
    'debug_key_secret_length' => strlen($razorpay_key_secret),
    'test_api' => true
]);

$url = 'https://api.razorpay.com/v1/orders?count=1';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $razorpay_key_id . ':' . $razorpay_key_secret);
$response = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\n\nHTTP STATUS: " . $http_status . "\n";
echo "RESPONSE: " . $response;
