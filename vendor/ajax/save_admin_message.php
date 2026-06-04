<?php
// vendor/ajax/save_admin_message.php
// Saves admin→vendor messages to local DB so vendor notification works
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db.php';       // $conn (btnevents)
require_once __DIR__ . '/../../db_main.php';  // $mainConn

$message     = trim($_POST['message'] ?? '');
$task_id     = (int)($_POST['task_id'] ?? 0);
$order_id    = (int)($_POST['order_id'] ?? 0);
$sender_type = 'admin';

if (empty($message) || ($task_id <= 0 && $order_id <= 0)) {
    echo json_encode(['success' => false, 'error' => 'Missing data']);
    exit;
}

// Ensure columns exist (pre-created during migration)
$is_read = 0;
if (stripos($message, 'call ended') !== false || 
    stripos($message, 'call declined') !== false || 
    stripos($message, 'call missed') !== false || 
    stripos($message, 'cancelled/missed') !== false) {
    $is_read = 1;
}

if ($task_id > 0) {
    $stmt = $mainConn->prepare("
        INSERT INTO chat_messages (task_id, order_id, sender_id, sender_type, message, is_read, created_at)
        VALUES (?, 0, 1, 'admin', ?, ?, NOW())
    ");
    $stmt->bind_param("isi", $task_id, $message, $is_read);
} else {
    $stmt = $mainConn->prepare("
        INSERT INTO chat_messages (task_id, order_id, sender_id, sender_type, message, is_read, created_at)
        VALUES (0, ?, 1, 'admin', ?, ?, NOW())
    ");
    $stmt->bind_param("isi", $order_id, $message, $is_read);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $mainConn->error]);
}
$stmt->close();
?>
