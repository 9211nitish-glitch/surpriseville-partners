<?php
// vendor/ajax/save_vendor_message.php
// Saves vendor→admin chat messages locally in surprise_main DB
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db.php';       // $conn (btnevents)
require_once __DIR__ . '/../../db_main.php';  // $mainConn (surpriseville_emp)

$vendor_id  = (int)$_SESSION['vendor_id'];
$message    = trim($_POST['message'] ?? '');
$task_id    = (int)($_POST['task_id'] ?? 0);
$order_id   = (int)($_POST['order_id'] ?? 0);
$is_offline = (int)($_POST['is_offline'] ?? 0);

if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Empty message']);
    exit;
}

// Check for phone numbers
$digitsOnly = preg_replace('/[^0-9]/', '', $message);
if (preg_match('/\d{10,}/', $digitsOnly)) {
    echo json_encode(['success' => false, 'error' => 'Sharing phone numbers is not allowed. / फ़ोन नंबर साझा करने की अनुमति नहीं है।']);
    exit;
}

if ($task_id <= 0 && $order_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'No task or order ID']);
    exit;
}

// Ensure columns exist
$mainConn->query("ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS is_read TINYINT(1) NOT NULL DEFAULT 0");
$mainConn->query("ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS sender_type VARCHAR(20) NOT NULL DEFAULT 'vendor'");

$is_read = 0;
if (stripos($message, 'call ended') !== false || 
    stripos($message, 'call declined') !== false || 
    stripos($message, 'call missed') !== false || 
    stripos($message, 'cancelled/missed') !== false) {
    $is_read = 1;
}

if ($is_offline || $task_id > 0) {
    // Offline gig — save with task_id
    $stmt = $mainConn->prepare("
        INSERT INTO chat_messages (task_id, order_id, sender_id, sender_type, message, is_read, created_at)
        VALUES (?, 0, ?, 'vendor', ?, ?, NOW())
    ");
    $stmt->bind_param("iisi", $task_id, $vendor_id, $message, $is_read);
} else {
    // Shop order — save with order_id
    $stmt = $mainConn->prepare("
        INSERT INTO chat_messages (task_id, order_id, sender_id, sender_type, message, is_read, created_at)
        VALUES (0, ?, ?, 'vendor', ?, ?, NOW())
    ");
    $stmt->bind_param("iisi", $order_id, $vendor_id, $message, $is_read);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $mainConn->insert_id]);
} else {
    echo json_encode(['success' => false, 'error' => $mainConn->error]);
}
$stmt->close();
?>
