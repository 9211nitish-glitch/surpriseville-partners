<?php
/**
 * Vendor Portfolio Page
 * Display vendor's videos and ratings
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../backend/decorator_ranking_system.php';
require_once __DIR__ . '/../backend/decorator_video_uploader.php';

// Get vendor ID from URL or session
$vendor_id = intval($_GET['vendor_id'] ?? $_SESSION['vendor_id'] ?? 0);

if ($vendor_id <= 0) {
    die('Invalid vendor ID');
}

// Get vendor info
$vendor = $db->query("SELECT * FROM vendors WHERE id = $vendor_id")->fetch_assoc();
if (!$vendor) {
    die('Vendor not found');
}

// Get ranking
$ranking_system = new DecoratorRankingSystem($db);
$ranking = $ranking_system->getDecoratorRanking($vendor_id);

// Get videos
$uploader = new DecoratorVideoUploader($db);
$videos = $uploader->getVendorVideos($vendor_id, 'approved');

// Get rating history
$ratings = $ranking_system->getVendorRatingHistory($vendor_id, 10);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($vendor['name']); ?> - Portfolio | Surpriseville</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8f9fa;
            color: #333;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .header-info h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .header-info p {
            color: #666;
            margin-bottom: 20px;
            font-size: 16px;
        }
        
        .medal-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 20px;
        }
        
        .medal-gold {
            background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%);
            color: #333;
        }
        
        .medal-silver {
            background: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%);
            color: #333;
        }
        
        .medal-bronze {
            background: linear-gradient(135deg, #cd7f32 0%, #e6a860 100%);
            color: white;
        }
        
        .rating-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .rating-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .rating-label {
            font-size: 13px;
            opacity: 0.9;
            text-transform: uppercase;
        }
        
        .section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .section h2 {
            font-size: 24px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .points-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .point-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .point-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .point-bar {
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            margin-bottom: 8px;
            overflow: hidden;
        }
        
        .point-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            border-radius: 2px;
        }
        
        .point-value {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
        }
        
        .videos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .video-card {
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .video-card:hover {
            transform: scale(1.02);
        }
        
        .video-thumbnail {
            width: 100%;
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #111;
            color: #fff;
            font-size: 40px;
        }
        
        .video-info {
            background: white;
            padding: 12px;
            font-size: 13px;
        }
        
        .video-type {
            display: inline-block;
            padding: 3px 8px;
            background: #f0f0f0;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            margin-right: 5px;
        }
        
        .no-content {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        
        .rating-history {
            margin-top: 20px;
        }
        
        .rating-entry {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        .rating-entry-header {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
        }
        
        .rating-entry-score {
            font-weight: 700;
            color: #667eea;
        }
        
        .rating-entry-meta {
            color: #999;
            font-size: 12px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            max-width: 800px;
            width: 95%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        
        .video-player {
            flex: 1;
            min-height: 400px;
            background: #000;
        }
        
        .video-player video {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            background: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2001;
        }
        
        @media (max-width: 768px) {
            .header {
                grid-template-columns: 1fr;
            }
            
            .videos-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../public_top_decorators.php" class="back-link">← Back to Top Decorators</a>
        
        <!-- Header Section -->
        <div class="header">
            <div class="header-info">
                <h1><?php echo htmlspecialchars($vendor['name']); ?></h1>
                <p><?php echo htmlspecialchars($vendor['business_name']); ?></p>
                
                <?php if ($ranking && $ranking['medal_tier'] !== 'none'): ?>
                    <div class="medal-badge medal-<?php echo htmlspecialchars($ranking['medal_tier']); ?>">
                        🏆 <?php echo strtoupper(htmlspecialchars($ranking['medal_tier'])); ?> Medal
                    </div>
                <?php endif; ?>
                
                <div>
                    <strong>Location:</strong> <?php echo htmlspecialchars($vendor['city']); ?><br>
                    <strong>Status:</strong> <span style="color: #28a745; font-weight: 600;">● Active</span>
                </div>
            </div>
            
            <div class="rating-card">
                <div class="rating-value">
                    <?php echo $ranking ? number_format($ranking['total_points'], 1) : '0'; ?>
                </div>
                <div class="rating-label">Overall Rating</div>
                <div style="font-size: 12px; margin-top: 10px; opacity: 0.8;">
                    Out of 5.0 stars
                </div>
            </div>
        </div>
        
        <!-- Points Breakdown -->
        <?php if ($ranking): ?>
            <div class="section">
                <h2>Rating Breakdown</h2>
                <div class="points-grid">
                    <div class="point-item">
                        <div class="point-label">Client Satisfaction</div>
                        <div class="point-bar">
                            <div class="point-fill" style="width: <?php echo min(100, ($ranking['client_satisfaction_points'] / 2) * 100); ?>%"></div>
                        </div>
                        <div class="point-value"><?php echo number_format($ranking['client_satisfaction_points'], 1); ?>/2.0</div>
                    </div>
                    <div class="point-item">
                        <div class="point-label">Video Reviews</div>
                        <div class="point-bar">
                            <div class="point-fill" style="width: <?php echo min(100, ($ranking['video_review_points'] / 1) * 100); ?>%"></div>
                        </div>
                        <div class="point-value"><?php echo number_format($ranking['video_review_points'], 1); ?>/1.0</div>
                    </div>
                    <div class="point-item">
                        <div class="point-label">Grooming & Style</div>
                        <div class="point-bar">
                            <div class="point-fill" style="width: <?php echo min(100, ($ranking['grooming_points'] / 1) * 100); ?>%"></div>
                        </div>
                        <div class="point-value"><?php echo number_format($ranking['grooming_points'], 1); ?>/1.0</div>
                    </div>
                    <div class="point-item">
                        <div class="point-label">Completion Time</div>
                        <div class="point-bar">
                            <div class="point-fill" style="width: <?php echo min(100, ($ranking['completion_time_points'] / 1) * 100); ?>%"></div>
                        </div>
                        <div class="point-value"><?php echo number_format($ranking['completion_time_points'], 1); ?>/1.0</div>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px; font-size: 13px;">
                    <strong>Rank:</strong> #<?php echo $ranking['ranking_position']; ?> of 
                    <?php 
                    $total = $db->query("SELECT COUNT(*) as total FROM decorator_rankings WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')")->fetch_assoc();
                    echo $total['total'];
                    ?> decorators
                    <br>
                    <strong>Ratings Count:</strong> <?php echo $ranking['total_ratings_count']; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Videos Section -->
        <div class="section">
            <h2>Before & After Videos</h2>
            
            <?php if (empty($videos)): ?>
                <div class="no-content">
                    <p>No approved videos yet.</p>
                </div>
            <?php else: ?>
                <div class="videos-grid">
                    <?php foreach ($videos as $video): ?>
                        <div>
                            <?php if ($video['before_video_url']): ?>
                                <div class="video-card" onclick="playVideo('<?php echo htmlspecialchars($video['before_video_url']); ?>', 'Before')">
                                    <div class="video-thumbnail">🎥</div>
                                    <div class="video-info">
                                        <span class="video-type">Before</span>
                                        Order #<?php echo htmlspecialchars($video['order_id']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($video['after_video_url']): ?>
                                <div class="video-card" onclick="playVideo('<?php echo htmlspecialchars($video['after_video_url']); ?>', 'After')">
                                    <div class="video-thumbnail">🎥</div>
                                    <div class="video-info">
                                        <span class="video-type">After</span>
                                        Order #<?php echo htmlspecialchars($video['order_id']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($video['vendor_selfie_url']): ?>
                                <div class="video-card" onclick="playVideo('<?php echo htmlspecialchars($video['vendor_selfie_url']); ?>', 'Selfie')">
                                    <div class="video-thumbnail">👤</div>
                                    <div class="video-info">
                                        <span class="video-type">Selfie</span>
                                        Order #<?php echo htmlspecialchars($video['order_id']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Rating History -->
        <?php if (!empty($ratings)): ?>
            <div class="section">
                <h2>Recent Ratings</h2>
                <div class="rating-history">
                    <?php foreach ($ratings as $rating): ?>
                        <div class="rating-entry">
                            <div class="rating-entry-header">
                                <span>Order #<?php echo htmlspecialchars($rating['order_id']); ?></span>
                                <span class="rating-entry-score"><?php echo number_format($rating['total_rating_points'], 2); ?>/5.00</span>
                            </div>
                            <div class="rating-entry-meta">
                                Rated on <?php echo date('M d, Y', strtotime($rating['created_at'])); ?>
                            </div>
                            <?php if ($rating['comments']): ?>
                                <div style="margin-top: 8px; color: #666;">
                                    <?php echo nl2br(htmlspecialchars($rating['comments'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Video Player Modal -->
    <div id="videoModal" class="modal">
        <button class="modal-close" onclick="closeVideo()">×</button>
        <div class="modal-content">
            <div class="video-player">
                <video id="videoPlayer" controls>
                    Your browser does not support the video tag.
                </video>
            </div>
        </div>
    </div>
    
    <script>
        function playVideo(videoUrl, title) {
            document.getElementById('videoPlayer').src = videoUrl;
            document.getElementById('videoModal').classList.add('show');
            document.getElementById('videoPlayer').play();
        }
        
        function closeVideo() {
            document.getElementById('videoModal').classList.remove('show');
            document.getElementById('videoPlayer').pause();
        }
        
        window.onclick = function(e) {
            let modal = document.getElementById('videoModal');
            if (e.target === modal) {
                closeVideo();
            }
        }
    </script>
</body>
</html>
