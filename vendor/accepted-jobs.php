<?php
// vendor/accepted-jobs.php
session_start();
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header('Location: login.php'); exit;
}

require_once '../db.php';       // vendor DB
require_once '../db_main.php';  // main DB

$vendor_id = (int)$_SESSION['vendor_id'];

// --- 1. FETCH ACCEPTED AUTOMATED ORDERS ---
// We join with main DB tables (orders, services) to get details
$stmt = $conn->prepare("
    SELECT ovn.*, o.booking_date, o.address_line, o.city, oi.price, s.name as service_name
    FROM order_vendor_notifications ovn
    LEFT JOIN btneventsin.orders o ON ovn.order_id = o.id
    LEFT JOIN btneventsin.order_items oi ON o.id = oi.order_id
    LEFT JOIN btneventsin.services s ON oi.service_id = s.id
    WHERE ovn.vendor_id = ? AND ovn.status = 'accepted'
    ORDER BY o.booking_date DESC
");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$auto_jobs = $stmt->get_result();
$stmt->close();

// --- 2. FETCH ACCEPTED MANUAL GIGS ---
$stmt2 = $conn->prepare("
    SELECT mt.*, gc.name as category_name 
    FROM manual_tasks mt
    LEFT JOIN gig_categories gc ON mt.category_id = gc.id
    WHERE mt.assigned_vendor_id = ? 
    ORDER BY mt.created_at DESC
");
$stmt2->bind_param("i", $vendor_id);
$stmt2->execute();
$gig_jobs = $stmt2->get_result();
$stmt2->close();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Accepted Jobs History</title>
<link rel="stylesheet" href="../assets/style.css">
<style>
    .job-card { background:#fff; border:1px solid #ddd; padding:15px; border-radius:8px; margin-bottom:15px; }
    .job-card h3 { margin:0 0 5px 0; font-size:18px; }
    .badge { padding:3px 8px; border-radius:4px; font-size:12px; font-weight:bold; color:white; }
    .bg-blue { background:#007bff; }
    .bg-green { background:#28a745; }
    .bg-orange { background:#fd7e14; }
    .meta { color:#666; font-size:13px; margin-top:5px; }
    .price { float:right; font-weight:bold; font-size:16px; color:#333; }
</style>
</head>
<body>

<div class="header">
    <h1>All Accepted Jobs</h1>
    <nav>Welcome <?=htmlspecialchars($_SESSION['vendor_name']??'Vendor')?> | <a href="logout.php">Logout</a></nav>
</div>

<div class="container">
    <div class="dashboard-layout">
        
        <aside class="sidebar">
            <?php include 'sidebar_fragment.php'; ?>
        </aside>

        <main class="main-content">
            
            <h2 style="margin-top:0; border-bottom:2px solid #fd7e14; padding-bottom:5px; display:inline-block;">🎯 Manual Gigs</h2>
            <?php if($gig_jobs->num_rows == 0): ?>
                <p style="color:#888; font-style:italic;">No manual gigs accepted yet.</p>
            <?php else: ?>
                <?php while($g = $gig_jobs->fetch_assoc()): 
                    $status = $g['status'];
                    $badgeColor = ($status=='completed') ? 'bg-blue' : (($status=='verified') ? 'bg-green' : 'bg-orange');
                ?>
                <div class="job-card" style="border-left:4px solid #fd7e14;">
                    <div class="price">₹<?= number_format($g['vendor_price']) ?></div>
                    <h3><?= htmlspecialchars($g['category_name']) ?></h3>
                    <span class="badge <?= $badgeColor ?>"><?= strtoupper($status) ?></span>
                    
                    <div class="meta">
                        <strong>Client:</strong> <?= htmlspecialchars($g['client_name']) ?> (<?= htmlspecialchars($g['client_phone']) ?>)<br>
                        <strong>Location:</strong> <?= htmlspecialchars($g['locality']) ?><br>
                        <?php
                        $remarks = $g['remarks'] ?? '';
                        $displayDate = date('d M Y', strtotime($g['created_at']));
                        if (preg_match('/Event:\s*(.*?)(?=\s*(Reach:|Ready:|Notes:|\n|\r|$))/i', $remarks, $m)) {
                            $ts = strtotime(trim($m[1]));
                            if ($ts) $displayDate = date('d M Y', $ts);
                            else if ($m[1]) $displayDate = trim($m[1]);
                        }
                        ?>
                        <strong>Date:</strong> <?= $displayDate ?>
                    </div>
                    
                    <div style="margin-top:10px;">
                        <a href="gig_market.php" style="font-size:13px; color:#007bff;">View Details & Upload Proof &rarr;</a>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>

            <br><hr><br>

            <h2 style="margin-top:0; border-bottom:2px solid #007bff; padding-bottom:5px; display:inline-block;">📦 Automated Orders</h2>
            <?php if($auto_jobs->num_rows == 0): ?>
                <p style="color:#888; font-style:italic;">No automated orders accepted yet.</p>
            <?php else: ?>
                <?php while($r = $auto_jobs->fetch_assoc()): ?>
                <div class="job-card" style="border-left:4px solid #007bff;">
                    <div class="price">₹<?= number_format((float)$r['price']) ?></div>
                    <h3><?= htmlspecialchars($r['service_name']) ?> (Order #<?= $r['order_id'] ?>)</h3>
                    
                    <div class="meta">
                        <strong>Booking Date:</strong> <?= date('d M Y', strtotime($r['booking_date'])) ?><br>
                        <strong>Location:</strong> <?= htmlspecialchars($r['address_line']) ?>, <?= htmlspecialchars($r['city']) ?><br>
                        <strong>Accepted On:</strong> <?= date('d M Y, h:i A', strtotime($r['responded_at'])) ?>
                    </div>

                    <div style="margin-top:10px;">
                        <a href="job-details.php?order_id=<?= $r['order_id'] ?>" style="font-size:13px; color:#007bff;">View Full Order Details &rarr;</a>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>

        </main>
    </div>
</div>

</body>
</html>