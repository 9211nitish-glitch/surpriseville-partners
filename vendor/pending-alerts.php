<?php
// vendor/pending-alerts.php

// 1. Auth Check handled by header.php include later, but we need session here for check
require_once 'includes/session_manager.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Auth Check
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';
require_once '../db_main.php';
require_once 'gig_helper.php';

$vendor_id = (int)$_SESSION['vendor_id'];

// --- FUNCTION TO FETCH VALIDATED ALERTS ---
function getAvailableAlerts($conn, $mainConn, $vendor_id)
{
    // Fetch vendor role (internal/external)
    $vendor_role = 'external';
    $vRoleQ = $conn->prepare("SELECT role FROM vendors WHERE id = ? LIMIT 1");
    $vRoleQ->bind_param("i", $vendor_id);
    $vRoleQ->execute();
    $vRoleRes = $vRoleQ->get_result()->fetch_assoc();
    $vRoleQ->close();
    if (!empty($vRoleRes['role'])) {
        $vendor_role = strtolower(trim($vRoleRes['role']));
    }

    // Get vendor's active subscription package and its allowed rules
    $package_id = null;
    $pc_rules = [];
    $has_rules = false;

    $sub_stmt = $conn->prepare("SELECT package_id FROM vendor_subscriptions WHERE vendor_id = ? AND status = 'active' AND credits_remaining > 0 LIMIT 1");
    $sub_stmt->bind_param("i", $vendor_id);
    $sub_stmt->execute();
    $sub_res = $sub_stmt->get_result();
    if ($sub_row = $sub_res->fetch_assoc()) {
        $package_id = (int)$sub_row['package_id'];
    }
    $sub_stmt->close();

    if ($package_id !== null) {
        $pc_stmt = $conn->prepare("SELECT category_id, subcategory_id FROM package_categories WHERE package_id = ?");
        $pc_stmt->bind_param("i", $package_id);
        $pc_stmt->execute();
        $pc_res = $pc_stmt->get_result();
        while ($pc_row = $pc_res->fetch_assoc()) {
            $has_rules = true;
            $c = (int)$pc_row['category_id'];
            $s = $pc_row['subcategory_id'] !== null ? (int)$pc_row['subcategory_id'] : null;
            if (!isset($pc_rules[$c])) {
                $pc_rules[$c] = [];
            }
            $pc_rules[$c][] = $s;
        }
        $pc_stmt->close();
    }

    $isAllowed = function($c, $s) use ($has_rules, $pc_rules) {
        if (!$has_rules) {
            return true; // No rules means all allowed
        }
        if (!isset($pc_rules[$c])) {
            return false; // Category not allowed
        }
        if (in_array(null, $pc_rules[$c], true) || empty($pc_rules[$c])) {
            return true; // All subcategories allowed under this category
        }
        return $s !== null && in_array((int)$s, $pc_rules[$c], true);
    };

    $alerts = [];
    // A. Automated Orders
    $stmt = $conn->prepare("SELECT id, order_id, sent_at, 'auto' as type FROM order_vendor_notifications WHERE vendor_id = ? AND status = 'pending'");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $alerts[] = $r;
    $stmt->close();

    // B. Offline Orders
    $stmt2 = $conn->prepare("
        SELECT ta.task_id as order_id, ta.sent_at, 'manual' as type, mt.status as task_status
        FROM task_alerts ta
        JOIN manual_tasks mt ON mt.id = ta.task_id
        WHERE ta.vendor_id = ? AND ta.status = 'pending' AND mt.status = 'open'
    ");
    $stmt2->bind_param("i", $vendor_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($r = $res2->fetch_assoc()) $alerts[] = $r;
    $stmt2->close();

    // C. Sort (Newest First)
    usort($alerts, function ($a, $b) {
        return strtotime($b['sent_at']) - strtotime($a['sent_at']);
    });

    $finalAlerts = [];
    foreach ($alerts as $item) {
        $id       = $item['order_id'];
        $notif_id = isset($item['id']) ? (int)$item['id'] : 0;
        $isManual = ($item['type'] === 'manual');
        $sentAt   = strtotime($item['sent_at']);
        $expiryTs = $sentAt + 1800; // 30 min expiry

        // Expiry cleanup
        if (time() > $expiryTs) {
            if (!$isManual && $notif_id > 0) {
                $conn->query("UPDATE order_vendor_notifications SET status='expired' WHERE id=$notif_id");
            } elseif ($isManual) {
                $conn->query("UPDATE task_alerts SET status='expired' WHERE task_id=$id AND vendor_id=$vendor_id");
            }
            continue;
        }

        if ($isManual) {
            $q = $conn->query("SELECT mt.*, gc.name as cat_name FROM manual_tasks mt LEFT JOIN gig_categories gc ON gc.id = mt.category_id WHERE mt.id = $id");
            $data = $q->fetch_assoc();
            if (!$data) continue;
            if ($data['status'] !== 'open' || ($data['assigned_vendor_id'] > 0 && $data['assigned_vendor_id'] != $vendor_id)) {
                $conn->query("UPDATE task_alerts SET status='expired' WHERE task_id=$id AND vendor_id=$vendor_id");
                continue;
            }

            // Check subscription package categories/subcategories allowed rules
            $m_cat = $data['category_id'] !== null ? (int)$data['category_id'] : null;
            $m_subcat = $data['subcategory_id'] !== null ? (int)$data['subcategory_id'] : null;
            if ($m_cat !== null && !$isAllowed($m_cat, $m_subcat)) {
                continue; // Subscription doesn't allow this category/subcategory
            }

            // Prepare Title (Category + Service Title)
            $displayTitle = $data['cat_name'];
            if (!empty($data['service_title'])) {
                $displayTitle .= " - " . $data['service_title'];
            }

            // Prepare Data
            $remarks = $data['remarks'] ?? '';
            $eventDate = "Not Set";
            $timingInfo = "";
            if (preg_match('/Event:\s*(.*?)(?=\s*(Reach:|Reach\:|Ready:|Ready\:|Notes:|Notes\:|\n|\r|$))/i', $remarks, $m)) $eventDate = trim($m[1]);
            if (preg_match('/Reach:\s*(.*?)(?=\s*(Ready:|Ready\:|Notes:|Notes\:|\n|\r|$))/i', $remarks, $m)) $timingInfo .= "Reach: " . trim($m[1]);
            if (preg_match('/Ready:\s*(.*?)(?=\s*(Notes:|Notes\:|\n|\r|$))/i', $remarks, $m)) $timingInfo .= ($timingInfo ? " | " : "") . "Ready: " . trim($m[1]);

            $vPrice = isset($data['vendor_price']) ? floatval($data['vendor_price']) : 0;
            $ivPrice = isset($data['internal_vendor_price']) ? floatval($data['internal_vendor_price']) : 0;
            $price = ($vendor_role === 'internal') ? ($ivPrice > 0 ? $ivPrice : $vPrice) : ($vPrice > 0 ? $vPrice : 0);

            $media = json_decode($data['admin_media'] ?? '[]', true);
            $finalAlerts[] = array_merge($item, [
                'title' => $displayTitle,
                'desc' => $data['inclusions'],
                'price' => $price,
                'priceLabel' => "You Earn",
                'eventDate' => $eventDate,
                'timingInfo' => $timingInfo,
                'loc' => $data['locality'],
                'img' => !empty($media) ? '../uploads/admin_task_media/' . $media[0] : '',
                'typeLabel' => "🛠 Offline Orders",
                'badgeColor' => "#fd7e14",
                'accentColor' => "#ff9800",
                'expiryTs' => $expiryTs,
                'inclusions' => $data['inclusions'],
                'gallery' => [],
                'productLink' => '',
                'sticky_note' => $data['sticky_note'] ?? '',
                'order_type' => $data['order_type'] ?? ''
            ]);
        } else {
            // Shop Orders
            $sql = "SELECT o.address_line, o.city, o.booking_date, o.datetime, o.reach_time, o.ready_time, o.vendor_id, o.sticky_note, s.id as service_id, s.name, s.description, s.main_image, s.gallery, s.slug, s.category_id as service_cat_id, s.subcategory_id as service_subcat_id, s.vendor_price, s.manpower_price, o.base_amount as price FROM orders o LEFT JOIN services s ON s.id = o.service_id WHERE o.id = $id LIMIT 1";
            $q = $mainConn->query($sql);
            if (!$q) continue;
            $data = $q->fetch_assoc();
            if (!$data) continue;

            $mainServiceTaken = ($data['vendor_id'] > 0 && $data['vendor_id'] != $vendor_id);
            $roleFound = false;
            $alertData = [];

            $main_cat = $data['service_cat_id'] !== null ? (int)$data['service_cat_id'] : null;
            $main_subcat = $data['service_subcat_id'] !== null ? (int)$data['service_subcat_id'] : null;

            if (!$mainServiceTaken && $isAllowed($main_cat, $main_subcat)) {
                // Main Service Match
                $roleFound = true;
                $vendor_price = isset($data['vendor_price']) ? floatval($data['vendor_price']) : 0;
                $manpower_price = isset($data['manpower_price']) ? floatval($data['manpower_price']) : 0;
                $base_price = isset($data['price']) ? floatval($data['price']) : 0;
                $price = ($vendor_role === 'internal') ? ($manpower_price > 0 ? $manpower_price : ($vendor_price > 0 ? $vendor_price : $base_price)) : ($vendor_price > 0 ? $vendor_price : $base_price);

                $alertData = [
                    'title' => $data['name'],
                    'desc' => strip_tags($data['description']),
                    'price' => $price,
                    'priceLabel' => "Payout",
                    'img' => $data['main_image'] ? "https://surpriseville.co.in/" . ltrim($data['main_image'], "/") : "",
                    'typeLabel' => "🛒 Online Orders",
                    'badgeColor' => "var(--stat-bg-5)",
                    'accentColor' => "#007bff",
                    'gallery' => json_decode($data['gallery'] ?? '[]', true),
                    'productLink' => $data['slug'] ? "https://surpriseville.co.in/service-details.php?slug=" . urlencode($data['slug']) : ''
                ];
            } else {
                // Check Addons
                $addonQ = $mainConn->query("SELECT a.id as addon_id, a.name, a.description, a.image, a.category_id, a.subcategory_id, a.price FROM order_addons oa JOIN addons a ON oa.addon_id = a.id WHERE oa.order_id = $id");
                while ($addon = $addonQ->fetch_assoc()) {
                    $addon_cat = $addon['category_id'] !== null ? (int)$addon['category_id'] : null;
                    $addon_subcat = $addon['subcategory_id'] !== null ? (int)$addon['subcategory_id'] : null;

                    if ($isAllowed($addon_cat, $addon_subcat)) {
                        $aid = $addon['addon_id'];
                        $asn = $mainConn->query("SELECT vendor_id FROM order_vendor_assignments WHERE order_id = $id AND addon_id = $aid LIMIT 1")->fetch_assoc();
                        if ($asn && $asn['vendor_id'] > 0 && $asn['vendor_id'] != $vendor_id) continue;

                        $roleFound = true;
                        $alertData = [
                            'title' => $addon['name'],
                            'desc' => strip_tags($addon['description']),
                            'price' => floatval($addon['price']),
                            'priceLabel' => "Addon Payout",
                            'img' => $addon['image'] ? "https://surpriseville.co.in/" . ltrim($addon['image'], "/") : "",
                            'typeLabel' => "➕ Online Addon",
                            'badgeColor' => "var(--stat-bg-4)",
                            'accentColor' => "#9c27b0",
                            'gallery' => [],
                            'productLink' => ''
                        ];
                        break;
                    }
                }
            }

            if (!$roleFound) continue;

            // Finalize Shop Order Details
            $eventDate = $data['booking_date'] ?: ($data['datetime'] ?: "Not Set");
            $timingInfo = "";
            if ($eventDate != "Not Set" && strpos($eventDate, ' ') !== false && empty($data['reach_time'])) {
                $pts = explode(' ', $eventDate);
                $eventDate = $pts[0];
                $timingInfo = "Time: " . $pts[1];
            }
            if (!empty($data['reach_time'])) $timingInfo = "Reach: " . $data['reach_time'];
            if (!empty($data['ready_time'])) $timingInfo .= ($timingInfo ? " | " : "") . "Ready: " . $data['ready_time'];

            $finalAlerts[] = array_merge($item, $alertData, [
                'loc' => $data['address_line'] ?: $data['city'],
                'eventDate' => $eventDate,
                'timingInfo' => $timingInfo,
                'expiryTs' => $expiryTs,
                'inclusions' => $data['description'] ?? '',
                'sticky_note' => $data['sticky_note'] ?? ''
            ]);
        }
    }
    return $finalAlerts;
}

function renderAlertsHtml($alerts)
{
    if (empty($alerts)) {
        return '<div class="empty-state"><div style="font-size:40px">🎉</div><p>No pending alerts. You are all caught up!</p></div>';
    }
    ob_start();
    foreach ($alerts as $item):
        $accentColor = $item['accentColor'];
        $badgeColor = $item['badgeColor'];
        $typeLabel = $item['typeLabel'];
        $id = $item['order_id'];
        $title = $item['title'];
        $loc = $item['loc'];
        $eventDate = $item['eventDate'];
        $timingInfo = $item['timingInfo'];
        $productLink = $item['productLink'];
        $price = $item['price'];
        $priceLabel = $item['priceLabel'];
        $expiryTs = $item['expiryTs'];
        $img = $item['img'];
        $isManual = ($item['type'] === 'manual');
        $inclusions = $item['inclusions'];
        $gallery = $item['gallery'];
        $sticky_note = $item['sticky_note'] ?? '';
        $order_type = $item['order_type'] ?? '';
?>
        <div class="job-card" data-id="<?= $id ?>" data-type="<?= $item['type'] ?>">
            <div class="card-strip" style="background: <?= $accentColor ?>"></div>
            <div class="card-content">
                <div class="job-header">
                    <div class="job-img-wrapper">
                        <?php if ($img): ?><img src="<?= $img ?>" class="job-img" onerror="this.style.display='none'"><?php else: ?><div class="job-img-placeholder" style="color:<?= $accentColor ?>"><?= $isManual ? 'OFF' : 'ONL' ?></div><?php endif; ?>
                    </div>
                    <div class="job-info">
                        <span class="type-badge" style="color: <?= $accentColor ?>; background: <?= $badgeColor ?>;"><?= $typeLabel ?> #<?= $id ?></span>
                        <h3 class="job-title"><?= htmlspecialchars($title) ?></h3>
                        <p class="job-loc">📍 <?= htmlspecialchars($loc) ?></p>
                        <p class="job-event-info" style="margin-top: 5px; font-size: 13px; font-weight: 600; color: #444;">
                            📅 <?= htmlspecialchars($eventDate) ?>
                            <?php if ($timingInfo): ?><br>⏰ <?= htmlspecialchars($timingInfo) ?><?php endif; ?>
                        </p>
                        <?php if ($sticky_note): ?>
                            <div class="sticky-note-alert" style="margin-top: 10px; padding: 8px 12px; background: #fff5f5; border: 1px dashed #feb2b2; border-radius: 8px; font-size: 12px; color: #c53030; font-weight: 600; display: flex; align-items: flex-start; gap: 8px;">
                                <i class="fa-solid fa-note-sticky" style="margin-top: 2px;"></i>
                                <span><?= htmlspecialchars($sticky_note) ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($order_type): ?>
                            <div class="order-type-alert" style="margin-top: 8px; padding: 6px 12px; background: rgba(19, 91, 236, 0.05); border: 1px solid rgba(19, 91, 236, 0.1); border-radius: 8px; font-size: 12px; color: var(--primary); font-weight: 700; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-briefcase"></i>
                                <span><?= htmlspecialchars($order_type) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($productLink): ?><a href="<?= htmlspecialchars($productLink) ?>" target="_blank" class="product-view-link">🔗 View Service Page</a><?php endif; ?>
                    </div>
                    <div class="job-meta">
                        <div class="price-group">
                            <div class="price-tag" style="color: <?= $accentColor ?>">₹<?= number_format($price) ?></div>
                            <div class="price-label"><?= $priceLabel ?></div>
                        </div>
                        <div class="timer">Expires: <span class="countdown" data-expiry="<?= $expiryTs ?>">--</span></div>
                    </div>
                </div>
                <?php if (!empty($inclusions)): ?>
                    <div class="inclusions-box">
                        <div class="inclusions-label">📋 Inclusions</div>
                        <div class="inclusions-text"><?= nl2br(htmlspecialchars(strip_tags($inclusions))) ?></div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($gallery)): ?>
                    <div class="gallery-strip">
                        <?php foreach (array_slice($gallery, 0, 5) as $gImg): ?><img src="https://surpriseville.co.in/<?= ltrim(htmlspecialchars($gImg), '/') ?>" class="gallery-thumb" onerror="this.style.display='none'"><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="job-actions">
                    <button class="btn btn-decline" onclick="handleAction('decline', <?= $id ?>, '<?= $item['type'] ?>')">Decline</button>
                    <button class="btn btn-accept" onclick="handleAction('accept', <?= $id ?>, '<?= $item['type'] ?>')">Accept Order</button>
                </div>
            </div>
        </div>
<?php endforeach;
    return ob_get_clean();
}

// --- AJAX REQUESTS ---
if (isset($_GET['get_count'])) {
    // Radius Expansion logic remains for manual gigs
    $stmtExpire = $conn->query("SELECT id FROM manual_tasks WHERE status='open' AND search_radius=0 AND TIMESTAMPDIFF(MINUTE, last_radius_update, NOW()) >= 10");
    if ($stmtExpire && $stmtExpire->num_rows > 0) {
        while ($t = $stmtExpire->fetch_assoc()) expandRadiusLoop($conn, $t['id']);
    }

    $finalAlerts = getAvailableAlerts($conn, $mainConn, $vendor_id);
    echo count($finalAlerts);
    exit;
}

if (isset($_GET['ajax'])) {
    $finalAlerts = getAvailableAlerts($conn, $mainConn, $vendor_id);
    echo renderAlertsHtml($finalAlerts);
    exit;
}

$alerts = getAvailableAlerts($conn, $mainConn, $vendor_id);
$alertHtml = renderAlertsHtml($alerts);
?>
<?php
$page_title = 'Pending Alerts';
include 'header.php';
?>
<style>
    /* CUSTOM STYLES FOR ALERTS */
    .page-title {
        margin: 0;
        font-size: 24px;
        font-weight: 800;
        color: var(--text-main);
        letter-spacing: -0.5px;
    }

    .job-card {
        background: var(--bg-card);
        backdrop-filter: var(--glass-blur);
        -webkit-backdrop-filter: var(--glass-blur);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        margin-bottom: 20px;
        display: flex;
        overflow: hidden;
        position: relative;
        box-shadow: var(--card-shadow);
        transition: transform 0.3s, box-shadow 0.3s;
    }

    .job-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    }

    .card-strip {
        width: 6px;
        flex-shrink: 0;
    }

    .card-content {
        padding: 20px;
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .job-header {
        display: flex;
        gap: 15px;
        align-items: flex-start;
    }

    .job-img-wrapper {
        width: 60px;
        height: 60px;
        border-radius: 10px;
        overflow: hidden;
        flex-shrink: 0;
        background: rgba(0, 0, 0, 0.05);
    }

    :root[data-theme="dark"] .job-img-wrapper {
        background: rgba(255, 255, 255, 0.05);
    }

    .job-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .job-img-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 14px;
    }

    .job-info {
        flex: 1;
    }

    .type-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
    }

    .job-title {
        margin: 0 0 4px 0;
        font-size: 16px;
        font-weight: 700;
        color: var(--text-main);
    }

    .job-loc {
        margin: 0;
        font-size: 13px;
        color: var(--text-muted);
    }

    .product-view-link {
        display: inline-block;
        margin-top: 5px;
        font-size: 12px;
        font-weight: 600;
        color: var(--primary);
        text-decoration: none;
    }

    .job-meta {
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        justify-content: space-between;
    }

    .price-tag {
        font-size: 20px;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .price-label {
        font-size: 11px;
        color: var(--text-muted);
        font-weight: 600;
        text-transform: uppercase;
    }

    .timer {
        margin-top: 10px;
        font-size: 12px;
        font-weight: 600;
        color: #e63946;
        background: rgba(230, 57, 70, 0.1);
        padding: 4px 8px;
        border-radius: 12px;
    }

    .inclusions-box {
        background: rgba(0, 0, 0, 0.02);
        border: 1px dashed var(--border-color);
        padding: 10px 15px;
        border-radius: 10px;
    }

    :root[data-theme="dark"] .inclusions-box {
        background: rgba(255, 255, 255, 0.02);
    }

    .inclusions-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--text-muted);
        margin-bottom: 4px;
        text-transform: uppercase;
    }

    .inclusions-text {
        font-size: 13px;
        color: var(--text-main);
        line-height: 1.4;
    }

    .gallery-strip {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding-bottom: 5px;
    }

    .gallery-thumb {
        width: 60px;
        height: 40px;
        border-radius: 6px;
        object-fit: cover;
        flex-shrink: 0;
    }

    .job-actions {
        display: flex;
        gap: 10px;
        margin-top: 5px;
    }

    .btn {
        flex: 1;
        padding: 10px;
        border: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 14px;
        cursor: pointer;
        transition: transform 0.2s, opacity 0.2s;
    }

    .btn:hover {
        transform: translateY(-2px);
        opacity: 0.9;
    }

    .btn-accept {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
    }

    .btn-decline {
        background: transparent;
        color: var(--text-muted);
        border: 1px solid var(--border-color);
    }

    .btn-decline:hover {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.2);
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: var(--text-muted);
        font-weight: 500;
    }

    /* OVERLAY */
    #incomingOverlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(8px);
        z-index: 9999;
        align-items: center;
        justify-content: center;
    }

    .call-content {
        background: var(--bg-card);
        padding: 40px;
        border-radius: 20px;
        text-align: center;
        border: 1px solid var(--border-color);
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
        animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    @keyframes popIn {
        0% {
            transform: scale(0.8);
            opacity: 0;
        }

        100% {
            transform: scale(1);
            opacity: 1;
        }
    }

    .call-icon {
        font-size: 60px;
        margin-bottom: 20px;
        animation: ringz 2s infinite ease-in-out;
    }

    @keyframes ringz {

        0%,
        100% {
            transform: rotate(0deg);
        }

        25% {
            transform: rotate(15deg);
        }

        75% {
            transform: rotate(-15deg);
        }
    }

    .call-title {
        font-size: 24px;
        font-weight: 800;
        color: var(--text-main);
        margin-bottom: 10px;
    }

    .call-btn {
        background: var(--primary);
        color: white;
        border: none;
        padding: 12px 30px;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 15px rgba(19, 91, 236, 0.3);
    }

    @media (max-width: 600px) {
        .job-header {
            flex-direction: column;
            gap: 12px;
        }

        .job-meta {
            text-align: left;
            align-items: flex-start;
            margin-top: 5px;
            width: 100%;
            flex-direction: column;
            /* Stacked Price and Timer */
            gap: 10px;
        }

        .job-actions {
            flex-direction: column;
        }

        .btn {
            width: 100%;
        }
    }
</style>

<div style="padding: 24px;">

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="page-title">New Opportunities</h2>
        <div style="text-align: right;">
            <span style="font-size: 13px; color: var(--text-main); background: var(--bg-card); padding: 5px 12px; border-radius: 20px; box-shadow: var(--card-shadow); border: 1px solid var(--border-color);">Pending: <strong id="statPending" style="color: #e63946;">--</strong></span>
        </div>
    </div>

    <div id="alertsWrapper">
        <?= $alertHtml ?>
    </div>

    <div id="incomingOverlay" onclick="userInteracted()">
        <div class="call-content">
            <div class="call-icon">🔔</div>
            <div class="call-title">New Order Received !</div>
            <p style="margin-bottom:30px; font-size:14px; opacity:0.8;">Tap anywhere to view details</p>
            <button class="call-btn">VIEW ORDER</button>
        </div>
    </div>

    <script>
        let lastCount = 0;

        function refreshAlerts() {
            fetch('pending-alerts.php?get_count=1').then(r => r.text()).then(count => {
                const newCount = parseInt(count);
                document.getElementById('statPending').innerText = newCount;

                if (newCount !== lastCount) {
                    fetch('pending-alerts.php?ajax=1').then(r => r.text()).then(html => {
                        document.getElementById('alertsWrapper').innerHTML = html;
                        updateCountdowns();
                    });
                }

                lastCount = newCount;
            });
        }

        refreshAlerts();
        setInterval(refreshAlerts, 2000);

        function handleAction(action, id, type) {
            const actionText = action === 'accept' ? "Accept" : "Decline";

            if (!confirm(`Are you sure you want to ${actionText} this order?`)) return;

            // Get Location for Acceptance
            if (action === 'accept') {
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition((pos) => {
                        const loc = pos.coords.latitude + "," + pos.coords.longitude;
                        executeAction(action, id, type, loc);
                    }, (err) => {
                        console.error("Location Error:", err);
                        executeAction(action, id, type, ''); // Proceed without location if blocked
                    }, {
                        timeout: 5000
                    });
                } else {
                    executeAction(action, id, type, '');
                }
            } else {
                executeAction(action, id, type, '');
            }
        }

        function executeAction(action, id, type, loc) {
            let api = 'gig_actions.php';
            if (type === 'auto' && action === 'accept') api = 'vendor_accept_job.php';
            if (type === 'auto' && action === 'decline') api = 'vendor_decline_job.php';

            const fd = new FormData();
            fd.append('order_id', id);
            fd.append('task_id', id);
            fd.append('action', action);
            fd.append('loc', loc);
            if (action === 'decline') fd.append('reason', 'Declined by Vendor');

            fetch(api, {
                method: 'POST',
                body: fd

            }).then(r => r.json()).then(data => {
                if (data.success) {
                    const card = document.querySelector(`.job-card[data-id="${id}"]`);

                    if (card) {
                        card.style.opacity = "0";
                        setTimeout(() => card.remove(), 500);
                    }

                    refreshAlerts();
                    if (action === 'accept') {
                        alert(data.message || "Order accepted!");
                        window.location.href = (type === 'auto') ? 'shop-orders.php' : 'my-gigs.php';
                    }
                } else {
                    alert(data.message || "Error processing request");
                }
            }).catch(e => alert("Network Error."));
        }

        const removingCards = new Set();

        function updateCountdowns() {
            const now = Math.floor(Date.now() / 1000);

            document.querySelectorAll('.countdown').forEach(el => {
                const expiry = parseInt(el.dataset.expiry || 0);
                const diff = expiry - now;

                if (diff <= 0) {
                    const card = el.closest('.job-card');

                    if (card && !removingCards.has(card)) {
                        removingCards.add(card);
                        el.textContent = "Expired";

                        // Auto-decline on server so DB count updates (sidebar polling will reflect)
                        const cardId = card.dataset.id;
                        const cardType = card.dataset.type;
                        const declineApi = (cardType === 'auto') ? 'vendor_decline_job.php' : 'gig_actions.php';
                        const fd = new FormData();
                        fd.append('order_id', cardId);
                        fd.append('task_id', cardId);
                        fd.append('action', 'decline');
                        fd.append('reason', 'Expired');

                        fetch(declineApi, {
                            method: 'POST',
                            body: fd

                        }).catch(() => {});

                        // Show "removing" message briefly, then fade out
                        const timerEl = el.closest('.timer');
                        if (timerEl) timerEl.textContent = '⏰ Removing in 3s...';

                        card.style.transition = 'opacity 0.8s ease, max-height 0.6s ease, margin 0.6s ease';

                        setTimeout(() => {
                                card.style.opacity = '0';
                                card.style.maxHeight = '0';
                                card.style.marginBottom = '0';
                                card.style.overflow = 'hidden';

                                setTimeout(() => {
                                        card.remove();
                                        // Decrement sidebar + header badge immediately
                                        const statEl = document.getElementById('statPending');

                                        if (statEl) {
                                            const cur = parseInt(statEl.innerText) || 0;
                                            statEl.innerText = Math.max(0, cur - 1);
                                        }

                                        // Check if list is now empty
                                        const wrapper = document.getElementById('alertsWrapper');

                                        if (wrapper && !wrapper.querySelector('.job-card')) {
                                            wrapper.innerHTML = '<div class="empty-state"><div style="font-size:40px">🎉</div><p>No pending alerts. You are all caught up!</p></div>';
                                        }
                                    }

                                    , 800);
                            }

                            , 3000);
                    }
                } else {
                    const m = Math.floor(diff / 60);
                    const s = diff % 60;
                    el.textContent = m + "m " + String(s).padStart(2, '0') + "s";
                }
            });
        }

        setInterval(updateCountdowns, 1000);
        updateCountdowns();

        function userInteracted() {
            document.getElementById('incomingOverlay').style.display = 'none';
        }
    </script>
</div>
<?php include 'footer.php'; ?>