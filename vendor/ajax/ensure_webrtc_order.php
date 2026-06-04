<?php
// vendor/ajax/ensure_webrtc_order.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['vendor_id']) && (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db_main.php'; // $mainConn (surpriseville_emp)

$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($task_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

// Check if order exists in surpriseville_emp.orders
$stmt = $mainConn->prepare("SELECT id FROM orders WHERE id = ?");
$stmt->bind_param("i", $task_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    // Insert dummy order row with specific ID
    $stmt = $mainConn->prepare("
        INSERT INTO orders (id, user_id, total_amount, status, payment_status)
        VALUES (?, 1, 0.00, 'confirmed', 'paid')
    ");
    $stmt->bind_param("i", $task_id);
    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => true, 'created' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'error' => $mainConn->error]);
        $stmt->close();
        exit;
    }
}

echo json_encode(['success' => true, 'created' => false]);
exit;
?>
