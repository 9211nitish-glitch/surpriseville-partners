<?php
// vendor/my-gigs.php
session_start();

// Check Login
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';       // Vendor DB
require_once '../db_main.php';  // Main DB (Keep for common inclusions if needed)

$vendor_id = $_SESSION['vendor_id'];
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';

// ==========================================
// FETCH MANUAL GIGS (Direct from Admin)
// ==========================================
$gigs_data = [];
$gig_q = "
    SELECT mt.*, gc.name as cat_name 
    FROM manual_tasks mt 
    LEFT JOIN gig_categories gc ON mt.category_id = gc.id
    WHERE mt.assigned_vendor_id = ? 
    AND mt.status IN ('assigned')
    ORDER BY mt.created_at DESC
";
$stmt = $conn->prepare($gig_q);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$resGig = $stmt->get_result();
while ($row = $resGig->fetch_assoc()) {
    $gigs_data[] = $row;
}
$stmt->close();

$page_title = 'Active Gigs';
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

    /* JOB CARDS */
    .job-card {
        background: #fff;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        border: 1px solid #f0f0f0;
        border-left: 5px solid #17a2b8;
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

    .bg-assigned { background: #17a2b8; }
    .bg-completed { background: #6f42c1; }
    .bg-verified { background: #20c997; }

    .details-box {
        background: #f8f9fa;
        padding: 12px;
        border-radius: 8px;
        margin-top: 10px;
        font-size: 14px;
        color: #444;
        border: 1px solid #eee;
    }

    .label { font-weight: 700; color: #333; }

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

    .btn-blue { background: #007bff; color: white; }
    .btn-disabled { background: #e9ecef; color: #6c757d; cursor: not-allowed; border: 1px solid #dee2e6; }
    .btn-success { background: #d4edda; color: #155724; cursor: default; border: 1px solid #c3e6cb; }

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

    /* MODAL */
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

    @keyframes slideUp {
        from { transform: translateY(50px); opacity: 0 }
        to { transform: translateY(0); opacity: 1 }
    }

    .close-modal {
        position: absolute;
        top: 15px;
        right: 20px;
        font-size: 28px;
        color: #999;
        cursor: pointer;
    }

    .form-group { margin-bottom: 15px; }
    .form-control {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 14px;
        box-sizing: border-box;
    }

    @keyframes pulse {
        0% { transform: scale(1); box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3); }
        100% { transform: scale(1.02); box-shadow: 0 4px 20px rgba(0, 123, 255, 0.5); }
    }

    /* MOBILE RESPONSIVE */
    @media (max-width: 768px) {
        .job-card {
            padding: 15px !important;
        }

        .job-header {
            flex-direction: column;
            gap: 12px;
        }

        .header-right {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            border-top: 1px solid #eee;
            padding-top: 10px;
        }

        .action-btn {
            width: 100%;
            text-align: center;
        }
    }

    #videoModal {
        align-items: center;
        justify-content: center;
    }
</style>

<div style="padding: 24px;">
    <h2 class="section-title">🛠️ Offline Orders (Admin Assigned)</h2>
    
    <?php if (empty($gigs_data)): ?>
        <div style="text-align:center; padding:30px; background:#fff; border-radius:8px; color:#777;">
            No active gigs assigned yet.
        </div>
    <?php else: ?>
        <?php foreach ($gigs_data as $gig): ?>
            <div class="job-card gig" id="task-<?= $gig['id'] ?>">
                <div class="job-header">
                    <div>
                         <h3 style="margin:0 0 5px 0; color:#333; font-size:16px;">
                            Offline Order #<?= $gig['id'] ?>: <?= htmlspecialchars($gig['cat_name']) ?> 
                            <?php if(!empty($gig['service_title'])): ?>
                                <span style="color:#007bff; font-weight:bold;">[<?= htmlspecialchars($gig['service_title']) ?>]</span>
                            <?php endif; ?>
                        </h3>
                        <?php
                        // EXTRACT EVENT DATE FROM REMARKS
                        $remarks = $gig['remarks'] ?? '';
                        $displayDate = date('d M Y', strtotime($gig['created_at'])); // Default
                        
                        // Robust regex to capture date before the next field or newline
                        if (preg_match('/Event:\s*(.*?)(?=\s*(Reach:|Ready:|Notes:|\n|\r|$))/i', $remarks, $m)) {
                            $parsedDate = trim($m[1]);
                            $ts = strtotime($parsedDate);
                            if ($ts) $displayDate = date('d M Y', $ts);
                            else if ($parsedDate) $displayDate = $parsedDate;
                        }
                        ?>
                        <p style="margin:0; color:#666; font-size:13px;">
                            <strong>📅 Event:</strong> <?= $displayDate ?><br>
                            <strong>👤 Client:</strong> <?= htmlspecialchars($gig['client_name']) ?>
                            <!-- Number Hidden -->
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

                <?php 
                $clean_remarks = '';
                if (!empty($gig['remarks'])) {
                    $clean_remarks = trim(preg_replace('/Notes:\s*.*$/is', '', $gig['remarks']));
                }
                if (!empty($clean_remarks)): 
                ?>
                    <div class="details-box" style="background:#fff3cd; border:1px solid #ffeeba; color:#856404;">
                        <span class="label">⚠️ Remarks:</span> <?= htmlspecialchars($clean_remarks) ?>
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
                    <?php 
                    // If status is 'pending' or NULL, treat it as 'assigned' for the first action (Start Journey)
                    $vStatus = ($gig['vendor_status'] === 'pending' || empty($gig['vendor_status'])) ? 'assigned' : $gig['vendor_status']; 
                    ?>
                    <div class="action-grid" style="display:grid; grid-template-columns:1fr; gap:10px;">
                        <?php if ($vStatus == 'assigned'): ?>
                            <button class="action-btn btn-blue" onclick="gigAction(<?= $gig['id'] ?>, 'start_journey')">🚀 Start Journey</button>
                        <?php elseif ($vStatus == 'out_for_service'): ?>
                            <button class="action-btn btn-orange" style="background:#fd7e14; color:#fff;" onclick="openGigReachedModal(<?= $gig['id'] ?>)">📍 Reached Location</button>
                        <?php elseif ($vStatus == 'reached'): ?>
                            <button class="action-btn btn-success" style="background:#28a745; color:#fff;" onclick="gigAction(<?= $gig['id'] ?>, 'start_work')">▶ Start Work</button>
                        <?php elseif ($vStatus == 'started'): ?>
                            <button class="action-btn btn-blue" onclick="openCompleteModal(<?= $gig['id'] ?>, <?= (float)($gig['amount_to_collect'] ?? 0) ?>)">✅ Mark as Completed</button>
                        <?php elseif ($gig['status'] == 'completed'): ?>
                            <button class="action-btn btn-disabled">⏳ Waiting Verification</button>
                        <?php elseif ($gig['status'] == 'verified'): ?>
                            <button class="action-btn btn-success">✔ Payment Verified</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal for Reached -->
<div id="gigReachedModal" class="modal">
    <div class="form-modal-content">
        <span class="close-modal" onclick="closeGigReachedModal()">&times;</span>
        <h2 style="margin-top:0;">📍 Reached Location</h2>
        <p style="font-size:13px; color:#666;">Please upload a photo/video of the venue/location.</p>
        <form onsubmit="submitGigReached(event)">
            <input type="hidden" name="task_id" id="reachedTaskId">
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

<!-- Modal for Completion -->
<div id="completeModal" class="modal">
    <div class="form-modal-content">
        <span class="close-modal" onclick="closeCompleteModal()">&times;</span>
        <h2 style="margin-top:0;">Submit Job Completion</h2>
        <form onsubmit="submitProof(event)">
            <input type="hidden" name="task_id" id="modalTaskId">
            <input type="hidden" name="action" value="complete">
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

            <div id="payProofDiv" class="form-group">
                <label style="font-weight:bold; display:block; margin-bottom:5px;">Payment Proof (Compulsory):</label>
                <input type="file" name="payment_proof" id="payment_proof_input" class="form-control" accept="image/*" required>
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

            <div class="form-group">
                <label style="font-weight:bold;">Notes (Optional):</label>
                <textarea name="vendor_notes" class="form-control" rows="2" placeholder="Describe addons or any other details..."></textarea>
            </div>

            <button type="submit" class="action-btn btn-blue" style="width:100%">Submit Completion</button>
        </form>
    </div>
</div>

<div id="videoModal" class="modal">
    <span onclick="closeVideoModal()" style="position:absolute; top:20px; right:20px; font-size:40px; color:white; cursor:pointer;">&times;</span>
    <div style="width:100%; max-width:800px;">
        <video id="mainVideoPlayer" style="width:100%; border-radius:8px;" controls>
            <source id="videoSource" src="" type="video/mp4">
        </video>
    </div>
</div>

<script>
    let userLat = "";
    let userLng = "";

    // Get Location on Load
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            userLat = pos.coords.latitude;
            userLng = pos.coords.longitude;
            document.querySelectorAll('.latField').forEach(f => f.value = userLat);
            document.querySelectorAll('.lngField').forEach(f => f.value = userLng);
        });
    }

    function gigAction(id, action) {
        if (!confirm('Confirm this action?')) return;
        if (typeof showLoading === 'function') showLoading();
        
        const fd = new FormData();
        fd.append('task_id', id);
        fd.append('action', action);
        fd.append('latitude', userLat);
        fd.append('longitude', userLng);

        fetch('gig_actions.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (typeof hideLoading === 'function') hideLoading();
                if (d.success) {
                    location.reload();
                } else {
                    alert(d.message);
                }
            })
            .catch(err => {
                if (typeof hideLoading === 'function') hideLoading();
                alert("Connection Error");
            });
    }

    function openGigReachedModal(id) {
        document.getElementById('reachedTaskId').value = id;
        document.getElementById('gigReachedModal').style.display = 'flex';
    }

    function closeGigReachedModal() {
        document.getElementById('gigReachedModal').style.display = 'none';
    }

    function submitGigReached(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        if (btn) { btn.innerText = "Processing..."; btn.disabled = true; }
        if (typeof showLoading === 'function') showLoading();

        const fd = new FormData(e.target);
        fd.append('latitude', userLat);
        fd.append('longitude', userLng);

        fetch('gig_actions.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (typeof hideLoading === 'function') hideLoading();
                if (d.success) {
                    location.reload();
                } else {
                    alert(d.message);
                    if (btn) { btn.disabled = false; btn.innerText = "Submit & Mark Reached"; }
                }
            })
            .catch(err => {
                if (typeof hideLoading === 'function') hideLoading();
                alert("Error submitting.");
                if (btn) { btn.disabled = false; btn.innerText = "Submit & Mark Reached"; }
            });
    }

    let activeGigId = 0;
    let activeGigAmt = 0;
    let statusPoller = null;

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

    function openCompleteModal(id, amount) {
        activeGigId = id;
        activeGigAmt = amount || 0;
        document.getElementById('modalTaskId').value = id;
        document.getElementById('completeModal').style.display = 'flex';
        toggleProof('cash');

        // Reset Addons
        document.getElementById('addonContainer').innerHTML = '';
        addAddonRow();

        if (statusPoller) clearInterval(statusPoller);
    }

    function closeCompleteModal() {
        document.getElementById('completeModal').style.display = 'none';
        if (statusPoller) clearInterval(statusPoller);
    }

    function toggleProof(val) {
        const div = document.getElementById('payProofDiv');
        const input = document.getElementById('payment_proof_input');
        const qrDiv = document.getElementById('gigQrDiv');
        const qrText = document.getElementById('gigQrText');
        
        // Payment proof is always required now as per user instruction
        input.required = true;

        if (val === 'online') {
            div.style.display = 'block';
            
            qrText.innerHTML = "Generating Secure QR Code...";
            const qrImg = document.getElementById('gigQrImage');
            qrImg.src = "";

            fetch(`ajax/generate_recharge_token.php?task_id=${activeGigId}&amount=${activeGigAmt}`)
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        const link = `https://surpriseville.co.in/gig-payment.php?task_id=${activeGigId}&amount=${activeGigAmt}&token=${d.token}`;
                        qrText.innerHTML = `Ask client to scan this QR to pay <strong>₹${activeGigAmt}</strong>`;
                        qrImg.src = `https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=${encodeURIComponent(link)}`;
                        startPaymentPolling(activeGigId);
                    } else {
                        qrText.innerHTML = `<span style="color:red">Error: ${d.message}</span>`;
                    }
                });
        } else {
            div.style.display = 'none';
            input.required = false;
            qrDiv.style.display = 'none';
            if (statusPoller) clearInterval(statusPoller);
        }
    }

    function startPaymentPolling(taskId) {
        if (statusPoller) clearInterval(statusPoller);
        statusPoller = setInterval(() => {
            fetch(`ajax/check_task_payment.php?task_id=${taskId}`)
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
        const qrDiv = document.getElementById('gigQrDiv');
        const payProofDiv = document.getElementById('payProofDiv');
        const input = document.getElementById('payment_proof_input');
        
        qrDiv.innerHTML = `
            <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #c3e6cb;">
                <i class="fa-solid fa-circle-check" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                <strong>Payment Received!</strong><br>
                Online payment of ₹${activeGigAmt} has been verified automatically.
            </div>
        `;
        payProofDiv.style.display = 'none';
        input.required = false;
    }

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

    function submitProof(e) {
        e.preventDefault();
        const btn = e.target.querySelector('button[type="submit"]');
        if (btn) { btn.innerText = "Uploading..."; btn.disabled = true; }
        if (typeof showLoading === 'function') showLoading();

        const fd = new FormData(e.target);
        fd.append('latitude', userLat);
        fd.append('longitude', userLng);

        // Collect Addons
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

        fetch('gig_actions.php', { method: 'POST', body: fd })
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
                alert("Error submitting form.");
                if (btn) { btn.disabled = false; btn.innerText = "Submit Completion"; }
            });
    }
</script>

<?php include 'footer.php'; ?>
<?php if (isset($conn)) $conn->close(); ?>
