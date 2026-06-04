<?php
// vendor/gig_market.php
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

// 1. Check Login
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';        
require_once '../db_main.php';   

$vendor_id = (int)$_SESSION['vendor_id'];

// --- AJAX HANDLER ---
if(isset($_GET['check_updates'])){
    header('Content-Type: application/json');
    $checkSql = "SELECT mt.id, mt.status, mt.assigned_vendor_id FROM task_alerts ta JOIN manual_tasks mt ON mt.id = ta.task_id WHERE ta.vendor_id = ?";
    $stmt = $conn->prepare($checkSql);
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $validTasks = [];
    while($r = $res->fetch_assoc()){
        if($r['status'] == 'open' || $r['assigned_vendor_id'] == $vendor_id){
            $validTasks[] = $r['id'];
        }
    }
    echo json_encode(['valid_ids' => $validTasks]);
    exit;
}
// --------------------

$sql = "
    SELECT 
        mt.id as task_id, mt.inclusions, mt.locality, mt.vendor_price, 
        mt.status as task_status, mt.assigned_vendor_id, mt.google_map,
        mt.client_name, mt.client_phone, mt.full_address, mt.remarks, mt.admin_media,
        gc.name as cat_name
    FROM task_alerts ta
    JOIN manual_tasks mt ON mt.id = ta.task_id
    LEFT JOIN gig_categories gc ON gc.id = mt.category_id
    WHERE ta.vendor_id = ? 
    AND ((ta.status = 'pending' AND mt.status = 'open') OR (mt.assigned_vendor_id = ?))
    ORDER BY mt.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $vendor_id, $vendor_id);
