<?php
// admin/tracking.php
session_start();

// 1. Check Admin Login
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';       // Vendor DB
require_once '../db_main.php';  // Main DB

// Inputs
$track_id = isset($_GET['track_id']) ? intval($_GET['track_id']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'gig';

// Data Holders
$data = null;
$items = [];
$alerts_log = [];
$accepted_log = null;
$completion = null;
$profit = 0;
$profit_status = "Pending"; // Default

if ($track_id > 0) {

    // ==========================================
    // CASE 1: MANUAL GIG TRACKING (Detailed)
    // ==========================================
    if ($type == 'gig') {
        $stmt = $conn->prepare("
            SELECT mt.*, gc.name as cat_name, v.name as vendor_name, v.phone as vendor_phone, v.business_name, v.role as vendor_role
            FROM manual_tasks mt 
            LEFT JOIN gig_categories gc ON mt.category_id = gc.id
            LEFT JOIN vendors v ON mt.assigned_vendor_id = v.id
            WHERE mt.id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $track_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($data) {
            // Profit Calculation
            $customer_price = floatval($data['original_price']); // DB Column: original_price

            $vRoleDisp = strtolower(trim($data['vendor_role'] ?? 'external'));
            $vPriceDisp = floatval($data['vendor_price']);
            $ivPriceDisp = floatval($data['internal_vendor_price'] ?? 0);

            $vendor_earning = ($vRoleDisp === 'internal') ? ($vPriceDisp > 0 ? $vPriceDisp : 0) : ($ivPriceDisp > 0 ? $ivPriceDisp : $vPriceDisp);

            $profit = $customer_price - $vendor_earning;

            // Check Status for Profit Label
            if ($data['status'] == 'verified') {
                $profit_status = "Verified & Received";
            } else {
                $profit_status = "Pending (Not Verified)";
            }

            // Timeline
            $stmt = $conn->prepare("
                SELECT ta.*, v.name, v.business_name, v.city, v.phone 
                FROM task_alerts ta 
                JOIN vendors v ON ta.vendor_id = v.id 
                WHERE ta.task_id = ? 
                ORDER BY ta.sent_at ASC
            ");
            $stmt->bind_param("i", $track_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                if ($r['status'] == 'accepted') {
                    $accepted_log = $r;
                } else {
                    $alerts_log[] = $r;
                }
            }
            $stmt->close();

            // Completion
            if ($data['status'] == 'completed' || $data['status'] == 'verified') {
                $stmt = $conn->prepare("SELECT * FROM task_completions WHERE task_id = ? LIMIT 1");
                $stmt->bind_param("i", $track_id);
                $stmt->execute();
                $completion = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
        }
    }

    // ==========================================
    // CASE 2: SHOP ORDER TRACKING
    // ==========================================
    elseif ($type == 'order') {
        // Redirection logic to new tracking if it's a shop order
        header("Location: order_tracking.php?order_id=$track_id");
        exit;
    }

    // ==========================================
    // CASE 3: CRM BOOKING TRACKING
    // ==========================================
    elseif ($type == 'crm_booking') {
        $stmt = $conn->prepare("
            SELECT b.*, v.name as vendor_name, v.phone as vendor_phone, v.business_name, v.role as vendor_role
            FROM crm_bookings b 
            LEFT JOIN vendors v ON b.assigned_vendor_id = v.id
            WHERE b.id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $track_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($data) {
            // CRM specific mapping for display
            $data['full_address'] = $data['location'];
            $data['service_title'] = $data['decoration_type'];
            $data['cat_name'] = "CRM Booking";
            
            $customer_price = floatval($data['amount_agreed']);
            $vendor_earning = floatval($data['vendor_price']);
            $profit = $customer_price - $vendor_earning;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gig Intelligence | Antigravity</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://surpriseville.co.in/assets/js/chat_engine.js"></script>
    <script src="https://surpriseville.co.in/assets/js/webrtc_client.js"></script>
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --border: #e2e8f0;
            --dark: #1e293b;
            --light: #f8fafc;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
            color: var(--dark);
            line-height: 1.5;
        }

        .header {
            background: #fff;
            border-bottom: 1px solid var(--border);
            padding: 20px 40px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-container {
            display: flex;
            padding: 30px;
            max-width: 1600px;
            margin: 0 auto;
            gap: 30px;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .premium-card {
            background: #fff;
            border-radius: 24px;
            border: 1px solid var(--border);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 30px;
        }

        .stat-badge {
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-open { background: #fff7ed; color: #9a3412; }
        .stat-assigned { background: #eff6ff; color: #1e40af; }
        .stat-completed { background: #ecfdf5; color: #065f46; }
        .stat-verified { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        .client-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-bottom: 20px;
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 700;
            color: var(--dark);
            font-size: 1.1rem;
        }

        .finance-card {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: #fff;
            padding: 30px;
            border-radius: 24px;
            margin-bottom: 30px;
        }

        .finance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }

        .fin-item h4 {
            font-size: 0.7rem;
            opacity: 0.7;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .fin-item p {
            font-size: 1.5rem;
            font-weight: 800;
        }

        /* Timeline Styles */
        .timeline-container {
            position: relative;
            padding-left: 40px;
            margin-top: 20px;
        }

        .timeline-container::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }

        .timeline-step {
            position: relative;
            margin-bottom: 40px;
        }

        .step-marker {
            position: absolute;
            left: -33px;
            top: 0;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid var(--border);
            z-index: 1;
        }

        .step-marker.done {
            background: var(--success);
            border-color: var(--success);
        }

        .step-marker.active {
            background: var(--warning);
            border-color: var(--warning);
        }

        .proof-gallery {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .proof-img {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 12px;
            cursor: pointer;
            transition: transform 0.2s;
            border: 1px solid var(--border);
        }

        .proof-img:hover { transform: scale(1.05); }

        .btn-update {
            background: var(--primary);
            color: #fff;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-update:hover { background: #3730a3; }

        .sidebar-toggle {
            display: none;
            width: 40px;
            height: 40px;
            background: var(--light);
            border-radius: 10px;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        @media (max-width: 1024px) {
            .dashboard-container { flex-direction: column; padding: 15px; }
            .sidebar-toggle { display: flex; }
        }

        @media (max-width: 768px) {
            .tracking-grid {
                grid-template-columns: 1fr !important;
            }
        }

        /* Modal Protocol */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.8);
            z-index: 9999;
            backdrop-filter: blur(12px);
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90vh;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            padding: 2.5rem;
            border-radius: 32px;
            position: relative;
            animation: zoomIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow-y: auto;
            box-shadow: 0 50px 100px -20px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        #mediaContainer {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }

        .media-item {
            width: 100%;
            height: auto;
            border-radius: 20px;
            box-shadow: 0 10px 20px -5px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .media-item:hover {
            transform: scale(1.02);
        }

        .close-modal-btn {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 40px;
            height: 40px;
            background: #f1f5f9;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            transition: all 0.2s;
            z-index: 10;
        }

        .close-modal-btn:hover {
            background: #e2e8f0;
            color: var(--danger);
        }

        @keyframes zoomIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>

<body>

    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="document.querySelector('.sidebar').classList.toggle('active')">
                <i class="fa-solid fa-bars"></i>
            </div>
            <h1 style="font-weight: 800; font-size: 1.25rem;">Gig Intelligence Center</h1>
        </div>
        <div style="font-weight: 700; color: #64748b; font-size: 0.85rem;">
            <i class="fa-solid fa-circle-dot" style="color: var(--success); margin-right: 5px;"></i> Active Monitoring
        </div>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>

        <main class="main-content">
            <?php if (!$data): ?>
                <div class="premium-card" style="text-align: center; padding: 100px;">
                    <i class="fa-solid fa-magnifying-glass fa-3x" style="color: #cbd5e1; margin-bottom: 20px;"></i>
                    <h2 style="font-weight: 800;">Mission Identifier Missing</h2>
                    <p style="color: #64748b; font-weight: 600;">Please scan a valid Gig or Booking ID to begin tracking.</p>
                    <form method="GET" style="margin-top: 30px; display: flex; justify-content: center; gap: 10px;">
                        <input type="number" name="track_id" placeholder="Enter ID..." required style="padding: 12px; border-radius: 12px; border: 1px solid var(--border); font-family: inherit; font-weight: 600;">
                        <button type="submit" class="btn-update">Initiate Scan</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="order-header">
                    <div>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                            <h2 style="font-weight: 800; font-size: 1.75rem;">Mission #<?= $data['id'] ?></h2>
                            <span class="stat-badge stat-<?= strtolower($data['status']) ?>"><?= $data['status'] ?></span>
                        </div>
                        <p style="color: #64748b; font-weight: 700;">
                            Deployed on <?= date('d M, Y | h:i A', strtotime($data['created_at'])) ?>
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div class="info-label">Current Phase</div>
                        <div style="font-weight: 800; font-size: 1.25rem; color: var(--primary);">
                            <?= $data['vendor_status'] ?: 'Planning' ?>
                        </div>
                    </div>
                </div>

                <div class="finance-card">
                    <div class="finance-grid">
                        <div class="fin-item">
                            <h4>Client Agreed</h4>
                            <p>₹<?= number_format($data['original_price'] ?? $data['amount_agreed'] ?? 0) ?></p>
                        </div>
                        <div class="fin-item">
                            <h4>Partner Payout</h4>
                            <p>₹<?= number_format($vendor_earning) ?></p>
                        </div>
                        <div class="fin-item">
                            <h4>Operating Margin</h4>
                            <p style="color: var(--success);">+ ₹<?= number_format($profit) ?></p>
                        </div>
                        <div class="fin-item">
                            <h4>Cash Collection</h4>
                            <p>₹<?= number_format($data['amount_to_collect'] ?? 0) ?></p>
                        </div>
                    </div>
                </div>

                <div class="premium-card">
                    <h4 style="margin-top:0; margin-bottom: 25px; font-weight: 800;">Target Brief</h4>
                    <div class="client-grid">
                        <div>
                            <div class="info-label">Client Identity</div>
                            <div class="info-value"><?= htmlspecialchars($data['client_name']) ?></div>
                            <div style="font-weight: 700; color: var(--primary); margin-top: 4px;">
                                <i class="fa-solid fa-phone" style="font-size: 0.8rem;"></i> <?= htmlspecialchars($data['client_phone']) ?>
                            </div>
                        </div>
                        <div>
                            <div class="info-label">Operational Unit</div>
                            <div class="info-value"><?= htmlspecialchars($data['cat_name']) ?></div>
                            <div style="font-weight: 600; color: #64748b; font-size: 0.9rem;"><?= htmlspecialchars($data['service_title'] ?? 'Custom Protocol') ?></div>
                        </div>
                        <div>
                            <div class="info-label">Deployment Date</div>
                            <div class="info-value">
                                <?php
                                $reach_dt = !empty($data['reach_datetime']) ? strtotime($data['reach_datetime']) : strtotime($data['created_at']);
                                echo date('d M, Y', $reach_dt);
                                ?>
                            </div>
                            <div style="font-weight: 600; color: var(--warning); font-size: 0.9rem;">
                                <i class="fa-solid fa-clock"></i> <?= date('h:i A', $reach_dt) ?>
                            </div>
                        </div>
                    </div>
                    <hr style="border: 0; border-top: 1px solid var(--border); margin: 25px 0;">
                    <div>
                        <div class="info-label">Operational Zone</div>
                        <div class="info-value" style="font-size: 1rem; margin-bottom: 10px;">
                            <i class="fa-solid fa-location-dot" style="color: var(--danger);"></i> <?= htmlspecialchars($data['full_address']) ?>
                        </div>
                        <?php if (!empty($data['google_map'])): ?>
                            <a href="<?= $data['google_map'] ?>" target="_blank" class="btn-update" style="padding: 8px 16px; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-map"></i> View Satellite Intel
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tracking-grid" style="display: grid; grid-template-columns: 1fr 350px; gap: 30px;">
                    <div class="left-col">
                        <div class="premium-card">
                            <h4 style="margin-top:0; margin-bottom: 25px; font-weight: 800;">Operational Timeline</h4>
                            <div class="timeline-container">
                                
                                <div class="timeline-step">
                                    <div class="step-marker done"></div>
                                    <div style="font-weight: 800;">Mission Initialized</div>
                                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= date('d M, h:i A', strtotime($data['created_at'])) ?></div>
                                </div>

                                <?php if ($accepted_log): ?>
                                    <div class="timeline-step">
                                        <div class="step-marker done"></div>
                                        <div style="font-weight: 800;">Strategic Partner Assigned</div>
                                        <?php 
                                        $accepted_time = (!empty($accepted_log['responded_at']) && $accepted_log['responded_at'] !== '0000-00-00 00:00:00') 
                                            ? $accepted_log['responded_at'] 
                                            : $accepted_log['sent_at'];
                                        ?>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= date('d M, h:i A', strtotime($accepted_time)) ?></div>
                                        <?php if (!empty($data['loc_accepted'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                                <i class="fa-solid fa-location-dot"></i> 
                                                <a href="https://www.google.com/maps?q=<?= $data['loc_accepted'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Acceptance Location</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($data['out_for_service_at']): ?>
                                    <div class="timeline-step">
                                        <div class="step-marker done"></div>
                                        <div style="font-weight: 800;">Journey Initiated (Out for Service)</div>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= date('d M, h:i A', strtotime($data['out_for_service_at'])) ?></div>
                                        <?php if (!empty($data['vendor_loc_out'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                                <i class="fa-solid fa-location-dot"></i> 
                                                <a href="https://www.google.com/maps?q=<?= $data['vendor_loc_out'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Departure Location</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($data['reached_at']): ?>
                                    <div class="timeline-step">
                                        <div class="step-marker done"></div>
                                        <div style="font-weight: 800;">Target Reached</div>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= date('d M, h:i A', strtotime($data['reached_at'])) ?></div>
                                        <?php if (!empty($data['vendor_loc_reached'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                                <i class="fa-solid fa-location-dot"></i> 
                                                <a href="https://www.google.com/maps?q=<?= $data['vendor_loc_reached'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Arrival Location</a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($data['reached_proof'])): ?>
                                            <div class="proof-gallery">
                                                <?php $rProofs = json_decode($data['reached_proof'], true) ?: [$data['reached_proof']];
                                                foreach ($rProofs as $rp): ?>
                                                    <img src="../uploads/proofs/<?= $rp ?>" class="proof-img" onclick="window.open(this.src)">
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($data['started_at']): ?>
                                    <div class="timeline-step">
                                        <div class="step-marker done"></div>
                                        <div style="font-weight: 800;">Operations Commenced</div>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= date('d M, h:i A', strtotime($data['started_at'])) ?></div>
                                        <?php if (!empty($data['vendor_loc_started'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                                <i class="fa-solid fa-location-dot"></i> 
                                                <a href="https://www.google.com/maps?q=<?= $data['vendor_loc_started'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Start Location</a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($data['work_proof'])): ?>
                                            <div class="proof-gallery">
                                                <?php $wProofs = json_decode($data['work_proof'], true) ?: [$data['work_proof']];
                                                foreach ($wProofs as $wp): ?>
                                                    <img src="../uploads/proofs/<?= $wp ?>" class="proof-img" onclick="window.open(this.src)">
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($data['completed_at']): ?>
                                    <div class="timeline-step">
                                        <div class="step-marker done"></div>
                                        <div style="font-weight: 800;">Mission Accomplished</div>
                                        <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= date('d M, h:i A', strtotime($data['completed_at'])) ?></div>
                                        <?php if (!empty($data['vendor_loc_completed'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                                <i class="fa-solid fa-location-dot"></i> 
                                                <a href="https://www.google.com/maps?q=<?= $data['vendor_loc_completed'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Completion Location</a>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($completion): ?>
                                            <?php if (!empty($completion['payment_screenshot'])): ?>
                                                <div style="margin-top: 15px;">
                                                    <div class="info-label">Collection Receipt</div>
                                                    <div class="proof-gallery">
                                                        <img src="../uploads/proofs/<?= $completion['payment_screenshot'] ?>" class="proof-img" onclick="window.open(this.src)">
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($completion['proof_media'])): ?>
                                                <div style="margin-top: 15px;">
                                                    <div class="info-label">Verification Assets</div>
                                                    <div class="proof-gallery">
                                                        <?php $cpMedia = json_decode($completion['proof_media'], true) ?: [$completion['proof_media']];
                                                        foreach ($cpMedia as $cm): ?>
                                                            <img src="../uploads/proofs/<?= $cm ?>" class="proof-img" onclick="window.open(this.src)">
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="premium-card">
                            <h4 style="margin-top:0; margin-bottom: 25px; font-weight: 800;">Strategic Partner Intelligence</h4>
                            <?php if ($data['assigned_vendor_id']): ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                                    <div>
                                        <div class="info-label">Business Identity</div>
                                        <div class="info-value"><?= htmlspecialchars($data['business_name']) ?></div>
                                        <div style="font-size: 0.85rem; color: #64748b; font-weight: 600;"><?= htmlspecialchars($data['vendor_name']) ?> (Owner)</div>
                                    </div>
                                    <div>
                                        <div class="info-label">Primary Channel</div>
                                        <div class="info-value"><?= htmlspecialchars($data['vendor_phone']) ?></div>
                                        <a href="vendor_details.php?id=<?= $data['assigned_vendor_id'] ?>" style="color: var(--primary); font-weight: 700; text-decoration: none; font-size: 0.85rem;">View Partner History</a>
                                    </div>
                                    <div>
                                        <div class="info-label">Strategic Role</div>
                                        <div class="info-value" style="text-transform: capitalize;"><?= htmlspecialchars($data['vendor_role']) ?> Partner</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 20px; color: #64748b;">
                                    <i class="fa-solid fa-satellite-dish fa-2x" style="margin-bottom: 10px; opacity: 0.5;"></i>
                                    <p style="font-weight: 600;">Broadcast in progress. Awaiting partner response.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($data['assigned_vendor_id'])): ?>
                            <div class="premium-card" style="padding:0; overflow:hidden; border:1px solid var(--border);">
                                <div style="padding:15px 20px; background:#f8fafc; border-bottom:1px solid var(--border); font-weight:800; display:flex; justify-content:space-between; align-items:center;">
                                    <span>💬 Chat with Partner</span>
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <button onclick="startCall('audio')" style="background:#e8f4fd; color:#4361ee; border:1px solid rgba(67,97,238,0.15); padding:4px 10px; border-radius:6px; font-weight:700; cursor:pointer; font-size:12px; display:flex; align-items:center; gap:4px;">📞 Audio</button>
                                        <button onclick="startCall('video')" style="background:#4361ee; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-weight:700; cursor:pointer; font-size:12px; display:flex; align-items:center; gap:4px;">🎥 Video</button>
                                        <span id="onlineStatusDot" style="width:8px; height:8px; border-radius:50%; background:#ccc; display:inline-block; margin-left:4px;"></span>
                                    </div>
                                </div>
                                <div id="chatMessages" style="height:250px; overflow-y:auto; padding:15px; background:#fafafa; display:flex; flex-direction:column; gap:10px;">
                                    <div data-empty="1" style="text-align:center; color:#888; font-size:12px; margin-top:10px;">Loading chat...</div>
                                </div>
                                <div style="padding:10px 15px; background:#fff; border-top:1px solid var(--border); display:flex; gap:10px;">
                                    <input type="text" id="chatInput" placeholder="Type message..." style="flex:1; padding:8px 12px; border-radius:8px; border:1px solid var(--border); font-size:13px;" onkeypress="if(event.key==='Enter') sendAdminChat()">
                                    <button onclick="sendAdminChat()" style="background:var(--primary); color:#fff; border:none; padding:8px 16px; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px;">Send</button>
                                </div>
                            </div>

                            <!-- WebRTC Call Overlay -->
                            <div id="callOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.93); z-index:20000; flex-direction:column; align-items:center; justify-content:center;">
                                <video id="remoteVideo" autoplay playsinline style="width:100%; height:100%; object-fit:cover; position:absolute; inset:0;"></video>
                                <video id="localVideo" autoplay muted playsinline style="position:absolute; bottom:120px; right:20px; width:140px; height:180px; border-radius:14px; object-fit:cover; border:2px solid rgba(255,255,255,0.5); box-shadow:0 4px 24px rgba(0,0,0,0.5); z-index:1;"></video>
                                <div id="callStatusText" style="position:absolute; top:20px; left:50%; transform:translateX(-50%); color:#fff; font-size:16px; font-weight:600; z-index:2; background:rgba(0,0,0,0.45); padding:8px 22px; border-radius:20px; white-space:nowrap;">Calling...</div>
                                <div id="callDuration" style="position:absolute; top:64px; left:50%; transform:translateX(-50%); color:rgba(255,255,255,0.75); font-size:13px; z-index:2; display:none; font-variant-numeric:tabular-nums;">00:00</div>
                                <div style="position:absolute; bottom:30px; left:50%; transform:translateX(-50%); display:flex; gap:18px; z-index:2;">
                                    <button id="btnMute" onclick="handleToggleMute()" title="Mute / Unmute" style="width:58px; height:58px; border-radius:50%; background:rgba(255,255,255,0.15); border:2px solid rgba(255,255,255,0.35); color:#fff; font-size:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s;">🎤</button>
                                    <button id="btnEndCall" onclick="endCall()" title="End Call" style="width:58px; height:58px; border-radius:50%; background:#ef4444; border:none; color:#fff; font-size:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(239,68,68,0.5);">📵</button>
                                    <button id="btnCamOff" onclick="handleToggleCamera()" title="Camera On/Off" style="width:58px; height:58px; border-radius:50%; background:rgba(255,255,255,0.15); border:2px solid rgba(255,255,255,0.35); color:#fff; font-size:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s;">📷</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="right-col">
                        <div class="premium-card">
                            <h4 style="margin-top:0; margin-bottom: 20px; font-weight: 800;">Tactical Brief</h4>
                            <div style="background: var(--light); padding: 15px; border-radius: 12px; font-size: 0.9rem; color: #475569; font-weight: 500; line-height: 1.6;">
                                <?= !empty($data['inclusions']) ? nl2br(htmlspecialchars($data['inclusions'])) : (!empty($data['details']) ? nl2br(htmlspecialchars($data['details'])) : 'No inclusions specified.') ?>
                            </div>
                        </div>

                        <?php if (!empty($data['admin_media'])): 
                            $adminMedia = json_decode($data['admin_media'], true);
                            if (!empty($adminMedia)):
                        ?>
                            <div class="premium-card">
                                <h4 style="margin-top:0; margin-bottom: 20px; font-weight: 800;">Admin Assets</h4>
                                <div class="proof-gallery">
                                    <?php foreach ($adminMedia as $am): ?>
                                        <img src="../uploads/admin_task_media/<?= $am ?>" class="proof-img" onclick="window.open(this.src)">
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; endif; ?>

                        <div class="premium-card">
                            <h4 style="margin-top:0; margin-bottom: 20px; font-weight: 800;">Dispatch Logs</h4>
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($alerts_log)): ?>
                                    <p style="font-size: 0.8rem; color: #94a3b8; text-align: center;">No broadcast history.</p>
                                <?php else: ?>
                                    <?php foreach ($alerts_log as $log): ?>
                                        <div style="padding: 12px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <div style="font-weight: 700; font-size: 0.85rem;"><?= htmlspecialchars($log['business_name']) ?></div>
                                                <div style="font-size: 0.7rem; color: #64748b;"><?= date('h:i A', strtotime($log['sent_at'])) ?></div>
                                            </div>
                                            <span class="stat-badge" style="background: #f1f5f9; color: #475569; font-size: 0.65rem; padding: 4px 8px;"><?= strtoupper($log['status']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php if (!empty($data['assigned_vendor_id'])): ?>
    <script>
        const chatEngine = new ChatEngine({
            apiUrl: '../chat_api_proxy.php',
            taskId: <?= (int)$data['id'] ?>,
            myType: 'admin',
            myId: 1, // Default Admin ID
            targetId: <?= (int)$data['assigned_vendor_id'] ?>,
            targetType: 'vendor',
            onNewMessages(msgs) {
                const box = document.getElementById('chatMessages');
                const placeholder = box.querySelector('[data-empty]');
                if (placeholder) placeholder.remove();
                
                if (msgs.length === 0 && box.children.length === 0) {
                    box.innerHTML = '<div data-empty="1" style="text-align:center;color:#888;font-size:12px;margin-top:10px;">No messages. Start conversation!</div>';
                    return;
                }
                msgs.forEach(m => {
                    box.appendChild(chatEngine.renderMessage(m, 'admin'));
                });
                box.scrollTop = box.scrollHeight;
            },
            onStatusChange(isOnline, name) {
                document.getElementById('onlineStatusDot').style.background = isOnline ? '#22c55e' : '#ccc';
            }
        });
        chatEngine.start();

        function sendAdminChat() {
            const input = document.getElementById('chatInput');
            const msg = input.value.trim();
            if (!msg) return;
            input.value = '';

            // Send via ChatEngine (proxy → live site)
            const sendPromise = chatEngine.sendMessage(msg);

            // If sendMessage returns a promise, catch errors
            if (sendPromise && typeof sendPromise.then === 'function') {
                sendPromise.then(res => {
                    console.log('Admin chat sent:', res);
                    if (res && res.error) {
                        alert('⚠️ Message send failed: ' + res.error);
                        input.value = msg; // Restore message
                    }
                }).catch(err => {
                    console.error('Chat send error:', err);
                    alert('⚠️ Could not send message. Check network/proxy.');
                    input.value = msg;
                });
            }
        }

        // ── WebRTC Call Timer ────────────────────────────────────────────────────
        let callDurationTimer = null;
        let callSeconds = 0;

        function startDurationTimer() {
            callSeconds = 0;
            clearInterval(callDurationTimer);
            const durEl = document.getElementById('callDuration');
            if (durEl) {
                durEl.style.display = 'block';
                callDurationTimer = setInterval(() => {
                    callSeconds++;
                    const m = String(Math.floor(callSeconds / 60)).padStart(2, '0');
                    const s = String(callSeconds % 60).padStart(2, '0');
                    durEl.textContent = m + ':' + s;
                }, 1000);
            }
        }

        function stopDurationTimer() {
            clearInterval(callDurationTimer);
            const durEl = document.getElementById('callDuration');
            if (durEl) { durEl.style.display = 'none'; durEl.textContent = '00:00'; }
        }

        // ── WebRTCClient Setup ───────────────────────────────────────────────────
        const webrtcClient = new WebRTCClient({
            signalApiUrl: '/webrtc_signal_proxy.php',
            orderId:      <?= (int)$data['id'] ?>,
            myType:       'admin',
            myId:         1,
            displayName:  'Admin',

            onRemoteStream(stream) {
                document.getElementById('remoteVideo').srcObject = stream;
                document.getElementById('callStatusText').innerText = 'Connected';
                startDurationTimer();
            },
            onCallConnected() {
                document.getElementById('callStatusText').innerText = 'In Call';
            },
            onCallEnded(duration) {
                hideCallOverlay();
                chatEngine.loadMessages();
            },
            onCallDeclined() {
                hideCallOverlay();
                alert('The partner declined the call.');
            },
            onIncomingCall(callData) {
                if (confirm('Incoming ' + callData.call_type + ' call from partner. Accept?')) {
                    showCallOverlay();
                    webrtcClient.handleIncomingCall(callData);
                } else {
                    webrtcClient.declineCall(callData.id);
                }
            },
            onCallMissed() {
                hideCallOverlay();
                alert('No answer.');
            }
        });

        function startCall(type) {
            const callUrl = `/admin/call.php?order_id=<?= (int)$data['id'] ?>&action=dial&call_type=${type}`;
            window.open(callUrl, 'webrtc_call_window', 'width=1000,height=750,toolbar=no,menubar=no,location=no,status=no');
        }

        function endCall() {
            webrtcClient.endCall();
        }

        function handleToggleMute() {
            const muted = webrtcClient.toggleMute();
            document.getElementById('btnMute').textContent = muted ? '🔇' : '🎤';
        }

        function handleToggleCamera() {
            const off = webrtcClient.toggleCamera();
            document.getElementById('btnCamOff').textContent = off ? '🚫' : '📷';
        }

        function showCallOverlay() {
            document.getElementById('callOverlay').style.display = 'flex';
        }

        function hideCallOverlay() {
            document.getElementById('callOverlay').style.display = 'none';
            document.getElementById('remoteVideo').srcObject = null;
            document.getElementById('localVideo').srcObject = null;
            document.getElementById('btnMute').textContent = '🎤';
            document.getElementById('btnCamOff').textContent = '📷';
            stopDurationTimer();
        }
    </script>
    <?php endif; ?>

    <div id="visualModal" class="modal" onclick="this.style.display='none'">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="close-modal-btn" onclick="document.getElementById('visualModal').style.display='none'">
                <i class="fa-solid fa-xmark"></i>
            </div>
            <h2 style="margin-bottom: 2rem; font-weight: 800; font-size: 1.5rem;">Task Proof Images</h2>
            <div id="mediaContainer"></div>
        </div>
    </div>

    <script>
        function openModal(proofs) {
            if (!Array.isArray(proofs) || proofs.length === 0) return;
            const container = document.getElementById('mediaContainer');
            container.innerHTML = '';
            proofs.forEach(p => {
                const isVid = p.match(/\.(mp4|webm|ogg|mov)$/i);
                const el = document.createElement(isVid ? 'video' : 'img');
                el.src = '../uploads/proofs/' + p;
                el.className = 'media-item';
                if (isVid) {
                    el.controls = true;
                    el.muted = true;
                }
                container.appendChild(el);
            });
            document.getElementById('visualModal').style.display = 'flex';
        }
    </script>
</body>
</html>