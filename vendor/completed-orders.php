<?php
// vendor/completed-orders.php
session_start();

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';       
require_once '../db_main.php';  

$vendor_id = (int)$_SESSION['vendor_id'];

// 1. Fetch Completed Shop Orders
$shop_orders = [];
$sQ = "
    SELECT 
        ova.order_id, 
        ova.status as v_status, 
        ova.completed_at, 
        o.datetime as order_date, 
        s.name as service_name, 
        s.main_image,
        a.name as addon_name,
        a.image as addon_image,
        ova.addon_id
    FROM order_vendor_assignments ova
    JOIN orders o ON ova.order_id = o.id
    LEFT JOIN services s ON o.service_id = s.id
    LEFT JOIN addons a ON ova.addon_id = a.id
    WHERE ova.vendor_id = ? AND ova.status = 'completed'
    ORDER BY ova.completed_at DESC
";
$stmt = $mainConn->prepare($sQ);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['type'] = 'shop';
    
    // Determine display title and image
    if (!empty($row['addon_id']) && !empty($row['addon_name'])) {
        $row['display_title'] = $row['addon_name'] . " (Addon)";
        $row['display_image'] = $row['addon_image'];
    } else {
        $row['display_title'] = $row['service_name'] ?: "Order #" . $row['order_id'];
        $row['display_image'] = $row['main_image'];
    }

    $row['display_date'] = $row['completed_at'] ?: $row['order_date'];
    $row['id'] = $row['order_id'];
    $shop_orders[] = $row;
}
$stmt->close();

// 2. Fetch Completed Manual Gigs
$gigs = [];
$gQ = "
    SELECT mt.id, mt.status, mt.created_at, gc.name as cat_name, tc.created_at as completed_at
    FROM manual_tasks mt
    LEFT JOIN gig_categories gc ON mt.category_id = gc.id
    LEFT JOIN task_completions tc ON tc.task_id = mt.id AND tc.vendor_id = mt.assigned_vendor_id
    WHERE mt.assigned_vendor_id = ? AND mt.status IN ('completed', 'verified')
    ORDER BY mt.created_at DESC
";
$stmt = $conn->prepare($gQ);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $row['type'] = 'gig';
    $row['display_title'] = $row['cat_name'] ?: 'Manual Gig #' . $row['id'];
    $row['display_date'] = $row['completed_at'] ?: $row['created_at'];
    $gigs[] = $row;
}
$stmt->close();

// 3. Merge and Sort
$all_completed = array_merge($shop_orders, $gigs);
usort($all_completed, function($a, $b) {
    return strtotime($b['display_date']) - strtotime($a['display_date']);
});

$page_title = 'Completed Jobs History';
include 'header.php';
?>

<div style="padding: 24px;" class="page-container">
    <div class="page-header">
        <h2 style="margin:0; font-size:24px; font-weight:800; letter-spacing:-0.5px;">Job History</h2>
        <p style="margin:0; color:var(--text-muted); font-size:14px;">Review your finalized and verified work.</p>
    </div>

    <?php if (empty($all_completed)): ?>
        <div class="card" style="text-align:center; padding:60px; color:var(--text-muted);">
            <span class="material-symbols-outlined" style="font-size:60px; opacity:0.3; margin-bottom:20px;">history</span>
            <p style="font-size:18px; font-weight:600; margin:0;">No completed jobs yet.</p>
            <p style="font-size:14px;">Once you finish a job, it will appear here.</p>
        </div>
    <?php else: ?>
        <div class="history-grid">
            <?php foreach ($all_completed as $job): 
                $badgeColor = ($job['type'] === 'shop') ? '#10b981' : '#f59e0b';
                $badgeBg = ($job['type'] === 'shop') ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)';
            ?>
                <div class="card history-card">
                    <div class="card-icon" style="background:<?= $badgeBg ?>; color:<?= $badgeColor ?>;">
                        <span class="material-symbols-outlined"><?= ($job['type'] === 'shop') ? 'shopping_cart' : 'work' ?></span>
                    </div>
                    
                    <div class="card-info">
                        <div class="card-meta">
                            <span class="type-badge" style="color:<?= $badgeColor ?>; background:<?= $badgeBg ?>;">
                                <?= $job['type'] ?> Order
                            </span>
                            <span class="date-text"><?= date('d M Y, h:i A', strtotime($job['display_date'])) ?></span>
                        </div>
                        <h3 class="card-title">
                            <?= htmlspecialchars($job['display_title']) ?>
                        </h3>
                    </div>

                    <div class="card-action">
                        <a href="view-job-details.php?type=<?= $job['type'] ?>&id=<?= $job['id'] ?>" class="btn-view">
                            <span>View Details</span>
                            <span class="material-symbols-outlined">arrow_forward</span>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .history-grid {
        display: grid;
        gap: 15px;
    }

    .history-card {
        padding: 15px;
        display: flex;
        align-items: center;
        gap: 20px;
        transition: all 0.3s ease;
    }

    .history-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(0,0,0,0.08);
    }

    .card-icon {
        width: 50px;
        height: 50px;
        min-width: 50px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .card-info {
        flex: 1;
    }

    .card-meta {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 4px;
    }

    .type-badge {
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        padding: 2px 8px;
        border-radius: 20px;
    }

    .date-text {
        font-size: 12px;
        color: var(--text-muted);
    }

    .card-title {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        color: var(--text-main);
    }

    .btn-view {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        background: var(--bg-body);
        color: var(--text-main);
        text-decoration: none;
        border-radius: 10px;
        font-weight: 600;
        font-size: 14px;
        border: 1px solid var(--border-color);
        transition: all 0.2s;
    }

    .btn-view .material-symbols-outlined {
        font-size: 18px;
    }

    .btn-view:hover {
        background: var(--border-color);
        transform: translateX(4px);
    }

    /* RESPONSIVE STYLES */
    @media (max-width: 768px) {
        .page-container {
            padding: 15px !important;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .history-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }

        .card-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
        }

        .card-action {
            width: 100%;
        }

        .btn-view {
            width: 100%;
            justify-content: center;
        }

        .card-meta {
            flex-wrap: wrap;
        }
    }
</style>

<?php include 'footer.php'; ?>
