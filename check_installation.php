<?php
/**
 * Decorator Ranking System - Installation Verification Script
 * Run this to verify all components are properly installed
 */

session_start();
require_once __DIR__ . '/db.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header('Location: admin/login.php?redirect=check_installation.php');
    exit;
}

$checks = [];
$all_passed = true;

// 1. Check database tables
$required_tables = [
    'decorator_rankings',
    'decorator_ratings',
    'decorator_videos',
    'decorator_video_portfolio',
    'order_broadcast_history',
    'ranking_audit_log'
];

foreach ($required_tables as $table) {
    $result = $db->query("SHOW TABLES LIKE '$table'");
    $exists = $result && $result->num_rows > 0;
    $checks[] = [
        'name' => "Database Table: $table",
        'passed' => $exists,
        'details' => $exists ? 'Found' : 'Missing - Run: decorator_ranking_schema.sql'
    ];
    if (!$exists) $all_passed = false;
}

// 2. Check columns in orders table
$required_columns = ['order_source', 'broadcast_status', 'posted_by_admin_id'];
foreach ($required_columns as $col) {
    $result = $db->query("DESCRIBE orders");
    $found = false;
    while ($row = $result->fetch_assoc()) {
        if ($row['Field'] === $col) {
            $found = true;
            break;
        }
    }
    $checks[] = [
        'name' => "Orders Column: $col",
        'passed' => $found,
        'details' => $found ? 'Found' : 'Missing - Run: decorator_ranking_schema.sql'
    ];
    if (!$found) $all_passed = false;
}

// 3. Check file/directory structure
$required_files = [
    'backend/decorator_ranking_system.php',
    'backend/decorator_video_uploader.php',
    'backend/order_management_system.php',
    'admin/decorator_rankings.php',
    'admin/decorator_videos.php',
    'admin/api/assign_decorator_points.php',
    'admin/api/upload_video.php',
    'admin/api/manage_orders.php',
    'public_top_decorators.php',
    'vendor/portfolio.php'
];

foreach ($required_files as $file) {
    $path = __DIR__ . '/' . $file;
    $exists = file_exists($path);
    $checks[] = [
        'name' => "File: $file",
        'passed' => $exists,
        'details' => $exists ? 'Found' : 'Missing'
    ];
    if (!$exists) $all_passed = false;
}

// 4. Check upload directory
$upload_dir = __DIR__ . '/uploads/decorator-videos';
$dir_exists = is_dir($upload_dir);
$dir_writable = $dir_exists && is_writable($upload_dir);

$checks[] = [
    'name' => 'Upload Directory: uploads/decorator-videos',
    'passed' => $dir_exists,
    'details' => $dir_exists ? 'Exists' : 'Missing - Run: mkdir uploads/decorator-videos'
];

$checks[] = [
    'name' => 'Upload Directory Writable',
    'passed' => $dir_writable,
    'details' => $dir_writable ? 'Writable' : 'Not writable - Check permissions'
];

if (!$dir_writable) $all_passed = false;

// 5. Check vendor initialization
$vendor_count = $db->query("SELECT COUNT(*) as total FROM vendors")->fetch_assoc()['total'];
$ranking_count = $db->query("SELECT COUNT(*) as total FROM decorator_rankings")->fetch_assoc()['total'];

$checks[] = [
    'name' => 'Vendor-to-Ranking Sync',
    'passed' => $vendor_count === $ranking_count,
    'details' => "Vendors: $vendor_count, Rankings: $ranking_count - " . 
                 ($vendor_count === $ranking_count ? 'Synced' : 'Run: migrate_vendors_to_ranking.sql')
];

if ($vendor_count !== $ranking_count) $all_passed = false;

// 6. Check if any data exists
$rating_count = $db->query("SELECT COUNT(*) as total FROM decorator_ratings")->fetch_assoc()['total'];
$video_count = $db->query("SELECT COUNT(*) as total FROM decorator_videos")->fetch_assoc()['total'];

$checks[] = [
    'name' => 'Sample Data',
    'passed' => true,
    'details' => "Ratings: $rating_count, Videos: $video_count"
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Verification - Decorator Ranking System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .status-banner {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            text-align: center;
        }
        
        .status-pass {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .status-fail {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .checks-list {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .check-item {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .check-item:last-child {
            border-bottom: none;
        }
        
        .check-icon {
            min-width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        
        .check-pass .check-icon {
            background: #28a745;
        }
        
        .check-fail .check-icon {
            background: #dc3545;
        }
        
        .check-content {
            flex: 1;
        }
        
        .check-name {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .check-details {
            font-size: 12px;
            color: #666;
        }
        
        .check-fail .check-details {
            color: #721c24;
        }
        
        .actions {
            margin-top: 30px;
            text-align: center;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin: 0 5px;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .footer {
            margin-top: 20px;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        @media (max-width: 480px) {
            .btn {
                display: block;
                width: 100%;
                margin: 10px 0;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 Installation Verification</h1>
            <p>Decorator Ranking & Video Review System</p>
        </div>
        
        <div class="status-banner <?php echo $all_passed ? 'status-pass' : 'status-fail'; ?>">
            <?php if ($all_passed): ?>
                ✓ All systems operational - System is ready to use
            <?php else: ?>
                ✗ Some components are missing - Please complete installation
            <?php endif; ?>
        </div>
        
        <div class="checks-list">
            <?php foreach ($checks as $check): ?>
                <div class="check-item <?php echo $check['passed'] ? 'check-pass' : 'check-fail'; ?>">
                    <div class="check-icon">
                        <?php echo $check['passed'] ? '✓' : '✗'; ?>
                    </div>
                    <div class="check-content">
                        <div class="check-name"><?php echo htmlspecialchars($check['name']); ?></div>
                        <div class="check-details"><?php echo htmlspecialchars($check['details']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="actions">
            <?php if ($all_passed): ?>
                <a href="public_top_decorators.php" class="btn btn-primary">View Top Decorators</a>
                <a href="admin/decorator_rankings.php" class="btn btn-primary">Admin Rankings</a>
            <?php else: ?>
                <p style="color: #dc3545; margin-bottom: 15px;">
                    Please complete the installation steps above before proceeding.
                </p>
                <a href="DECORATOR_RANKING_README.md" class="btn btn-primary">Installation Guide</a>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>Last checked: <?php echo date('M d, Y H:i:s'); ?></p>
            <p><a href="?refresh=1" style="color: #007bff;">Refresh checks</a></p>
        </div>
    </div>
</body>
</html>
