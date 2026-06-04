<?php
// vendor/dashboard.php
// 1. Session Config
require_once 'includes/session_manager.php';

// 2. Auth Check
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';        // vendor DB
require_once '../db_main.php';   // main DB

$vendor_id = (int)$_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

// --- STATS LOGIC ---
function countNotifications($conn, $vendor_id, $status)
{
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM order_vendor_notifications WHERE vendor_id = ? AND status = ?");
    $stmt->bind_param("is", $vendor_id, $status);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $cnt = (int)($res['cnt'] ?? 0);
    $stmt->close();
    return $cnt;
}

$pending_count = countNotifications($conn, $vendor_id, 'pending');
// Online Order Stats (Accepted but not completed)
$accepted_count = 0;
$stmt_ids = $conn->prepare("SELECT order_id FROM order_vendor_notifications WHERE vendor_id = ? AND status = 'accepted'");
$stmt_ids->bind_param("i", $vendor_id);
$stmt_ids->execute();
$res_ids = $stmt_ids->get_result();
$accepted_ids = [];
while ($r = $res_ids->fetch_assoc()) $accepted_ids[] = (int)$r['order_id'];
$stmt_ids->close();

if (!empty($accepted_ids)) {
    $ids_str = implode(',', $accepted_ids);
    $stmt3 = $mainConn->prepare("SELECT COUNT(DISTINCT o.id) as cnt FROM orders o JOIN order_vendor_assignments ova ON o.id = ova.order_id WHERE ova.vendor_id = ? AND o.id IN ($ids_str) AND ova.status != 'completed'");
    $stmt3->bind_param("i", $vendor_id);
    $stmt3->execute();
    $accepted_count = (int)$stmt3->get_result()->fetch_assoc()['cnt'];
    $stmt3->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM order_vendor_notifications WHERE vendor_id = ? AND status IN ('missed','expired','declined')");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$missed_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Offline Order Stats
$g_sql = "SELECT COUNT(*) as cnt FROM task_alerts ta JOIN manual_tasks mt ON mt.id = ta.task_id WHERE ta.vendor_id = ? AND ta.status = 'pending' AND mt.status = 'open'";
$stmt = $conn->prepare($g_sql);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$gig_pending_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$ga_sql = "SELECT COUNT(*) as cnt FROM manual_tasks WHERE assigned_vendor_id = ? AND status = 'assigned'";
$stmt = $conn->prepare($ga_sql);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$gig_active_count = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// --- RANKING LOGIC ---
$ranking_data = [
    'score' => 0,
    'orders' => 0,
    'rating' => 0,
    'videos' => 0,
    'tier' => 'Standard',
    'color' => '#64748b', // Slate
    'icon' => 'military_tech'
];

$stmtR = $conn->prepare("SELECT * FROM decorator_rankings WHERE vendor_id = ?");
$stmtR->bind_param("i", $vendor_id);
$stmtR->execute();
$resR = $stmtR->get_result();
if ($row = $resR->fetch_assoc()) {
    $ranking_data['score'] = floatval($row['ranking_score']);
    $ranking_data['orders'] = (int)$row['completed_orders_count'];
    $ranking_data['rating'] = floatval($row['average_rating']);
    $ranking_data['videos'] = (int)$row['video_submissions'];
}
$stmtR->close();

if ($ranking_data['score'] >= 80) {
    $ranking_data['tier'] = 'Elite';
    $ranking_data['color'] = '#eab308'; // Gold
    $ranking_data['icon'] = 'workspace_premium';
} elseif ($ranking_data['score'] >= 50) {
    $ranking_data['tier'] = 'Pro';
    $ranking_data['color'] = '#3b82f6'; // Blue
    $ranking_data['icon'] = 'verified';
}
?>
<?php
$page_title = "Vendor Portal";
include 'header.php';
?>

<div class="dashboard-wrapper">
    <style>
        .dashboard-wrapper {
            padding: 24px;
        }

        .premium-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        @media (max-width: 600px) {
            .dashboard-wrapper {
                padding: 10px 5px;
            }

            .premium-stat-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .premium-card {
                padding: 16px;
            }
        }

        .premium-card {
            background: var(--bg-card);
            padding: 24px;
            border-radius: 24px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            gap: 16px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .premium-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.1);
            border-color: rgba(19, 91, 236, 0.3);
        }

        .icon-box {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-muted);
            margin: 0;
        }

        .ranking-stats-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1px;
            background: var(--border-color);
        }

        @media (max-width: 400px) {
            .ranking-stats-row {
                grid-template-columns: 1fr;
                gap: 12px;
                background: transparent;
            }
            .ranking-stats-row > div {
                border-radius: 12px;
                border: 1px solid var(--border-color);
            }
        }
    </style>

    <!-- Stats Grid -->
    <div class="premium-stat-grid">
        <!-- Card 1: Pending Orders -->
        <a href="pending-alerts.php" class="premium-card" style="text-decoration: none; display: flex;">
            <div class="icon-box" style="background: rgba(249,115,22,0.1); color: #f97316;">
                <span class="material-symbols-outlined" style="font-size: 24px;">pending_actions</span>
            </div>
            <div>
                <p class="stat-value"><?= $pending_count ?></p>
                <p class="stat-label">Pending Orders</p>
            </div>
            <div style="display: flex; align-items: center; gap: 4px; color: #10b981; font-size: 12px; font-weight: 700;">
                <span class="material-symbols-outlined" style="font-size: 14px;">trending_up</span>
                <span>Active</span>
            </div>
        </a>

        <!-- Card 2: Accepted Orders -->
        <a href="shop-orders.php" class="premium-card" style="text-decoration: none; display: flex;">
            <div class="icon-box" style="background: rgba(16,185,129,0.1); color: #10b981;">
                <span class="material-symbols-outlined" style="font-size: 24px;">check_circle</span>
            </div>
            <div>
                <p class="stat-value"><?= $accepted_count ?></p>
                <p class="stat-label">Accepted Orders</p>
            </div>
            <div style="display: flex; align-items: center; gap: 4px; color: #10b981; font-size: 12px; font-weight: 700;">
                <span class="material-symbols-outlined" style="font-size: 14px;">trending_up</span>
                <span>Consistent</span>
            </div>
        </a>

        <!-- Card 3: Missed Orders -->
        <a href="pending-alerts.php" class="premium-card" style="text-decoration: none; display: flex;">
            <div class="icon-box" style="background: rgba(244,63,94,0.1); color: #f43f5e;">
                <span class="material-symbols-outlined" style="font-size: 24px;">event_busy</span>
            </div>
            <div>
                <p class="stat-value"><?= $missed_count ?></p>
                <p class="stat-label">Missed Orders</p>
            </div>
            <div style="display: flex; align-items: center; gap: 4px; color: #f43f5e; font-size: 12px; font-weight: 700;">
                <span class="material-symbols-outlined" style="font-size: 14px;">trending_down</span>
                <span>Lost Revenue</span>
            </div>
        </a>

        <!-- Card 4: New Offline Offers (Highlighted) -->
        <a href="pending-alerts.php" style="background: linear-gradient(135deg, #a855f7, #3b82f6, #06b6d4); padding: 2px; border-radius: 26px; box-shadow: 0 10px 30px rgba(59,130,246,0.3); text-decoration: none; display: block; transition: transform 0.2s ease, box-shadow 0.2s ease;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 12px 40px rgba(59,130,246,0.5)'" onmouseout="this.style.transform='none'; this.style.boxShadow='0 10px 30px rgba(59,130,246,0.3)'">
            <div style="background: var(--bg-card); border-radius: 24px; height: 100%; padding: 24px; display: flex; flex-direction: column; gap: 16px; box-sizing: border-box;">
                <div class="icon-box" style="background: rgba(147,51,234,0.1); color: #9333ea;">
                    <span class="material-symbols-outlined" style="font-size: 24px;">campaign</span>
                </div>
                <div>
                    <p class="stat-value" style="color: var(--text-main); margin:0; line-height:1;"><?= $gig_pending_count ?></p>
                    <p class="stat-label" style="color: var(--text-muted); margin:0; font-size:14px; font-weight:500;">New Offline Offers</p>
                </div>
                <div style="margin-top: auto;">
                    <span style="padding: 4px 8px; border-radius: 999px; background: rgba(147,51,234,0.1); color: #9333ea; font-size: 10px; font-weight: 800; text-transform: uppercase;">Priority</span>
                </div>
            </div>
        </a>

        <!-- Card 5: Active Offline Orders -->
        <a href="my-gigs.php" class="premium-card" style="text-decoration: none; display: flex;">
            <div class="icon-box" style="background: rgba(59,130,246,0.1); color: #3b82f6;">
                <span class="material-symbols-outlined" style="font-size: 24px;">task_alt</span>
            </div>
            <div>
                <p class="stat-value"><?= $gig_active_count ?></p>
                <p class="stat-label">Active Offline Orders</p>
            </div>
            <div style="display: flex; align-items: center; gap: 4px; color: #10b981; font-size: 12px; font-weight: 700;">
                <span class="material-symbols-outlined" style="font-size: 14px;">trending_up</span>
                <span>Growing</span>
            </div>
        </a>
    </div>

    <style>
        .split-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 32px;
        }

        @media (max-width: 1024px) {
            .split-layout {
                grid-template-columns: 1fr;
            }
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
        }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 16px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border: none;
        }

        .btn-primary-action {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 10px 25px rgba(19, 91, 236, 0.3);
        }

        .btn-primary-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(19, 91, 236, 0.4);
        }

        .btn-outline-action {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
        }

        .btn-outline-action:hover {
            background: rgba(0, 0, 0, 0.02);
        }

        :root[data-theme="dark"] .btn-outline-action:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .timeline-container {
            background: var(--bg-card);
            padding: 32px;
            border-radius: 32px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            position: relative;
        }

        .timeline-line {
            position: absolute;
            left: 43px;
            top: 80px;
            bottom: 32px;
            width: 2px;
            background: var(--border-color);
        }

        .timeline-item {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
            position: relative;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--bg-card);
            border: 2px solid transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 10;
        }

        .info-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .info-row {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        @media (max-width: 600px) {
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }

            .section-header>div {
                width: 100%;
                flex-direction: column;
            }

            .btn-action {
                width: 100%;
                justify-content: center;
            }

            .timeline-container {
                padding: 24px;
                border-radius: 24px;
            }

            .timeline-line {
                left: 35px;
            }
        }
    </style>

    <div class="split-layout">
        <!-- LEFT COLUMN -->
        <div style="display: flex; flex-direction: column; gap: 32px;">
            <!-- Quick Management -->
            <div>
                <div class="section-header">
                    <h3 class="section-title">Quick Management</h3>
                    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                        <a href="my-jobs.php" class="btn-action btn-primary-action">
                            <span class="material-symbols-outlined" style="font-size: 18px;">list_alt</span>
                            View My Jobs
                        </a>
                        <a href="my_skills.php" class="btn-action btn-outline-action">
                            <span class="material-symbols-outlined" style="font-size: 18px;">psychology</span>
                            Update Skills
                        </a>
                    </div>
                </div>
            </div>

            <!-- Ranking Card -->
            <div class="info-card" style="background: linear-gradient(145deg, #ffffff, #f8fafc); position: relative; overflow: hidden; border: 1px solid rgba(0,0,0,0.05);">
                <!-- Decorative background blob -->
                <div style="position: absolute; top: -50px; right: -50px; width: 150px; height: 150px; background: <?= $ranking_data['color'] ?>; opacity: 0.1; border-radius: 50%; filter: blur(30px);"></div>
                
                <div style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 48px; height: 48px; border-radius: 12px; background: <?= $ranking_data['color'] ?>22; color: <?= $ranking_data['color'] ?>; display: flex; align-items: center; justify-content: center;">
                            <span class="material-symbols-outlined" style="font-size: 28px;"><?= $ranking_data['icon'] ?></span>
                        </div>
                        <div>
                            <h4 style="font-weight: 800; color: var(--text-main); margin: 0; font-size: 18px;">Vendor Ranking</h4>
                            <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--text-muted); font-weight: 600;">Current Tier: <span style="color: <?= $ranking_data['color'] ?>; font-weight: 800; text-transform: uppercase;"><?= $ranking_data['tier'] ?></span></p>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <span style="font-size: 32px; font-weight: 900; color: <?= $ranking_data['color'] ?>; line-height: 1;"><?= $ranking_data['score'] ?></span>
                        <span style="font-size: 14px; color: var(--text-muted); font-weight: 700;">/100</span>
                    </div>
                </div>
                
                <div class="ranking-stats-row">
                    <div style="background: var(--bg-card); padding: 16px; text-align: center;">
                        <span class="material-symbols-outlined" style="color: #eab308; font-size: 20px; margin-bottom: 4px;">star</span>
                        <p style="margin: 0; font-size: 18px; font-weight: 800; color: var(--text-main);"><?= number_format($ranking_data['rating'], 1) ?></p>
                        <p style="margin: 2px 0 0 0; font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Avg Rating</p>
                    </div>
                    <div style="background: var(--bg-card); padding: 16px; text-align: center;">
                        <span class="material-symbols-outlined" style="color: #3b82f6; font-size: 20px; margin-bottom: 4px;">task_alt</span>
                        <p style="margin: 0; font-size: 18px; font-weight: 800; color: var(--text-main);"><?= $ranking_data['orders'] ?></p>
                        <p style="margin: 2px 0 0 0; font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Orders</p>
                    </div>
                    <div style="background: var(--bg-card); padding: 16px; text-align: center;">
                        <span class="material-symbols-outlined" style="color: #ec4899; font-size: 20px; margin-bottom: 4px;">video_library</span>
                        <p style="margin: 0; font-size: 18px; font-weight: 800; color: var(--text-main);"><?= $ranking_data['videos'] ?></p>
                        <p style="margin: 2px 0 0 0; font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Videos</p>
                    </div>
                </div>
            </div>

            <!-- Vendor Information Panel -->
            <div class="info-card">
                <div style="padding: 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="font-weight: 800; color: var(--text-main); margin: 0;">Vendor Identity</h4>
                    <a href="profile.php" style="color: var(--primary); font-size: 14px; font-weight: 700; text-decoration: none;">Edit Profile</a>
                </div>
                <div>
                    <div class="info-row">
                        <span style="color: var(--text-muted); font-size: 14px; font-weight: 500;">Business Name</span>
                        <span style="color: var(--text-main); font-weight: 700; font-size: 14px;"><?= htmlspecialchars($_SESSION['vendor_business_name'] ?? '-') ?></span>
                    </div>
                    <div class="info-row" style="background: rgba(0,0,0,0.01);">
                        <span style="color: var(--text-muted); font-size: 14px; font-weight: 500;">Email</span>
                        <span style="color: var(--text-main); font-weight: 700; font-size: 14px;"><?= htmlspecialchars($_SESSION['vendor_email'] ?? '-') ?></span>
                    </div>
                    <div class="info-row">
                        <span style="color: var(--text-muted); font-size: 14px; font-weight: 500;">City</span>
                        <span style="color: var(--text-main); font-weight: 700; font-size: 14px;"><?= htmlspecialchars($_SESSION['vendor_city'] ?? '-') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Timeline -->
        <div>
            <div class="timeline-container">
                <h3 class="section-title" style="margin-bottom: 32px;">Recent Activity</h3>
                <div class="timeline-line"></div>

                <!-- Activity 1 -->
                <div class="timeline-item">
                    <div class="timeline-icon" style="border-color: var(--primary);">
                        <div style="width: 8px; height: 8px; background: var(--primary); border-radius: 50%; animation: pulse 2s infinite;"></div>
                    </div>
                    <div style="flex: 1; margin-top: -4px;">
                        <p style="font-size: 14px; font-weight: 700; color: var(--text-main); margin: 0;">System Login</p>
                        <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 12px 0;">Just now</p>
                        <div style="padding: 12px; background: rgba(0,0,0,0.02); border: 1px solid var(--border-color); border-radius: 12px; font-size: 12px; color: var(--text-muted); font-style: italic;">
                            "Authenticated as <?= htmlspecialchars($_SESSION['vendor_name'] ?? 'Vendor') ?>"
                        </div>
                    </div>
                </div>

                <!-- Activity 2 -->
                <div class="timeline-item">
                    <div class="timeline-icon" style="border-color: #10b981;">
                        <div style="width: 8px; height: 8px; background: #10b981; border-radius: 50%;"></div>
                    </div>
                    <div style="flex: 1; margin-top: -4px;">
                        <p style="font-size: 14px; font-weight: 700; color: var(--text-main); margin: 0;">Online Status Check</p>
                        <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;">System Active</p>
                        <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;">Your profile is visible to customers.</p>
                    </div>
                </div>

                <!-- Activity 3 -->
                <div class="timeline-item">
                    <div class="timeline-icon" style="border-color: var(--border-color);">
                        <div style="width: 8px; height: 8px; background: var(--text-muted); border-radius: 50%;"></div>
                    </div>
                    <div style="flex: 1; margin-top: -4px;">
                        <p style="font-size: 14px; font-weight: 700; color: var(--text-main); margin: 0;">Dashboard Ready</p>
                        <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;">Today</p>
                        <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 0;">Awaiting new orders.</p>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
</div>

<?php include 'footer.php'; ?>