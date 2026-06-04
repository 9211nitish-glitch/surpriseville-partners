<?php
/**
 * Admin Panel: Decorator Videos Management
 * Approve/reject video submissions
 */

session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../backend/decorator_video_uploader.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$uploader = new DecoratorVideoUploader($conn);

// Handle video approval/rejection
$response_message = null;
$response_type = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $video_id = intval($_POST['video_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    
    if ($action === 'approve') {
        $result = $uploader->approveVideo($video_id, $_SESSION['admin_id'], $notes);
    } elseif ($action === 'reject') {
        $result = $uploader->rejectVideo($video_id, $_SESSION['admin_id'], $notes);
    } else {
        $result = ['success' => false, 'message' => 'Invalid action'];
    }
    
    $response_message = $result['message'];
    $response_type = $result['success'] ? 'success' : 'error';
}

// Get pending videos
$pending_videos = $uploader->getPendingVideos(50);

// Get stats
$stats = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM decorator_videos WHERE video_status = 'pending') as pending_count,
        (SELECT COUNT(*) FROM decorator_videos WHERE video_status = 'approved') as approved_count,
        (SELECT COUNT(*) FROM decorator_videos WHERE video_status = 'rejected') as rejected_count,
        (SELECT COUNT(DISTINCT vendor_id) FROM decorator_videos) as total_vendors_with_videos
