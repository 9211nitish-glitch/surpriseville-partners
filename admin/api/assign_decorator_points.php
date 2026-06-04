<?php
/**
 * API Endpoint: Assign Decorator Points
 * POST /admin/api/assign_decorator_points.php
 * 
 * Allows admin to assign points to decorators
 */

session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../decorator_ranking_system.php';

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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['vendor_id']) || !isset($input['order_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $ranking_system = new DecoratorRankingSystem($db, $_SESSION['admin_id']);
    
    // Extract points (defaults to 0)
    $client_satisfaction = isset($input['client_satisfaction']) ? floatval($input['client_satisfaction']) : 0;
    $video_review = isset($input['video_review']) ? floatval($input['video_review']) : 0;
    $grooming = isset($input['grooming']) ? floatval($input['grooming']) : 0;
    $completion_time = isset($input['completion_time']) ? floatval($input['completion_time']) : 0;
    $comments = isset($input['comments']) ? trim($input['comments']) : null;
    
    // Assign points
    $result = $ranking_system->assignPoints(
        intval($input['vendor_id']),
        intval($input['order_id']),
        $client_satisfaction,
        $video_review,
        $grooming,
        $completion_time,
        $comments
    );
    
    if ($result['success']) {
        // Fetch updated ranking
        $updated_ranking = $ranking_system->getDecoratorRanking($input['vendor_id']);
        $result['data']['updated_ranking'] = $updated_ranking;
    }
    
    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

?>
