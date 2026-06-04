<?php
// vendor/view-job-details.php
session_start();

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';       
require_once '../db_main.php';  

$vendor_id = (int)$_SESSION['vendor_id'];
$type = $_GET['type'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['shop', 'gig'])) {
    header('Location: completed-orders.php');
    exit;
}

$job_data = [];
$proofs = [];
$payment_proof = "";

if ($type === 'shop') {
    // 1. FETCH SHOP ORDER DETAILS
    $q = "
        SELECT ova.*, o.datetime as order_date, o.name as customer_name, o.phone as customer_phone, 
               o.address_line, o.city, o.remaining_amount, s.name as service_name, s.main_image
        FROM order_vendor_assignments ova
        JOIN orders o ON ova.order_id = o.id
        JOIN services s ON o.service_id = s.id
        WHERE ova.order_id = ? AND ova.vendor_id = ? AND ova.status = 'completed'
    ";
    $stmt = $mainConn->prepare($q);
    $stmt->bind_param("ii", $id, $vendor_id);
    $stmt->execute();
    $job_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job_data) die("Job not found or access denied.");

    $proofs = json_decode($job_data['work_proof'] ?? '[]', true);
    $payment_proof = $job_data['payment_proof'];
    $display_title = $job_data['service_name'];
    $display_date = $job_data['completed_at'];

} else {
    // 2. FETCH OFFLINE ORDER DETAILS
    $q = "
        SELECT mt.*, gc.name as cat_name, tc.proof_media, tc.payment_mode, tc.payment_screenshot, tc.created_at as completed_at
        FROM manual_tasks mt
        LEFT JOIN gig_categories gc ON mt.category_id = gc.id
        LEFT JOIN task_completions tc ON tc.task_id = mt.id AND tc.vendor_id = mt.assigned_vendor_id
        WHERE mt.id = ? AND mt.assigned_vendor_id = ? AND mt.status IN ('completed', 'verified')
    ";
    $stmt = $conn->prepare($q);
    $stmt->bind_param("ii", $id, $vendor_id);
    $stmt->execute();
    $job_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job_data) die("Job not found or access denied.");

    $proofs = json_decode($job_data['proof_media'] ?? '[]', true);
    $payment_proof = $job_data['payment_screenshot'];
    $display_title = $job_data['cat_name'] ?: 'Offline Order #' . $id;
    $display_date = $job_data['completed_at'];
}

$page_title = 'Job Details #' . $id;
include 'header.php';
?>

