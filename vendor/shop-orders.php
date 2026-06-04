<?php
// vendor/shop-orders.php
session_start();

// Check Login
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';       // Vendor DB
require_once '../db_main.php';  // Main DB

$vendor_id = $_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

// Fetch vendor role
$vRole = 'external';
$vq = $conn->query("SELECT role FROM vendors WHERE id = $vendor_id LIMIT 1");
if ($vq && $rv = $vq->fetch_assoc()) {
    $vRole = strtolower(trim($rv['role']));
}

// ==========================================
// FETCH SHOP ORDERS (From Website)
// ==========================================
$orders_data = [];
$stmt = $conn->prepare("SELECT order_id, status, responded_at FROM order_vendor_notifications WHERE vendor_id = ? AND status IN ('accepted') ORDER BY responded_at DESC");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();

$order_ids = [];
$job_meta = [];

while ($row = $res->fetch_assoc()) {
    $order_ids[] = $row['order_id'];
    $job_meta[$row['order_id']] = $row;
}
$stmt->close();

if (!empty($order_ids)) {
    $ids_str = implode(',', array_map('intval', $order_ids));

    // Join with Order Items & Services
    $q = "
        SELECT o.*, s.name as service_name, s.description as service_desc, s.main_image, s.image as service_backup_image, s.gallery, s.slug, 
               ova.status as vendor_status, ova.id as assignment_id, ova.vendor_price as assignment_price,
               s.vendor_price as service_vendor_price, s.manpower_price as service_manpower_price, o.sticky_note
        FROM orders o 
        LEFT JOIN services s ON o.service_id = s.id 
        JOIN order_vendor_assignments ova ON o.id = ova.order_id AND ova.vendor_id = $vendor_id
        WHERE o.id IN ($ids_str) 
        AND ova.status != 'completed'
        GROUP BY o.id 
        ORDER BY ova.updated_at DESC, o.datetime DESC
    ";

    $resMain = $mainConn->query($q);

    if ($resMain) {
        while ($o = $resMain->fetch_assoc()) {
            $o['notification_status'] = $job_meta[$o['id']]['status'];
            $o['accepted_at'] = $job_meta[$o['id']]['responded_at'];

            // CHECK FOR ADDON ASSIGNMENT
            $ovq = "SELECT a.name as addon_name, a.description as addon_desc, a.image as addon_image, a.price as addon_price 
                    FROM order_vendor_assignments ova 
                    JOIN addons a ON ova.addon_id = a.id 
                    WHERE ova.order_id = {$o['id']} 
                    AND ova.vendor_id = $vendor_id 
                    AND ova.addon_id IS NOT NULL 
                    LIMIT 1";
            $ovRes = $mainConn->query($ovq);
            if ($ovRes && $ovRes->num_rows > 0) {
                $ovData = $ovRes->fetch_assoc();
                $o['service_name'] = $ovData['addon_name'] . " (Addon)";
                $o['service_desc'] = "Addon Service: " . $ovData['addon_desc'];
                $o['addon_image'] = $ovData['addon_image'];
                $o['addon_price'] = $ovData['addon_price'];
                $o['is_addon_job'] = true;
            }
            
            // Calculate Earning
            $earning = 0;
            if (!empty($o['is_addon_job'])) {
                $earning = floatval($o['addon_price']);
            } else {
                // Main Service Earning Logic: Prioritize Modified Assignment Price
                $modPrice    = floatval($o['assignment_price'] ?? 0);
                $vPriceInput = floatval($o['service_vendor_price'] ?? 0);
                $mPrice      = floatval($o['service_manpower_price'] ?? 0);
                $bPrice      = floatval($o['base_amount'] ?? 0);
                
                if ($modPrice > 0) {
                    $earning = $modPrice;
                } else {
                    $earning = ($vRole === 'internal') ? ($mPrice > 0 ? $mPrice : ($vPriceInput > 0 ? $vPriceInput : $bPrice)) : ($vPriceInput > 0 ? $vPriceInput : $bPrice);
                }
            }
            $o['earning_amount'] = $earning;

            // Prepare extra fields for display
            $o['map_link'] = "https://www.google.com/maps/search/?api=1&query=" . urlencode(($o['address_line'] ?? '') . ', ' . ($o['city'] ?? ''));
            $o['product_link'] = (!empty($o['slug'])) ? "https://surpriseville.co.in/service-details.php?slug=" . urlencode($o['slug']) : "";
            
            // Inclusions logic: Addon Desc > Service Desc > Order Note
            $inclusions = '';
            if (!empty($o['is_addon_job'])) {
                $inclusions = $o['service_desc']; // Already set to "Addon Service: ..."
            } else {
                $inclusions = !empty($o['service_desc']) ? $o['service_desc'] : ($o['note'] ?? '');
            }
            $o['display_inclusions'] = $inclusions;
            $o['clean_remarks'] = '';

            // Gallery logic
            $gallery = json_decode($o['gallery'] ?? '[]', true);
            if (!is_array($gallery)) $gallery = [];
            $o['gallery_json'] = $gallery;

            // FETCH UNREAD CHAT COUNT
            $o['unread_chat'] = 0;
            $ucQ = $mainConn->query("SELECT COUNT(*) as c FROM chat_messages WHERE order_id = {$o['id']} AND sender_type = 'user' AND is_read = 0");
            if ($ucQ && $ucR = $ucQ->fetch_assoc()) {
                $o['unread_chat'] = (int)$ucR['c'];
            }

            $orders_data[] = $o;
        }
    }
}

