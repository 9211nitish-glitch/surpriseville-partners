<?php
// admin/edit_crm_booking.php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";       // Vendor DB
require_once "../db_main.php";  // Shop DB
require_once '../vendor/gig_helper.php';
require_once '../backend/whatsapp_helper.php';

$crm_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$crm_id) {
    die("Invalid ID");
}

// Fetch Booking
$stmt = $conn->prepare("SELECT * FROM crm_bookings WHERE id = ?");
$stmt->bind_param("i", $crm_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
if (!$booking) {
    die("Booking not found");
}

// Fetch actual remaining balance from CRM database, or default to amount_agreed
$crm_amount_to_collect = floatval($booking['amount_to_collect']);
if ($crm_amount_to_collect <= 0) {
    $crm_amount_to_collect = floatval($booking['amount_agreed']);
}

$crm_photo_urls = [];
$crm_activity_logs = [];
$existing_addons = [];
if (!empty($booking['addons'])) {
    $existing_addons = json_decode($booking['addons'], true);
    if (!is_array($existing_addons)) {
        $existing_addons = [];
    }
}

if ($booking['crm_booking_id'] > 0) {
    try {
        $crm_db = new mysqli('swift.herosite.pro', 'btneventsin_crm', 'Btn@123@12', 'btneventsin_crm');
        if (!$crm_db->connect_error) {
            // A. Payments
            $b_stmt = $crm_db->prepare("SELECT amount_agreed FROM bookings WHERE id = ?");
            $b_stmt->bind_param("i", $booking['crm_booking_id']);
            $b_stmt->execute();
            $b_res = $b_stmt->get_result()->fetch_assoc();
            if ($b_res) {
                $crm_agreed = floatval($b_res['amount_agreed']);
                $p_stmt = $crm_db->prepare("SELECT SUM(amount) as total_paid FROM payments WHERE booking_id = ?");
                $p_stmt->bind_param("i", $booking['crm_booking_id']);
                $p_stmt->execute();
                $p_res = $p_stmt->get_result()->fetch_assoc();
                $total_paid = floatval($p_res['total_paid'] ?? 0);
                
                $crm_amount_to_collect = $crm_agreed - $total_paid;
            }
            
            // B. Photos
            $f_stmt = $crm_db->prepare("SELECT file_path FROM booking_files WHERE booking_id = ?");
            $f_stmt->bind_param("i", $booking['crm_booking_id']);
            $f_stmt->execute();
            $f_res = $f_stmt->get_result();
            while ($f_row = $f_res->fetch_assoc()) {
                $crm_photo_urls[] = "https://crm.btnevents.in/" . $f_row['file_path'];
            }
            
            // C. Activity Logs from CRM
            $crm_activity_logs = [];
            $b2_stmt = $crm_db->prepare("SELECT lead_id FROM bookings WHERE id = ?");
            $b2_stmt->bind_param("i", $booking['crm_booking_id']);
            $b2_stmt->execute();
            $b2_res = $b2_stmt->get_result()->fetch_assoc();
            if ($b2_res) {
                $crm_lead_id = $b2_res['lead_id'];
                $log_stmt = $crm_db->prepare("SELECT ll.type, ll.message, ll.status_tag, ll.created_at, COALESCE(u.name, 'System') as user_name FROM lead_logs ll LEFT JOIN users u ON ll.user_id = u.id WHERE ll.lead_id = ? ORDER BY ll.created_at DESC LIMIT 50");
                $log_stmt->bind_param("i", $crm_lead_id);
                $log_stmt->execute();
                $log_res = $log_stmt->get_result();
                while ($log_row = $log_res->fetch_assoc()) {
                    $crm_activity_logs[] = $log_row;
                }
            }
            
            $crm_db->close();
        }
    } catch (Exception $e) {
        // Fallback already set
    }
}


$msg = "";

// ---------------------------------------------------
// POST HANDLER (CONVERT TO RICH TASK & ALLOCATE/BROADCAST)
// ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Common Event Details
    $client_name  = $_POST['client_name'];
    $client_phone = $_POST['client_phone'];
    $locality     = $_POST['locality'];
    $address      = $_POST['full_address'];
    $google_map   = $_POST['google_map'];
    $event_date   = $_POST['event_date'];
    $reach_time   = $_POST['reach_time'];
    $ready_time   = $_POST['ready_time'];
    $order_type   = $_POST['order_type'] ?? NULL;

    $reach_datetime_main = date('Y-m-d H:i:s', strtotime("$event_date $reach_time"));
    $common_remarks = "Event: $event_date\nReach: $reach_time\nReady: $ready_time\nNotes: " . $_POST['notes'];

    // Lat/Lng
    $latitude = !empty($_POST['latitude']) ? $_POST['latitude'] : NULL;
    $longitude = !empty($_POST['longitude']) ? $_POST['longitude'] : NULL;
    $sticky_note = !empty($_POST['sticky_note']) ? $_POST['sticky_note'] : NULL;

    // Media
    $uploadedFiles = [];
    if (isset($_FILES['task_media']) && count($_FILES['task_media']['name']) > 0) {
        $targetDir = "../uploads/admin_task_media/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        for ($i = 0; $i < count($_FILES['task_media']['name']); $i++) {
            if (!empty($_FILES['task_media']['name'][$i])) {
                $fileName = time() . '_' . basename($_FILES['task_media']['name'][$i]);
                if (move_uploaded_file($_FILES['task_media']['tmp_name'][$i], $targetDir . $fileName)) {
                    $uploadedFiles[] = $fileName;
                }
            }
        }
    }

    // Fetch and download CRM photos if any
    if ($booking['crm_booking_id'] > 0) {
        try {
            $crm_db = new mysqli('swift.herosite.pro', 'btneventsin_crm', 'Btn@123@12', 'btneventsin_crm');
            if (!$crm_db->connect_error) {
                $f_stmt = $crm_db->prepare("SELECT file_path FROM booking_files WHERE booking_id = ?");
                $f_stmt->bind_param("i", $booking['crm_booking_id']);
                $f_stmt->execute();
                $f_res = $f_stmt->get_result();
                while ($f_row = $f_res->fetch_assoc()) {
                    $crm_file_path = $f_row['file_path'];
                    $crm_url = "https://crm.btnevents.in/" . $crm_file_path;
                    
                    // Download file content
                    $file_content = @file_get_contents($crm_url);
                    if ($file_content !== false) {
                        $ext = strtolower(pathinfo($crm_file_path, PATHINFO_EXTENSION));
                        $new_filename = time() . '_crm_' . uniqid() . '.' . $ext;
                        $target_path = "../uploads/admin_task_media/" . $new_filename;
                        
                        // Ensure directory exists
                        $targetDir = "../uploads/admin_task_media/";
                        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                        
                        if (file_put_contents($target_path, $file_content)) {
                            $uploadedFiles[] = $new_filename;
                        }
                    }
                }
                $crm_db->close();
            }
        } catch (Exception $e) {
            // Ignore
        }
    }

    $mediaJson = !empty($uploadedFiles) ? json_encode($uploadedFiles) : NULL;

    // Smart Broadcast Helper Function
    function runSmartBroadcast($conn, $taskId, $catId, $subcatId, $lat, $lng)
    {
        $vendors_found = false;
        $subcat_cond = $subcatId === null 
            ? "pc.subcategory_id IS NULL" 
            : "(pc.subcategory_id IS NULL OR pc.subcategory_id = $subcatId)";

        $v_sql = "
            SELECT DISTINCT v.id 
            FROM vendors v
            INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
            LEFT JOIN vendor_wallet vw ON vw.vendor_id = v.id
            WHERE vs.status = 'active' 
              AND v.status = 'active'
              AND v.role = 'internal'
              AND (vw.balance IS NULL OR vw.balance >= 0)
              AND (
                  (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
                  OR
                  EXISTS (
                      SELECT 1 FROM package_categories pc
                      WHERE pc.package_id = vs.package_id 
                      AND pc.category_id = ? 
                      AND (pc.subcategory_id = ? OR pc.subcategory_id IS NULL OR ? IS NULL)
                  )
              )
        ";
        $stmt = $conn->prepare($v_sql);
        $stmt->bind_param("iii", $catId, $subcatId, $subcatId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $vid = $row['id'];
                $chk = $conn->query("SELECT id FROM task_alerts WHERE task_id=$taskId AND vendor_id=$vid");
                if ($chk->num_rows == 0) {
                    $conn->query("INSERT INTO task_alerts (task_id, vendor_id, status, sent_at) VALUES ($taskId, $vid, 'pending', NOW())");
                }
            }
            $conn->query("UPDATE manual_tasks SET search_radius = 0, last_radius_update = NOW() WHERE id = $taskId");
            $vendors_found = true;
        }

        if (!$vendors_found) {
            $conn->query("UPDATE manual_tasks SET search_radius = 0, last_radius_update = NOW() WHERE id = $taskId");
            $vendors_found = expandRadiusLoop($conn, $taskId);
        }

        return $vendors_found;
    }

    $allocation_mode = $_POST['allocation_mode'];
    $amount_agreed   = floatval($_POST['amount_agreed']);
    $amount_to_collect = floatval($_POST['amount_to_collect']);
    $inclusions      = $_POST['inclusions'];

    $cat_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : 1;
    $subcat_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
    $vendor_price = isset($_POST['vendor_price']) ? (float)$_POST['vendor_price'] : 0.00;
    $internal_base_price = isset($_POST['internal_base_price']) ? (float)$_POST['internal_base_price'] : 0.00;

    // Fetch Category/Subcategory Name for service_title
    $decorName = "Service";
    $dNRes = $mainConn->query("SELECT name FROM categories WHERE id=$cat_id LIMIT 1");
    if ($dNRes && $dNR = $dNRes->fetch_assoc()) $decorName = $dNR['name'];
    if ($subcat_id) {
        $sNRes = $mainConn->query("SELECT name FROM subcategories WHERE id=$subcat_id LIMIT 1");
        if ($sNRes && $sNR = $sNRes->fetch_assoc()) $decorName .= " - " . $sNR['name'];
    }

    // 1. Update CRM Bookings Table
    $upd = $conn->prepare("UPDATE crm_bookings SET client_name=?, client_phone=?, event_date=?, location=?, decoration_type=?, amount_agreed=?, details=?, amount_to_collect=? WHERE id=?");
    $full_event_datetime = date('Y-m-d H:i:s', strtotime("$event_date $reach_time"));
    $upd->bind_param("sssssdsdi", $client_name, $client_phone, $full_event_datetime, $address, $decorName, $amount_agreed, $inclusions, $amount_to_collect, $crm_id);
    
    if ($upd->execute()) {
        // 2. Insert Main Task in manual_tasks
        $sql = "INSERT INTO manual_tasks 
            (category_id, subcategory_id, service_title, order_type, client_name, client_phone, original_price, vendor_price, internal_vendor_price, locality, full_address, inclusions, remarks, google_map, admin_media, event_latitude, event_longitude, search_radius, last_radius_update, status, created_at, amount_to_collect, reach_datetime, sticky_note, crm_booking_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 10, NOW(), 'open', NOW(), ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iissssdddssssssdddssi", $cat_id, $subcat_id, $decorName, $order_type, $client_name, $client_phone, $amount_agreed, $vendor_price, $internal_base_price, $locality, $address, $inclusions, $common_remarks, $google_map, $mediaJson, $latitude, $longitude, $amount_to_collect, $reach_datetime_main, $sticky_note, $crm_id);
        
        if ($stmt->execute()) {
            $mainTaskId = $conn->insert_id;

            // 3. Handle Addons
            if (isset($_POST['addon_cats'])) {
                foreach ($_POST['addon_cats'] as $key => $aCat) {
                    if (empty($aCat)) continue;

                    $aName  = $_POST['addon_names'][$key];
                    if (empty($aName)) $aName = "Custom Addon";
                    $aPrice = (float)$_POST['addon_vendor_prices'][$key];
                    $aCat   = (int)$aCat;
                    $aSubcat = !empty($_POST['addon_subcats'][$key]) ? (int)$_POST['addon_subcats'][$key] : null;
                    $aReachTime = $_POST['addon_reach_times'][$key];
                    $aReachDatetime = date('Y-m-d H:i:s', strtotime("$event_date $aReachTime"));

                    $desc   = "Addon Service: $aName\nIncluded in package.";
                    $addon_remarks = "Event: $event_date\nReach: $aReachTime\nNotes: " . $_POST['notes'];

                    $addonMediaJson = NULL;
                    if (isset($_FILES['addon_media']) && !empty($_FILES['addon_media']['name'][$key])) {
                        $targetDir = "../uploads/admin_task_media/";
                        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                        $fileName = time() . '_addon_' . basename($_FILES['addon_media']['name'][$key]);
                        if (move_uploaded_file($_FILES['addon_media']['tmp_name'][$key], $targetDir . $fileName)) {
                            $addonMediaJson = json_encode([$fileName]);
                        }
                    }

                    $sqlAddon = "INSERT INTO manual_tasks 
                    (category_id, subcategory_id, service_title, order_type, client_name, client_phone, original_price, vendor_price, internal_vendor_price, locality, full_address, inclusions, remarks, google_map, admin_media, event_latitude, event_longitude, search_radius, last_radius_update, status, created_at, reach_datetime, sticky_note, crm_booking_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 10, NOW(), 'open', NOW(), ?, ?, ?)";

                    $stmtAddon = $conn->prepare($sqlAddon);
                    $stmtAddon->bind_param("iissssdddssssssddssi", $aCat, $aSubcat, $aName, $order_type, $client_name, $client_phone, $aPrice, $aPrice, $aPrice, $locality, $address, $desc, $addon_remarks, $google_map, $addonMediaJson, $latitude, $longitude, $aReachDatetime, $sticky_note, $crm_id);
                    if ($stmtAddon->execute()) {
                        $addonTaskId = $conn->insert_id;
                        if ($allocation_mode === 'auto') {
                            runSmartBroadcast($conn, $addonTaskId, $aCat, $aSubcat, $latitude, $longitude);
                            sendOrderStatusNotification($conn, $addonTaskId, 'confirmed', true);
                            syncStatusToCRM($conn, $addonTaskId, 'open');
                        }
                    }
                }
            }

            if ($allocation_mode === 'auto') {
                runSmartBroadcast($conn, $mainTaskId, $cat_id, $subcat_id, $latitude, $longitude);
                sendOrderStatusNotification($conn, $mainTaskId, 'confirmed', true);
                syncStatusToCRM($conn, $mainTaskId, 'open');
                $conn->query("UPDATE crm_bookings SET status='tasked' WHERE id=$crm_id");
                header("Location: manage_gigs.php?msg=Auto+broadcast+started+for+CRM+booking+$crm_id");
                exit;
            } else {
                $conn->query("UPDATE crm_bookings SET status='tasked' WHERE id=$crm_id");
                header("Location: allocate_order.php?type=manual_task&task_id=$mainTaskId");
                exit;
            }
        } else {
            $msg = "<div class='alert error'>Task insertion failed: " . $conn->error . "</div>";
        }
    } else {
        $msg = "<div class='alert error'>Update failed: " . $conn->error . "</div>";
    }
}

