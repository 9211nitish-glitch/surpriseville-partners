<?php
// vendor/ajax/check_task_payment.php
session_start();
header('Content-Type: application/json');
require_once '../../db.php';

if (!isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Auth error']);
    exit;
}

$task_id = $_GET['task_id'] ?? 0;

if (!$task_id) {
    echo json_encode(['success' => false, 'message' => 'Missing Task ID']);
    exit;
}

$stmt = $conn->prepare("SELECT payment_status, amount_to_collect FROM manual_tasks WHERE id = ?");
$stmt->bind_param("i", $task_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row) {
    $isPaid = ($row['payment_status'] === 'paid' || $row['amount_to_collect'] <= 0);
    echo json_encode(['success' => true, 'payment_status' => $isPaid ? 'paid' : 'pending']);
} else {
    echo json_encode(['success' => false, 'message' => 'Task not found']);
}
