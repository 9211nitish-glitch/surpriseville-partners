<?php
// vendor/ajax/verify_recharge.php
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

$razorpay_order_id = $input['razorpay_order_id'] ?? '';
$razorpay_payment_id = $input['razorpay_payment_id'] ?? '';
$razorpay_signature = $input['razorpay_signature'] ?? '';
$amount = floatval($input['amount'] ?? 0);
$vendor_id = $_SESSION['vendor_id'];

if (!$razorpay_order_id || !$razorpay_payment_id || !$razorpay_signature) {
    echo json_encode(['success' => false, 'message' => 'Missing Signature Data']);
    exit;
}

// 1. Verify Signature
$generated_signature = hash_hmac('sha256', $razorpay_order_id . "|" . $razorpay_payment_id, $razorpay_key_secret);

if ($generated_signature === $razorpay_signature) {
    // SUCCESS
    try {
        $conn->begin_transaction();

        // Update Wallet
        $stmt = $conn->prepare("UPDATE vendor_wallet SET balance = balance + ? WHERE vendor_id = ?");
        $stmt->bind_param("di", $amount, $vendor_id);
        $stmt->execute();

        // Log Transaction
        $desc = "Wallet Recharge via Razorpay";
        $stmt = $conn->prepare("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES (?, 0, 'credit', ?, ?, 'completed', NOW())");
        $stmt->bind_param("ids", $vendor_id, $amount, $desc);
        $stmt->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Recharge successful']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Signature']);
}
