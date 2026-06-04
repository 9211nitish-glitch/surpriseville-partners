<?php
/**
 * Public Page: Top Decorators
 * Display the top 10 decorators with their rankings and video portfolios
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backend/decorator_ranking_system.php';

$ranking_system = new DecoratorRankingSystem($db);
$top_decorators = $ranking_system->getTopDecorators(10);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏆 Top Decorators | Surpriseville</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8f9fa;
            line-height: 1.6;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 50px;
            padding: 40px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
        }
        
        .header h1 {
            font-size: 48px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .header p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .decorators-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            margin-bottom: 50px;
        }
        
        .decorator-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .decorator-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        }
        
        .card-rank {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
        }
        
        .medal-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 10px;
        }
        
        .medal-gold {
            background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%);
            color: #333;
            box-shadow: 0 2px 8px rgba(255, 215, 0, 0.4);
        }
        
        .medal-silver {
            background: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%);
            color: #333;
            box-shadow: 0 2px 8px rgba(192, 192, 192, 0.4);
        }
        
        .medal-bronze {
            background: linear-gradient(135deg, #cd7f32 0%, #e6a860 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(205, 127, 50, 0.4);
        }
        
        .card-content {
            padding: 30px 20px 20px;
        }
        
        .decorator-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 5px;
            color: #333;
        }
        
        .decorator-business {
            font-size: 14px;
            color: #666;
            margin-bottom: 3px;
        }
        
        .decorator-location {
            font-size: 13px;
            color: #999;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .points-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .points-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        .points-row:last-child {
            margin-bottom: 0;
            border-top: 2px solid #e0e0e0;
            padding-top: 10px;
            font-weight: 600;
        }
        
        .points-label {
            color: #666;
        }
        
        .points-value {
            font-weight: 600;
            color: #667eea;
        }
        
        .stats-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
            font-size: 12px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-weight: 700;
            font-size: 16px;
            color: #667eea;
            display: block;
        }
        
        .stat-label {
            color: #999;
            font-size: 11px;
            text-transform: uppercase;
        }
        
        .view-portfolio {
            display: block;
            text-align: center;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            margin-top: 15px;
        }
        
        .view-portfolio:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state h2 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 32px;
            }
            
            .decorators-grid {
                grid-template-columns: 1fr;
            }
            
            .medal-badge {
                display: block;
                margin: 10px 0 0 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🏆 Top Decorators</h1>
            <p>Celebrate our most talented and highly-rated event decorators</p>
        </div>
        
        <!-- Decorators Grid -->
        <?php if (empty($top_decorators)): ?>
            <div class="empty-state">
                <h2>Coming Soon</h2>
                <p>Top decorators rankings are being compiled. Check back soon!</p>
            </div>
        <?php else: ?>
            <div class="decorators-grid">
                <?php foreach ($top_decorators as $decorator): ?>
                    <div class="decorator-card">
                        <div class="card-rank">
                            #<?php echo htmlspecialchars($decorator['ranking_position']); ?> 
                            <span class="medal-badge medal-<?php echo htmlspecialchars($decorator['medal_tier']); ?>">
                                <?php echo strtoupper(htmlspecialchars($decorator['medal_tier'])); ?> Medal
                            </span>
                        </div>
                        
                        <div class="card-content">
                            <div class="decorator-name">
                                <?php echo htmlspecialchars($decorator['name']); ?>
                            </div>
                            <div class="decorator-business">
                                <?php echo htmlspecialchars($decorator['business_name']); ?>
                            </div>
                            <div class="decorator-location">
                                📍 <?php echo htmlspecialchars($decorator['city']); ?>
                            </div>
                            
                            <div class="points-summary">
                                <div class="points-row">
                                    <span class="points-label">Client Satisfaction</span>
                                    <span class="points-value"><?php echo number_format($decorator['client_satisfaction_points'], 1); ?>/2</span>
                                </div>
                                <div class="points-row">
                                    <span class="points-label">Video Reviews</span>
                                    <span class="points-value"><?php echo number_format($decorator['video_review_points'], 1); ?>/1</span>
                                </div>
                                <div class="points-row">
                                    <span class="points-label">Grooming & Style</span>
                                    <span class="points-value"><?php echo number_format($decorator['grooming_points'], 1); ?>/1</span>
                                </div>
                                <div class="points-row">
                                    <span class="points-label">Completion Time</span>
                                    <span class="points-value"><?php echo number_format($decorator['completion_time_points'], 1); ?>/1</span>
                                </div>
                                <div class="points-row">
                                    <span class="points-label">Total Score</span>
                                    <span class="points-value"><?php echo number_format($decorator['total_points'], 2); ?>/5.00</span>
                                </div>
                            </div>
                            
                            <div class="stats-footer">
                                <div class="stat-item">
                                    <span class="stat-value"><?php echo $decorator['total_ratings_count']; ?></span>
                                    <span class="stat-label">Ratings</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-value"><?php echo $decorator['approved_videos_count']; ?></span>
                                    <span class="stat-label">Videos</span>
                                </div>
                            </div>
                            
                            <a href="vendor/portfolio.php?vendor_id=<?php echo $decorator['vendor_id']; ?>" class="view-portfolio">
                                View Portfolio →
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
