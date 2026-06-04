<?php
// vendor/missed-alerts.php
session_start();

// Check login
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";       // vendor DB
require_once "../db_main.php";  // main DB

$vendor_id = intval($_SESSION['vendor_id']);
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';
$all_missed = [];

/* =======================================================
   1️⃣ FETCH MISSED AUTOMATED ORDERS
   ======================================================= */
$stmt = $conn->prepare("
    SELECT order_id, status, responded_at
    FROM order_vendor_notifications
    WHERE vendor_id = ?
    AND status IN ('missed','expired','declined')
    ORDER BY responded_at DESC
");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $oid = $row['order_id'];

    // Fetch details from Main DB
    $q = $mainConn->prepare("
        SELECT 
            o.id AS order_id, o.name AS customer_name, o.pincode, o.city,
            s.name AS service_name, s.main_image AS service_image
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN services s ON s.id = oi.service_id
        WHERE o.id = ? LIMIT 1
    ");
    $q->bind_param("i", $oid);
    $q->execute();
    $data = $q->get_result()->fetch_assoc();
    $q->close();

    if ($data) {
        if (!empty($data['service_image'])) {
            $data['service_image'] = "https://surpriseville.co.in/" . ltrim($data['service_image'], '/');
        }

        $missedItem = [
            'type' => 'automated',
            'id' => $oid,
            'title' => $data['service_name'],
            'image' => $data['service_image'],
            'customer' => $data['customer_name'],
            'location' => $data['city'] . " (" . $data['pincode'] . ")",
            'status' => $row['status'],
            'date' => $row['responded_at']
        ];
        $all_missed[] = $missedItem;
    }
}
$stmt->close();

/* =======================================================
   2️⃣ FETCH MISSED MANUAL GIGS (UPDATED WITH IMAGE)
   ======================================================= */
$stmt2 = $conn->prepare("
    SELECT ta.task_id, ta.status, ta.sent_at, ta.missed_reason,
           mt.locality, mt.admin_media, gc.name as category_name
    FROM task_alerts ta
    JOIN manual_tasks mt ON mt.id = ta.task_id
    JOIN gig_categories gc ON gc.id = mt.category_id
    WHERE ta.vendor_id = ?
    AND ta.status IN ('missed', 'declined')
    ORDER BY ta.sent_at DESC
");
$stmt2->bind_param("i", $vendor_id);
$stmt2->execute();
$res2 = $stmt2->get_result();

while ($g = $res2->fetch_assoc()) {
    // Extract Image
    $gig_image = '';
    $media = json_decode($g['admin_media'] ?? '[]', true);

    // Check if media exists and grab the first item
    if (!empty($media) && is_array($media)) {
        // Only use if it's an image, not video (basic check by extension)
        $first_file = $media[0];
        $ext = strtolower(pathinfo($first_file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $gig_image = "../uploads/admin_task_media/" . $first_file;
        } else {
            // If it's a video, maybe use a default video icon or leave empty
            $gig_image = '';
        }
    }

    $gigItem = [
        'type' => 'manual',
        'id' => $g['task_id'],
        'title' => $g['category_name'],
        'image' => $gig_image, // Now correctly populated
        'customer' => 'Hidden (Pre-Accept)',
        'location' => $g['locality'],
        'status' => $g['status'],
        'date' => $g['sent_at'],
        'reason' => $g['missed_reason']
    ];
    $all_missed[] = $gigItem;
}
$stmt2->close();

// Sort combined list by date DESC
usort($all_missed, function ($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Missed Alerts</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        /* BASE & RESET */
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f6f9;
        }

        .container {
            padding: 15px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* HEADER & HAMBURGER */
        .header {
            background: #fff;
            padding: 15px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .menu-btn {
            display: none;
            font-size: 24px;
            cursor: pointer;
            margin-right: 15px;
            background: none;
            border: none;
            padding: 0;
            color: #333;
        }

        .header h1 {
            font-size: 18px;
            margin: 0;
            font-weight: 700;
            color: #2c3e50;
            flex: 1;
        }

        .header nav {
            font-size: 13px;
            color: #666;
        }

        .header a {
            color: #d32f2f;
            text-decoration: none;
            font-weight: 600;
            margin-left: 10px;
        }

        /* LAYOUT */
        .dashboard-layout {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            margin-top: 20px;
            position: relative;
        }

        .sidebar-wrapper {
            width: 250px;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        /* JOB CARD */
        .job-card {
            background: #fff;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            border-left-width: 4px;
            border-left-style: solid;
        }

        .strip-auto {
            border-left-color: #007bff;
        }

        .strip-manual {
            border-left-color: #fd7e14;
        }

        .job-img {
            width: 90px;
            height: 90px;
            object-fit: cover;
            border-radius: 6px;
            flex-shrink: 0;
            border: 1px solid #eee;
        }

        .job-placeholder {
            width: 90px;
            height: 90px;
            background: #f0f0f0;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 12px;
            flex-shrink: 0;
            border: 1px solid #eee;
            text-align: center;
            padding: 5px;
        }

        .job-info {
            flex: 1;
            min-width: 0;
        }

        .type-label {
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 700;
            color: #888;
            margin-bottom: 4px;
            display: block;
        }

        .job-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #333;
            line-height: 1.3;
        }

        .job-meta {
            font-size: 13px;
            margin-top: 6px;
            color: #555;
            line-height: 1.5;
        }

        .date-text {
            font-size: 11px;
            color: #999;
            display: block;
            margin-top: 4px;
        }

        .job-status {
            text-align: right;
            min-width: 80px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            display: inline-block;
        }

        .missed {
            background: #d9534f;
        }

        .expired {
            background: #6c757d;
        }

        .declined {
            background: #f0ad4e;
        }

        .reason-text {
            font-size: 11px;
            color: #d9534f;
            margin-top: 5px;
        }

        /* LEGEND */
        .legend {
            font-size: 13px;
            color: #666;
            margin-bottom: 15px;
            padding: 10px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #eee;
            display: inline-block;
            width: 100%;
            box-sizing: border-box;
        }

        /* MOBILE OVERLAY */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            opacity: 0;
            transition: opacity 0.3s;
        }

        /* MEDIA QUERIES */
        @media (max-width: 900px) {
            .container {
                padding: 10px;
            }

            .menu-btn {
                display: block;
            }

            .sidebar-wrapper {
                position: fixed;
                top: 0;
                left: 0;
                height: 100%;
                width: 280px;
                background: #fff;
                z-index: 999;
                transform: translateX(-100%);
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
                overflow-y: auto;
                padding: 20px;
            }

            .sidebar-wrapper.active {
                transform: translateX(0);
            }

            .overlay.active {
                display: block;
                opacity: 1;
            }

            .dashboard-layout {
                flex-direction: column;
                margin-top: 10px;
            }

            .main-content {
                width: 100%;
            }

            .job-card {
                flex-direction: column;
                gap: 10px;
            }

            .job-img,
            .job-placeholder {
                width: 100%;
                height: 150px;
            }

            .job-status {
                text-align: left;
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-top: 1px dashed #eee;
                padding-top: 10px;
                margin-top: 5px;
            }

            .reason-text {
                margin-top: 0;
            }
        }
    </style>
</head>

<body>

    <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

    <div class="header">
        <button class="menu-btn" onclick="toggleSidebar()">&#9776;</button>
        <h1>Missed Alerts</h1>
        <nav>
            <span><?= htmlspecialchars($vendor_name) ?></span>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">

            <div class="sidebar-wrapper" id="sidebar">
                <button onclick="toggleSidebar()" style="float:right; background:none; border:none; font-size:24px; color:#666;" class="mobile-only-close">&times;</button>
                <?php include 'sidebar_fragment.php'; ?>
            </div>

            <main class="main-content">
                <div class="card" style="background:transparent; box-shadow:none; border:none; padding:0;">

                    <div class="legend">
                        <span style="color:#007bff; font-weight:bold;">🟦 Automated Orders</span> &nbsp;|&nbsp;
                        <span style="color:#fd7e14; font-weight:bold;">🟧 Manual Gigs</span>
                    </div>

                    <?php if (empty($all_missed)): ?>
                        <div style="text-align:center; padding:40px; background:#fff; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
                            <h3 style="color:#777;">No missed jobs found.</h3>
                            <p style="color:#999;">Great job keeping up!</p>
                        </div>
                    <?php else: ?>

                        <?php foreach ($all_missed as $j):
                            $isManual = ($j['type'] === 'manual');
                            $stripClass = $isManual ? 'strip-manual' : 'strip-auto';
                            $typeText = $isManual ? 'Manual Gig Task' : 'Automated Order';
                        ?>
                            <div class="job-card <?= $stripClass ?>">

                                <?php if (!empty($j['image'])): ?>
                                    <img src="<?= $j['image'] ?>" class="job-img" onerror="this.src='https://placehold.co/130x90?text=No+Image'">
                                <?php else: ?>
                                    <div class="job-placeholder">
                                        <?= $isManual ? 'Gig Task' : 'No Image' ?>
                                    </div>
                                <?php endif; ?>

                                <div class="job-info">
                                    <span class="type-label"><?= $typeText ?> #<?= $j['id'] ?></span>
                                    <h3 class="job-title"><?= htmlspecialchars($j['title']); ?></h3>

                                    <div class="job-meta">
                                        <strong>Customer:</strong> <?= htmlspecialchars($j['customer']); ?><br>
                                        <strong>Location:</strong> <?= htmlspecialchars($j['location']); ?>
                                        <span class="date-text">Date: <?= date('d M Y, h:i A', strtotime($j['date'])) ?></span>
                                    </div>
                                </div>

                                <div class="job-status">
                                    <span class="status-badge <?= $j['status']; ?>">
                                        <?= ucfirst($j['status']); ?>
                                    </span>
                                    <?php if (isset($j['reason']) && $j['reason']): ?>
                                        <div class="reason-text">
                                            (<?= htmlspecialchars($j['reason']) ?>)
                                        </div>
                                    <?php endif; ?>
                                </div>

                            </div>
                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>
            </main>

        </div>
    </div>

    <script>
        // Sidebar Toggle
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('overlay').classList.toggle('active');
        }

        // Android App Integration
        if (typeof AndroidInterface !== 'undefined') {
            var vendorId = '<?php echo $vendor_id; ?>';
            if (vendorId) AndroidInterface.saveVendorId(vendorId);
        }

        if (window.innerWidth > 900) {
            document.querySelector('.mobile-only-close').style.display = 'none';
        }
    </script>

</body>

</html>