// ==========================================
// FETCH OFFLINE ORDERS (From manual_tasks)
// ==========================================
$gig_q = "
    SELECT mt.*, gc.name as cat_name 
    FROM manual_tasks mt 
    LEFT JOIN gig_categories gc ON mt.category_id = gc.id
    WHERE mt.assigned_vendor_id = ? 
    AND mt.vendor_status NOT IN ('completed') /* Adjust status as needed, mirroring online */
    ORDER BY mt.created_at DESC
";
$stmtGig = $conn->prepare($gig_q);
$stmtGig->bind_param("i", $vendor_id);
$stmtGig->execute();
$resGig = $stmtGig->get_result();

while ($gig = $resGig->fetch_assoc()) {
    $o = [];
    $o['id'] = $gig['id'];
    $o['is_offline'] = true; // internal flag
    $o['service_name'] = $gig['cat_name'] ? $gig['cat_name'] : 'Offline Order #' . $gig['id'];
    $o['datetime'] = $gig['created_at'];
    $o['name'] = $gig['client_name'];
    
    // Map status
    $o['vendor_status'] = $gig['vendor_status'] ?: 'assigned';
    
    // Address
    $o['address_line'] = $gig['full_address'];
    $o['city'] = $gig['locality'];
    
    // Amounts
    $o['earning_amount'] = floatval($gig['vendor_price']);
    $o['remaining_amount'] = floatval($gig['amount_to_collect']);
    
    // Links
    if (!empty($gig['google_map'])) {
        $o['map_link'] = $gig['google_map'];
    } else {
        $o['map_link'] = "https://www.google.com/maps/search/?api=1&query=" . urlencode(($o['address_line'] ?? '') . ', ' . ($o['city'] ?? ''));
    }
    $o['product_link'] = "";
    
    // Inclusions
    $o['display_inclusions'] = $gig['inclusions'];
    
    // Remarks
    $o['clean_remarks'] = '';
    if (!empty($gig['remarks'])) {
        $o['clean_remarks'] = trim(preg_replace('/Notes:\s*.*$/is', '', $gig['remarks']));
    }
    
    // Gallery
    $gallery = json_decode($gig['admin_media'] ?? '[]', true);
    if (!is_array($gallery)) $gallery = [];
    // Convert paths for offline media if needed (they might be stored in /uploads/admin_task_media/)
    $formatted_gallery = [];
    foreach ($gallery as $img) {
        $formatted_gallery[] = "uploads/admin_task_media/" . ltrim($img, '/');
    }
    $o['gallery_json'] = $formatted_gallery;
    
    $o['unread_chat'] = 0;
    $o['assignment_id'] = 0;
    
    $orders_data[] = $o;
}
$stmtGig->close();

// Sort combined array by datetime descending
usort($orders_data, function($a, $b) {
    return strtotime($b['datetime']) - strtotime($a['datetime']);
});

$page_title = 'Shop Orders';
include 'header.php';
?>