<style>
    .detail-section { margin-bottom: 25px; }
    .section-label { font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; display: block; }
    .proof-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; }
    .proof-item { border-radius: 12px; overflow: hidden; border: 1px solid var(--border-color); background: #000; position: relative; aspect-ratio: 1; }
    .proof-item img, .proof-item video { width: 100%; height: 100%; object-fit: cover; cursor: pointer; }
    .play-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.3); color: #fff; pointer-events: none; }

    /* Responsive Utilities */
    .detail-container { padding: 24px; max-width: 1000px; margin: 0 auto; }
    .detail-card { padding: 30px; }
    .job-header { border-bottom: 1px solid var(--border-color); padding-bottom: 20px; margin-bottom: 25px; display: flex; gap: 20px; align-items: flex-start; }
    .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
    .financials-card { background: var(--bg-body); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }

    @media (max-width: 768px) {
        .details-grid {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        .job-header {
            flex-direction: column;
            gap: 15px;
            align-items: flex-start;
        }
    }
    @media (max-width: 600px) {
        .detail-container { padding: 12px; }
        .detail-card { padding: 15px; }
        .job-header img { width: 80px; height: 80px; }
        .job-header h1 { font-size: 22px !important; }
    }
    @media (max-width: 480px) {
        .financials-card {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }
        .financials-card > div {
            text-align: left !important;
        }
    }
</style>

<div class="detail-container">
    <a href="completed-orders.php" style="display:inline-flex; align-items:center; gap:8px; color:var(--text-muted); text-decoration:none; font-weight:600; margin-bottom:20px; font-size:14px;">
        <span class="material-symbols-outlined" style="font-size:20px;">arrow_back</span>
        <span>Back to History</span>
    </a>

    <div class="card detail-card">
        <div class="job-header">
            <?php 
            $display_img = "https://partners.surpriseville.co.in/assets/no-img.png";
            if ($type === 'shop') {
                if (!empty($job_data['main_image'])) {
                    $display_img = "https://surpriseville.co.in/" . ltrim($job_data['main_image'], '/');
                }
            } else {
                if (!empty($job_data['admin_media'])) {
                    $media_arr = json_decode($job_data['admin_media'], true);
                    if (is_array($media_arr) && !empty($media_arr)) {
                        $first_img = ltrim($media_arr[0], '/');
                        $display_img = "../uploads/admin_task_media/" . $first_img;
                    }
                }
            }
            ?>
            <img src="<?= $display_img ?>" style="width:100px; height:100px; object-fit:cover; border-radius:12px; border:1px solid var(--border-color); flex-shrink:0;">
            <div style="flex:1;">
                <h1 style="margin:0 0 5px 0; font-size:28px; font-weight:800; letter-spacing:-1px;"><?= htmlspecialchars($display_title) ?></h1>
                <p style="margin:0; color:var(--text-muted); font-weight:600;">
                    <span style="color:var(--primary);">Order #<?= $id ?></span> • 
                    Completed on <?= date('d M Y, h:i A', strtotime($display_date)) ?>
                </p>
            </div>
            <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 6px 15px; border-radius: 999px; font-weight:800; font-size:12px; text-transform:uppercase;">
                <?= ($type === 'shop') ? 'Online Order' : 'Offline Order' ?>
            </div>
        </div>

        <div class="details-grid">
            <!-- Column 1: Info -->
            <div>
                <div class="detail-section">
                    <span class="section-label">📍 Location & Timing</span>
                    <div style="background: var(--bg-body); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color);">
                        <p style="margin:0 0 10px 0;"><strong>Address:</strong><br><?= nl2br(htmlspecialchars($type === 'shop' ? $job_data['address_line'] : $job_data['full_address'])) ?></p>
                        <p style="margin:0;"><strong>City:</strong> <?= htmlspecialchars($type === 'shop' ? $job_data['city'] : $job_data['locality']) ?></p>
                    </div>
                </div>

                <div class="detail-section">
                    <span class="section-label">👤 Client Information</span>
                    <div style="background: var(--bg-body); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color);">
                        <p style="margin:0;"><strong>Name:</strong> <?= htmlspecialchars($type === 'shop' ? $job_data['customer_name'] : $job_data['client_name']) ?></p>
                    </div>
                </div>

                <div class="detail-section">
                    <span class="section-label">💰 Financials</span>
                    <div class="financials-card">
                        <div>
                            <p style="margin:0; font-size:12px; color:var(--text-muted); font-weight:700;">PAYOUT EARNED</p>
                            <h2 style="margin:0; color:#10b981;">₹<?= number_format($type === 'shop' ? ($job_data['vendor_price'] ?: 0) : $job_data['vendor_price']) ?></h2>
                        </div>
                        <div style="text-align:right;">
                            <p style="margin:0; font-size:12px; color:var(--text-muted); font-weight:700;">CASH COLLECTED</p>
                            <h2 style="margin:0; color:#ef4444;">₹<?= number_format($type === 'shop' ? ($job_data['remaining_amount'] ?: 0) : $job_data['amount_to_collect']) ?></h2>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Column 2: Proofs -->
            <div>
                <div class="detail-section">
                    <span class="section-label">📸 Work Proofs</span>
                    <?php if (empty($proofs)): ?>
                        <p style="color:var(--text-muted); font-style:italic;">No proofs uploaded.</p>
                    <?php else: ?>
                        <div class="proof-grid">
                            <?php foreach ($proofs as $p): 
                                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                                $is_video = in_array($ext, ['mp4', 'webm', 'mov']);
                                $url = "../uploads/proofs/" . $p;
                            ?>
                                <div class="proof-item" onclick="<?= $is_video ? "playMedia('$url', true)" : "window.open('$url', '_blank')" ?>">
                                    <?php if ($is_video): ?>
                                        <video src="<?= $url ?>" muted></video>
                                        <div class="play-overlay"><span class="material-symbols-outlined">play_circle</span></div>
                                    <?php else: ?>
                                        <img src="<?= $url ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($payment_proof): ?>
                <div class="detail-section">
                    <span class="section-label">💳 Payment Screenshot</span>
                    <div class="proof-item" style="width:120px;" onclick="window.open('../uploads/proofs/<?= $payment_proof ?>', '_blank')">
                        <img src="../uploads/proofs/<?= $payment_proof ?>">
                    </div>
                </div>
                <?php endif; ?>

                <?php 
                $clean_notes = '';
                if ($type === 'shop') {
                    $clean_notes = $job_data['notes'] ?? '';
                } else {
                    $clean_notes = trim(preg_replace('/Notes:\s*.*$/is', '', $job_data['remarks'] ?? ''));
                }
                if (!empty($clean_notes)):
                ?>
                <div class="detail-section">
                    <span class="section-label">📝 Completion Notes</span>
                    <div style="background: #fff8e1; color: #856404; padding: 15px; border-radius: 12px; border: 1px solid #ffeeba; font-size: 14px;">
                        <?= nl2br(htmlspecialchars($clean_notes)) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Simple Video Player Modal -->
<div id="videoModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:99999; align-items:center; justify-content:center;">
    <span onclick="closeVideo()" style="position:absolute; top:20px; right:20px; color:white; font-size:40px; cursor:pointer;">&times;</span>
    <video id="p-video" style="max-width:90%; max-height:90%;" controls></video>
</div>

<script>
    function playMedia(url, isVideo) {
        if (isVideo) {
            document.getElementById('p-video').src = url;
            document.getElementById('videoModal').style.display = 'flex';
            document.getElementById('p-video').play();
        }
    }
    function closeVideo() {
        document.getElementById('videoModal').style.display = 'none';
        document.getElementById('p-video').pause();
    }
</script>

<?php include 'footer.php'; ?>
