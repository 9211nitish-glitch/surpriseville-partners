<?php
/**
 * API Endpoint: Upload Decorator Video
 * POST /admin/api/upload_video.php
 * 
 * Handles video uploads for decorators
 */

session_start();
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../backend/decorator_video_uploader.php';

header('Content-Type: application/json');

// Check authentication (vendor or admin can upload)
if (!isset($_SESSION['vendor_id']) && !isset($_SESSION['admin_id'])) {
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

// Validate required fields
if (!isset($_POST['order_id']) || !isset($_POST['video_type']) || !isset($_FILES['video'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields (order_id, video_type, video file)']);
    exit;
}

try {
    $order_id = intval($_POST['order_id']);
    $video_type = trim($_POST['video_type']);
    $vendor_id = $_SESSION['vendor_id'] ?? $_POST['vendor_id'];
    
    // Validate vendor_id
    if (empty($vendor_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Vendor ID required']);
        exit;
    }
    
    $vendor_id = intval($vendor_id);
    
    // If vendor is uploading, ensure they can only upload for themselves
    if (isset($_SESSION['vendor_id']) && $_SESSION['vendor_id'] !== $vendor_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cannot upload videos for other vendors']);
        exit;
    }
    
    // Initialize uploader
    $uploader = new DecoratorVideoUploader($db);
    
    // Upload video
    $result = $uploader->uploadVideo($_FILES['video'], $vendor_id, $order_id, $video_type);
    
    http_response_code($result['success'] ? 200 : 400);
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

?>
