<?php
/**
 * Admin Panel: Decorator Rankings Management
 * Display and manage decorator rankings, points, and medals
 */

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../backend/decorator_ranking_system.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$ranking_system = new DecoratorRankingSystem($conn, $_SESSION['admin_id']);

// Get pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 25;
$offset = ($page - 1) * $limit;

// Get all decorators
$decorators = $ranking_system->getAllDecoratorRankings($limit, $offset);

// Get total count
$count_result = $conn->query("SELECT COUNT(*) as total FROM decorator_rankings WHERE vendor_id IN (SELECT id FROM vendors WHERE status = 'active')");
$total_count = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_count / $limit);

// Handle point assignment form submission
$response_message = null;
$response_type = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_points'])) {
    $vendor_id = intval($_POST['vendor_id']);
    $order_id = intval($_POST['order_id']);
    $client_satisfaction = floatval($_POST['client_satisfaction'] ?? 0);
    $video_review = floatval($_POST['video_review'] ?? 0);
    $grooming = floatval($_POST['grooming'] ?? 0);
    $completion_time = floatval($_POST['completion_time'] ?? 0);
    $comments = trim($_POST['comments'] ?? '');
    
    $result = $ranking_system->assignPoints(
        $vendor_id,
        $order_id,
        $client_satisfaction,
        $video_review,
        $grooming,
        $completion_time,
        $comments
    );
    
    $response_message = $result['message'];
    $response_type = $result['success'] ? 'success' : 'error';
    
    if ($result['success']) {
        // Reload decorators to show updated rankings
        $decorators = $ranking_system->getAllDecoratorRankings($limit, $offset);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Decorator Rankings - Admin Panel</title>
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

        .rankings-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .ranking-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ranking-info {
            flex: 1;
        }
        
        .ranking-position {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin-right: 20px;
            min-width: 50px;
            text-align: center;
        }
        
        .decorator-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        
        .decorator-meta {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .points-display {
            display: flex;
            gap: 20px;
            margin: 10px 0;
        }
        
        .point-item {
            text-align: center;
        }
        
        .point-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
        }
        
        .point-value {
            font-size: 16px;
            font-weight: bold;
            color: #007bff;
        }
        
        .medal-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-right: 10px;
        }
        
        .medal-gold {
            background: #ffd700;
            color: #333;
        }
        
        .medal-silver {
            background: #c0c0c0;
            color: #333;
        }
        
        .medal-bronze {
            background: #cd7f32;
            color: white;
        }
        
        .medal-none {
            background: #e9ecef;
            color: #666;
        }
        
        .ranking-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #117a8b;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
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
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .point-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
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
        
        .pagination {
            text-align: center;
            margin-top: 30px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            margin: 0 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #007bff;
        }
        
        .pagination a:hover {
            background: #007bff;
            color: white;
        }
        
        .pagination span.current {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .stats-banner {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #007bff;
        }
        
        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
            text-transform: uppercase;
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
            .stats-banner {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                padding: 12px;
            }
            .ranking-card {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            .points-display {
                flex-wrap: wrap;
                gap: 10px 15px;
            }
            .ranking-actions {
                justify-content: flex-end;
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
                <i class="fa-solid fa-star"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Decorator Rankings</h1>
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
        
        <div class="stats-banner">
            <div class="stat-item">
                <div class="stat-value"><?php echo $total_count; ?></div>
                <div class="stat-label">Total Decorators</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo count(array_filter($decorators, function($d) { return $d['medal_tier'] === 'gold'; })); ?></div>
                <div class="stat-label">Gold Medals</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo count(array_filter($decorators, function($d) { return $d['medal_tier'] === 'silver'; })); ?></div>
                <div class="stat-label">Silver Medals</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo count(array_filter($decorators, function($d) { return $d['medal_tier'] === 'bronze'; })); ?></div>
                <div class="stat-label">Bronze Medals</div>
            </div>
        </div>
        
        <h2>All Decorators</h2>
        
        <?php if (empty($decorators)): ?>
            <p>No decorators found.</p>
        <?php else: ?>
            <?php foreach ($decorators as $decorator): ?>
                <div class="ranking-card">
                    <div class="ranking-position">
                        #<?php echo htmlspecialchars($decorator['ranking_position']); ?>
                    </div>
                    <div class="ranking-info">
                        <div class="decorator-name">
                            <?php echo htmlspecialchars($decorator['name']); ?>
                        </div>
                        <div class="decorator-meta">
                            <?php echo htmlspecialchars($decorator['business_name']); ?> • <?php echo htmlspecialchars($decorator['city']); ?>
                        </div>
                        <div class="points-display">
                            <div class="point-item">
                                <div class="point-label">Total</div>
                                <div class="point-value"><?php echo number_format($decorator['total_points'], 2); ?>/5</div>
                            </div>
                            <div class="point-item">
                                <div class="point-label">Satisfaction</div>
                                <div class="point-value"><?php echo number_format($decorator['client_satisfaction_points'], 2); ?>/2</div>
                            </div>
                            <div class="point-item">
                                <div class="point-label">Video</div>
                                <div class="point-value"><?php echo number_format($decorator['video_review_points'], 2); ?>/1</div>
                            </div>
                            <div class="point-item">
                                <div class="point-label">Grooming</div>
                                <div class="point-value"><?php echo number_format($decorator['grooming_points'], 2); ?>/1</div>
                            </div>
                            <div class="point-item">
                                <div class="point-label">Time</div>
                                <div class="point-value"><?php echo number_format($decorator['completion_time_points'], 2); ?>/1</div>
                            </div>
                        </div>
                        <div>
                            <span class="medal-badge medal-<?php echo htmlspecialchars($decorator['medal_tier']); ?>">
                                <?php echo ucfirst(htmlspecialchars($decorator['medal_tier'])); ?>
                            </span>
                            <span style="font-size: 12px; color: #666;">
                                <?php echo $decorator['total_ratings_count']; ?> ratings • <?php echo $decorator['approved_videos_count']; ?> videos
                            </span>
                        </div>
                    </div>
                    <div class="ranking-actions">
                        <button class="btn btn-primary" onclick="openPointsModal(<?php echo $decorator['vendor_id']; ?>, '<?php echo htmlspecialchars($decorator['name']); ?>')">
                            Assign Points
                        </button>
                        <button class="btn btn-info" onclick="viewHistory(<?php echo $decorator['vendor_id']; ?>)">
                            History
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1">First</a>
                    <a href="?page=<?php echo $page - 1; ?>">Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>">Next</a>
                    <a href="?page=<?php echo $total_pages; ?>">Last</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Points Assignment Modal -->
    <div id="pointsModal" class="modal">
        <div class="modal-content">
            <h2>Assign Points</h2>
            <span id="decoratorName" style="color: #666; font-size: 14px;"></span>
            
            <form method="POST">
                <input type="hidden" name="assign_points" value="1">
                <input type="hidden" name="vendor_id" id="vendorId">
                
                <div class="form-group">
                    <label for="orderId">Select Order *</label>
                    <select id="orderId" name="order_id" required>
                        <option value="">Loading recent orders...</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Points Distribution</label>
                    <div class="point-inputs">
                        <div>
                            <label for="satisfaction">Client Satisfaction (0-2)</label>
                            <input type="number" id="satisfaction" name="client_satisfaction" min="0" max="2" step="0.1" value="0">
                        </div>
                        <div>
                            <label for="video">Video Review (0-1)</label>
                            <input type="number" id="video" name="video_review" min="0" max="1" step="0.1" value="0">
                        </div>
                        <div>
                            <label for="grooming">Grooming (0-1)</label>
                            <input type="number" id="grooming" name="grooming" min="0" max="1" step="0.1" value="0">
                        </div>
                        <div>
                            <label for="completion">Completion Time (0-1)</label>
                            <input type="number" id="completion" name="completion_time" min="0" max="1" step="0.1" value="0">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="comments">Comments (Optional)</label>
                    <textarea id="comments" name="comments"></textarea>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Assign Points</button>
                    <button type="button" class="btn" style="flex: 1; background: #6c757d; color: white;" onclick="closePointsModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openPointsModal(vendorId, decoratorName) {
            document.getElementById('vendorId').value = vendorId;
            document.getElementById('decoratorName').textContent = 'Rating for: ' + decoratorName;
            
            // Fetch vendor's orders
            const orderSelect = document.getElementById('orderId');
            orderSelect.innerHTML = '<option value="">Loading recent orders...</option>';
            
            fetch(`api/get_vendor_orders.php?vendor_id=${vendorId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.orders.length > 0) {
                        let options = '<option value="">-- Select Order --</option>';
                        data.orders.forEach(order => {
                            options += `<option value="${order.id}">#${order.id} - ${order.client_name} (${order.order_date})</option>`;
                        });
                        orderSelect.innerHTML = options;
                    } else {
                        orderSelect.innerHTML = '<option value="">No completed orders found</option>';
                    }
                })
                .catch(err => {
                    orderSelect.innerHTML = '<option value="">Error loading orders</option>';
                });
                
            document.getElementById('pointsModal').classList.add('show');
        }
        
        function closePointsModal() {
            document.getElementById('pointsModal').classList.remove('show');
        }
        
        function viewHistory(vendorId) {
            // Redirect to vendor details page or history view
            window.location.href = 'vendor_details.php?id=' + vendorId + '#rating-history';
        }
        
        window.onclick = function(e) {
            let modal = document.getElementById('pointsModal');
            if (e.target === modal) {
                closePointsModal();
            }
        }
    </script>
    
        </main>
    </div>
</body>
</html>
