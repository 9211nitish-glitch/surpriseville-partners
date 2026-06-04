<?php
/**
 * Admin API: Fetch Live Dashboard Stats
 * Path: admin/ajax/get_dashboard_stats.php
 */

session_start();
header('Content-Type: application/json');

// Check admin session
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once "../../db.php";       // vendor DB
require_once "../../db_main.php";  // main DB

try {
    // 1. Total Vendors
    $q1 = $conn->query("SELECT COUNT(*) AS count FROM vendors");
    $total_vendors = (int)$q1->fetch_assoc()['count'];

    // 2. Total Orders (Shop + Manual Tasks)
    $qOrderMain = $mainConn->query("SELECT COUNT(*) AS count FROM orders WHERE service_id IS NOT NULL");
    $mainCount = (int)$qOrderMain->fetch_assoc()['count'];

    $qOrderManual = $conn->query("SELECT COUNT(*) AS count FROM manual_tasks");
    $manualCount = (int)$qOrderManual->fetch_assoc()['count'];

    $total_orders = $mainCount + $manualCount;

    // 3. Pending Withdrawals
    $q3 = $conn->query("SELECT COUNT(*) AS count FROM withdrawal_requests WHERE status='pending'");
    $pending_withdrawals = (int)$q3->fetch_assoc()['count'];

    // 4. Total Categories
    $q4 = $mainConn->query("SELECT COUNT(*) AS count FROM categories");
    $total_categories = (int)$q4->fetch_assoc()['count'];

    // 5. Pending Gigs (completed tasks awaiting approval)
    $q5 = $conn->query("SELECT COUNT(*) AS count FROM manual_tasks WHERE status='completed'");
    $pending_gigs = (int)$q5->fetch_assoc()['count'];

    echo json_encode([
        'success'              => true,
        'total_vendors'        => $total_vendors,
        'total_orders'         => $total_orders,
        'pending_withdrawals'  => $pending_withdrawals,
        'total_categories'     => $total_categories,
        'pending_gigs'         => $pending_gigs
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
