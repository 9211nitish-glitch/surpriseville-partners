<?php
ob_start();
if (file_exists(__DIR__ . '/../../vendor/includes/session_manager.php')) {
    require_once __DIR__ . '/../../vendor/includes/session_manager.php';
} else {
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../db.php';       // $conn (vendor DB)
require_once __DIR__ . '/../../db_main.php';  // $mainConn (main DB)

// Look for active ringing call where callee is admin (last 5 minutes only)
$stmt = $mainConn->prepare("
    SELECT id, order_id, caller_type, caller_id, call_type, created_at 
    FROM call_sessions 
    WHERE callee_type = 'admin'
    AND status = 'ringing' 
    AND created_at >= NOW() - INTERVAL 5 MINUTE
    ORDER BY id DESC LIMIT 1
");

if (!$stmt) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'DB prepare failed: ' . $mainConn->error]);
    exit;
}

$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($res) {
    $caller_id = intval($res['caller_id']);
    $caller_type = $res['caller_type'];
    $vendor_name = "Partner";
    $business_name = "Surpriseville Partner";
    
    // Fetch vendor/caller name
    if ($caller_type === 'vendor' && isset($conn)) {
        $vStmt = $conn->prepare("SELECT name, business_name FROM vendors WHERE id = ? LIMIT 1");
        if ($vStmt) {
            $vStmt->bind_param("i", $caller_id);
            $vStmt->execute();
            $vRes = $vStmt->get_result()->fetch_assoc();
            if ($vRes) {
                $vendor_name   = $vRes['name'];
                $business_name = $vRes['business_name'] ?: $vRes['name'];
            }
            $vStmt->close();
        }
    }
    
    ob_clean();
    echo json_encode([
        'success'         => true,
        'incoming'        => true,
        'call_session_id' => intval($res['id']),
        'order_id'        => intval($res['order_id']),
        'call_type'       => $res['call_type'],
        'vendor_name'     => $vendor_name,
        'business_name'   => $business_name
    ]);
} else {
    ob_clean();
    echo json_encode([
        'success'  => true,
        'incoming' => false
    ]);
}
exit;
