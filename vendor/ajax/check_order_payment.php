<?php
// vendor/ajax/check_order_payment.php
session_start();
header('Content-Type: application/json');
require_once '../../db_main.php'; // Uses main database for orders

if (!isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Auth error']);
    exit;
}

$order_id = $_GET['order_id'] ?? 0;

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Missing Order ID']);
    exit;
}

// Check if vendor is authorized for this order
$vendor_id = $_SESSION['vendor_id'];
$qAuth = $mainConn->prepare("SELECT id FROM order_vendor_assignments WHERE order_id = ? AND vendor_id = ? LIMIT 1");
$qAuth->bind_param("ii", $order_id, $vendor_id);
$qAuth->execute();
if ($qAuth->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$stmt = $mainConn->prepare("SELECT payment_status, remaining_amount FROM orders WHERE id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row) {
    // Only consider it 'verified' for the vendor if the remaining amount is 0
    // Sometimes payment_status is 'paid' from the start due to deposit
    $isPaid = ($row['remaining_amount'] <= 0);
    echo json_encode(['success' => true, 'payment_status' => $isPaid ? 'paid' : 'pending']);
} else {
    echo json_encode(['success' => false, 'message' => 'Order not found']);
}

if (isset($mainConn)) $mainConn->close();