$stmt->execute();
$res = $stmt->get_result();
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Gig Market</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        /* BASE RESET */
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f0f2f5; }
        
        /* HEADER */
        .header { background: #fff; padding: 15px; display: flex; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); position: sticky; top: 0; z-index: 100; }
        .menu-btn { display: none; font-size: 24px; background: none; border: none; margin-right: 15px; cursor: pointer; color: #333; }
        .header h1 { font-size: 18px; margin: 0; font-weight: 700; color: #2c3e50; flex: 1; }
        .header nav { font-size: 13px; color: #666; }
        .header a { color: #d32f2f; text-decoration: none; font-weight: 600; margin-left: 10px; }

        /* LAYOUT */
        .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .dashboard-layout { display: flex; gap: 20px; align-items: flex-start; }
        .sidebar-wrapper { width: 250px; flex-shrink: 0; transition: transform 0.3s ease; }
        .main-content { flex: 1; min-width: 0; }

        /* GIG CARD DESIGN */
        .gig-card { background: #fff; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; border: 1px solid #eee; transition: opacity 0.3s; }
        
        .gig-header { padding: 15px; display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #f0f0f0; }
        .gig-title { font-size: 18px; font-weight: 700; color: #333; margin: 0 0 5px 0; }
        .gig-loc { font-size: 13px; color: #666; display: flex; align-items: center; gap: 4px; }
        
        .gig-price-tag { background: #e8f5e9; color: #2e7d32; padding: 5px 10px; border-radius: 6px; font-weight: 800; font-size: 18px; white-space: nowrap; }

        .gig-body { padding: 15px; }

        /* BADGES */
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; color: #fff; text-transform: uppercase; margin-bottom: 5px; }
        .badge-open { background: #17a2b8; }
        .badge-mine { background: #28a745; }

        /* MEDIA GRID */
        .media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 8px; margin-bottom: 15px; }
        .media-thumb { width: 100%; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #eee; }

        /* DETAILS BOX */
        .details-box { background: #f8f9fa; padding: 12px; border-radius: 8px; font-size: 14px; color: #444; line-height: 1.5; margin-bottom: 15px; border: 1px solid #eee; }
        .client-box { background: #f0f4ff; border: 1px solid #dbeafe; color: #1e40af; }
        
        /* BUTTONS */
        .action-row { display: flex; gap: 10px; }
        .btn { flex: 1; padding: 12px; border: none; border-radius: 8px; font-weight: 600; font-size: 15px; cursor: pointer; text-align: center; }
        .btn-accept { background: #28a745; color: white; box-shadow: 0 4px 6px rgba(40,167,69,0.2); }
        .btn-decline { background: #fff; color: #dc3545; border: 1px solid #dc3545; }
        .btn-blue { background: #007bff; color: white; }
        .btn-disabled { background: #ccc; color: #fff; cursor: not-allowed; }

        /* MOBILE FIXES */
        @media (max-width: 900px) {
            body { background: #f8f9fa; }
            .container { padding: 10px; width: 100%; } /* Reduced padding */
            .menu-btn { display: block; }
            
            .dashboard-layout { flex-direction: column; }
            
            /* Sidebar Logic */
            .sidebar-wrapper { position: fixed; top: 0; left: 0; height: 100%; width: 280px; background: #fff; z-index: 999; transform: translateX(-100%); box-shadow: 2px 0 10px rgba(0,0,0,0.1); overflow-y: auto; padding: 20px; }
            .sidebar-wrapper.active { transform: translateX(0); }
            
            /* IMPORTANT: Full Width Cards */
            .main-content { width: 100%; }
            .gig-card { border-radius: 12px; margin-bottom: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            .gig-header { padding: 12px 15px; }
            .gig-body { padding: 15px; }
            
            .action-row { flex-direction: column; } /* Stack buttons on very small screens? No, side by side is better usually, let's keep side by side unless very small */
            .sidebar-close-btn { display: block; }
        }

        .sidebar-close-btn { display: none; }

        @media (max-width: 400px) {
            .gig-header {
                flex-direction: column;
                gap: 10px;
            }
            .gig-price-tag {
                align-self: flex-start;
            }
        }

        /* Overlay */
        .overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 998; }
        .overlay.active { display: block; }

        /* Modal */
        .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:2000; }
        .modal-content { background:#fff; margin: 15vh auto; padding: 20px; width: 90%; max-width: 400px; border-radius: 12px; }
        .form-group { margin-bottom: 15px; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
    </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<div class="header">
    <button class="menu-btn" onclick="toggleSidebar()">&#9776;</button>
    <h1>Gig Market</h1>
    <nav>
        <span><?= htmlspecialchars($vendor_name) ?></span>
        <a href="logout.php">Logout</a>
    </nav>
</div>

<div class="container">
    <div class="dashboard-layout">
        
        <div class="sidebar-wrapper" id="sidebar">
            <button onclick="toggleSidebar()" class="sidebar-close-btn" style="float:right; background:none; border:none; font-size:24px;">&times;</button>
            <?php include 'sidebar_fragment.php'; ?>
        </div>

        <main class="main-content">
            <div id="gig-container">
            <?php if ($res->num_rows == 0): ?>
                <div style="text-align:center; padding:60px 20px;">
                    <div style="font-size:50px;">📭</div>
                    <h3>No Gigs Available</h3>
                    <p style="color:#888;">Refresh or check back later.</p>
                </div>
            <?php else: ?>
                <?php while ($row = $res->fetch_assoc()): 
                    $isMine = ($row['assigned_vendor_id'] == $vendor_id);
                    $media = !empty($row['admin_media']) ? json_decode($row['admin_media'], true) : [];
                ?>
                
                <div class="gig-card" id="task-<?= $row['task_id'] ?>" data-id="<?= $row['task_id'] ?>">
                    
                    <div class="gig-header">
                        <div style="flex: 1; padding-right: 10px;">
                            <?php if($isMine): ?>
                                <span class="badge badge-mine">ASSIGNED TO YOU</span>
                            <?php else: ?>
                                <span class="badge badge-open">OPEN OFFER</span>
                            <?php endif; ?>
                            <h3 class="gig-title"><?= htmlspecialchars($row['cat_name']) ?></h3>
                            <div class="gig-loc">📍 <?= htmlspecialchars($row['locality']) ?></div>
                        </div>
                        <div class="gig-price-tag">₹<?= number_format($row['vendor_price']) ?></div>
                    </div>

                    <div class="gig-body">
                        <?php if(!empty($media)): ?>
                            <div class="media-grid">
                                <?php foreach($media as $m): ?>
                                    <a href="../uploads/admin_task_media/<?= $m ?>" target="_blank">
                                        <img src="../uploads/admin_task_media/<?= $m ?>" class="media-thumb">
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="details-box">
                            <strong>Requirements:</strong><br>
                            <?= nl2br(htmlspecialchars($row['inclusions'])) ?>
                        </div>

                        <?php if($isMine): ?>
                            <div class="details-box client-box">
                                <h4 style="margin:0 0 10px 0;">✅ Client Details</h4>
                                <strong>Name:</strong> <?= htmlspecialchars($row['client_name']) ?><br>
                                <strong>Address:</strong> <?= nl2br(htmlspecialchars($row['full_address'])) ?><br>
                                <?php if(!empty($row['google_map'])): ?>
                                    <a href="<?= htmlspecialchars($row['google_map']) ?>" target="_blank" style="color:#007bff; display:inline-block; margin-top:5px;">Open Google Maps 🗺️</a><br>
                                <?php endif; ?>
                                <?php 
                                $clean_remarks = '';
                                if (!empty($row['remarks'])) {
                                    $clean_remarks = trim(preg_replace('/Notes:\s*.*$/is', '', $row['remarks']));
                                }
                                ?>
                                <strong>Remarks:</strong> <?= htmlspecialchars($clean_remarks ?: 'None') ?>
                            </div>
                            
                            <div class="action-row">
                                <?php if($row['task_status'] == 'completed'): ?>
                                    <button class="btn btn-disabled" disabled>Waiting Approval</button>
                                <?php elseif($row['task_status'] == 'verified'): ?>
                                    <button class="btn btn-disabled" disabled>Payment Credited</button>
                                <?php else: ?>
                                    <button class="btn btn-blue" onclick="openCompleteModal(<?= $row['task_id'] ?>)">Mark Complete</button>
                                <?php endif; ?>
                            </div>

                        <?php else: ?>
                            <div style="font-size:12px; color:#888; text-align:center; margin-bottom:10px;">
                                🔒 Accept job to unlock client details
                            </div>
                            <div class="action-row">
                                <button class="btn btn-decline" onclick="declineGig(<?= $row['task_id'] ?>, this)">Decline</button>
                                <button class="btn btn-accept" onclick="acceptGig(<?= $row['task_id'] ?>, this)">Accept</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<div id="completeModal" class="modal">
    <div class="modal-content">
        <span onclick="document.getElementById('completeModal').style.display='none'" style="float:right;font-size:24px;cursor:pointer;">&times;</span>
        <h3>Submit Completion</h3>
        <form onsubmit="submitProof(event)">
            <input type="hidden" name="task_id" id="modalTaskId">
            <input type="hidden" name="action" value="complete">
            <div class="form-group">
                <label>Payment Mode:</label>
                <select name="payment_mode" class="form-control" onchange="toggleProof(this.value)">
                    <option value="cash">Cash</option><option value="online">Online</option>
                </select>
            </div>
            <div id="payProofDiv" class="form-group" style="display:none;">
                <label>Payment Screenshot:</label><input type="file" name="payment_proof" class="form-control">
            </div>
            <div class="form-group">
                <label>Work Proof (Required):</label><input type="file" name="job_proof[]" multiple required class="form-control">
            </div>
            <button type="submit" class="btn btn-blue" style="width:100%">Submit</button>
        </form>
    </div>
</div>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
    document.getElementById('overlay').classList.toggle('active');
}
setInterval(function(){
    fetch('gig_market.php?check_updates=1').then(r=>r.json()).then(d=>{
        let v = d.valid_ids.map(String);
        document.querySelectorAll('.gig-card').forEach(c => {
            if(!v.includes(c.getAttribute('data-id'))) c.remove();
        });
    });
}, 5000);

function acceptGig(tid, btn){
    if(!confirm("Accept Job?")) return;
    btn.disabled=true; btn.innerText="...";
    let fd=new FormData(); fd.append('action','accept'); fd.append('task_id',tid);
    fetch('gig_actions.php', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
        if(d.success) location.reload(); else { alert(d.message); btn.disabled=false; }
    });
}
function declineGig(tid, btn){
    if(!confirm("Decline?")) return;
    let fd=new FormData(); fd.append('action','decline'); fd.append('task_id',tid);
    fetch('gig_actions.php', {method:'POST', body:fd}).then(()=>{ document.getElementById('task-'+tid).remove(); });
}
function openCompleteModal(id){ document.getElementById('modalTaskId').value=id; document.getElementById('completeModal').style.display='block'; }
function toggleProof(val){ document.getElementById('payProofDiv').style.display = (val==='online')?'block':'none'; }
function submitProof(e){
    e.preventDefault();
    fetch('gig_actions.php', {method:'POST', body:new FormData(e.target)}).then(r=>r.json()).then(d=>{
        if(d.success) location.reload(); else alert(d.message);
    });
}
</script>

</body>
</html>