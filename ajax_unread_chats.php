<?php
require_once __DIR__ . '/db.php';       // $conn (vendor DB btnevents)
require_once __DIR__ . '/db_main.php';  // $mainConn (main DB surpriseville_emp)
require_once __DIR__ . '/vendor/includes/session_manager.php'; // Enforces proper session setup

attemptAutoLogin($conn);
session_write_close();

header('Content-Type: application/json');

if ($conn->connect_error || $mainConn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$response = [
    'success' => true,
    'role' => null,
    'count' => 0,
    'chats' => []
];

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    // Admin Session Active
    $response['role'] = 'admin';
    
    // Query unread vendor messages on Manual Tasks (task_id > 0)
    $stmt = $mainConn->prepare("
        SELECT cm.task_id, cm.order_id, cm.message, cm.created_at, cm.sender_id
        FROM chat_messages cm
        WHERE cm.sender_type = 'vendor' AND cm.is_read = 0 AND (cm.task_id > 0 OR cm.order_id > 0)
        ORDER BY cm.created_at DESC
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    
    $unreadList = [];
    while ($row = $res->fetch_assoc()) {
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
        
        $listKey  = ($isTask ? 'task_' : 'order_') . $entityId;
        $vendorId = intval($row['sender_id']);
        
        if (isset($unreadList[$listKey])) continue;
        
        $vName = "Partner";
        if ($vendorId > 0) {
            $vStmt = $conn->prepare("SELECT business_name FROM vendors WHERE id = ? LIMIT 1");
            $vStmt->bind_param("i", $vendorId);
            $vStmt->execute();
            $vRes = $vStmt->get_result()->fetch_assoc();
            if ($vRes) $vName = $vRes['business_name'];
            $vStmt->close();
        }
        
        $prefix = $isTask ? "Task #$entityId" : "Order #$entityId";
        $link   = $isTask
            ? '/admin/tracking.php?track_id=' . $entityId
            : '/admin/order_tracking.php?order_id=' . $entityId;
        
        $unreadList[$listKey] = [
            'id'      => $listKey,
            'title'   => "$prefix — $vName",
            'message' => $row['message'],
            'time'    => $row['created_at'],
            'link'    => $link
        ];
    }
    $stmt->close();
    
    $response['chats'] = array_values($unreadList);
    $response['count'] = count($unreadList);

} elseif (isset($_SESSION['vendor_id'])) {
    // Vendor Session Active
    $response['role'] = 'vendor';
    $vendorId = intval($_SESSION['vendor_id']);
    
    $conversations = [];
    $totalUnreadCount = 0;
    
    // 1. Get vendor's manual tasks
    $tasks = [];
    $tRes = $conn->query("
        SELECT mt.id, mt.service_title, gc.name as cat_name, mt.created_at
        FROM manual_tasks mt
        LEFT JOIN gig_categories gc ON mt.category_id = gc.id
        WHERE mt.assigned_vendor_id = $vendorId
        ORDER BY mt.created_at DESC
    ");
    if ($tRes) {
        while ($tRow = $tRes->fetch_assoc()) {
            $tasks[intval($tRow['id'])] = [
                'title' => ($tRow['cat_name'] ?: ($tRow['service_title'] ?: "Offline Task #" . $tRow['id'])) . " (Admin)",
                'date' => $tRow['created_at']
            ];
        }
    }
    
    // 2. Get vendor's shop order assignments
    $orders = [];
    $oRes = $mainConn->query("
        SELECT ova.order_id, s.name as service_name, o.datetime
        FROM order_vendor_assignments ova
        JOIN orders o ON ova.order_id = o.id
        LEFT JOIN services s ON o.service_id = s.id
        WHERE ova.vendor_id = $vendorId
        ORDER BY o.datetime DESC
    ");
    if ($oRes) {
        while ($oRow = $oRes->fetch_assoc()) {
            $orders[intval($oRow['order_id'])] = [
                'title' => ($oRow['service_name'] ?: "Order #" . $oRow['order_id']) . " (Customer)",
                'date' => $oRow['datetime']
            ];
        }
    }
    
    // 3. For each task, fetch latest message & unread count
    foreach ($tasks as $tid => $tData) {
        $latestMsg = "No messages yet. Click to start chat.";
        $latestTime = $tData['date'];
        $unreadCount = 0;
        
        $msgStmt = $mainConn->prepare("
            SELECT message, created_at
            FROM chat_messages
            WHERE task_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        if ($msgStmt) {
            $msgStmt->bind_param("i", $tid);
            $msgStmt->execute();
            $mRes = $msgStmt->get_result()->fetch_assoc();
            if ($mRes) {
                $latestMsg = $mRes['message'];
                $latestTime = $mRes['created_at'];
            }
            $msgStmt->close();
        }
        
        $urStmt = $mainConn->prepare("
            SELECT COUNT(*) as cnt
            FROM chat_messages
            WHERE task_id = ? AND sender_type = 'admin' AND is_read = 0
        ");
        if ($urStmt) {
            $urStmt->bind_param("i", $tid);
            $urStmt->execute();
            $urRes = $urStmt->get_result()->fetch_assoc();
            if ($urRes) {
                $unreadCount = intval($urRes['cnt']);
            }
            $urStmt->close();
        }
        
        if ($unreadCount > 0) {
            $totalUnreadCount++;
        }
        
        $conversations[] = [
            'id' => $tid,
            'is_offline' => 1,
            'title' => $tData['title'],
            'message' => $latestMsg,
            'time' => $latestTime,
            'unread_count' => $unreadCount,
            'link' => 'order-chat.php?order_id=' . $tid . '&is_offline=1'
        ];
    }
    
    // 4. For each order, fetch latest message & unread count
    foreach ($orders as $oid => $oData) {
        $latestMsg = "No messages yet. Click to start chat.";
        $latestTime = $oData['date'];
        $unreadCount = 0;
        
        $msgStmt = $mainConn->prepare("
            SELECT message, created_at
            FROM chat_messages
            WHERE order_id = ?
            ORDER BY id DESC LIMIT 1
        ");
        if ($msgStmt) {
            $msgStmt->bind_param("i", $oid);
            $msgStmt->execute();
            $mRes = $msgStmt->get_result()->fetch_assoc();
            if ($mRes) {
                $latestMsg = $mRes['message'];
                $latestTime = $mRes['created_at'];
            }
            $msgStmt->close();
        }
        
        $urStmt = $mainConn->prepare("
            SELECT COUNT(*) as cnt
            FROM chat_messages
            WHERE order_id = ? AND sender_type = 'user' AND is_read = 0
        ");
        if ($urStmt) {
            $urStmt->bind_param("i", $oid);
            $urStmt->execute();
            $urRes = $urStmt->get_result()->fetch_assoc();
            if ($urRes) {
                $unreadCount = intval($urRes['cnt']);
            }
            $urStmt->close();
        }
        
        if ($unreadCount > 0) {
            $totalUnreadCount++;
        }
        
        $conversations[] = [
            'id' => $oid,
            'is_offline' => 0,
            'title' => $oData['title'],
            'message' => $latestMsg,
            'time' => $latestTime,
            'unread_count' => $unreadCount,
            'link' => 'order-chat.php?order_id=' . $oid
        ];
    }
    
    // Sort conversations: newer messages first, then newer orders/tasks first
    usort($conversations, function($a, $b) {
        return strcmp($b['time'], $a['time']);
    });
    
    $response['chats'] = $conversations;
    $response['count'] = $totalUnreadCount;
}

echo json_encode($response);
exit;
