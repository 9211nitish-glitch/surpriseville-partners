<?php
ob_start();
if (file_exists(__DIR__ . '/../includes/session_manager.php')) {
    require_once __DIR__ . '/../includes/session_manager.php';
} else {
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['vendor_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db.php';       // $conn (vendor DB)
require_once __DIR__ . '/../../db_main.php';  // $mainConn (main DB)

$vendor_id = intval($_SESSION['vendor_id']);

// Look for active ringing call where callee is this vendor
$stmt = $mainConn->prepare("
    SELECT id, order_id, caller_id, call_type, created_at 
    FROM call_sessions 
    WHERE callee_type = 'vendor' AND callee_id = ? AND status = 'ringing' 
    AND (created_at >= NOW() - INTERVAL 12 HOUR OR created_at >= UTC_TIMESTAMP() - INTERVAL 12 HOUR)
    ORDER BY id DESC LIMIT 1
");

if (!$stmt) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $mainConn->error]);
    exit;
}

$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($res) {
    ob_clean();
    echo json_encode([
        'success' => true,
        'incoming' => true,
        'call_session_id' => intval($res['id']),
        'order_id' => intval($res['order_id']),
        'call_type' => $res['call_type'],
        'caller_name' => 'Admin (Support)',
        'business_name' => 'Surpriseville Office'
    ]);
} else {
    ob_clean();
    echo json_encode([
        'success' => true,
        'incoming' => false
    ]);
}
exit;
