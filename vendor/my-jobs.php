<?php
// vendor/my_orders.php
session_start();

// Check Login
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

// REDIRECT TO NEW PAGES
header('Location: shop-orders.php');
exit;

require_once '../db.php';       // Vendor DB
require_once '../db_main.php';  // Main DB

$vendor_id = $_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

// ==========================================
// 1. FETCH Online OrderS (From Website)
// ==========================================
$vendor_jobs = [];
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

$orders_data = [];
if (!empty($order_ids)) {
    $ids_str = implode(',', array_map('intval', $order_ids));

    // Join with Order Items & Services
    $q = "
            SELECT o.*, s.name as service_name, s.description as service_desc, s.main_image, s.gallery, s.slug 
            FROM orders o 
            JOIN order_items oi ON o.id = oi.order_id 
            JOIN services s ON s.id = oi.service_id 
            WHERE o.id IN ($ids_str) 
            GROUP BY o.id 
            ORDER BY o.datetime DESC
        ";

    $resMain = $mainConn->query($q);

    // Fallback for older DB or missing columns
    if (!$resMain) {
        $q = "
                SELECT o.*, s.name as service_name, s.description as service_desc, s.main_image 
                FROM orders o 
                JOIN order_items oi ON o.id = oi.order_id 
                JOIN services s ON s.id = oi.service_id 
                WHERE o.id IN ($ids_str) 
                GROUP BY o.id 
                ORDER BY o.datetime DESC
            ";
        $resMain = $mainConn->query($q);
    }

    if ($resMain) {
        while ($o = $resMain->fetch_assoc()) {
            // FIX: Do NOT overwrite vendor_status from orders table!
            $o['notification_status'] = $job_meta[$o['id']]['status'];
            $o['accepted_at'] = $job_meta[$o['id']]['responded_at'];

            // CHECK FOR ADDON ASSIGNMENT
            // If this vendor is assigned to a specific addon, show that instead of main service
            $ovq = "SELECT a.name as addon_name, a.description as addon_desc 
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
                $o['is_addon_job'] = true;
                // For addons, we might not have a separate slug/gallery in this query yet, 
                // but let's keep what we have or null them if they don't apply.
            }

            // Prepare extra fields for display
            $o['map_link'] = "https://www.google.com/maps/search/?api=1&query=" . urlencode($o['address_line'] . ', ' . $o['city']);
            $o['product_link'] = (!empty($o['slug'])) ? "https://surpriseville.co.in/service-details.php?slug=" . urlencode($o['slug']) : "";
            $o['inclusions'] = $o['service_desc'] ?? ''; // services uses description for inclusions
            $o['gallery_json'] = json_decode($o['gallery'] ?? '[]', true);
            if (!is_array($o['gallery_json'])) $o['gallery_json'] = [];

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
$mainConn->close();

// ==========================================
// 2. FETCH Offline OrderS (Direct from Admin)
// ==========================================
$offline_orders_data = [];
$gig_q = "
    SELECT mt.*, gc.name as cat_name 
    FROM manual_tasks mt 
    LEFT JOIN gig_categories gc ON mt.category_id = gc.id
    WHERE mt.assigned_vendor_id = ? 
    AND mt.status IN ('assigned', 'completed', 'verified')
    ORDER BY mt.created_at DESC
";
$stmt = $conn->prepare($gig_q);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$resGig = $stmt->get_result();
while ($row = $resGig->fetch_assoc()) {
    $offline_orders_data[] = $row;
}
$stmt->close();
?>
<?php
$page_title = 'My Jobs';
include 'header.php';
?>
<style>
    /* SECTION TITLE */
    .section-title {
        margin-top: 20px;
        border-bottom: 2px solid #eee;
        padding-bottom: 10px;
        color: #444;
        font-size: 18px;
        font-weight: 700;
    }

    .section-title:first-of-type {
        margin-top: 0;
    }

    /* JOB CARDS */
    .job-card {
        background: #fff;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #f0f0f0;
        border-left: 5px solid #ddd;
    }

    .job-card.shop {
        border-left-color: #28a745;
    }

    .job-card.gig {
        border-left-color: #17a2b8;
    }

    .job-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        color: white;
        font-size: 11px;
        font-weight: bold;
        text-transform: uppercase;
        display: inline-block;
    }

    .bg-accepted {
        background: #28a745;
    }

    .bg-assigned {
        background: #17a2b8;
    }

    .bg-completed {
        background: #6f42c1;
    }

    .bg-verified {
        background: #20c997;
    }

    .details-box {
        background: #f8f9fa;
        padding: 12px;
        border-radius: 8px;
        margin-top: 10px;
        font-size: 14px;
        color: #444;
        border: 1px solid #eee;
    }

    .label {
        font-weight: 700;
        color: #333;
    }

    /* MEDIA THUMBNAILS */
    .media-grid {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding-bottom: 5px;
    }

    .media-container {
        position: relative;
        width: 80px;
        height: 80px;
        flex-shrink: 0;
        cursor: pointer;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #ddd;
        background: #000;
    }

    .gig-thumb {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .play-icon {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: white;
        font-size: 24px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
        opacity: 0.9;
        pointer-events: none;
    }

    /* BUTTONS */
    .action-area {
        text-align: right;
        margin-top: 15px;
        border-top: 1px dashed #eee;
        padding-top: 15px;
    }

    .action-btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        display: inline-block;
        transition: opacity 0.2s;
    }

    .action-btn:hover {
        opacity: 0.9;
    }

    .action-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    @media (max-width: 600px) {
        .action-grid {
            grid-template-columns: 1fr;
        }
    }

    .btn-blue {
        background: #007bff;
        color: white;
    }

    .btn-disabled {
        background: #e9ecef;
        color: #6c757d;
        cursor: not-allowed;
        border: 1px solid #dee2e6;
    }

    .btn-success {
        background: #d4edda;
        color: #155724;
        cursor: default;
        border: 1px solid #c3e6cb;
    }

    /* MODALS */
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
    }

    .form-modal-content {
        background: #fff;
        padding: 25px;
        width: 90%;
        max-width: 450px;
        border-radius: 12px;
        position: relative;
        animation: slideUp 0.3s;
        margin: auto;
    }

    .video-modal-content {
        width: 100%;
        max-width: 800px;
        padding: 0;
        background: transparent;
        text-align: center;
    }

    .video-player {
        width: 100%;
        max-height: 80vh;
        border-radius: 8px;
        background: black;
    }

    @keyframes slideUp {
        from {
            transform: translateY(50px);
            opacity: 0
        }

        to {
            transform: translateY(0);
            opacity: 1
        }
    }

    .close-modal {
        position: absolute;
        top: 15px;
        right: 20px;
        font-size: 28px;
        color: #999;
        cursor: pointer;
        z-index: 10;
    }

    .close-video {
        position: absolute;
        top: 20px;
        right: 20px;
        font-size: 40px;
        color: white;
        cursor: pointer;
        z-index: 2000;
        text-shadow: 0 0 10px black;
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-control {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        box-sizing: border-box;
    }

    /* MEDIA QUERIES */
    @media (max-width: 900px) {
        .job-header {
            flex-direction: column;
            gap: 10px;
        }

        .header-right {
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px dashed #eee;
            padding-top: 10px;
            margin-top: 5px;
        }

        .action-btn {
            width: 100%;
            text-align: center;
        }
    }

    /* NEW ENHANCEMENTS */
    .product-view-link {
        display: inline-block;
        font-size: 13px;
        color: #007bff;
        text-decoration: none;
        margin-bottom: 8px;
        font-weight: 600;
    }

    .product-view-link:hover {
        text-decoration: underline;
    }

    .map-link {
        color: #d32f2f;
        font-weight: 700;
        text-decoration: none;
        margin-left: 5px;
        font-size: 13px;
    }

    .map-link:hover {
        text-decoration: underline;
    }

    .inclusions-box {
        margin-top: 10px;
        background: #f8f9ff;
        border-left: 4px solid #007bff;
        border-radius: 4px;
        padding: 10px 15px;
    }

    .inclusions-label {
        font-size: 12px;
        font-weight: 700;
        color: #555;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
    }

    .inclusions-text {
        font-size: 14px;
        color: #444;
        line-height: 1.5;
    }

    .gallery-strip {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        margin-top: 12px;
        padding-bottom: 5px;
    }

    .gallery-thumb {
        width: 70px;
        height: 70px;
        border-radius: 8px;
        object-fit: cover;
        flex-shrink: 0;
        border: 1px solid #eee;
        cursor: pointer;
        transition: transform 0.2s;
    }

    .gallery-thumb:hover {
        transform: scale(1.05);
    }

    /* EYE-CATCHING REMARKS */
    .remarks-highlight {
        background: #fff3cd !important;
        border: 2px solid #ffc107 !important;
        border-left: 8px solid #ffc107 !important;
        position: relative;
        padding-left: 45px !important;
        animation: pulse-border 2s infinite;
    }

    .remarks-highlight::before {
        content: '⚠️';
        position: absolute;
        left: 12px;
        top: 12px;
        font-size: 20px;
    }

    @keyframes pulse-border {
        0% { border-color: #ffc107; box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.4); }
        70% { border-color: #ffc107; box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
        100% { border-color: #ffc107; box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
    }
</style>
<div style="padding: 24px;">

    <h2 class="section-title">🛠️ Active Offline Orders (Admin Assigned)</h2>
    <?php if (empty($offline_orders_data)): ?>
        <div style="text-align:center; padding:30px; background:#fff; border-radius:8px; color:#777;">
            No Active Offline Orders assigned yet.
        </div>
    <?php else: ?>
        <?php foreach ($offline_orders_data as $gig): ?>
            <div class="job-card gig" id="task-<?= $gig['id'] ?>">
                <div class="job-header">
                    <div>
                        <h3 style="margin:0 0 5px 0; color:#333; font-size:16px;">
                            Gig #<?= $gig['id'] ?>: <?= htmlspecialchars($gig['cat_name']) ?>
                        </h3>
                        <p style="margin:0; color:#666; font-size:13px;">
                            <strong>📅 Event:</strong> <?= date('d M Y', strtotime($gig['created_at'])) ?><br>
                            <strong>👤 Client:</strong> <?= htmlspecialchars($gig['client_name']) ?>
                        </p>
                    </div>
                    <div class="header-right">
                        <strong style="color:#28a745; font-size:14px;">₹<?= number_format($gig['vendor_price']) ?></strong>
                        <span class="badge bg-<?= $gig['status'] ?>" style="margin-left:10px;"><?= strtoupper($gig['status']) ?></span>
                    </div>
                </div>

                <div class="details-box">
                    <p style="margin:0 0 5px 0;"><strong>📍 Address:</strong> <?= htmlspecialchars($gig['full_address']) ?>
                        <?php if (!empty($gig['google_map'])): ?>
                            <a href="<?= htmlspecialchars($gig['google_map']) ?>" target="_blank" style="color:#007bff; font-weight:600;">(Map ↗)</a>
                        <?php endif; ?>
                    </p>
                    <p style="margin:0 0 5px 0;"><strong>📍 Locality:</strong> <?= htmlspecialchars($gig['locality']) ?></p>
                    <?php if (($gig['amount_to_collect'] ?? 0) > 0): ?>
                        <p style="margin:0; color:#e02424; font-weight:bold;">💰 To Collect from Client: ₹<?= number_format($gig['amount_to_collect']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="details-box">
                    <span class="label">📝 Inclusions:</span><br>
                    <?= nl2br(htmlspecialchars($gig['inclusions'] ?: 'No specific inclusions mentioned.')) ?>
                </div>

                <?php if (!empty($gig['remarks'])): ?>
                    <div class="details-box remarks-highlight">
                        <span class="label">IMPORTANT REMARKS:</span><br>
                        <span style="font-size: 15px; font-weight: 700; color: #856404;"><?= nl2br(htmlspecialchars($gig['remarks'])) ?></span>
                    </div>
                <?php endif; ?>

                <?php
                $images = json_decode($gig['admin_media'] ?? '[]');
                if (!empty($images)):
                ?>
                    <div style="margin-top:10px;">
                        <span class="label" style="font-size:12px;">📸 Reference:</span><br>
                        <div class="media-grid">
                            <?php foreach ($images as $img):
                                $ext = strtolower(pathinfo($img, PATHINFO_EXTENSION));
                                $is_video = in_array($ext, ['mp4', 'webm', 'ogg', 'mov']);
                                $file_url = "../uploads/admin_task_media/" . $img;
                            ?>
                                <div class="media-container" onclick="<?php echo $is_video ? "playVideo('$file_url')" : "window.open('$file_url', '_blank')"; ?>">
                                    <?php if ($is_video): ?>
                                        <video src="<?= $file_url ?>" class="gig-thumb" muted preload="metadata"></video>
                                        <div class="play-icon">▶</div>
                                    <?php else: ?>
                                        <img src="<?= $file_url ?>" class="gig-thumb">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="action-area">
                    <?php if ($gig['status'] == 'assigned'): ?>
                        <button class="action-btn btn-blue" onclick="openCompleteModal(<?= $gig['id'] ?>, <?= (float)($gig['amount_to_collect'] ?? 0) ?>)">✅ Mark as Completed</button>
                    <?php elseif ($gig['status'] == 'completed'): ?>
                        <button class="action-btn btn-disabled">⏳ Waiting Verification</button>
                    <?php elseif ($gig['status'] == 'verified'): ?>
                        <button class="action-btn btn-success">✔ Payment Verified</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="section-title">🛒 Online Orders</h2>
    <?php if (empty($orders_data)): ?>
        <div style="text-align:center; padding:30px; background:#fff; border-radius:8px; color:#777;">
            No active Online Orders.
        </div>
    <?php else: ?>
        <?php foreach ($orders_data as $job): ?>
            <div class="job-card shop">
                <div class="job-header">
                    <div>
                        <h3 style="margin:0 0 5px 0; font-size:16px;">
                            <?= !empty($job['service_name']) ? htmlspecialchars($job['service_name']) : 'Order #' . $job['id'] ?>
                        </h3>
                        <p style="margin:0; color:#666; font-size:13px;">
                            <strong>📅 Booking:</strong> <?= date('d M Y h:i A', strtotime($job['datetime'])) ?><br>
                            <strong>👤 Customer:</strong> <?= htmlspecialchars($job['name']) ?>
                        </p>
                    </div>
                    <div class="header-right">
                        <span class="badge bg-accepted">ACCEPTED</span>
                    </div>
                </div>

                <div class="details-box">
                    <p style="margin:0 0 5px 0;">
                        <strong>📍 Address:</strong> <?= htmlspecialchars($job['address_line'] . ', ' . $job['city']) ?>
                        <a href="<?= $job['map_link'] ?>" target="_blank" class="map-link mt-1">🗺️ Open in Maps</a>
                    </p>
                    <p style="margin:0 0 5px 0;"><strong>📞 Phone:</strong> <?= htmlspecialchars($job['phone']) ?></p>
                    <?php if (($job['remaining_amount'] ?? 0) > 0): ?>
                        <p style="margin:0; color:#e02424; font-weight:bold;">💰 To Collect from Client: ₹<?= number_format($job['remaining_amount']) ?></p>
                    <?php endif; ?>
                    <?php if ($job['product_link']): ?>
                        <a href="<?= $job['product_link'] ?>" target="_blank" class="product-view-link" style="margin-top:10px;">🔗 View Service Page</a>
                    <?php endif; ?>
                </div>

                <?php
                $dispInclusions = !empty($job['inclusions']) ? $job['inclusions'] : $job['service_desc'];
                if (!empty($dispInclusions)):
                ?>
                    <div class="inclusions-box">
                        <div class="inclusions-label">📋 Inclusions / Description</div>
                        <div class="inclusions-text"><?= nl2br(htmlspecialchars(strip_tags($dispInclusions))) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($job['gallery_json'])): ?>
                    <div class="gallery-strip">
                        <?php foreach (array_slice($job['gallery_json'], 0, 6) as $gImg): ?>
                            <img src="https://surpriseville.co.in<?= ltrim(htmlspecialchars($gImg), '/') ?>" class="gallery-thumb" onclick="window.open(this.src, '_blank')" onerror="this.style.display='none'">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="action-area">
                    <?php $curStatus = $job['vendor_status'] ?: 'assigned'; ?>
                    <div class="action-grid">
                        <!-- Chat Button -->
                        <button class="action-btn" style="background:#38a169; color:#fff;" onclick="window.location.href='order-chat.php?order_id=<?= $job['id'] ?>'">
                            Message Customer
                        </button>
                        
                        <?php if ($curStatus == 'assigned'): ?>
                            <button class="action-btn btn-blue" onclick="startJourney(<?= $job['id'] ?>)">🚀 Start Journey</button>
                        <?php elseif ($curStatus == 'out_for_service'): ?>
                            <button class="action-btn btn-orange" onclick="openReachedModal(<?= $job['id'] ?>)">📍 Reached Location</button>
                        <?php elseif ($curStatus == 'reached'): ?>
                            <button class="action-btn btn-success" onclick="startWork(<?= $job['id'] ?>)">▶ Start Work</button>
                        <?php elseif ($curStatus == 'started'): ?>
                            <button class="action-btn btn-blue" onclick="openCompleteShopModal(<?= $job['id'] ?>, <?= (float)$job['remaining_amount'] ?>)">✅ Complete Job</button>
                        <?php elseif ($curStatus == 'completed'): ?>
                            <button class="action-btn btn-disabled">⏳ Verification Pending</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>



    <!-- Modals for Shop Workflow -->
    <div id="reachedModal" class="modal" style="display:none; justify-content:center;">
        <div class="form-modal-content">
            <span class="close-modal" onclick="document.getElementById('reachedModal').style.display='none'">&times;</span>
            <h2 style="margin-top:0;">📍 Reached Location</h2>
            <p style="font-size:13px; color:#666;">Please upload a photo/video of the venue/location.</p>
            <form onsubmit="submitShopAction(event, 'mark_reached')">
                <input type="hidden" name="order_id" id="reachedOrderId">
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

    <div id="completeShopModal" class="modal" style="display:none; justify-content:center;">
        <div class="form-modal-content">
            <span class="close-modal" onclick="document.getElementById('completeShopModal').style.display='none'">&times;</span>
            <h2 style="margin-top:0;">✅ Complete Job</h2>
            <form onsubmit="submitShopAction(event, 'complete_job')">
                <input type="hidden" name="order_id" id="completeShopId">
                <input type="hidden" name="action" value="complete_job">
                <input type="hidden" name="latitude" class="latField">
                <input type="hidden" name="longitude" class="lngField">

                <div class="form-group">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Payment Collected:</label>
                    <select name="payment_mode" class="form-control" onchange="toggleShopProof(this.value)">
                        <option value="cash">Cash</option>
                        <option value="online">Online (Scan QR & Upload Screenshot)</option>
                    </select>
                </div>

                <div id="shopQrDiv" class="form-group" style="display:none; text-align:center;">
                    <p style="font-size:13px; color:#555;"><span id="shopQrText"></span></p>
                    <img id="shopQrImage" src="" style="width:200px; height:200px; border:1px solid #ccc; border-radius:8px; margin-bottom:10px;">
                </div>

                <div id="shopPayProof" class="form-group" style="display:none;">
                    <label style="font-weight:bold;">Payment Screenshot:</label>
                    <input type="file" name="payment_proof" class="form-control" accept="image/*">
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
                    <textarea name="vendor_notes" class="form-control" rows="2"></textarea>
                </div>

                <button type="submit" class="action-btn btn-blue" style="width:100%">Submit Completion</button>
            </form>
        </div>
    </div>

    <div id="completeModal" class="modal" style="display:none; justify-content:center;">
        <div class="form-modal-content">
            <span class="close-modal" onclick="document.getElementById('completeModal').style.display='none'">&times;</span>
            <h2 style="margin-top:0;">Submit Job Completion</h2>
            <form onsubmit="submitProof(event)">
                <input type="hidden" name="task_id" id="modalTaskId">
                <input type="hidden" name="action" value="complete">

                <div class="form-group">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Payment Mode:</label>
                    <select name="payment_mode" class="form-control" onchange="toggleProof(this.value)">
                        <option value="cash">Cash</option>
                        <option value="online">Online (Scan QR & Upload Screenshot)</option>
                    </select>
                </div>

                <div id="gigQrDiv" class="form-group" style="display:none; text-align:center;">
                    <p style="font-size:13px; color:#555;"><span id="gigQrText"></span></p>
                    <img id="gigQrImage" src="" style="width:200px; height:200px; border:1px solid #ccc; border-radius:8px; margin-bottom:10px;">
                </div>

                <div id="payProofDiv" class="form-group" style="display:none;">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Payment Screenshot (Required for Online):</label>
                    <input type="file" name="payment_proof" id="payment_proof_input" class="form-control" accept="image/*">
                </div>

                <div class="form-group">
                    <label style="font-weight:bold; display:block; margin-bottom:5px;">Work Proof (Photos/Videos):</label>
                    <input type="file" name="job_proof[]" multiple required class="form-control" accept="image/*,video/*">
                    <small style="color:#666; font-size:12px;">Hold CTRL/CMD to select multiple files.</small>
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

    <div id="videoModal" class="modal" style="display:none; justify-content:center;">
        <span class="close-video" onclick="closeVideoModal()">&times;</span>
        <div class="video-modal-content">
            <video id="mainVideoPlayer" class="video-player" controls>
                <source id="videoSource" src="" type="video/mp4">
                Your browser does not support the video tag.
            </video>
        </div>
    </div>

    <script>
        // Sidebar
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
        }

        // Completion Modal
        let activeGigId = 0;
        let activeGigAmt = 0;

        function openCompleteModal(id, amount) {
            activeGigId = id;
            activeGigAmt = amount || 0;
            document.getElementById('modalTaskId').value = id;
            document.getElementById('completeModal').style.display = 'flex';
            toggleProof('cash'); // Reset to cash
        }

        // Smart Payment Proof Toggle
        function toggleProof(val) {
            const div = document.getElementById('payProofDiv');
            const input = document.getElementById('payment_proof_input');
            const qrDiv = document.getElementById('gigQrDiv');
            const qrText = document.getElementById('gigQrText');
            const qrImg = document.getElementById('gigQrImage');

            if (val === 'online') {
                div.style.display = 'block';
                input.required = true;
                qrDiv.style.display = 'block';
                qrText.innerHTML = "Generating Secure QR Code...";
                qrImg.src = "";

                fetch(`ajax/generate_recharge_token.php?task_id=${activeGigId}&amount=${activeGigAmt}`)
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            const link = `https://surpriseville.co.in/gig-payment.php?task_id=${activeGigId}&amount=${activeGigAmt}&token=${d.token}`;
                            qrText.innerHTML = `Ask client to scan this QR to pay <strong>₹${activeGigAmt}</strong>`;
                            qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(link)}`;
                            
                            startPaymentPolling(activeGigId, 'gig');
                        } else {
                            qrText.innerHTML = `<span style="color:red">Error: ${d.message}</span>`;
                        }
                    });
            } else {
                div.style.display = 'none';
                input.required = false;
                input.value = "";
                qrDiv.style.display = 'none';
                if (statusPoller) clearInterval(statusPoller);
            }
        }

        // VIDEO PLAYER LOGIC
        function playVideo(url) {
            const modal = document.getElementById('videoModal');
            const video = document.getElementById('mainVideoPlayer');
            const source = document.getElementById('videoSource');

            source.src = url;
            video.load();
            modal.style.display = 'flex';
            video.play();
        }

        function closeVideoModal() {
            const modal = document.getElementById('videoModal');
            const video = document.getElementById('mainVideoPlayer');
            video.pause();
            modal.style.display = 'none';
        }

        // Submit Form
        function submitProof(e) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            if (btn) { btn.innerText = "Uploading..."; btn.disabled = true; }
            if (typeof showLoading === 'function') showLoading();

            const fd = new FormData(e.target);

            fetch('gig_actions.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (typeof hideLoading === 'function') hideLoading();
                    if (d.success) {
                        alert("Submitted Successfully! Wait for Admin Approval.");
                        location.reload();
                    } else {
                        alert(d.message);
                        if (btn) { btn.disabled = false; btn.innerText = "Submit Completion"; }
                    }
                })
                .catch(err => {
                    if (typeof hideLoading === 'function') hideLoading();
                    alert("Error submitting form. Check internet connection.");
                    if (btn) { btn.disabled = false; btn.innerText = "Submit Completion"; }
                });
        }

        // --- Shop Workflow Scripts ---
        function getGeo(callback) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    (pos) => callback(pos.coords.latitude, pos.coords.longitude),
                    (err) => {
                        if (typeof hideLoading === 'function') hideLoading();
                        alert("Location access required for this step!");
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
            if (!confirm("Are you starting the journey to location?")) return;
            if (typeof showLoading === 'function') showLoading();
            getGeo((lat, lng) => {
                const fd = new FormData();
                fd.append('action', 'start_journey');
                fd.append('order_id', id);
                fd.append('latitude', lat);
                fd.append('longitude', lng);
                postShopAction(fd);
            });
        }

        function openReachedModal(id) {
            document.getElementById('reachedOrderId').value = id;
            document.getElementById('reachedModal').style.display = 'flex';
            getGeo((lat, lng) => {
                document.querySelectorAll('.latField').forEach(e => e.value = lat);
                document.querySelectorAll('.lngField').forEach(e => e.value = lng);
            });
        }

        function startWork(id) {
            if (!confirm("Are you starting the service/work now?")) return;
            if (typeof showLoading === 'function') showLoading();
            getGeo((lat, lng) => {
                const fd = new FormData();
                fd.append('action', 'start_work');
                fd.append('order_id', id);
                fd.append('latitude', lat);
                fd.append('longitude', lng);
                postShopAction(fd);
            });
        }

        let activeShopId = 0;
        let activeShopAmt = 0;

        function openCompleteShopModal(id, amount) {
            activeShopId = id;
            activeShopAmt = amount || 0;
            document.getElementById('completeShopId').value = id;
            document.getElementById('completeShopModal').style.display = 'flex';
            toggleShopProof('cash'); // Reset
            getGeo((lat, lng) => {
                document.querySelectorAll('.latField').forEach(e => e.value = lat);
                document.querySelectorAll('.lngField').forEach(e => e.value = lng);
            });
        }

        function toggleShopProof(val) {
            const div = document.getElementById('shopPayProof');
            const qrDiv = document.getElementById('shopQrDiv');
            const qrText = document.getElementById('shopQrText');
            const qrImg = document.getElementById('shopQrImage');

            if (val === 'online') {
                div.style.display = 'block';
                qrDiv.style.display = 'block';
                qrText.innerHTML = "Generating Secure QR Code...";
                qrImg.src = "";

                fetch(`ajax/generate_recharge_token.php?order_id=${activeShopId}&amount=${activeShopAmt}`)
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            const link = `https://surpriseville.co.in/gig-payment.php?vendor_pay=1&order_id=${activeShopId}&amount=${activeShopAmt}&token=${d.token}`;
                            qrText.innerHTML = `Ask client to scan this QR to pay <strong>₹${activeShopAmt}</strong>`;
                            qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(link)}`;
                            
                            startPaymentPolling(activeShopId, 'shop');
                        } else {
                            qrText.innerHTML = `<span style="color:red">Error: ${d.message}</span>`;
                        }
                    });
            } else {
                div.style.display = 'none';
                qrDiv.style.display = 'none';
                if (statusPoller) clearInterval(statusPoller);
            }
        }

        let statusPoller = null;
        function startPaymentPolling(id, type) {
            if (statusPoller) clearInterval(statusPoller);
            statusPoller = setInterval(() => {
                const url = (type === 'gig') ? `ajax/check_task_payment.php?task_id=${id}` : `ajax/check_order_payment.php?order_id=${id}`;
                fetch(url)
                    .then(r => r.json())
                    .then(d => {
                        if (d.success && d.payment_status === 'paid') {
                            clearInterval(statusPoller);
                            handlePaymentSuccess(type);
                        }
                    });
            }, 5000);
        }

        function handlePaymentSuccess(type) {
            const qrDiv = (type === 'gig') ? document.getElementById('gigQrDiv') : document.getElementById('shopQrDiv');
            const payProofDiv = (type === 'gig') ? document.getElementById('payProofDiv') : document.getElementById('shopPayProof');
            const input = payProofDiv.querySelector('input');

            qrDiv.innerHTML = `
                <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #c3e6cb; text-align:center;">
                    <i class="fa-solid fa-circle-check" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                    <strong>Payment Received!</strong><br>
                    Online payment of ₹${(type === 'gig' ? activeGigAmt : activeShopAmt)} verified automatically.
                </div>
            `;
            payProofDiv.style.display = 'none';
            if (input) input.required = false;

            const modalId = (type === 'gig') ? 'completeModal' : 'completeShopModal';
            const submitBtn = document.querySelector(`#${modalId} button[type="submit"]`);
            if (submitBtn) {
                submitBtn.style.animation = "pulse 1s infinite alternate";
                submitBtn.innerText = "Verified! Click to Finish";
            }
        }

        function submitShopAction(e, action) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.innerText = "Processing..."; }
            if (typeof showLoading === 'function') showLoading();

            const fd = new FormData(e.target);
            postShopAction(fd, btn);
        }

        function postShopAction(fd, btn = null) {
            if (typeof showLoading === 'function') showLoading();
            fetch('../backend/order_actions.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (typeof hideLoading === 'function') hideLoading();
                    if (d.success) {
                        alert(d.message);
                        location.reload();
                    } else {
                        alert(d.message);
                        if (btn) {
                            btn.disabled = false;
                            btn.innerText = "Submit";
                        }
                    }
                })
                .catch(e => {
                    if (typeof hideLoading === 'function') hideLoading();
                    alert("Error: " + e);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerText = "Submit";
                    }
                });
        }
    </script>
</div>

<?php include 'footer.php'; ?>
<?php
if (isset($conn)) $conn->close();
?>