<?php
// vendor/job-details.php
session_start();

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';       // vendor DB
require_once '../db_main.php';  // main DB (surpriseville.co.in)

$vendor_id = (int)$_SESSION['vendor_id'];
$order_id  = (int)($_GET['order_id'] ?? 0); // Changed to order_id to match links

if ($order_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

/* -------------------------------
   1. SECURITY: Check Vendor Access
---------------------------------*/
$stmt = $conn->prepare("SELECT status FROM order_vendor_notifications WHERE order_id = ? AND vendor_id = ?");
$stmt->bind_param("ii", $order_id, $vendor_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 0) {
    die("<div style='padding:20px; font-family:sans-serif;'><h3>Access Denied</h3><p>You are not authorized to view this order.</p><a href='dashboard.php'>Go Back</a></div>");
}

$notif = $res->fetch_assoc();
$notif_status = $notif['status']; // pending, accepted, missed, declined
$is_accepted = ($notif_status === 'accepted');
$stmt->close();

/* -------------------------------
   2. FETCH MAIN ORDER DATA
---------------------------------*/
$stmt = $mainConn->prepare("
    SELECT o.*, u.name AS customer_name, u.phone AS customer_phone
    FROM orders o
    LEFT JOIN users u ON u.id = o.user_id
    WHERE o.id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) die("Order not found in main database.");

/* -------------------------------
   3. FETCH SERVICE DETAILS
---------------------------------*/
$stmt = $mainConn->prepare("
    SELECT s.name, s.description, s.main_image, oi.price
    FROM order_items oi
    LEFT JOIN services s ON oi.service_id = s.id
    WHERE oi.order_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$service = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Image handling
$service_img = "https://partners.surpriseville.co.in/assets/no-img.png";
if (!empty($service['main_image'])) {
    $service_img = "https://surpriseville.co.in/" . ltrim($service['main_image'], '/');
}

/* -------------------------------
   4. FETCH ADDONS
---------------------------------*/
$stmt = $mainConn->prepare("
    SELECT a.name, a.image, oa.quantity
    FROM order_addons oa
    LEFT JOIN addons a ON a.id = oa.addon_id
    WHERE oa.order_id = ?
");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$addons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html>

<head>
    <title>Order #<?= $order_id ?> Details</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .job-header {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .job-img {
            width: 150px;
            height: 110px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #ddd;
        }

        .info-box {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            border: 1px solid #eee;
        }

        .locked-blur {
            filter: blur(4px);
            user-select: none;
            opacity: 0.6;
        }

        .locked-overlay {
            position: relative;
            overflow: hidden;
        }

        .locked-msg {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.7);
            color: #fff;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 10;
        }

        .addon-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }

        .addon-thumb {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            object-fit: cover;
        }
    </style>
</head>

<body>

    <div class="header">
        <h1>Vendor Portal</h1>
        <nav>Welcome, <?= htmlspecialchars($_SESSION['vendor_name'] ?? 'Vendor') ?> | <a href="logout.php">Logout</a></nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">

            <aside class="sidebar">
                <?php include 'sidebar_fragment.php'; ?>
            </aside>

            <main class="main-content">

                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:10px; margin-bottom:15px;">
                        <h2 style="margin:0;">Order #<?= $order_id ?> Details</h2>

                        <?php if ($notif_status == 'pending'): ?>
                            <span style="background:#ffc107; padding:5px 10px; border-radius:4px; font-weight:bold;">⚠️ PENDING ACCEPTANCE</span>
                        <?php elseif ($notif_status == 'accepted'): ?>
                            <span style="background:#28a745; color:white; padding:5px 10px; border-radius:4px; font-weight:bold;">✅ ACCEPTED BY YOU</span>
                        <?php else: ?>
                            <span style="background:#dc3545; color:white; padding:5px 10px; border-radius:4px; font-weight:bold;">❌ MISSED / EXPIRED</span>
                        <?php endif; ?>
                    </div>

                    <div class="job-header">
                        <img src="<?= $service_img ?>" class="job-img">
                        <div>
                            <h3 style="margin:0 0 5px 0;"><?= htmlspecialchars($service['name']) ?></h3>
                            <p style="color:#666; font-size:14px; margin:0 0 10px 0;">
                                <?= nl2br(htmlspecialchars(substr($service['description'], 0, 150))) ?>...
                            </p>
                            <div style="font-size:16px;">
                                <strong>Your Payout: </strong>
                                <span style="color:#28a745; font-size:18px; font-weight:bold;">
                                    ₹<?= number_format($service['price'] * 0.80, 2) ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">

                        <div class="info-box">
                            <h4 style="margin-top:0;">📅 Schedule & Location</h4>
                            <p><strong>Booking Date:</strong> <?= date('d M Y', strtotime($order['booking_date'])) ?></p>
                            <p><strong>City:</strong> <?= htmlspecialchars($order['city']) ?></p>
                            <p><strong>Pincode:</strong> <?= htmlspecialchars($order['pincode']) ?></p>
                        </div>

                        <div class="info-box locked-overlay">
                            <h4 style="margin-top:0;">👤 Client Details</h4>

                            <?php if ($is_accepted): ?>
                                <p><strong>Name:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
                                <p><strong>Full Address:</strong><br><?= nl2br(htmlspecialchars($order['address_line'])) ?></p>
                            <?php else: ?>
                                <div class="locked-msg">🔒 Accept to View</div>
                                <div class="locked-blur">
                                    <p><strong>Name:</strong> Rahul Sharma</p>
                                    <p><strong>Address:</strong> Flat 101, Galaxy Apartments, Sector 62, Noida...</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($addons)): ?>
                        <div class="info-box">
                            <h4 style="margin-top:0;">➕ Extra Addons Required</h4>
                            <?php foreach ($addons as $ad):
                                $aimg = "https://surpriseville.co.in/" . ltrim($ad['image'], '/');
                            ?>
                                <div class="addon-row">
                                    <img src="<?= $aimg ?>" class="addon-thumb" onerror="this.src='../assets/no-img.png'">
                                    <div style="flex:1;">
                                        <strong><?= htmlspecialchars($ad['name']) ?></strong>
                                    </div>
                                    <div style="font-weight:bold;">Qty: <?= $ad['quantity'] ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:20px; text-align:right;">
                        <?php if ($notif_status == 'pending'): ?>
                            <button onclick="acceptJob(<?= $order_id ?>)" class="btn-success" style="padding:12px 25px; font-size:16px; cursor:pointer; background:#28a745; color:white; border:none; border-radius:5px;">✅ Accept Job</button>
                            <a href="pending-alerts.php" style="margin-left:10px; text-decoration:none; color:#666;">Cancel</a>
                        <?php elseif ($notif_status == 'accepted'): ?>
                            <button onclick="window.print()" style="padding:10px 20px; cursor:pointer;">🖨️ Print Details</button>
                            <a href="my-jobs.php" style="margin-left:10px; color:#007bff;">Back to My Jobs</a>
                        <?php else: ?>
                            <a href="dashboard.php" style="color:#007bff;">Back to Dashboard</a>
                        <?php endif; ?>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <script>
        function acceptJob(oid) {
            if (!confirm("Are you sure you want to accept this job?")) return;

            // Reuse the existing acceptance API
            fetch("vendor_accept_job.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: "order_id=" + oid
                })
                .then(r => r.json())
                .then(data => {
                    alert(data.message);
                    if (data.success) location.reload();
                })
                .catch(() => alert("Server Error"));
        }
    </script>

</body>

</html>