");
$stats_data = $stats->fetch_assoc();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Reviews - Admin Panel</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1);
        }
        
        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
        }

        .videos-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .stats-banner {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #007bff;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-top: 5px;
        }
        
        .video-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        
        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
        }
        
        .video-player {
            width: 100%;
            height: 200px;
            background: #000;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 12px;
            text-align: center;
            overflow: hidden;
        }
        
        .video-player video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .video-info {
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .video-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }
        
        .video-meta {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
            line-height: 1.6;
        }
        
        .video-actions {
            display: flex;
            gap: 10px;
            margin-top: auto;
        }
        
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
            flex: 1;
        }
        
        .btn-approve {
            background: #28a745;
            color: white;
        }
        
        .btn-approve:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        
        .btn-reject:hover {
            background: #c82333;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 900px;
            width: 90%;
            max-height: 90vh;
            overflow: auto;
        }
        
        .video-preview {
            width: 100%;
            max-height: 400px;
            background: #000;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .video-preview video {
            width: 100%;
            height: auto;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 13px;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            resize: vertical;
            min-height: 80px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .no-videos {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .no-videos h3 {
            margin-bottom: 10px;
        }

        /* Layout styles */


        .header {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .dashboard-container {
            display: flex;
            flex: 1;
            padding: 2rem;
            gap: 2rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
            box-sizing: border-box;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .sidebar-toggle {
            display: none;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: white;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow);
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
        }

        .sidebar-toggle:hover {
            background: #f8fafc;
        }

        @media (max-width: 1024px) {
            .dashboard-container {
                padding: 1rem;
            }
            .header {
                padding: 1rem;
            }
            .sidebar-toggle {
                display: flex !important;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                flex-direction: column;
            }
            .video-card {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-video"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Video Reviews Management</h1>
        </div>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>

        <main class="main-content">
            <?php if ($response_message): ?>
            <div class="alert alert-<?php echo $response_type; ?>">
                <?php echo htmlspecialchars($response_message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Stats Banner -->
        <div class="stats-banner">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats_data['pending_count']; ?></div>
                <div class="stat-label">Pending Review</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats_data['approved_count']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats_data['rejected_count']; ?></div>
                <div class="stat-label">Rejected</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats_data['total_vendors_with_videos']; ?></div>
                <div class="stat-label">Vendors</div>
            </div>
        </div>
        
        <h2>Pending Videos</h2>
        
        <?php if (empty($pending_videos)): ?>
            <div class="no-videos">
                <h3>✓ All caught up!</h3>
                <p>No pending videos waiting for review.</p>
            </div>
        <?php else: ?>
            <div class="video-grid">
                <?php foreach ($pending_videos as $video): ?>
                    <div class="video-card">
                        <div class="video-grid" style="grid-column: 1/-1; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; padding: 15px;">
                            <?php if ($video['before_video_url']): ?>
                                <div>
                                    <div class="video-player" onclick="previewVideo('<?php echo htmlspecialchars($video['before_video_url']); ?>', 'Before Video - <?php echo htmlspecialchars($video['name']); ?>')">
                                        <span>🎥 Before<br>Click to preview</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($video['after_video_url']): ?>
                                <div>
                                    <div class="video-player" onclick="previewVideo('<?php echo htmlspecialchars($video['after_video_url']); ?>', 'After Video - <?php echo htmlspecialchars($video['name']); ?>')">
                                        <span>🎥 After<br>Click to preview</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($video['vendor_selfie_url']): ?>
                                <div>
                                    <div class="video-player" onclick="previewVideo('<?php echo htmlspecialchars($video['vendor_selfie_url']); ?>', 'Vendor Selfie - <?php echo htmlspecialchars($video['name']); ?>')">
                                        <span>👤 Selfie<br>Click to preview</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="video-info">
                            <div>
                                <div class="video-title">
                                    <?php echo htmlspecialchars($video['name']); ?>
                                </div>
                                <div class="video-meta">
                                    <strong>Business:</strong> <?php echo htmlspecialchars($video['business_name']); ?><br>
                                    <strong>Order ID:</strong> #<?php echo htmlspecialchars($video['order_id']); ?><br>
                                    <strong>Submitted:</strong> <?php echo date('M d, Y H:i', strtotime($video['uploaded_at'])); ?>
                                </div>
                            </div>
                            
                            <div class="video-actions">
                                <button class="btn btn-approve" onclick="approveVideo(<?php echo $video['id']; ?>)">
                                    ✓ Approve
                                </button>
                                <button class="btn btn-reject" onclick="rejectVideo(<?php echo $video['id']; ?>)">
                                    ✗ Reject
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Video Preview Modal -->
    <div id="videoModal" class="modal">
        <div class="modal-content">
            <h2 id="videoTitle"></h2>
            <video id="videoPlayer" class="video-preview" controls>
                Your browser does not support the video tag.
            </video>
            <button class="btn" style="background: #6c757d; color: white; width: 100%;" onclick="closeVideoModal()">Close</button>
        </div>
    </div>
    
    <!-- Approval Modal -->
    <div id="approvalModal" class="modal">
        <div class="modal-content">
            <h2 id="approvalTitle"></h2>
            <form method="POST">
                <input type="hidden" name="video_id" id="approvalVideoId">
                <input type="hidden" name="action" id="approvalAction">
                
                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" placeholder="Add approval or rejection notes..."></textarea>
                </div>
                
                <div class="action-buttons">
                    <button type="submit" class="btn btn-approve" style="flex: 1;">
                        Confirm
                    </button>
                    <button type="button" class="btn" style="flex: 1; background: #6c757d; color: white;" onclick="closeApprovalModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function previewVideo(videoUrl, title) {
            document.getElementById('videoTitle').textContent = title;
            document.getElementById('videoPlayer').src = videoUrl;
            document.getElementById('videoModal').classList.add('show');
        }
        
        function closeVideoModal() {
            document.getElementById('videoModal').classList.remove('show');
            document.getElementById('videoPlayer').pause();
        }
        
        function approveVideo(videoId) {
            document.getElementById('approvalTitle').textContent = 'Approve Video';
            document.getElementById('approvalVideoId').value = videoId;
            document.getElementById('approvalAction').value = 'approve';
            document.getElementById('approvalModal').classList.add('show');
        }
        
        function rejectVideo(videoId) {
            document.getElementById('approvalTitle').textContent = 'Reject Video';
            document.getElementById('approvalVideoId').value = videoId;
            document.getElementById('approvalAction').value = 'reject';
            document.getElementById('approvalModal').classList.add('show');
        }
        
        function closeApprovalModal() {
            document.getElementById('approvalModal').classList.remove('show');
        }
        
        window.onclick = function(e) {
            let videoModal = document.getElementById('videoModal');
            let approvalModal = document.getElementById('approvalModal');
            
            if (e.target === videoModal) closeVideoModal();
            if (e.target === approvalModal) closeApprovalModal();
        }
    </script>
    
        </main>
    </div>
</body>
</html>
