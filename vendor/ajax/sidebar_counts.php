<?php
// vendor/ajax/sidebar_counts.php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once '../../db.php';
require_once '../../db_main.php';


$vendor_id = (int)$_SESSION['vendor_id'];

$counts = [
    'pending' => 0,
    'shop' => 0,
    'gigs' => 0
];

if ($vendor_id > 0) {
    // 1. Pending Counts (Shop + Gigs)
    require_once '../includes/alerts_helper.php';
    $finalAlerts = getAvailableAlerts($conn, $mainConn, $vendor_id);
    $counts['pending'] = count($finalAlerts);

    // 2. Active Shop Orders
    // We only count orders that have been 'accepted' in notifications AND are not 'completed' in assignments
    $accepted_ids = [];
    $stmt_ids = $conn->prepare("SELECT order_id FROM order_vendor_notifications WHERE vendor_id = ? AND status = 'accepted'");
    $stmt_ids->bind_param("i", $vendor_id);
    $stmt_ids->execute();
    $res_ids = $stmt_ids->get_result();
    while ($r = $res_ids->fetch_assoc()) $accepted_ids[] = (int)$r['order_id'];
    $stmt_ids->close();

    if (!empty($accepted_ids)) {
        $ids_str = implode(',', $accepted_ids);
        // We join with 'orders' table to ensure we only count orders that actually exist and are visible in the list
        $stmt3 = $mainConn->prepare("
            SELECT COUNT(DISTINCT o.id) as cnt 
            FROM orders o
            JOIN order_vendor_assignments ova ON o.id = ova.order_id
            WHERE ova.vendor_id = ? 
            AND o.id IN ($ids_str) 
            AND ova.status != 'completed'
        ");
        $stmt3->bind_param("i", $vendor_id);
        $stmt3->execute();
        $res3 = $stmt3->get_result()->fetch_assoc();
        $counts['shop'] = (int)$res3['cnt'];
        $stmt3->close();
    } else {
        $counts['shop'] = 0;
    }

    // 3. Active Gigs
    $stmt4 = $conn->prepare("SELECT COUNT(*) as cnt FROM manual_tasks WHERE assigned_vendor_id = ? AND status = 'assigned'");
    $stmt4->bind_param("i", $vendor_id);
    $stmt4->execute();
    $res4 = $stmt4->get_result()->fetch_assoc();
    $counts['gigs'] = (int)$res4['cnt'];
    $stmt4->close();
}

echo json_encode($counts);
?>
