<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
require_once '../db.php';
require_once '../db_main.php';
require_once '../backend/whatsapp_helper.php';

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id > 0) {
    // Check if this is a dummy placeholder order (service_id is NULL) for a manual task
    $stmt = $mainConn->prepare("SELECT service_id FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order_check = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($order_check && is_null($order_check['service_id'])) {
        header("Location: tracking.php?track_id=$order_id&type=gig");
        exit;
    }
}
$order = null;
$order_items = [];
$notifications = [];
$assigned_vendor_details = null;
$assignment_details = null;
$msg = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $order_id_to_update = intval($_POST['order_id']);
    $sticky_note = !empty($_POST['sticky_note']) ? $_POST['sticky_note'] : NULL;

    $stmt = $mainConn->prepare("UPDATE orders SET status = ?, sticky_note = ? WHERE id = ?");
    $stmt->bind_param("ssi", $new_status, $sticky_note, $order_id_to_update);
    if ($stmt->execute()) {
        $msg = "Order details updated successfully!";
        sendOrderStatusNotification($mainConn, $order_id_to_update, $new_status);
    } else {
        $error = "Failed to update order details.";
    }
    $stmt->close();
}

if ($order_id > 0) {
    $stmt = $mainConn->prepare("
        SELECT o.*, u.name AS customer_name, u.phone AS customer_phone 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE o.id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($order) {
        $stmt = $mainConn->prepare("SELECT oi.*, s.name AS service_name, s.main_image, s.description FROM order_items oi LEFT JOIN services s ON oi.service_id=s.id WHERE oi.order_id=?");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $order_items[] = $r;
        $stmt->close();

        // Fallback: if order_items is empty but orders has a service_id
        if (empty($order_items) && !empty($order['service_id'])) {
            $stmt = $mainConn->prepare("SELECT s.name AS service_name, s.main_image, s.description, ? as quantity, ? as price FROM services s WHERE s.id = ?");
            $qty = 1;
            $price = $order['total_amount'];
            $stmt->bind_param("idi", $qty, $price, $order['service_id']);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($r = $res->fetch_assoc()) {
                $order_items[] = $r;
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT ovn.*, v.name, v.business_name, v.city, v.phone FROM order_vendor_notifications ovn JOIN vendors v ON ovn.vendor_id=v.id WHERE ovn.order_id=? ORDER BY ovn.sent_at ASC");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $notifications[] = $r;
        $stmt->close();

        $vid = !empty($order['assigned_vendor_id']) ? intval($order['assigned_vendor_id']) : 0;
        if ($vid <= 0) {
            $s_va = $mainConn->prepare("SELECT vendor_id FROM order_vendor_assignments WHERE order_id=? AND status IN ('accepted','out_for_service','reached','started','completed') ORDER BY id DESC LIMIT 1");
            $s_va->bind_param("i", $order_id);
            $s_va->execute();
            if ($res_va = $s_va->get_result()->fetch_assoc()) {
                $vid = intval($res_va['vendor_id']);
            }
            $s_va->close();
        }

        if ($vid > 0) {
            $s = $conn->prepare("SELECT * FROM vendors WHERE id=? LIMIT 1");
            $s->bind_param("i", $vid);
            $s->execute();
            $assigned_vendor_details = $s->get_result()->fetch_assoc();
            $s->close();

            $s = $mainConn->prepare("SELECT * FROM order_vendor_assignments WHERE order_id=? AND vendor_id=? LIMIT 1");
            $s->bind_param("ii", $order_id, $vid);
            $s->execute();
            $assignment_details = $s->get_result()->fetch_assoc();
            $s->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Intelligence | Antigravity</title>
    <link rel="stylesheet" href="../assets/style.css">
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
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
        }

        .main-content {
            padding: 30px;
        }

        .premium-card {
            background: #fff;
            border-radius: 24px;
            border: 1px solid var(--border);
            padding: 25px;
            margin-bottom: 25px;
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

        .stat-pending {
            background: #fff7ed;
            color: #9a3412;
        }

        .stat-accepted {
            background: #ecfdf5;
            color: #065f46;
        }

        .stat-out {
            background: #eff6ff;
            color: #1e40af;
        }

        .stat-completed {
            background: #f0fdf4;
            color: #166534;
        }

        .financial-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .fin-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 20px;
            border: 1px solid var(--border);
        }

        .timeline-container {
            position: relative;
            padding-left: 50px;
            margin-top: 30px;
        }

        .timeline-container::before {
            content: '';
            position: absolute;
            left: 24px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-step {
            position: relative;
            margin-bottom: 35px;
        }

        .step-marker {
            position: absolute;
            left: -35px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #fff;
            border: 4px solid #cbd5e1;
            z-index: 2;
        }

        .step-marker.active {
            border-color: var(--primary);
            background: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        }

        .step-marker.done {
            border-color: var(--success);
            background: var(--success);
        }

        .proof-gallery {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        .proof-img {
            width: 140px;
            height: 140px;
            border-radius: 16px;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            cursor: pointer;
            transition: transform 0.2s;
        }

        .proof-img:hover {
            transform: scale(1.05);
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }

        .modern-table th {
            background: #f8fafc;
            padding: 12px 20px;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
        }

        .modern-table td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
        }

        .governance-panel {
            background: #f1f5f9;
            padding: 20px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        select {
            padding: 12px 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            font-family: inherit;
            font-weight: 700;
            background: #fff;
            flex: 1;
        }

        .btn-update {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
        }

        .sidebar-toggle {
            display: none;
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 10px;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            color: var(--primary);
            cursor: pointer;
            font-size: 1.2rem;
            transition: all 0.2s;
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        @media (max-width: 1024px) {
            .sidebar-toggle {
                display: flex;
            }
        }

        @media (max-width: 768px) {
            .tracking-stats-grid,
            .tracking-info-split {
                grid-template-columns: 1fr !important;
            }
        }

        @media (max-width: 600px) {
            .product-card {
                flex-direction: column !important;
                align-items: center !important;
            }
            .product-card .product-image-container {
                width: 100% !important;
            }
            .product-card img,
            .product-card .product-image-placeholder {
                width: 100% !important;
                height: auto !important;
                aspect-ratio: 1 / 1 !important;
            }
        }

        @media (max-width: 480px) {
            .header {
                flex-direction: column !important;
                align-items: center !important;
                text-align: center !important;
                padding: 15px !important;
            }
            .header nav {
                margin-top: 10px !important;
            }
        }
    </style>
</head>

<body>

    <div class="header" style="background: #fff; border-bottom: 1px solid var(--border); padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></div>
            <h1 style="margin: 0; font-size: 1.5rem; font-weight: 800;">Order Intelligence</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <a href="orders.php" style="color: var(--primary); font-weight: 800; text-decoration: none;"><i class="fa-solid fa-arrow-left"></i> Logistics Terminal</a>
        </nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">
            <?php include 'sidebar_fragment.php'; ?>
            <main class="main-content">

                <?php if ($order): ?>
                    <div class="premium-card">
                        <div class="order-header">
                            <div>
                                <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; margin-bottom: 5px;">SEQUENCE IDENTIFIER</div>
                                <h2 style="margin:0; font-weight: 800; font-size: 1.8rem;">#<?= $order['id'] ?></h2>
                                <div style="margin-top: 5px; font-size: 0.85rem; font-weight: 600; color: #64748b;">
                                    <i class="fa-regular fa-clock"></i> Booked on: <?= date('d M Y, h:i A', strtotime($order['created_at'])) ?>
                                </div>
                            </div>
                            <div class="stat-badge stat-<?= strtolower($order['status'] ?: 'pending') ?>">
                                <i class="fa-solid fa-circle-dot"></i> <?= $order['status'] ?: 'Pending' ?>
                            </div>
                        </div>

                        <div class="tracking-stats-grid" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                            <div class="fin-card" style="border-left: 4px solid var(--primary);">
                                <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; text-transform: uppercase;">Event Date & Time</div>
                                <div style="font-size: 1rem; font-weight: 800; color: var(--dark); margin-top: 5px;">
                                    <i class="fa-regular fa-calendar-check" style="color: var(--primary);"></i> 
                                    <?= !empty($order['datetime']) ? date('d M Y | h:i A', strtotime($order['datetime'])) : 'Not Specified' ?>
                                </div>
                            </div>
                            <div class="fin-card" style="border-left: 4px solid var(--success);">
                                <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; text-transform: uppercase;">Total Valuation</div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: var(--dark); margin-top: 5px;">₹<?= number_format($order['total_amount'], 2) ?></div>
                            </div>
                            <div class="fin-card" style="border-left: 4px solid var(--warning);">
                                <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; text-transform: uppercase;">Outstanding Amount</div>
                                <div style="font-size: 1.1rem; font-weight: 800; color: var(--danger); margin-top: 5px;">₹<?= number_format($order['remaining_amount'], 2) ?></div>
                            </div>
                        </div>

                        <div class="tracking-info-split" style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 30px;">
                            <div>
                                <h4 style="margin-bottom: 15px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 10px;">
                                    <i class="fa-solid fa-user-tie" style="color: var(--primary);"></i> Recipient Intelligence
                                </h4>
                                <div style="background: #f8fafc; padding: 20px; border-radius: 20px; border: 1px solid var(--border);">
                                    <div style="display: flex; align-items: flex-start; gap: 15px; margin-bottom: 20px;">
                                        <div style="width: 50px; height: 50px; border-radius: 14px; background: #fff; color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05);"><i class="fa-solid fa-id-card"></i></div>
                                        <div>
                                            <div style="font-weight: 800; font-size: 1.1rem; color: #0f172a;"><?= htmlspecialchars($order['customer_name'] ?? $order['name'] ?? 'N/A') ?></div>
                                            <div style="font-size: 0.9rem; font-weight: 700; color: var(--primary); margin-top: 2px;">
                                                <i class="fa-solid fa-phone"></i> <?= htmlspecialchars($order['customer_phone'] ?? $order['phone'] ?? '-') ?>
                                                <?php if(!empty($order['alt_phone'])): ?>
                                                    <span style="color: #94a3b8; font-weight: 400; margin: 0 5px;">|</span>
                                                    <i class="fa-solid fa-phone-flip"></i> <?= htmlspecialchars($order['alt_phone']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="border-top: 1px solid #e2e8f0; padding-top: 15px;">
                                        <div style="font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Delivery Location</div>
                                        <div style="font-size: 0.95rem; color: #334155; font-weight: 600; line-height: 1.6;">
                                            <i class="fa-solid fa-map-location-dot" style="color: var(--danger); margin-right: 8px;"></i>
                                            <?= htmlspecialchars($order['address_line'] ?? '') ?><br>
                                            <?php if(!empty($order['landmark'])): ?>
                                                <span style="font-size: 0.85rem; color: #64748b;">Landmark: <?= htmlspecialchars($order['landmark']) ?></span><br>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($order['city'] ?? '-') ?> - <?= $order['pincode'] ?>
                                        </div>
                                        
                                        <?php if(!empty($order['exact_location'])): ?>
                                            <div style="margin-top: 15px; padding: 10px; background: #fff; border-radius: 12px; border: 1px dashed #cbd5e1;">
                                                <div style="font-size: 0.65rem; font-weight: 800; color: var(--primary); text-transform: uppercase;">Exact Map Location</div>
                                                <div style="font-size: 0.8rem; font-weight: 600; color: #475569;"><?= htmlspecialchars($order['exact_location']) ?></div>
                                                <?php if(!empty($order['latitude']) && !empty($order['longitude'])): ?>
                                                    <a href="https://www.google.com/maps?q=<?= $order['latitude'] ?>,<?= $order['longitude'] ?>" target="_blank" style="display: inline-block; margin-top: 5px; font-size: 0.75rem; font-weight: 700; color: var(--primary); text-decoration: none;">
                                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Open in Google Maps
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <h4 style="margin-bottom: 15px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 10px;">
                                    <i class="fa-solid fa-box" style="color: var(--success);"></i> Order Logistics Note
                                </h4>
                                <div style="background: #fff7ed; padding: 20px; border-radius: 20px; border: 1px solid #ffedd5; height: calc(100% - 40px);">
                                    <div style="font-size: 0.7rem; font-weight: 800; color: #9a3412; text-transform: uppercase; margin-bottom: 10px;">Special Instructions</div>
                                    <div style="font-size: 0.9rem; color: #7c2d12; font-weight: 600; line-height: 1.6; font-style: italic;">
                                        <?= !empty($order['note']) ? '"' . htmlspecialchars($order['note']) . '"' : 'No special instructions provided by the client.' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="premium-card">
                        <h4 style="margin-bottom: 20px; font-weight: 800; color: var(--dark); display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-layer-group" style="color: var(--primary);"></i> Product & Service Inventory
                        </h4>
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <?php foreach ($order_items as $item): ?>
                                <div class="product-card" style="display: flex; gap: 25px; background: #fff; padding: 20px; border-radius: 24px; border: 1px solid var(--border); box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
                                    <div class="product-image-container" style="flex-shrink: 0;">
                                        <?php if (!empty($item['main_image'])): ?>
                                            <img src="https://surpriseville.co.in/<?= $item['main_image'] ?>" style="width: 180px; height: 180px; border-radius: 20px; object-fit: cover; border: 1px solid #e2e8f0; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);">
                                        <?php else: ?>
                                            <div class="product-image-placeholder" style="width: 180px; height: 180px; border-radius: 20px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 2.5rem;">
                                                <i class="fa-solid fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex-grow: 1;">
                                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                            <div>
                                                <div style="font-weight: 800; font-size: 1.4rem; color: #0f172a; letter-spacing: -0.5px;"><?= htmlspecialchars($item['service_name']) ?></div>
                                                <div style="font-size: 0.85rem; font-weight: 700; color: var(--primary); margin-top: 6px; display: flex; align-items: center; gap: 8px;">
                                                    <i class="fa-solid fa-layer-group"></i> UNIT QUANTITY: <?= $item['quantity'] ?>
                                                </div>
                                            </div>
                                            <div style="font-weight: 800; font-size: 1.25rem; color: var(--success); background: #f0fdf4; padding: 6px 12px; border-radius: 10px;">₹<?= number_format($item['price'] * $item['quantity'], 2) ?></div>
                                        </div>
                                        <div style="margin-top: 20px;">
                                            <div style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 1px; display: flex; align-items: center; gap: 8px;">
                                                <div style="width: 20px; height: 2px; background: var(--primary);"></div> SERVICE INCLUSIONS
                                            </div>
                                            <ul style="list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: 1fr; gap: 10px;">
                                                <?php 
                                                $inclusions = array_filter(explode("\n", str_replace("\r", "", $item['description'])));
                                                foreach ($inclusions as $inc): 
                                                    if(empty(trim($inc))) continue;
                                                ?>
                                                    <li style="display: flex; align-items: flex-start; gap: 12px; font-size: 0.9rem; color: #475569; font-weight: 600; line-height: 1.4;">
                                                        <i class="fa-solid fa-circle-check" style="color: var(--success); margin-top: 3px; font-size: 1rem;"></i>
                                                        <span><?= htmlspecialchars(trim($inc)) ?></span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="premium-card">
                        <h4 style="margin-top:0; margin-bottom: 25px; font-weight: 800;">Governance & Sticky Notes</h4>
                        <form method="POST" class="governance-panel" style="flex-direction: column; align-items: stretch;">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            
                            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 15px;">
                                <div style="flex: 1;">
                                    <label style="font-size: 0.75rem; font-weight: 800; color: #64748b; margin-bottom: 5px; display: block;">OPERATIONAL STATUS</label>
                                    <select name="status" style="width: 100%;">
                                        <option value="pending" <?= $order['status'] == 'pending' ? 'selected' : '' ?>>Pending / New</option>
                                        <option value="accepted" <?= $order['status'] == 'accepted' ? 'selected' : '' ?>>Accepted</option>
                                        <option value="out_for_service" <?= $order['status'] == 'out_for_service' ? 'selected' : '' ?>>Out for Service</option>
                                        <option value="reached" <?= $order['status'] == 'reached' ? 'selected' : '' ?>>Reached</option>
                                        <option value="started" <?= $order['status'] == 'started' ? 'selected' : '' ?>>Work Started</option>
                                        <option value="completed" <?= $order['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="cancelled" <?= $order['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                </div>
                            </div>

                            <div style="margin-bottom: 20px;">
                                <label style="font-size: 0.75rem; font-weight: 800; color: #ef4444; margin-bottom: 5px; display: block;"><i class="fa-solid fa-note-sticky"></i> STICKY NOTE (VISIBLE TO VENDOR)</label>
                                <textarea name="sticky_note" rows="2" style="width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--border); font-family: inherit; font-weight: 600; background: #fff8f8;" placeholder="Add urgent notes for vendor..."><?= htmlspecialchars($order['sticky_note'] ?? '') ?></textarea>
                            </div>

                            <button type="submit" name="update_status" class="btn-update">Deploy Administrative Updates</button>
                        </form>
                    </div>

                    <?php if ($assignment_details): ?>
                        <div class="premium-card">
                            <h4 style="margin-top:0; font-weight: 800; color: var(--dark);">Operational Progress Timeline</h4>
                            <div class="timeline-container">
                                <div class="timeline-step">
                                    <div class="step-marker done"></div>
                                    <div style="font-weight: 800;">Job Assignment Confirmed</div>
                                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= date('d M, h:i A', strtotime($assignment_details['created_at'])) ?></div>
                                    <?php if (!empty($assignment_details['loc_accepted'])): ?>
                                        <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                            <i class="fa-solid fa-location-dot"></i> 
                                            <a href="https://www.google.com/maps?q=<?= $assignment_details['loc_accepted'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Acceptance Location</a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php $is_out = !empty($assignment_details['out_for_service_at']); ?>
                                <div class="timeline-step">
                                    <div class="step-marker <?= $is_out ? 'done' : '' ?>"></div>
                                    <div style="font-weight: 800;">Logistics Departure (Out for Service)</div>
                                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= $is_out ? date('d M, h:i A', strtotime($assignment_details['out_for_service_at'])) : 'Pending Departure' ?></div>
                                    <?php if ($is_out && !empty($assignment_details['loc_out'])): ?>
                                        <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                            <i class="fa-solid fa-location-dot"></i> 
                                            <a href="https://www.google.com/maps?q=<?= $assignment_details['loc_out'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Departure Location</a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php $is_reached = !empty($assignment_details['reached_at']); ?>
                                <div class="timeline-step">
                                    <div class="step-marker <?= $is_reached ? 'done' : '' ?>"></div>
                                    <div style="font-weight: 800;">Geospatial Proximity Confirmed (Reached)</div>
                                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= $is_reached ? date('d M, h:i A', strtotime($assignment_details['reached_at'])) : 'Awaiting Arrival' ?></div>
                                    <?php if ($is_reached): ?>
                                        <?php if (!empty($assignment_details['loc_reached'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                                <i class="fa-solid fa-location-dot"></i> 
                                                <a href="https://www.google.com/maps?q=<?= $assignment_details['loc_reached'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Arrival Location</a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($assignment_details['reached_proof'])): ?>
                                            <div class="proof-gallery" style="margin-top: 8px;">
                                                <?php 
                                                $rProofs = json_decode($assignment_details['reached_proof'], true) ?: [$assignment_details['reached_proof']];
                                                foreach ($rProofs as $rp): ?>
                                                    <img src="../uploads/proofs/<?= $rp ?>" class="proof-img" onclick="window.open(this.src)">
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>

                                <?php $is_started = !empty($assignment_details['started_at']); ?>
                                <div class="timeline-step">
                                    <div class="step-marker <?= $is_started ? 'done' : '' ?>"></div>
                                    <div style="font-weight: 800;">Operational Commencement (Started)</div>
                                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= $is_started ? date('d M, h:i A', strtotime($assignment_details['started_at'])) : 'Pending Start' ?></div>
                                    <?php if ($is_started && !empty($assignment_details['loc_started'])): ?>
                                        <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                            <i class="fa-solid fa-location-dot"></i> 
                                            <a href="https://www.google.com/maps?q=<?= $assignment_details['loc_started'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Execution Location</a>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php $is_done = !empty($assignment_details['completed_at']); ?>
                                <div class="timeline-step">
                                    <div class="step-marker <?= $is_done ? 'done' : '' ?>"></div>
                                    <div style="font-weight: 800;">Operational Fulfillment (Completed)</div>
                                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 700;"><?= $is_done ? date('d M, h:i A', strtotime($assignment_details['completed_at'])) : 'In Progress' ?></div>
                                    
                                    <?php if ($is_done): ?>
                                        <?php if (!empty($assignment_details['loc_completed'])): ?>
                                            <div style="margin-top: 5px; font-size: 0.7rem; font-weight: 700; color: var(--primary);">
                                                <i class="fa-solid fa-location-dot"></i> 
                                                <a href="https://www.google.com/maps?q=<?= $assignment_details['loc_completed'] ?>" target="_blank" style="color: inherit; text-decoration: none;">Exact Completion Location</a>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($assignment_details['payment_method'])): ?>
                                            <div style="margin-top: 10px; font-size: 0.85rem; font-weight: 700;">
                                                <i class="fa-solid fa-credit-card" style="color: var(--primary);"></i> 
                                                Payment Method: <span style="text-transform: uppercase; color: var(--dark);"><?= htmlspecialchars($assignment_details['payment_method']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($assignment_details['payment_proof'])): ?>
                                            <div style="margin-top: 10px;">
                                                <div style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Payment Proof</div>
                                                <div class="proof-gallery">
                                                    <img src="../uploads/proofs/<?= $assignment_details['payment_proof'] ?>" class="proof-img" onclick="window.open(this.src)">
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($assignment_details['work_proof'])): ?>
                                            <div style="margin-top: 15px;">
                                                <div style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Work Photos</div>
                                                <div class="proof-gallery">
                                                    <?php $wProofs = json_decode($assignment_details['work_proof'], true) ?: [$assignment_details['work_proof']];
                                                    foreach ($wProofs as $wp): ?>
                                                        <img src="../uploads/proofs/<?= $wp ?>" class="proof-img" onclick="window.open(this.src)">
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="premium-card">
                        <h4 style="margin-top:0; margin-bottom: 20px; font-weight: 800;">Service Inventory</h4>
                        <div class="table-container">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Classification</th>
                                        <th>Units</th>
                                        <th>Valuation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($order_items as $it): ?>
                                        <tr>
                                            <td style="font-weight: 800; color: var(--dark);"><?= htmlspecialchars($it['service_name'] ?? ('Service ID: ' . $it['service_id'])) ?></td>
                                            <td style="font-weight: 700;"><?= (int)($it['quantity'] ?? 1) ?></td>
                                            <td style="font-weight: 800; color: var(--success);">₹<?= number_format(floatval($it['price'] ?? 0), 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="premium-card">
                        <h4 style="margin-top:0; margin-bottom: 20px; font-weight: 800;">Vendor Dispatch Network</h4>
                        <div class="table-container">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Strategic Partner</th>
                                        <th>Operational State</th>
                                        <th>Response Latency</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($notifications as $n): ?>
                                        <tr>
                                            <td>
                                                <a href="vendor_details.php?id=<?= $n['vendor_id'] ?>" style="text-decoration: none; color: inherit;">
                                                    <div style="font-weight: 800; color: var(--primary);"><?= htmlspecialchars($n['business_name']) ?></div>
                                                    <div style="font-size: 0.7rem; color: #64748b; font-weight: 700;"><?= $n['phone'] ?> | <?= $n['city'] ?></div>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="stat-badge stat-<?= $n['status'] ?>"><?= ucfirst($n['status']) ?></span>
                                            </td>
                                            <td style="font-size: 0.75rem; color: #475569; font-weight: 600;">
                                                <?= $n['responded_at'] ? date('H:i | d M', strtotime($n['responded_at'])) : 'N/A' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($assignment_details && !empty($assignment_details['vendor_id'])): 
                        // Fetch extended vendor info
                        $v_stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
                        $v_stmt->bind_param("i", $assignment_details['vendor_id']);
                        $v_stmt->execute();
                        $v_info = $v_stmt->get_result()->fetch_assoc();
                        $v_stmt->close();
                    ?>
                        <div class="premium-card" style="border-left: 5px solid var(--primary);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                                <h4 style="margin:0; font-weight: 800; color: var(--dark);">Strategic Partner Intelligence</h4>
                                <a href="vendor_details.php?id=<?= $v_info['id'] ?>" class="btn-update" style="padding: 6px 12px; font-size: 0.75rem; text-decoration: none;">View Full Profile</a>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                                <div>
                                    <div style="font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Business Identity</div>
                                    <div style="font-weight: 800; color: #0f172a; font-size: 1.1rem;"><?= htmlspecialchars($v_info['business_name']) ?></div>
                                    <div style="font-size: 0.85rem; color: #64748b; font-weight: 600;"><?= htmlspecialchars($v_info['name']) ?> (Owner)</div>
                                </div>
                                <div>
                                    <div style="font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Primary Channel</div>
                                    <div style="font-weight: 800; color: #0f172a; font-size: 1rem;"><?= htmlspecialchars($v_info['phone']) ?></div>
                                    <div style="font-size: 0.85rem; color: #64748b; font-weight: 600;"><?= htmlspecialchars($v_info['email']) ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px;">Territory</div>
                                    <div style="font-weight: 800; color: #0f172a; font-size: 1rem;"><?= htmlspecialchars($v_info['city']) ?></div>
                                    <div style="font-size: 0.85rem; color: #64748b; font-weight: 600;"><?= htmlspecialchars($v_info['state'] ?? 'N/A') ?></div>
                                </div>
                        </div>

                        <?php if ($assignment_details && !empty($assignment_details['vendor_id'])): ?>
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
                    <?php endif; ?>

                <?php else: ?>
                    <div class="premium-card" style="text-align: center; padding: 100px;">
                        <i class="fa-solid fa-triangle-exclamation fa-3x" style="color: var(--danger); margin-bottom: 20px;"></i>
                        <h2 style="margin:0;">Order Identifier Not Found</h2>
                        <p style="color: #64748b;">The sequence you are attempting to track does not exist in the mainnet.</p>
                    </div>
                <?php endif; ?>

            </main>
        </div>
    </div>

    <?php if ($assignment_details && !empty($assignment_details['vendor_id'])): ?>
    <script>
        const chatEngine = new ChatEngine({
            apiUrl: '../chat_api_proxy.php',
            orderId: <?= (int)$order_id ?>,
            myType: 'admin',
            myId: 1, // Default Admin ID
            targetId: <?= (int)$assignment_details['vendor_id'] ?>,
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
            chatEngine.sendMessage(msg);
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
            orderId:      <?= (int)$order_id ?>,
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

        async function startCall(type) {
            showCallOverlay();
            document.getElementById('callStatusText').innerText = 'Initializing call room...';
            
            // Ensure call room exists in production database for WebRTC signaling
            try {
                await fetch('../vendor/ajax/ensure_webrtc_order.php?id=' + <?= (int)$order_id ?>, { credentials: 'include' });
            } catch (e) {
                console.warn('Call room pre-check failed:', e);
            }
            
            document.getElementById('callStatusText').innerText = type === 'video' ? 'Starting video call...' : 'Calling...';
            webrtcClient.initiateCall(type).catch(err => {
                hideCallOverlay();
                alert('Could not start call: ' + (err.message || err));
            });
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
</body>

</html>