<style>
    .section-title { margin-top: 20px; border-bottom: 2px solid #eee; padding-bottom: 10px; color: #444; font-size: 18px; font-weight: 700; }
    .job-card { background: #fff; padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); border: 1px solid #f0f0f0; border-left: 5px solid #28a745; }
    .job-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
    .badge { padding: 4px 10px; border-radius: 20px; color: white; font-size: 11px; font-weight: bold; text-transform: uppercase; display: inline-block; }
    .bg-accepted { background: #28a745; }
    .bg-orange { background: #ed8936; }
    .bg-blue { background: #007bff; }
    .details-box { background: #f8f9fa; padding: 12px; border-radius: 8px; margin-top: 10px; font-size: 14px; color: #444; border: 1px solid #eee; }
    .product-view-link { display: inline-block; font-size: 13px; color: #007bff; text-decoration: none; margin-bottom: 8px; font-weight: 600; }
    .map-link { color: #d32f2f; font-weight: 700; text-decoration: none; margin-left: 5px; font-size: 13px; }
    .inclusions-box { margin-top: 10px; background: #f8f9ff; border-left: 4px solid #007bff; border-radius: 4px; padding: 10px 15px; }
    .inclusions-label { font-size: 12px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
    .inclusions-text { font-size: 14px; color: #444; line-height: 1.5; }
    .gallery-strip { display: flex; gap: 8px; overflow-x: auto; margin-top: 12px; padding-bottom: 5px; }
    .gallery-thumb { width: 70px; height: 70px; border-radius: 8px; object-fit: cover; flex-shrink: 0; border: 1px solid #eee; cursor: pointer; }
    .action-area { text-align: right; margin-top: 15px; border-top: 1px dashed #eee; padding-top: 15px; }
    .action-btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; transition: opacity 0.2s; }
    .btn-blue { background: #007bff; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-orange { background: #ed8936; color: white; }
    .btn-disabled { background: #e9ecef; color: #6c757d; cursor: not-allowed; border: 1px solid #dee2e6; }
    
    .addon-row {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr auto;
        gap: 8px;
        margin-bottom: 10px;
        align-items: center;
        background: #fff;
        padding: 8px;
        border-radius: 6px;
        border: 1px solid #eee;
    }
    @media (max-width: 480px) {
        .addon-row {
            grid-template-columns: 1fr 1fr;
        }
        .addon-row div:first-child {
            grid-column: span 2;
        }
    }
    
    .modal { 
        display: none; 
        position: fixed; 
        top: 0; 
        left: 0; 
        width: 100%; 
        height: 100%; 
        background: rgba(0, 0, 0, 0.8); 
        z-index: 1000; 
        backdrop-filter: blur(4px); 
        align-items: center; 
        justify-content: center; 
        overflow-y: auto;
        padding: 40px 10px;
    }
    .form-modal-content { 
        background: #fff; 
        padding: 25px; 
        width: 100%; 
        max-width: 450px; 
        border-radius: 12px; 
        position: relative; 
        animation: slideUp 0.3s; 
        margin: auto; 
    }
</style>

<style>
    /* Responsive overrides for this page */
    @media (max-width: 768px) {
        .main-padding { padding: 12px !important; }
        .job-card { padding: 15px 12px !important; border-radius: 8px !important; margin-bottom: 15px !important; }
        .section-title { margin-top: 10px !important; font-size: 16px !important; }
        .details-box { padding: 10px !important; font-size: 13px !important; }
        
        .job-header {
            flex-direction: column;
            gap: 10px;
        }

        .action-grid {
            grid-template-columns: 1fr !important;
            gap: 10px;
        }

        .action-btn {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="main-padding" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:2px solid #eee; padding-bottom:10px;">
        <h2 style="margin:0; color:#444; font-size:18px; font-weight:700;">🛒 All Orders</h2>
        <a href="ajax/export_csv.php?type=orders" class="action-btn" style="background:#4a5568; color:#fff; display:flex; align-items:center; gap:8px; padding:6px 15px; font-size:12px;">
            <i class="fa-solid fa-file-export"></i> Export CSV
        </a>
    </div>
    
    <?php if (empty($orders_data)): ?>
        <div style="text-align:center; padding:30px; background:#fff; border-radius:8px; color:#777;">
            No active shop orders.
        </div>
    <?php else: ?>
        <?php foreach ($orders_data as $job): ?>
            <div class="job-card shop">
                <div class="job-header" style="display:flex; gap:15px; align-items:flex-start;">
                    <?php 
                    $service_img = "https://partners.surpriseville.co.in/assets/no-img.png";
                    
                    if (!empty($job['is_offline'])) {
                        if (!empty($job['gallery_json']) && is_array($job['gallery_json'])) {
                            $service_img = "https://partners.surpriseville.co.in/" . ltrim($job['gallery_json'][0], '/');
                        }
                    } else {
                        // Priority: Addon Image > Service Main Image > Service Backup Image
                        $display_img = "";
                        if (!empty($job['addon_image'])) {
                            $display_img = $job['addon_image'];
                        } elseif (!empty($job['main_image'])) {
                            $display_img = $job['main_image'];
                        } elseif (!empty($job['service_backup_image'])) {
                            $display_img = $job['service_backup_image'];
                        }

                        if (!empty($display_img)) {
                            $service_img = "https://surpriseville.co.in/" . ltrim($display_img, '/');
                        }
                    }
                    ?>
                    <img src="<?= $service_img ?>" style="width:80px; height:80px; object-fit:cover; border-radius:8px; border:1px solid #eee; flex-shrink:0;">
                    <div style="flex:1;">
                        <h3 style="margin:0 0 5px 0; font-size:16px;">
                            <?= !empty($job['service_name']) ? htmlspecialchars($job['service_name']) : 'Order #' . $job['id'] ?>
                        </h3>
                        <p style="margin:0; color:#666; font-size:13px;">
                            <strong>📅 Booking:</strong> <?= date('d M Y h:i A', strtotime($job['datetime'])) ?><br>
                            <strong>👤 Customer:</strong> <?= htmlspecialchars($job['name']) ?>
                        </p>
                    </div>
                    <div class="header-right">
                        <?php 
                        $curStatus = $job['vendor_status'] ?: 'assigned';
                        $statusText = 'Accepted';
                        $badgeClass = 'bg-accepted';
                        
                        if ($curStatus == 'out_for_service') { $statusText = 'On the Way'; $badgeClass = 'bg-orange'; }
                        elseif ($curStatus == 'reached') { $statusText = 'Reached'; $badgeClass = 'bg-success'; }
                        elseif ($curStatus == 'started') { $statusText = 'Work Started'; $badgeClass = 'bg-blue'; }
                        elseif ($curStatus == 'completed') { $statusText = 'Completed'; $badgeClass = 'bg-success'; }
                        elseif ($curStatus == 'assigned') { $statusText = 'Assigned'; $badgeClass = 'bg-accepted'; }
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= $statusText ?></span>
                    </div>
                </div>

                <div class="details-box">
                    <p style="margin:0 0 5px 0;">
                        <strong>📍 Address:</strong> <?= htmlspecialchars($job['address_line'] . ', ' . $job['city']) ?>
                        <a href="<?= $job['map_link'] ?>" target="_blank" class="map-link mt-1">🗺️ Open in Maps</a>
                    </p>
                    <div style="display:flex; gap:20px; align-items:center;">
                        <?php if ($job['earning_amount'] > 0): ?>
                            <p style="margin:0; color:#10b981; font-weight:bold; font-size:14px;">
                                <i class="fa-solid fa-wallet"></i> Your Earning: ₹<?= number_format($job['earning_amount']) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (($job['remaining_amount'] ?? 0) > 0): ?>
                            <p style="margin:0; color:#e02424; font-weight:bold; font-size:14px;">
                                <i class="fa-solid fa-hand-holding-dollar"></i> To Collect: ₹<?= number_format($job['remaining_amount']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                $dispInclusions = !empty($job['display_inclusions']) ? $job['display_inclusions'] : '';
                if (!empty($dispInclusions)):
                ?>
                    <div class="inclusions-box">
                        <div class="inclusions-label">📋 Inclusions / Details</div>
                        <div class="inclusions-text"><?= nl2br(htmlspecialchars($dispInclusions)) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($job['clean_remarks'])): ?>
                    <div class="details-box" style="font-weight:bold; color:#000; margin-top:10px;">
                        ⚠️ Remarks: <?= nl2br(htmlspecialchars($job['clean_remarks'])) ?>
                    </div>
                <?php endif; ?>

                <?php 
                $gallery = $job['gallery_json'];
                // Fallback to main image if gallery is empty
                if (empty($gallery) && !empty($display_img)) {
                    $gallery[] = $display_img;
                }
                
                if (!empty($gallery)): 
                ?>
                    <div class="gallery-strip">
                        <?php foreach (array_slice($gallery, 0, 10) as $gImg): ?>
                            <?php 
                                if (!empty($job['is_offline'])) {
                                    $img_url = "https://partners.surpriseville.co.in/" . ltrim($gImg, '/');
                                } else {
                                    $img_url = "https://surpriseville.co.in/" . ltrim($gImg, '/');
                                }
                            ?>
                            <img src="<?= $img_url ?>" class="gallery-thumb" onclick="window.open(this.src, '_blank')" onerror="this.style.display='none'">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="action-area">
                    <?php // Status already defined above ?>
                    <div class="action-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <button class="action-btn" style="background:#38a169; color:#fff;" onclick="window.location.href='order-chat.php?order_id=<?= $job['id'] ?><?= !empty($job['is_offline']) ? '&is_offline=1' : '' ?>'">
                            <?= !empty($job['is_offline']) ? 'Message Admin' : 'Message Customer' ?> <?php if ($job['unread_chat'] > 0): ?> (<?= $job['unread_chat'] ?>) <?php endif; ?>
                        </button>
                        
                        <?php if ($curStatus == 'assigned'): ?>
                            <button class="action-btn btn-blue" onclick="<?= !empty($job['is_offline']) ? "startOfflineJourney({$job['id']})" : "startJourney({$job['id']})" ?>">🚀 Start Journey</button>
                        <?php elseif ($curStatus == 'out_for_service'): ?>
                            <button class="action-btn btn-orange" onclick="openReachedModal(<?= $job['id'] ?>, <?= !empty($job['is_offline']) ? 'true' : 'false' ?>)">📍 Reached Location</button>
                        <?php elseif ($curStatus == 'reached'): ?>
                            <button class="action-btn btn-success" onclick="<?= !empty($job['is_offline']) ? "startOfflineWork({$job['id']})" : "startWork({$job['id']})" ?>">▶ Start Work</button>
                        <?php elseif ($curStatus == 'started'): ?>
                            <?php if (!empty($job['is_offline'])): ?>
                                <button onclick="openCompleteOfflineModal(<?= $job['id'] ?>, <?= (float)$job['remaining_amount'] ?>)" class="action-btn btn-success">✅ Complete Job</button>
                            <?php else: ?>
                                <button onclick="openCompleteShopModal(<?= $job['id'] ?>, <?= (float)$job['remaining_amount'] ?>, <?= (int)$job['assignment_id'] ?>)" class="action-btn btn-success">✅ Complete Job</button>
                            <?php endif; ?>
                        <?php elseif ($curStatus == 'completed'): ?>
                            <button class="action-btn btn-disabled">⏳ Verification Pending</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modals for Shop Workflow -->
<div id="reachedModal" class="modal">
    <div class="form-modal-content">
        <span class="close-modal" onclick="document.getElementById('reachedModal').style.display='none'">&times;</span>
        <h2 style="margin-top:0;">📍 Reached Location</h2>
        <form onsubmit="submitShopAction(event, 'mark_reached')">
            <input type="hidden" name="order_id" id="reachedOrderId">
            <input type="hidden" name="is_offline" id="reachedIsOffline" value="false">
            <input type="hidden" name="action" value="mark_reached">
            <input type="hidden" name="latitude" class="latField">
            <input type="hidden" name="longitude" class="lngField">
            <div class="form-group">
                <label style="font-weight:bold;">Location Proof:</label>
                <input type="file" name="reached_proof[]" class="form-control" multiple required accept="image/*,video/*">
            </div>
            <button type="submit" class="action-btn btn-blue" style="width:100%">Submit & Mark Reached</button>
        </form>
    </div>
</div>

<div id="completeShopModal" class="modal">
    <div class="form-modal-content">
        <span class="close-modal" onclick="document.getElementById('completeShopModal').style.display='none'">&times;</span>
        <h2 style="margin-top:0;">✅ Complete Job</h2>
        <form onsubmit="submitShopAction(event, 'complete_job')">
            <input type="hidden" name="order_id" id="completeShopId">
            <input type="hidden" name="assignment_id" id="completeAssignmentId">
            <input type="hidden" name="action" value="complete_job">
            <input type="hidden" name="latitude" class="latField">
            <input type="hidden" name="longitude" class="lngField">

            <div class="form-group" style="background: #fff8f8; padding: 15px; border-radius: 8px; border: 1px solid #ffebeb; margin-bottom: 15px;">
                <label style="font-weight:bold; color: #d32f2f; display: block; margin-bottom: 10px;">
                    <i class="fa-solid fa-plus-circle"></i> Did you take any Addons?
                </label>
                
                <div id="addonContainer">
                    <!-- Addons will be added here -->
                </div>
                
                <button type="button" onclick="addAddonRow()" style="background:#edf2f7; border:1px dashed #cbd5e0; color:#4a5568; padding:5px 10px; border-radius:4px; font-size:12px; cursor:pointer; width:100%; margin-top:5px;">
                    + Add More Addons
                </button>

                <div style="margin-top:10px; padding-top:10px; border-top:1px solid #eee; text-align:right;">
                    <span style="font-weight:bold; font-size:14px;">Addon Total: ₹<span id="addonTotalDisplay">0</span></span>
                    <input type="hidden" name="addon_total" id="addonTotalInput" value="0">
                </div>
            </div>

            <div class="form-group">
                <label style="font-weight:bold;">Payment Collected:</label>
                <select name="payment_mode" class="form-control" onchange="toggleShopProof(this.value)">
                    <option value="cash">Cash</option>
                    <option value="online">Online</option>
                </select>
            </div>

            <div id="shopQrDiv" class="form-group" style="display:none; text-align:center;">
                <p id="shopQrText"></p>
                <img id="shopQrImage" style="width:200px; height:200px; border:1px solid #ccc; border-radius:8px;">
            </div>

            <div id="shopPayProof" class="form-group">
                <label style="font-weight:bold;">Payment Proof (Compulsory):</label>
                <input type="file" name="payment_proof" class="form-control" accept="image/*" required>
            </div>

            <div class="form-group">
                <label style="font-weight:bold;">Final Work Proofs:</label>
                <input type="file" name="work_proof[]" class="form-control" multiple required accept="image/*,video/*">
            </div>

            <div class="form-group" style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 15px; margin-bottom: 15px;">
                <label style="font-weight:bold; color: #3182ce; display: block; margin-bottom: 10px;">
                    <i class="fa-solid fa-video" style="margin-right: 5px;"></i> Submit Video Reviews (Optional)
                </label>
                <div style="margin-bottom: 10px;">
                    <label style="font-size: 12px; display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">Before Setup Video:</label>
                    <input type="file" name="before_video" class="form-control" accept="video/*" style="padding: 4px 8px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 10px;">
                    <label style="font-size: 12px; display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">After Setup Video:</label>
                    <input type="file" name="after_video" class="form-control" accept="video/*" style="padding: 4px 8px; font-size: 13px;">
                </div>
                <div>
                    <label style="font-size: 12px; display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">Selfie Video with Setup:</label>
                    <input type="file" name="selfie_video" class="form-control" accept="video/*" style="padding: 4px 8px; font-size: 13px;">
                </div>
                <small style="color:#718096; font-size:11px; display:block; margin-top:8px;">Uploading videos improves your vendor profile ranking!</small>
            </div>

            <div class="form-group">
                <label style="font-weight:bold;">Notes (Optional):</label>
                <textarea name="vendor_notes" class="form-control" rows="2" placeholder="Describe addons or any other details..."></textarea>
            </div>

            <button type="submit" class="action-btn btn-blue" style="width:100%">Submit Completion</button>
        </form>
    </div>
</div>

<div id="completeOfflineModal" class="modal">
    <div class="form-modal-content">
        <span class="close-modal" onclick="document.getElementById('completeOfflineModal').style.display='none'">&times;</span>
        <h2 style="margin-top:0;">✅ Complete Offline Job</h2>
        <form onsubmit="submitOfflineAction(event, 'complete_job')">
            <input type="hidden" name="task_id" id="completeOfflineId">
            <input type="hidden" name="action" value="complete">
            <input type="hidden" name="latitude" class="latField">
            <input type="hidden" name="longitude" class="lngField">

            <div class="form-group">
                <label style="font-weight:bold;">Payment Collected:</label>
                <select name="payment_mode" class="form-control" onchange="toggleOfflineProof(this.value)">
                    <option value="cash">Cash</option>
                    <option value="online">Online (Upload Screenshot)</option>
                </select>
            </div>

            <div id="offlinePayProof" class="form-group" style="display:none;">
                <label style="font-weight:bold;">Payment Proof:</label>
                <input type="file" name="payment_proof" class="form-control" accept="image/*">
            </div>

            <div class="form-group">
                <label style="font-weight:bold;">Final Work Proofs:</label>
                <input type="file" name="job_proof[]" class="form-control" multiple required accept="image/*,video/*">
            </div>

            <div class="form-group" style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 15px; margin-bottom: 15px;">
                <label style="font-weight:bold; color: #3182ce; display: block; margin-bottom: 10px;">
                    <i class="fa-solid fa-video" style="margin-right: 5px;"></i> Submit Video Reviews (Optional)
                </label>
                <div style="margin-bottom: 10px;">
                    <label style="font-size: 12px; display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">Before Setup Video:</label>
                    <input type="file" name="before_video" class="form-control" accept="video/*" style="padding: 4px 8px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 10px;">
                    <label style="font-size: 12px; display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">After Setup Video:</label>
                    <input type="file" name="after_video" class="form-control" accept="video/*" style="padding: 4px 8px; font-size: 13px;">
                </div>
                <div>
                    <label style="font-size: 12px; display: block; margin-bottom: 5px; color: #4a5568; font-weight: 500;">Selfie Video with Setup:</label>
                    <input type="file" name="selfie_video" class="form-control" accept="video/*" style="padding: 4px 8px; font-size: 13px;">
                </div>
                <small style="color:#718096; font-size:11px; display:block; margin-top:8px;">Uploading videos improves your vendor profile ranking!</small>
            </div>

            <button type="submit" class="action-btn btn-blue" style="width:100%">Submit Completion</button>
        </form>
    </div>
</div>

<script>
    function getGeo(callback) {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (pos) => callback(pos.coords.latitude, pos.coords.longitude),
                (err) => { 
                    if (typeof hideLoading === 'function') hideLoading();
                    alert("Location access required!"); 
                    callback('', ''); 
                }
            );
        } else { 
            if (typeof hideLoading === 'function') hideLoading();
            alert("Geolocation not supported."); 
            callback('', ''); 
        }
    }

    function startJourney(id) {
        if (!confirm("Start journey?")) return;
        if (typeof showLoading === 'function') showLoading();
        getGeo((lat, lng) => {
            const fd = new FormData();
            fd.append('action', 'start_journey'); fd.append('order_id', id); fd.append('latitude', lat); fd.append('longitude', lng);
            postShopAction(fd);
        });
    }

    function openReachedModal(id, isOffline = false) {
        document.getElementById('reachedOrderId').value = id;
        document.getElementById('reachedIsOffline').value = isOffline ? 'true' : 'false';
        document.getElementById('reachedModal').style.display = 'flex';
        getGeo((lat, lng) => {
            document.querySelectorAll('.latField').forEach(e => e.value = lat);
            document.querySelectorAll('.lngField').forEach(e => e.value = lng);
        });
    }

    function startWork(id) {
        if (!confirm("Start work?")) return;
        if (typeof showLoading === 'function') showLoading();
        getGeo((lat, lng) => {
            const fd = new FormData();
            fd.append('action', 'start_work'); fd.append('order_id', id); fd.append('latitude', lat); fd.append('longitude', lng);
            postShopAction(fd);
        });
    }

    function startOfflineJourney(id) {
        if (!confirm("Start journey for this offline order?")) return;
        if (typeof showLoading === 'function') showLoading();
        getGeo((lat, lng) => {
            const fd = new FormData();
            fd.append('action', 'start_journey'); fd.append('task_id', id); fd.append('latitude', lat); fd.append('longitude', lng);
            postOfflineAction(fd);
        });
    }

    function startOfflineWork(id) {
        if (!confirm("Start work for this offline order?")) return;
        if (typeof showLoading === 'function') showLoading();
        getGeo((lat, lng) => {
            const fd = new FormData();
            fd.append('action', 'start_work'); fd.append('task_id', id); fd.append('latitude', lat); fd.append('longitude', lng);
            postOfflineAction(fd);
        });
    }

    let activeShopAmt = 0;
    let activeShopId = 0;
    let statusPoller = null;

    function openCompleteShopModal(id, amount, asgn_id = 0) {
        activeShopId = id;
        activeShopAmt = amount || 0;
        document.getElementById('completeShopId').value = id;
        document.getElementById('completeAssignmentId').value = asgn_id;
        document.getElementById('completeShopModal').style.display = 'flex';
        toggleShopProof('cash');
        
        // Reset Addons
        document.getElementById('addonContainer').innerHTML = '';
        addAddonRow();
        
        if (statusPoller) clearInterval(statusPoller);
        getGeo((lat, lng) => {
            document.querySelectorAll('.latField').forEach(e => e.value = lat);
            document.querySelectorAll('.lngField').forEach(e => e.value = lng);
        });
    }

    function openCompleteOfflineModal(id, amount) {
        document.getElementById('completeOfflineId').value = id;
        document.getElementById('completeOfflineModal').style.display = 'flex';
        toggleOfflineProof('cash');
        getGeo((lat, lng) => {
            document.querySelectorAll('.latField').forEach(e => e.value = lat);
            document.querySelectorAll('.lngField').forEach(e => e.value = lng);
        });
    }

    const addonList = {
        "Pastel Balloons": 5,
        "Metallic Balloons": 5,
        "Vintage Balloons": 7,
        "Chrome Balloons": 10,
        "Confetti Balloons": 20,
        "Bladder Balloons": 40,
        "Foil Banner": 200,
        "Digit Foil (32 inch)": 150,
        "Digit Foil (16 inch)": 50,
        "Large Animal Face Foil": 200,
        "4D Foil Balloons": 150,
        "Heart / Star Foil": 50,
        "Fringe Curtain": 100,
        "Square Fringe Curtain": 150,
        "White Curtain": 250,
        "6x6 Wall Flex": 1500,
        "Artificial Leaves": 20,
        "3D Butterfly": 15,
        "Butterfly Cutouts": 300,
        "Paper Cutouts (Theme)": 50,
        "Rose": 40,
        "Petals (1 kg)": 500,
        "Fairy Lights": 200,
        "Neon Light": 500,
        "Focus Lights": 500,
        "LED Numbers/Letters": 500,
        "LED Butterfly": 1000,
        "Ring Stand / Frame Setup": 1000,
        "Rectangle Stand": 1500,
        "Welcome Board": 1500,
        "Cardboard Frame": 4000,
        "Cake Table": 500,
        "Baby Box": 700,
        "Soft Teddy Bear": 450,
        "Banner": 150,
        "Name Foil Letters": 50,
        "Paper Lanterns": 300,
        "Sunboard Cutout (2x3 ft)": 500,
        "Large Baby Theme Foils": 200,
        "5 Piece Set": 350
    };

    function addAddonRow() {
        const container = document.getElementById('addonContainer');
        const rowId = Date.now();
        const row = document.createElement('div');
        row.className = 'addon-row';
        row.id = `addon-${rowId}`;
        row.style.cssText = "display:grid; grid-template-columns: 2fr 1fr 1fr auto; gap:8px; margin-bottom:10px; align-items:center; background:#fff; padding:8px; border-radius:6px; border:1px solid #eee;";

        let options = '<option value="">-- Select Item --</option>';
        for (const [name, price] of Object.entries(addonList)) {
            options += `<option value="${name}" data-price="${price}">${name} (₹${price})</option>`;
        }
        options += '<option value="other">Other (Manual)</option>';

        row.innerHTML = `
            <div>
                <select class="form-control addon-select" onchange="handleAddonSelect(this, ${rowId})">
                    ${options}
                </select>
                <input type="text" class="form-control addon-name-manual" placeholder="Item Name" style="display:none; margin-top:5px;">
            </div>
            <div>
                <input type="number" class="form-control addon-price" placeholder="Price" oninput="calculateAddonTotal()" value="0">
            </div>
            <div>
                <input type="number" class="form-control addon-qty" placeholder="Qty" oninput="calculateAddonTotal()" value="1" min="1">
            </div>
            <button type="button" onclick="removeAddonRow(${rowId})" style="background:#fff5f5; color:#c53030; border:1px solid #fed7d7; border-radius:4px; padding:8px; cursor:pointer;">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;
        container.appendChild(row);
    }

    function removeAddonRow(id) {
        const row = document.getElementById(`addon-${id}`);
        if (row) row.remove();
        calculateAddonTotal();
    }

    function handleAddonSelect(select, rowId) {
        const row = document.getElementById(`addon-${rowId}`);
        const manualInput = row.querySelector('.addon-name-manual');
        const priceInput = row.querySelector('.addon-price');
        
        if (select.value === 'other') {
            manualInput.style.display = 'block';
            manualInput.required = true;
            priceInput.readOnly = false;
            priceInput.value = 0;
        } else if (select.value === '') {
            manualInput.style.display = 'none';
            manualInput.required = false;
            priceInput.value = 0;
        } else {
            manualInput.style.display = 'none';
            manualInput.required = false;
            const price = select.options[select.selectedIndex].getAttribute('data-price');
            priceInput.value = price;
            priceInput.readOnly = true;
        }
        calculateAddonTotal();
    }

    function calculateAddonTotal() {
        let total = 0;
        const rows = document.querySelectorAll('.addon-row');
        rows.forEach(row => {
            const price = parseFloat(row.querySelector('.addon-price').value) || 0;
            const qty = parseFloat(row.querySelector('.addon-qty').value) || 0;
            total += (price * qty);
        });
        document.getElementById('addonTotalDisplay').innerText = total.toLocaleString();
        document.getElementById('addonTotalInput').value = total;
    }

    function toggleShopProof(val) {
        const div = document.getElementById('shopPayProof');
        const qrDiv = document.getElementById('shopQrDiv');
        const qrText = document.getElementById('shopQrText');
        const qrImg = document.getElementById('shopQrImage');
        const proofInput = div.querySelector('input');

        // Payment proof is always required now as per user instruction
        proofInput.required = true; 
        div.style.display = 'block'; // Keep visible so it is focusable for validation

        if (val === 'online') {
            qrDiv.style.display = 'block';
            qrText.innerHTML = "Generating Secure QR Code...";
            qrImg.src = ""; // Clear old QR

            // Fetch Token
            fetch(`ajax/generate_recharge_token.php?order_id=${activeShopId}&amount=${activeShopAmt}`)
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        let link = `https://surpriseville.co.in/gig-payment.php?vendor_pay=1&order_id=${activeShopId}&amount=${activeShopAmt}&token=${d.token}`;
                        qrText.innerHTML = `Ask client to scan this QR to pay <strong>₹${activeShopAmt}</strong>`;
                        qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(link)}`;

                        // Start Polling
                        startPaymentPolling(activeShopId);
                    } else {
                        qrText.innerHTML = `<span style="color:red">Error: ${d.message}</span>`;
                    }
                })
                .catch(e => {
                    qrText.innerHTML = `<span style="color:red">Connection Error</span>`;
                });

        } else {
            qrDiv.style.display = 'none';
            if (statusPoller) clearInterval(statusPoller);
        }
    }

    function toggleOfflineProof(val) {
        const div = document.getElementById('offlinePayProof');
        const proofInput = div.querySelector('input');
        if (val === 'online') {
            div.style.display = 'block';
            proofInput.required = true;
        } else {
            div.style.display = 'none';
            proofInput.required = false;
        }
    }

    function startPaymentPolling(orderId) {
        if (statusPoller) clearInterval(statusPoller);
        statusPoller = setInterval(() => {
            fetch(`ajax/check_order_payment.php?order_id=${orderId}`)
                .then(r => r.json())
                .then(d => {
                    if (d.success && d.payment_status === 'paid') {
                        clearInterval(statusPoller);
                        handlePaymentSuccess();
                    }
                });
        }, 5000);
    }

    function handlePaymentSuccess() {
        const qrDiv = document.getElementById('shopQrDiv');
        const payProofDiv = document.getElementById('shopPayProof');
        const input = payProofDiv.querySelector('input');

        qrDiv.innerHTML = `
            <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #c3e6cb;">
                <i class="fa-solid fa-circle-check" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                <strong>Payment Received!</strong><br>
                Online payment of ₹${activeShopAmt} has been verified automatically.
            </div>
        `;
        payProofDiv.style.display = 'none';
        if (input) input.required = false;

        const submitBtn = document.querySelector('#completeShopModal button[type="submit"]');
        submitBtn.style.animation = "pulse 1s infinite alternate";
        submitBtn.innerText = "Verified! Click to Finish";
    }

    function submitShopAction(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.innerText = "Processing..."; }
        
        const fd = new FormData(e.target);
        
        // Collect Addons
        if (action === 'complete_job') {
            const addons = [];
            document.querySelectorAll('.addon-row').forEach(row => {
                const select = row.querySelector('.addon-select');
                const nameManual = row.querySelector('.addon-name-manual').value;
                const price = row.querySelector('.addon-price').value;
                const qty = row.querySelector('.addon-qty').value;
                
                const itemName = (select.value === 'other') ? nameManual : select.value;
                if (itemName && price > 0 && qty > 0) {
                    addons.push({ name: itemName, price: price, qty: qty, total: price * qty });
                }
            });
            fd.append('addon_items_json', JSON.stringify(addons));
        }
        
        const isOffline = document.getElementById('reachedIsOffline') && document.getElementById('reachedIsOffline').value === 'true';
        if (action === 'mark_reached' && isOffline) {
            fd.delete('order_id');
            fd.append('task_id', document.getElementById('reachedOrderId').value);
            postOfflineAction(fd, btn);
        } else {
            postShopAction(fd, btn);
        }
    }

    function submitOfflineAction(e, action) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.innerText = "Processing..."; }
        const fd = new FormData(e.target);
        postOfflineAction(fd, btn);
    }

    function postShopAction(fd, btn = null) {
        if (typeof showLoading === 'function') showLoading();
        fetch('../backend/order_actions.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (typeof hideLoading === 'function') hideLoading();
                alert(d.message);
                if (d.success) location.reload();
                else if (btn) { btn.disabled = false; btn.innerText = "Submit"; }
            })
            .catch(e => { 
                if (typeof hideLoading === 'function') hideLoading();
                alert("Error: " + e); 
                if (btn) { btn.disabled = false; btn.innerText = "Submit"; } 
            });
    }

    function postOfflineAction(fd, btn = null) {
        if (typeof showLoading === 'function') showLoading();
        fetch('gig_actions.php', { method: 'POST', body: fd }) // Handles offline gig actions.
            .then(r => r.json())
            .then(d => {
                if (typeof hideLoading === 'function') hideLoading();
                alert(d.message || "Action processed");
                if (d.success) location.reload();
                else if (btn) { btn.disabled = false; btn.innerText = "Submit"; }
            })
            .catch(e => { 
                if (typeof hideLoading === 'function') hideLoading();
                alert("Error: " + e); 
                if (btn) { btn.disabled = false; btn.innerText = "Submit"; } 
            });
    }
</script>

<?php include 'footer.php'; ?>
<?php if (isset($mainConn)) $mainConn->close(); if (isset($conn)) $conn->close(); ?>
