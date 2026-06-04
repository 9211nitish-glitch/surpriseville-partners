<?php
// vendor/ajax/create_recharge_order.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../includes/razorpay_config.php';

if (!isset($razorpay_key_id) || !isset($razorpay_key_secret) || empty($razorpay_key_id)) {
    echo json_encode(['success' => false, 'message' => 'Razorpay Config Error: Keys not found or empty.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$amount = floatval($input['amount'] ?? 0);
$vendor_id = $_SESSION['vendor_id'];

if ($amount < 10) {
    echo json_encode(['success' => false, 'message' => 'Minimum recharge amount is ₹10']);
    exit;
}

// Razorpay Order Data
$data = [
    'receipt'         => 'rcg_' . $vendor_id . '_' . time(),
    'amount'          => $amount * 100, // in paise
    'currency'        => 'INR',
    'payment_capture' => 1
];

$url = 'https://api.razorpay.com/v1/orders';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, $razorpay_key_id . ':' . $razorpay_key_secret);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
$response = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$resData = json_decode($response, true);

if ($http_status === 200 && isset($resData['id'])) {
    echo json_encode([
        'success' => true,
        'razorpay_order_id' => $resData['id'],
        'amount' => $resData['amount'],
        'key' => $razorpay_key_id
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Razorpay Error: ' . ($resData['error']['description'] ?? 'Unknown Error'), 'debug' => $resData]);
}