// Fetch all categories
$categories = [];
$res = $mainConn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) $categories[] = $r;
}

// Fetch all subcategories
$subcategories = [];
$res2 = $mainConn->query("SELECT id, category_id, name FROM subcategories ORDER BY name ASC");
if ($res2) {
    while ($r = $res2->fetch_assoc()) $subcategories[] = $r;
}

// Guess default category from decoration_type string
$inferred_cat_id = 1;
if (stripos($booking['decoration_type'], 'Photographer') !== false || stripos($booking['decoration_type'], 'Photography') !== false) $inferred_cat_id = 4;
elseif (stripos($booking['decoration_type'], 'Magician') !== false) $inferred_cat_id = 2;
elseif (stripos($booking['decoration_type'], 'Tattoo') !== false) $inferred_cat_id = 3;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit & Process CRM Booking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --accent: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --glass: rgba(255, 255, 255, 0.7);
            --glass-border: rgba(255, 255, 255, 0.4);
            --shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            font-family: 'Outfit', sans-serif;
            color: #1e293b;
            min-height: 100vh;
        }

        .header {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            padding: 1.25rem 2.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .container {
            display: flex;
            gap: 2rem;
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .main-content { flex: 1; min-width: 0; }

        .form-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            padding: 2.5rem;
            border-radius: 24px;
            box-shadow: var(--shadow);
            border: 1px solid var(--glass-border);
            margin-bottom: 2rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .form-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.12);
        }

        .sec-title {
            font-size: 0.9rem;
            font-weight: 800;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 2.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sec-title i {
            background: white;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .sec-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(to right, #e2e8f0, transparent);
        }

        .input-group { margin-bottom: 1.5rem; }
        label { display: block; font-weight: 600; margin-bottom: 10px; font-size: 0.9rem; color: #475569; }
        input, select, textarea {
            width: 100%;
            padding: 14px 20px;
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            background: white;
            font-family: inherit;
            font-size: 1rem;
            transition: all 0.2s;
        }
        input:focus, select:focus, textarea:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }

        .btn-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .btn {
            padding: 18px;
            border-radius: 20px;
            border: none;
            font-weight: 800;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-manual { background: white; color: var(--primary); border: 2px solid var(--primary); }
        .btn-auto { background: var(--primary); color: white; box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3); }

        .btn:hover { transform: translateY(-3px); box-shadow: 0 15px 30px -10px rgba(0, 0, 0, 0.2); }

        .addon-row {
            background: white;
            padding: 20px;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            position: relative;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        .del-btn {
            position: absolute;
            top: -12px;
            right: -12px;
            background: var(--danger);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
            transition: all 0.2s ease;
            border: 2px solid white;
            z-index: 10;
        }

        .btn-add {
            background: white;
            color: var(--primary);
            border: 2px dashed var(--primary);
            padding: 14px;
            border-radius: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 1.5rem;
            transition: all 0.2s ease;
            width: 100%;
        }

        .btn-add:hover { background: #eef2ff; }

        .alert.error { background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; }
    </style>
</head>
<body>

    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-pen-to-square"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Process CRM Booking #<?= $crm_id ?></h1>
        </div>
        <nav>
            <a href="crm_bookings.php" style="color: #64748b; font-weight: 800; text-decoration: none; font-size: 0.9rem;">
                <i class="fa-solid fa-arrow-left"></i> Back to List
            </a>
        </nav>
    </header>

    <div class="container">
        <?php include 'sidebar_fragment.php'; ?>
        
        <main class="main-content">
            <?= $msg ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="allocation_mode" id="allocation_mode" value="manual">

                <!-- 1. EVENT LOGISTICS -->
                <div class="form-card">
                    <div class="sec-title"><i class="fa-solid fa-location-dot"></i> Event Logistics</div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 25px; margin-bottom: 25px;">
                        <div class="input-group">
                            <label><i class="fa-regular fa-calendar-check" style="margin-right: 5px;"></i> Event Date</label>
                            <input type="date" name="event_date" value="<?= date('Y-m-d', strtotime($booking['event_date'])) ?>" required>
                        </div>
                        <div class="input-group">
                            <label><i class="fa-regular fa-clock" style="margin-right: 5px;"></i> Reach Time</label>
                            <input type="text" name="reach_time" value="<?= date('h:i A', strtotime($booking['event_date'])) ?>" placeholder="e.g. 05:30 PM" required>
                        </div>
                        <div class="input-group">
                            <label><i class="fa-solid fa-stopwatch" style="margin-right: 5px;"></i> Ready Time</label>
                            <input type="text" name="ready_time" value="<?= date('h:i A', strtotime($booking['event_date']) + 3600) ?>" placeholder="e.g. 06:30 PM" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label><i class="fa-solid fa-map-pin" style="margin-right: 5px;"></i> Locality / Area</label>
                        <input type="text" name="locality" required placeholder="e.g. Sector 62, Noida">
                    </div>

                    <div class="input-group">
                        <label><i class="fa-solid fa-house-chimney" style="margin-right: 5px;"></i> Full Venue Address</label>
                        <textarea name="full_address" rows="3" required placeholder="Complete detailed address with landmarks"><?= htmlspecialchars($booking['location']) ?></textarea>
                    </div>

                    <div class="input-group">
                        <label><i class="fa-brands fa-google" style="margin-right: 5px;"></i> Google Maps Integration</label>
                        <div style="position: relative;">
                            <input type="text" name="google_map" id="gmap_input" placeholder="Paste maps.google.com link here" style="padding-left: 45px;">
                            <i class="fa-solid fa-link" style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #94a3b8;"></i>
                        </div>
                        <input type="hidden" name="latitude" id="lat_field">
                        <input type="hidden" name="longitude" id="lng_field">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                        <div class="input-group">
                            <label><i class="fa-solid fa-user-tag" style="margin-right: 5px;"></i> Client Name</label>
                            <input type="text" name="client_name" value="<?= htmlspecialchars($booking['client_name']) ?>" required>
                        </div>
                        <div class="input-group">
                            <label><i class="fa-solid fa-phone-volume" style="margin-right: 5px;"></i> Contact Number</label>
                            <input type="text" name="client_phone" value="<?= htmlspecialchars($booking['client_phone']) ?>" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label><i class="fa-solid fa-list-check" style="margin-right: 5px;"></i> Order Type</label>
                        <select name="order_type" required>
                            <option value="">-- Select Order Type --</option>
                            <option value="For Only ManPower(Decoration)">For Only ManPower(Decoration)</option>
                            <option value="For only Manpower with material (Decoration with Materials)">For only Manpower with material (Decoration with Materials)</option>
                        </select>
                    </div>
                </div>

                <!-- 2. SERVICE CONFIGURATION -->
                <div class="form-card">
                    <div class="sec-title"><i class="fa-solid fa-wand-magic-sparkles"></i> Service Details</div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                        <div class="input-group" style="margin-bottom: 0;">
                            <label>Category (Main Task)</label>
                            <select name="category_id" id="main_category_select" required>
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= $cat['id'] == $inferred_cat_id ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group" style="margin-bottom: 0; display: none;">
                            <label>Subcategory (Main Task)</label>
                            <select name="subcategory_id" id="main_subcategory_select">
                                <option value="">-- Select Subcategory --</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                        <div class="input-group">
                            <label>Vendor Payout (External)</label>
                            <div style="position: relative;">
                                <input type="number" name="vendor_price" value="0" placeholder="0.00" style="padding-left: 40px;">
                                <span style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-weight: 700; color: #94a3b8;">₹</span>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Vendor Payout (Internal)</label>
                            <div style="position: relative;">
                                <input type="number" name="internal_base_price" value="0" placeholder="0.00" style="padding-left: 40px;">
                                <span style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-weight: 700; color: #94a3b8;">₹</span>
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Scope of Work / Inclusions</label>
                        <textarea name="inclusions" rows="5" placeholder="Detailed materials list and specific instructions..."><?= htmlspecialchars($booking['inclusions'] ?? $booking['details']) ?></textarea>
                    </div>

                    <div class="input-group">
                        <label>Reference Media</label>
                        <div style="border: 2px dashed #cbd5e1; padding: 30px; border-radius: 18px; text-align: center; background: white; cursor: pointer; transition: all 0.2s ease;" onclick="document.getElementById('main_media_input').click();">
                            <i class="fa-solid fa-cloud-arrow-up" style="font-size: 2rem; color: var(--primary); margin-bottom: 10px;"></i>
                            <p style="font-weight: 600; color: #475569;">Click to upload reference images</p>
                            <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 5px;">Multiple files supported</p>
                            <input type="file" name="task_media[]" id="main_media_input" multiple onchange="previewImages(this)" style="display: none;">
                        </div>
                        <div class="image-preview" id="main_preview" style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap;"></div>
                        
                        <?php if (!empty($crm_photo_urls)): ?>
                            <div style="margin-top: 20px;">
                                <label style="font-size: 0.85rem; color: #64748b;"><i class="fa-solid fa-link"></i> Imported from CRM Booking:</label>
                                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px;">
                                    <?php foreach ($crm_photo_urls as $url): ?>
                                        <div style="width: 70px; height: 70px; border-radius: 12px; overflow: hidden; border: 2px solid white; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                                            <img src="<?= htmlspecialchars($url) ?>" style="width: 100%; height: 100%; object-fit: cover;" onclick="window.open('<?= htmlspecialchars($url) ?>', '_blank')">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3. ADDONS & SUPPLEMENTAL SERVICES -->
                <div class="form-card" style="background: rgba(79, 70, 229, 0.03); border: 1px dashed var(--primary);">
                    <div class="sec-title"><i class="fa-solid fa-puzzle-piece"></i> Addons & Supplemental Services</div>
                    <div id="addonContainer"></div>
                    <button type="button" class="btn-add" onclick="addAddonRow()">
                        <i class="fa-solid fa-plus-circle"></i> Add Supplemental Service (Photography, Magician, etc.)
                    </button>
                </div>

                <!-- 4. FINALIZATION -->
                <div class="form-card" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(5, 150, 105, 0.05) 100%); border-color: rgba(16, 185, 129, 0.2);">
                    <div class="sec-title" style="color: var(--accent);"><i class="fa-solid fa-wallet" style="color: var(--accent);"></i> Collection & Notes</div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                        <div class="input-group">
                            <label>Customer Amount Agreed (Total)</label>
                            <div style="position: relative;">
                                <input type="number" name="amount_agreed" value="<?= $booking['amount_agreed'] ?>" required placeholder="0.00" style="padding-left: 40px; border-color: rgba(16, 185, 129, 0.2);">
                                <span style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--accent);">₹</span>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Amount to Collect at Venue</label>
                            <div style="position: relative;">
                                <input type="number" name="amount_to_collect" value="<?= htmlspecialchars($crm_amount_to_collect) ?>" required placeholder="0.00" style="padding-left: 40px; border-color: rgba(16, 185, 129, 0.2);">
                                <span style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--accent);">₹</span>
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Expected Payment Mode</label>
                        <select name="payment_mode">
                            <option value="Cash">Cash at Venue</option>
                            <option value="Online">Online Payment</option>
                        </select>
                    </div>

                    <div class="input-group">
                        <label>Internal Notes (Confidential)</label>
                        <textarea name="notes" rows="3" placeholder="Private administrative remarks..."></textarea>
                    </div>

                    <div class="input-group">
                        <label style="color: #ef4444;"><i class="fa-solid fa-note-sticky"></i> Sticky Note (Visible to Vendor)</label>
                        <textarea name="sticky_note" rows="2" placeholder="Urgent/Important notes for the vendor..." style="border-color: #fecaca; background: #fff8f8;"></textarea>
                    </div>

                    <div class="btn-group">
                        <button type="submit" onclick="document.getElementById('allocation_mode').value='manual'" class="btn btn-manual">
                            <i class="fa-solid fa-user-gear"></i> Save & Manual Allocation
                        </button>
                        <button type="submit" onclick="document.getElementById('allocation_mode').value='auto'" class="btn btn-auto">
                            <i class="fa-solid fa-bolt"></i> Save & Start Auto Broadcast
                        </button>
                    </div>
                </div>
            </form>

            <!-- CRM ACTIVITY LOG -->
            <?php if (!empty($crm_activity_logs)): ?>
            <div class="form-card" style="background: rgba(99, 102, 241, 0.03); border: 1px solid rgba(99, 102, 241, 0.15);">
                <div class="sec-title" style="color: #6366f1;"><i class="fa-solid fa-clock-rotate-left" style="color: #6366f1;"></i> CRM Activity Log</div>
                <div style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                    <?php foreach ($crm_activity_logs as $log): 
                        $tagColors = [
                            'Payment Update' => ['bg' => '#dcfce7', 'text' => '#166534'],
                            'Paid' => ['bg' => '#dcfce7', 'text' => '#166534'],
                            'Converted' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
                            'Vendor Assigned' => ['bg' => '#f3e8ff', 'text' => '#7c3aed'],
                            'Broadcasted' => ['bg' => '#fef3c7', 'text' => '#92400e'],
                            'Updated' => ['bg' => '#e0f2fe', 'text' => '#0369a1'],
                        ];
                        $tc = $tagColors[$log['status_tag']] ?? ['bg' => '#f1f5f9', 'text' => '#475569'];
                    ?>
                    <div style="border-left: 3px solid <?= $tc['text'] ?>; padding: 12px 16px; margin-bottom: 12px; background: white; border-radius: 0 12px 12px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.06);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 0.75rem; color: #94a3b8;">
                                <?= date('d M Y, h:i A', strtotime($log['created_at'])) ?> &middot; <?= htmlspecialchars($log['user_name']) ?>
                            </span>
                            <span style="background: <?= $tc['bg'] ?>; color: <?= $tc['text'] ?>; padding: 2px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700;">
                                <?= htmlspecialchars($log['status_tag']) ?>
                            </span>
                        </div>
                        <div style="font-size: 0.88rem; color: #334155; white-space: pre-wrap; line-height: 1.5;"><?= htmlspecialchars($log['message']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- JS DATA & LOGIC -->
    <script>
        const mainCategories = <?= json_encode($categories) ?>;
        const subcategories = <?= json_encode($subcategories) ?>;

        function loadSubcategories(catId, selectEl) {
            selectEl.innerHTML = '<option value="">-- Select Subcategory --</option>';
            const group = selectEl.closest('.input-group');
            if (catId) {
                const filtered = subcategories.filter(s => s.category_id == catId);
                if (filtered.length > 0) {
                    if (group) group.style.display = '';
                    filtered.forEach(s => {
                        const opt = document.createElement('option');
                        opt.value = s.id;
                        opt.textContent = s.name;
                        selectEl.appendChild(opt);
                    });
                } else {
                    if (group) group.style.display = 'none';
                    selectEl.value = '';
                }
            } else {
                if (group) group.style.display = 'none';
                selectEl.value = '';
            }
        }

        // Trigger subcategory loading for main Category
        const mainCatSelect = document.getElementById('main_category_select');
        const mainSubcatSelect = document.getElementById('main_subcategory_select');
        if (mainCatSelect && mainSubcatSelect) {
            mainCatSelect.addEventListener('change', function() {
                loadSubcategories(this.value, mainSubcatSelect);
            });
            // Initial load
            if (mainCatSelect.value) {
                loadSubcategories(mainCatSelect.value, mainSubcatSelect);
            }
        }

        function addAddonRow(name = '', price = 0) {
            const container = document.getElementById('addonContainer');
            const div = document.createElement('div');
            div.className = 'addon-row';

            let opts = '<option value="">Select Category...</option>';
            mainCategories.forEach(a => {
                opts += `<option value="${a.id}">${a.name}</option>`;
            });

            div.innerHTML = `
                <div class="del-btn" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 15px;">
                    <div class="input-group" style="margin-bottom:0;">
                        <label>Category</label>
                        <select name="addon_cats[]" class="addon-cat-select" required>
                            ${opts}
                        </select>
                    </div>
                    <div class="input-group" style="margin-bottom:0; display: none;">
                        <label>Subcategory</label>
                        <select name="addon_subcats[]" class="addon-subcat-select">
                            <option value="">Select Subcategory...</option>
                        </select>
                    </div>
                    <div class="input-group" style="margin-bottom:0;">
                        <label>Service Name</label>
                        <input type="text" name="addon_names[]" value="${escapeHtml(name)}" placeholder="e.g. 2hr Magician Show" required>
                    </div>
                    <div class="input-group" style="margin-bottom:0;">
                        <label>Reach Time</label>
                        <input type="text" name="addon_reach_times[]" placeholder="05:30 PM" required>
                    </div>
                    <div class="input-group" style="margin-bottom:0;">
                        <label>Vendor Price (Cost)</label>
                        <div style="position: relative;">
                            <input type="number" name="addon_vendor_prices[]" placeholder="0" value="${price}" style="padding-left: 35px;">
                            <span style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); font-weight: 700; color: #94a3b8; font-size: 0.9rem;">₹</span>
                        </div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 250px;">
                        <label style="font-size: 0.8rem; margin-bottom: 5px; color: #64748b;">Activity Reference Photo:</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="file" name="addon_media[]" accept="image/*" onchange="previewImages(this)" style="padding: 8px; font-size: 0.8rem; border-style: dashed;">
                        </div>
                    </div>
                    <div class="image-preview" style="display: flex; gap: 10px; flex-wrap: wrap;"></div>
                </div>
            `;
            container.appendChild(div);

            const catSelect = div.querySelector('.addon-cat-select');
            const subcatSelect = div.querySelector('.addon-subcat-select');
            catSelect.addEventListener('change', function() {
                loadSubcategories(this.value, subcatSelect);
            });
        }

        function previewImages(input) {
            const container = input.parentElement.parentElement.nextElementSibling || input.parentElement.nextElementSibling;
            if (!container || !container.classList.contains('image-preview')) return;
            container.innerHTML = '';

            if (input.files) {
                Array.from(input.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const wrapper = document.createElement('div');
                        wrapper.style.width = '70px';
                        wrapper.style.height = '70px';
                        wrapper.style.borderRadius = '12px';
                        wrapper.style.overflow = 'hidden';
                        wrapper.style.border = '2px solid white';
                        wrapper.style.boxShadow = '0 4px 10px rgba(0,0,0,0.1)';

                        if (file.type.startsWith('image/')) {
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.style.width = '100%';
                            img.style.height = '100%';
                            img.style.objectFit = 'cover';
                            wrapper.appendChild(img);
                        } else {
                            const icon = document.createElement('div');
                            icon.innerHTML = '<i class="fa-solid fa-file-video"></i>';
                            icon.style.display = 'flex';
                            icon.style.alignItems = 'center';
                            icon.style.justifyContent = 'center';
                            icon.style.height = '100%';
                            icon.style.background = '#f1f5f9';
                            icon.style.color = 'var(--primary)';
                            wrapper.appendChild(icon);
                        }
                        container.appendChild(wrapper);
                    }
                    reader.readAsDataURL(file);
                });
            }
        }

        // Map Lat/Lng Parsing from link
        document.getElementById('gmap_input').addEventListener('change', function() {
            const url = this.value;
            const regex = /@?([-0-9.]+),([-0-9.]+)/;
            const match = url.match(regex);
            if (match && match.length >= 3) {
                document.getElementById('lat_field').value = match[1];
                document.getElementById('lng_field').value = match[2];
                this.style.borderColor = 'var(--accent)';
                this.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.1)';
            }
        });

        function escapeHtml(str) {
            if (!str) return '';
            return str.toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Load existing addons from crm_booking
        <?php foreach ($existing_addons as $addon): ?>
            addAddonRow(<?= json_encode($addon['name']) ?>, <?= floatval($addon['price']) ?>);
        <?php endforeach; ?>
    </script>
</body>
</html>
