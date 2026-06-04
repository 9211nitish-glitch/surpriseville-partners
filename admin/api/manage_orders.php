<?php
/**
 * API Endpoint: Order Management
 * POST /admin/api/manage_orders.php
 * 
 * Handles creating offline orders and broadcasting
 */

session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../backend/order_management_system.php';

header('Content-Type: application/json');

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    $order_system = new OrderManagementSystem($db, $_SESSION['admin_id']);
    
    switch ($action) {
        case 'create_offline':
            // Validate required fields
            if (empty($input['event_name']) || empty($input['event_date']) || empty($input['location'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            
            $result = $order_system->createOfflineOrder($input);
            http_response_code($result['success'] ? 201 : 400);
            echo json_encode($result);
            break;
            
        case 'broadcast':
            // Validate required fields
            if (empty($input['order_id']) || empty($input['vendor_ids'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            
            $result = $order_system->broadcastOrder(
                intval($input['order_id']),
                $input['vendor_ids'],
                $input['broadcast_type'] ?? 'manual'
            );
            
            http_response_code($result['success'] ? 200 : 400);
            echo json_encode($result);
            break;
            
        case 'repost':
            // Validate required fields
            if (empty($input['order_id']) || empty($input['vendor_ids'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }
            
            $result = $order_system->repostOrder(
                intval($input['order_id']),
                $input['vendor_ids']
            );
            
            http_response_code($result['success'] ? 200 : 400);
            echo json_encode($result);
            break;
            
        case 'get_stats':
            $stats = $order_system->getStats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

?>
