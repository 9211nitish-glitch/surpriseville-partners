<?php
// vendor/vendor_decline_job.php
session_start();
header('Content-Type: application/json');

// Error handling settings (Hide from output, log to file if needed)
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once '../db.php';

// 1. Auth Check
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login']);
    exit;
}

$vendor_id = (int)$_SESSION['vendor_id'];
$order_id  = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
$reason    = isset($_POST['reason']) ? trim($_POST['reason']) : 'Declined by Vendor';

// 2. Input Validation
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID']);
    exit;
}

// 3. Check if the notification exists and is 'pending'
$checkStmt = $conn->prepare("SELECT id FROM order_vendor_notifications WHERE vendor_id = ? AND order_id = ? AND status = 'pending'");
$checkStmt->bind_param("ii", $vendor_id, $order_id);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows === 0) {
    $checkStmt->close();
    echo json_encode(['success' => false, 'message' => 'This order is no longer available or already processed.']);
    exit;
}
$checkStmt->close();

// 4. Update Status to 'declined'
$now = date('Y-m-d H:i:s');

// We perform the update
$stmt = $conn->prepare("UPDATE order_vendor_notifications SET status = 'declined', responded_at = ?, remark = ? WHERE vendor_id = ? AND order_id = ?");
$stmt->bind_param("ssii", $now, $reason, $vendor_id, $order_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Job declined successfully.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>