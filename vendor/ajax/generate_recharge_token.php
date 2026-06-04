<?php
// vendor/ajax/generate_recharge_token.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$amount = $input['amount'] ?? ($_GET['amount'] ?? 0);
$vendor_id = $_SESSION['vendor_id'];
$task_id = $input['task_id'] ?? ($_GET['task_id'] ?? 0);

if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount']);
    exit;
}

// Security Token calculation using the shared secret
// If task_id is present, we use it for gig payment,
// If order_id is present, we use it for shop order payment,
// else we use vendor_id for recharge
if ($task_id > 0) {
    $token = hash_hmac('sha256', $task_id . $amount, $RECHARGE_SECRET);
} elseif (isset($input['order_id']) || isset($_GET['order_id'])) {
    $order_id = $input['order_id'] ?? $_GET['order_id'];
    $token = hash_hmac('sha256', $order_id . $amount, $RECHARGE_SECRET);
} else {
    $token = hash_hmac('sha256', $vendor_id . $amount, $RECHARGE_SECRET);
}

echo json_encode([
    'success' => true,
    'token' => $token
]);
