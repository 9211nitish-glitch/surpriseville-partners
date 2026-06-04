<?php
/**
 * API: Get Vendor Orders
 * Fetch recent completed/assigned orders for a specific vendor
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../db.php';

// Check auth
session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$vendor_id = isset($_GET['vendor_id']) ? intval($_GET['vendor_id']) : 0;

if ($vendor_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid vendor ID']);
    exit;
}

try {
    // Fetch manual tasks assigned to this vendor
    // Assuming status like 'completed', 'verified', 'assigned'
    $stmt = $conn->prepare("
        SELECT id, client_name, order_date, status 
        FROM manual_tasks 
        WHERE assigned_vendor_id = ? 
        ORDER BY id DESC 
        LIMIT 50
    ");
    
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
