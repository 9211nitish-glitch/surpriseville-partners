<?php
// admin/api/recent_activity.php
session_start();
header('Content-Type: application/json');

// Admin session guard
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Use main db.php files instead of hardcoded credentials
require_once __DIR__ . '/../../db.php';       // $conn  (btnevents)
require_once __DIR__ . '/../../db_main.php';  // $mainConn (surprise_main)

if ($conn->connect_error || $mainConn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$activity = [];

// 1. Recent Gigs (manual_tasks)
$res = $conn->query("SELECT id, status, created_at FROM manual_tasks ORDER BY created_at DESC LIMIT 5");
if ($res) {
    while($row = $res->fetch_assoc()) {
        $activity[] = [
            'id'     => $row['id'],
            'status' => $row['status'],
            'time'   => $row['created_at'],
            'type'   => 'gig',
            'title'  => 'Gig Task #' . $row['id'],
            'link'   => 'manage_gigs.php'
        ];
    }
}

// 2. Recent Shop Orders
$res = $mainConn->query("SELECT id, status, created_at FROM orders ORDER BY created_at DESC LIMIT 5");
if ($res) {
    while($row = $res->fetch_assoc()) {
        $activity[] = [
            'id'     => $row['id'],
            'status' => $row['status'],
            'time'   => $row['created_at'],
            'type'   => 'shop',
            'title'  => 'Shop Order #' . $row['id'],
            'link'   => 'order_tracking.php?order_id=' . $row['id']
        ];
    }
}

// 3. Recent Allocation Logs
$res = $conn->query("SELECT * FROM manual_allocation_logs ORDER BY created_at DESC LIMIT 5");
if ($res) {
    while($row = $res->fetch_assoc()) {
        $eid = $row['order_id'] ?: $row['task_id'];
        $activity[] = [
            'id'     => $eid . '_alloc',
            'status' => 'assigned',
            'time'   => $row['created_at'],
            'type'   => $row['allocation_type'] == 'shop_order' ? 'shop' : 'gig',
            'title'  => 'Order #' . $eid . ' Assigned',
            'link'   => $row['allocation_type'] == 'shop_order' ? 'order_tracking.php?order_id='.$row['order_id'] : 'manage_gigs.php'
        ];
    }
}

// 4. Unread Vendor Messages (Manual Tasks + Shop Orders)
$res = $mainConn->query("
    SELECT cm.task_id, cm.order_id, cm.message, cm.created_at, cm.sender_id
    FROM chat_messages cm
    WHERE cm.sender_type = 'vendor' AND cm.is_read = 0
      AND (cm.task_id > 0 OR cm.order_id > 0)
    ORDER BY cm.created_at DESC LIMIT 10
");
if ($res) {
    $seen = [];
    while($row = $res->fetch_assoc()) {
        $isTask   = intval($row['task_id']) > 0;
        $entityId = $isTask ? intval($row['task_id']) : intval($row['order_id']);
        
        if (!$isTask && $entityId > 0) {
            $checkStmt = $mainConn->prepare("SELECT service_id FROM orders WHERE id = ?");
            $checkStmt->bind_param("i", $entityId);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result()->fetch_assoc();
            $checkStmt->close();
            if ($checkRes && is_null($checkRes['service_id'])) {
                $isTask = true;
            }
        }
        
        $listKey  = ($isTask ? 't' : 'o') . $entityId;
        if (isset($seen[$listKey])) continue;
        $seen[$listKey] = 1;

        $vName = "Partner";
        $vId = intval($row['sender_id']);
        if ($vId > 0) {
            $vRes = $conn->query("SELECT business_name FROM vendors WHERE id = $vId LIMIT 1");
            if ($vRes && $vRow = $vRes->fetch_assoc()) $vName = $vRow['business_name'];
        }

        $msgSnippet = strlen($row['message']) > 35 ? substr($row['message'], 0, 32) . '...' : $row['message'];
        $label = $isTask ? "Task #$entityId" : "Order #$entityId";
        $link  = $isTask ? "tracking.php?track_id=$entityId" : "order_tracking.php?order_id=$entityId";

        $activity[] = [
            'id'     => $listKey . '_' . strtotime($row['created_at']),
            'status' => 'unread',
            'time'   => $row['created_at'],
            'type'   => 'chat',
            'title'  => 'Message from ' . $vName,
            'body'   => "💬 \"$msgSnippet\" ($label)",
            'link'   => $link
        ];
    }
}

// Sort by time DESC, return top 5
usort($activity, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});
echo json_encode(array_slice($activity, 0, 5));
?>
