<?php
// admin/create_manual_task.php
session_start();

// DB Connection
require_once '../db.php';       // Vendor DB
require_once '../db_main.php';  // Shop DB
require_once '../vendor/gig_helper.php';
require_once '../backend/whatsapp_helper.php';

// Auto-migrate column subcategory_id if missing
$chkCol = $conn->query("SHOW COLUMNS FROM manual_tasks LIKE 'subcategory_id'");
if ($chkCol && $chkCol->num_rows == 0) {
    $conn->query("ALTER TABLE manual_tasks ADD COLUMN subcategory_id INT NULL AFTER category_id");
}

// Auth Check
if (!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$msg = "";

// ---------------------------------------------------
// POST HANDLER (MULTI-TASK CREATION)
// ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Common Event Details
    $c_name     = $_POST['client_name'];
    $c_phone    = $_POST['client_phone'];
    $locality   = $_POST['locality'];
    $address    = $_POST['full_address'];
    $gmap       = $_POST['google_map'];
    $e_date     = $_POST['event_date'];
    $reach_time = $_POST['reach_time'];
    $ready_time = $_POST['ready_time'];
    $order_type = $_POST['order_type'] ?? NULL;

    $reach_datetime_main = date('Y-m-d H:i:s', strtotime("$e_date $reach_time"));

    $common_remarks = "Event: $e_date\nReach: $reach_time\nReady: $ready_time\nNotes: " . $_POST['notes'];

    // Lat/Lng
    $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : NULL;
    $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : NULL;
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
    $mediaJson = !empty($uploadedFiles) ? json_encode($uploadedFiles) : NULL;

    // ---------------------------------------------------
    // FUNCTION: SMART BROADCAST (RESTORED)
    // ---------------------------------------------------
    function runSmartBroadcast($conn, $taskId, $catId, $subcatId, $lat, $lng, $originalPrice)
    {
        $vendors_found = false;

        $subcat_cond = $subcatId === null 
            ? "pc.subcategory_id IS NULL" 
            : "(pc.subcategory_id IS NULL OR pc.subcategory_id = $subcatId)";

        // Stage 0: Broadcast to active Internal Vendors FIRST who have a matching active subscription
        $v_sql = "
            SELECT DISTINCT v.id 
            FROM vendors v
            INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
            INNER JOIN packages p ON vs.package_id = p.id
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
              AND (p.order_min_price IS NULL OR ? >= p.order_min_price)
              AND (p.order_max_price IS NULL OR ? <= p.order_max_price)
        ";
        $stmt = $conn->prepare($v_sql);
        $stmt->bind_param("iiidd", $catId, $subcatId, $subcatId, $originalPrice, $originalPrice);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            // Found internal vendor(s)
            while ($row = $res->fetch_assoc()) {
                $vid = $row['id'];
                $chk = $conn->query("SELECT id FROM task_alerts WHERE task_id=$taskId AND vendor_id=$vid");
                if ($chk->num_rows == 0) {
                    $conn->query("INSERT INTO task_alerts (task_id, vendor_id, status, sent_at) VALUES ($taskId, $vid, 'pending', NOW())");
                }
            }
            // Mark task as being in the internal broadcast phase (radius 0)
            $conn->query("UPDATE manual_tasks SET search_radius = 0, last_radius_update = NOW() WHERE id = $taskId");
            $vendors_found = true;
        }

        // If no internal vendors exist, immediately fallback to external vendors
        if (!$vendors_found) {
            // Fake the radius to 0 so the helper knows we are expanding OUT of phase 0
            $conn->query("UPDATE manual_tasks SET search_radius = 0, last_radius_update = NOW() WHERE id = $taskId");
            $vendors_found = expandRadiusLoop($conn, $taskId);
        }

        return $vendors_found;
    }

    $tasksCreated = 0;

    // 1. CREATE DECORATION TASK (If Category is selected)
    $originalPrice = isset($_POST['original_price']) ? (float)$_POST['original_price'] : 0.00;
    $vendorPrice = isset($_POST['vendor_price']) ? (float)$_POST['vendor_price'] : 0.00;
    $internalBasePrice = isset($_POST['internal_base_price']) ? (float)$_POST['internal_base_price'] : 0.00;

    // Changed condition: Allow price to be 0 or empty, as long as Category is selected.
    if (!empty($_POST['category_id'])) {

        $cat_id = (int)$_POST['category_id'];
        $subcat_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
        $inclusions = $_POST['inclusions']; // Main inclusions for Decor

        // Fetch Category Name for service_title
        $decorName = "Service";
        $dNRes = $mainConn->query("SELECT name FROM categories WHERE id=$cat_id LIMIT 1");
        if ($dNRes && $dNR = $dNRes->fetch_assoc()) $decorName = $dNR['name'];
        if ($subcat_id) {
            $sNRes = $mainConn->query("SELECT name FROM subcategories WHERE id=$subcat_id LIMIT 1");
            if ($sNRes && $sNR = $sNRes->fetch_assoc()) $decorName .= " - " . $sNR['name'];
        }

        $amountToCollect = isset($_POST['amount_to_collect']) ? (float)$_POST['amount_to_collect'] : 0.00;

        $sql = "INSERT INTO manual_tasks 
            (category_id, subcategory_id, service_title, order_type, client_name, client_phone, original_price, vendor_price, internal_vendor_price, locality, full_address, inclusions, remarks, google_map, admin_media, event_latitude, event_longitude, search_radius, last_radius_update, status, created_at, amount_to_collect, reach_datetime, sticky_note) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 10, NOW(), 'open', NOW(), ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iissssdddssssssdddss", $cat_id, $subcat_id, $decorName, $order_type, $c_name, $c_phone, $originalPrice, $vendorPrice, $internalBasePrice, $locality, $address, $inclusions, $common_remarks, $gmap, $mediaJson, $lat, $lng, $amountToCollect, $reach_datetime_main, $sticky_note);
        if ($stmt->execute()) {
            $tId = $conn->insert_id;

            // Check Allocation Mode
            $alloc_mode = 'auto';
            $set_res = $conn->query("SELECT value FROM settings WHERE `key` = 'order_allocation_mode' LIMIT 1");
            if ($set_res && $s_row = $set_res->fetch_assoc()) {
                $alloc_mode = $s_row['value'];
            }

            if ($alloc_mode === 'auto') {
                runSmartBroadcast($conn, $tId, $cat_id, $subcat_id, $lat, $lng, $originalPrice);
            }

            sendOrderStatusNotification($conn, $tId, 'confirmed', true);
            $tasksCreated++;
        }
    }

    // 2. CREATE ADDON TASKS
    if (isset($_POST['addon_cats'])) {
        foreach ($_POST['addon_cats'] as $key => $aCat) {
            if (empty($aCat)) continue;

            $aName  = $_POST['addon_names'][$key];
            if (empty($aName)) $aName = "Custom Addon";
            $aPrice = (float)$_POST['addon_vendor_prices'][$key]; // Only ONE vendor price for Addons
            $aCat   = (int)$aCat;
            $aSubcat = !empty($_POST['addon_subcats'][$key]) ? (int)$_POST['addon_subcats'][$key] : null;
            $aReachTime = $_POST['addon_reach_times'][$key];
            $aReachDatetime = date('Y-m-d H:i:s', strtotime("$e_date $aReachTime"));

            $desc   = "Addon Service: $aName\nIncluded in package.";
            $addon_remarks = "Event: $e_date\nReach: $aReachTime\nNotes: " . $_POST['notes'];

            // Handle Addon-Specific Media
            $addonMediaJson = NULL;
            if (isset($_FILES['addon_media']) && !empty($_FILES['addon_media']['name'][$key])) {
                $targetDir = "../uploads/admin_task_media/";
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
                $fileName = time() . '_addon_' . basename($_FILES['addon_media']['name'][$key]);
                if (move_uploaded_file($_FILES['addon_media']['tmp_name'][$key], $targetDir . $fileName)) {
                    $addonMediaJson = json_encode([$fileName]);
                }
            }

            $sql = "INSERT INTO manual_tasks 
            (category_id, subcategory_id, service_title, order_type, client_name, client_phone, original_price, vendor_price, internal_vendor_price, locality, full_address, inclusions, remarks, google_map, admin_media, event_latitude, event_longitude, search_radius, last_radius_update, status, created_at, reach_datetime, sticky_note) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 10, NOW(), 'open', NOW(), ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iissssdddssssssddss", $aCat, $aSubcat, $aName, $order_type, $c_name, $c_phone, $aPrice, $aPrice, $aPrice, $locality, $address, $desc, $addon_remarks, $gmap, $addonMediaJson, $lat, $lng, $aReachDatetime, $sticky_note);
            if ($stmt->execute()) {
                $tId = $conn->insert_id;

                // Check Allocation Mode
                $alloc_mode = 'auto';
                $set_res = $conn->query("SELECT value FROM settings WHERE `key` = 'order_allocation_mode' LIMIT 1");
                if ($set_res && $s_row = $set_res->fetch_assoc()) {
                    $alloc_mode = $s_row['value'];
                }

                if ($alloc_mode === 'auto') {
                    runSmartBroadcast($conn, $tId, $aCat, $aSubcat, $lat, $lng, $aPrice);
                }

                sendOrderStatusNotification($conn, $tId, 'confirmed', true);
                $tasksCreated++;
            }
        }
    }

    if ($tasksCreated > 0) {
        $msg = "<div class='alert success'>Successfully created <b>$tasksCreated tasks</b> and broadcasted alerts!</div>";
    } else {
        $msg = "<div class='alert error'>No tasks were created. Please calculate price/select items.</div>";
    }
}

// FETCH DATA
if (file_exists('../db_main.php')) require_once '../db_main.php';

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

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Task</title>
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .dashboard-container {
            display: flex;
            gap: 2rem;
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .sidebar {
            width: 280px;
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 1.5rem;
            height: fit-content;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
            position: sticky;
            top: 100px;
        }

        .sidebar ul {
            list-style: none !important;
            padding: 0 !important;
            margin: 0 !important;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sidebar ul li a {
            text-decoration: none;
            color: #64748b;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 14px;
            transition: all 0.2s ease;
        }

        .sidebar ul li a:hover {
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            transform: translateX(5px);
        }

        .sidebar ul li a.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .sidebar .badge {
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: auto;
        }

        .sidebar ul li[style*="text-transform:uppercase"] {
            margin-top: 2rem !important;
            margin-bottom: 0.75rem !important;
            color: #94a3b8 !important;
            font-size: 0.7rem !important;
            font-weight: 800 !important;
            letter-spacing: 1.5px !important;
            padding-left: 1rem !important;
            background: none !important;
            list-style: none !important;
        }

        .sidebar .badge-pending {
            float: none !important;
            margin-left: auto !important;
        }

        .main-content {
            flex: 1;
            min-width: 0;
        }

        .page-header {
            margin-bottom: 2rem;
            animation: fadeInDown 0.6s ease-out;
        }

        .page-header h2 {
            font-size: 2.25rem;
            font-weight: 800;
            background: linear-gradient(to right, var(--primary), #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .page-header p {
            color: #64748b;
            font-size: 1.1rem;
            margin-top: 0.5rem;
        }

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

        .input-group {
            margin-bottom: 1.5rem;
        }

        label {
            font-weight: 600;
            font-size: 0.9rem;
            color: #475569;
            display: block;
            margin-bottom: 10px;
            padding-left: 4px;
        }

        input,
        select,
        textarea {
            padding: 14px 18px;
            border-radius: 14px;
            border: 2px solid #e2e8f0;
            font-family: inherit;
            width: 100%;
            outline: none;
            background: white;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1rem;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            background: white;
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--primary) 0%, #6366f1 100%);
            color: white;
            border: none;
            padding: 20px;
            border-radius: 16px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            width: 100%;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.4);
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px) scale(1.01);
            box-shadow: 0 15px 30px -5px rgba(79, 70, 229, 0.5);
            opacity: 0.95;
        }

        .addon-row {
            background: white;
            padding: 20px;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            position: relative;
            animation: slideInRight 0.4s ease-out;
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
        }

        .del-btn:hover {
            transform: scale(1.1) rotate(90deg);
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

        .btn-add:hover {
            background: #eef2ff;
            transform: scale(1.01);
        }

        .image-preview div {
            border: 2px solid white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease;
        }

        .image-preview div:hover {
            transform: scale(1.1);
        }

        .alert {
            padding: 1.25rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.5s ease-out;
        }

        .alert.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .sidebar {
            width: 280px;
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 1.5rem;
            height: fit-content;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow);
            position: sticky;
            top: 100px;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), padding 0.3s ease, left 0.3s ease;
            flex-shrink: 0;
        }

        .sidebar.collapsed {
            width: 80px !important;
            padding: 1.5rem 0.75rem;
        }

        .sidebar ul {
            list-style: none !important;
            padding: 0 !important;
            margin: 0 !important;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sidebar ul li a {
            text-decoration: none;
            color: #64748b;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 14px;
            transition: all 0.2s ease;
            position: relative;
            white-space: nowrap;
        }

        .sidebar.collapsed ul li a {
            justify-content: center;
            padding: 12px;
            gap: 0;
        }

        .sidebar.collapsed ul li a span,
        .sidebar.collapsed .sidebar-header {
            display: none;
        }

        .sidebar ul li a:hover {
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            transform: translateX(5px);
        }

        .sidebar.collapsed ul li a:hover {
            transform: scale(1.1);
        }

        .sidebar ul li a.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
        }

        .sidebar .badge {
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: auto;
        }

        .sidebar.collapsed .badge {
            position: absolute;
            top: 4px;
            right: 4px;
            margin: 0;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            padding: 0;
        }

        .sidebar .sidebar-header {
            margin-top: 2rem !important;
            margin-bottom: 0.75rem !important;
            color: #94a3b8 !important;
            font-size: 0.7rem !important;
            font-weight: 800 !important;
            letter-spacing: 1.5px !important;
            padding-left: 1rem !important;
            background: none !important;
            list-style: none !important;
            text-transform: uppercase;
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
        }

        @media (max-width: 1024px) {
            .dashboard-container {
                flex-direction: column;
                padding: 1rem;
                gap: 1rem;
            }

            .header {
                padding: 1rem;
            }

            .sidebar {
                position: fixed !important;
                left: -300px !important;
                top: 0 !important;
                bottom: 0 !important;
                z-index: 1000 !important;
                transition: all 0.3s ease !important;
                border-radius: 0 24px 24px 0 !important;
                height: 100vh !important;
                width: 280px !important;
                background: white !important;
                opacity: 1 !important;
                visibility: visible !important;
            }

            .sidebar.active {
                left: 0 !important;
                box-shadow: 0 0 0 1000px rgba(15, 23, 42, 0.5) !important;
            }

            .sidebar-toggle {
                display: flex !important;
            }

            .form-grid {
                grid-template-columns: 1fr !important;
            }
        }

        @media (max-width: 640px) {
            .header h1 {
                font-size: 1.1rem;
            }

            .section-card {
                padding: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <header class="header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fa-solid fa-bars"></i>
            </div>
            <div style="background: var(--primary); color: white; width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                <i class="fa-solid fa-bolt"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Create New Task</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 25px;">
            <div style="display: flex; align-items: center; gap: 10px; background: white; padding: 8px 16px; border-radius: 12px; border: 1px solid #e2e8f0;">
                <i class="fa-solid fa-circle-user" style="color: var(--primary);"></i>
                <span style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?></span>
            </div>
            <a href="logout.php" style="color: var(--danger); font-weight: 700; text-decoration: none; font-size: 0.9rem; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-power-off"></i> Logout
            </a>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>

        <main class="main-content">
            <div class="page-header">
                <h2>Create Task</h2>
                <p>Create a new task and send to vendors</p>
            </div>

            <?= $msg ?>

            <form method="POST" enctype="multipart/form-data">

                <!-- 1. EVENT INFO -->
                <div class="form-card">
                    <div class="sec-title"><i class="fa-solid fa-location-dot"></i> Event Logistics</div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 25px; margin-bottom: 25px;">
                        <div class="input-group">
                            <label><i class="fa-regular fa-calendar-check" style="margin-right: 5px;"></i> Event Date</label>
                            <input type="date" name="event_date" required>
                        </div>
                        <div class="input-group">
                            <label><i class="fa-regular fa-clock" style="margin-right: 5px;"></i> Reach Time</label>
                            <input type="text" name="reach_time" placeholder="e.g. 05:30 PM" required>
                        </div>
                        <div class="input-group">
                            <label><i class="fa-solid fa-stopwatch" style="margin-right: 5px;"></i> Ready Time</label>
                            <input type="text" name="ready_time" placeholder="e.g. 06:30 PM" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label><i class="fa-solid fa-map-pin" style="margin-right: 5px;"></i> Locality / Area</label>
                        <input type="text" name="locality" required placeholder="e.g. Sector 62, Noida">
                    </div>

                    <div class="input-group">
                        <label><i class="fa-solid fa-house-chimney" style="margin-right: 5px;"></i> Full Venue Address</label>
                        <textarea name="full_address" rows="3" required placeholder="Complete detailed address with landmarks"></textarea>
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
                            <input type="text" name="client_name" required>
                        </div>
                        <div class="input-group">
                            <label><i class="fa-solid fa-phone-volume" style="margin-right: 5px;"></i> Contact Number</label>
                            <input type="text" name="client_phone">
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
                            <select name="category_id" id="main_category_select">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
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

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                        <div class="input-group">
                            <label>Actual Selling Price (Original Price)</label>
                            <div style="position: relative;">
                                <input type="number" name="original_price" value="0" placeholder="0.00" style="padding-left: 40px;" required>
                                <span style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-weight: 700; color: #94a3b8;">₹</span>
                            </div>
                        </div>
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
                        <textarea name="inclusions" rows="5" placeholder="Detailed materials list and specific instructions for the vendor..."></textarea>
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
                    </div>
                </div>

                <!-- 3. ADDONS & EXTRA ITEMS -->
                <div class="form-card" style="background: rgba(79, 70, 229, 0.03); border: 1px dashed var(--primary);">
                    <div class="sec-title"><i class="fa-solid fa-puzzle-piece"></i> Addons & Supplemental Services</div>
                    <div id="addonContainer"></div>
                    <button type="button" class="btn-add" onclick="addAddonRow()">
                        <i class="fa-solid fa-plus-circle"></i> Add Supplemental Service (Photography, Magician, etc.)
                    </button>
                    <div style="background: white; padding: 12px 18px; border-radius: 12px; display: flex; align-items: center; gap: 10px; border: 1px solid #e2e8f0;">
                        <i class="fa-solid fa-circle-info" style="color: var(--primary);"></i>
                        <p style="color: #64748b; font-size: 0.85rem; font-weight: 500;">Use this for any separate service provider required for the same event.</p>
                    </div>
                </div>

                <!-- 4. FINALIZATION -->
                <div class="form-card" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(5, 150, 105, 0.05) 100%); border-color: rgba(16, 185, 129, 0.2);">
                    <div class="sec-title" style="color: var(--accent);"><i class="fa-solid fa-wallet" style="color: var(--accent);"></i> Collection & Notes</div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-bottom: 25px;">
                        <div class="input-group">
                            <label>Amount to Collect at Venue</label>
                            <div style="position: relative;">
                                <input type="number" name="amount_to_collect" value="0" required placeholder="0.00" style="padding-left: 40px; border-color: rgba(16, 185, 129, 0.2);">
                                <span style="position: absolute; left: 18px; top: 50%; transform: translateY(-50%); font-weight: 700; color: var(--accent);">₹</span>
                            </div>
                        </div>
                        <div class="input-group">
                            <label>Expected Payment Mode</label>
                            <select name="payment_mode">
                                <option value="Cash">Cash at Venue</option>
                                <option value="Online">Online Payment</option>
                            </select>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Internal Notes (Confidential)</label>
                        <textarea name="notes" rows="3" placeholder="Private administrative remarks, not shared with vendors..."></textarea>
                    </div>

                    <div class="input-group">
                        <label style="color: #ef4444;"><i class="fa-solid fa-note-sticky"></i> Sticky Note (Visible to Vendor)</label>
                        <textarea name="sticky_note" rows="2" placeholder="Urgent/Important notes for the vendor (e.g. Balloon color must be red only!)..." style="border-color: #fecaca; background: #fff8f8;"></textarea>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-paper-plane"></i> Create Task & Inform Vendors
                    </button>
                </div>
            </form>
        </main>
    </div>

    <!-- JS DATA -->
    <script>
        const mainCategories = <?= json_encode($categories) ?>;
        const subcategories = <?= json_encode($subcategories) ?>;

        document.getElementById('main_category_select')?.addEventListener('change', function() {
            const catId = this.value;
            const subcatSelect = document.getElementById('main_subcategory_select');
            if (subcatSelect) {
                subcatSelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
                const group = subcatSelect.closest('.input-group');
                if (catId) {
                    const filtered = subcategories.filter(s => s.category_id == catId);
                    if (filtered.length > 0) {
                        if (group) group.style.display = '';
                        filtered.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = s.id;
                            opt.textContent = s.name;
                            subcatSelect.appendChild(opt);
                        });
                    } else {
                        if (group) group.style.display = 'none';
                        subcatSelect.value = '';
                    }
                } else {
                    if (group) group.style.display = 'none';
                    subcatSelect.value = '';
                }
            }
        });

        function addAddonRow() {
            const container = document.getElementById('addonContainer');
            const div = document.createElement('div');
            div.className = 'addon-row';

            let opts = '<option value="">Select Category...</option>';
            mainCategories.forEach(a => {
                opts += `<option value="${a.id}">${a.name}</option>`;
            });

            div.innerHTML = `
                <div class="del-btn" onclick="removeAddonRow(this)"><i class="fa-solid fa-xmark"></i></div>
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
                        <input type="text" name="addon_names[]" placeholder="e.g. 2hr Magician Show" required>
                    </div>
                    <div class="input-group" style="margin-bottom:0;">
                        <label>Reach Time</label>
                        <input type="text" name="addon_reach_times[]" placeholder="05:30 PM" required>
                    </div>
                    <div class="input-group" style="margin-bottom:0;">
                        <label>Vendor Price (Cost)</label>
                        <div style="position: relative;">
                            <input type="number" name="addon_vendor_prices[]" placeholder="0" value="0" style="padding-left: 35px;">
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
                const catId = this.value;
                subcatSelect.innerHTML = '<option value="">Select Subcategory...</option>';
                const group = subcatSelect.closest('.input-group');
                if (catId) {
                    const filtered = subcategories.filter(s => s.category_id == catId);
                    if (filtered.length > 0) {
                        if (group) group.style.display = '';
                        filtered.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = s.id;
                            opt.textContent = s.name;
                            subcatSelect.appendChild(opt);
                        });
                    } else {
                        if (group) group.style.display = 'none';
                        subcatSelect.value = '';
                    }
                } else {
                    if (group) group.style.display = 'none';
                    subcatSelect.value = '';
                }
            });
        }

        function removeAddonRow(btn) {
            const row = btn.parentElement;
            row.style.animation = 'fadeOut 0.3s forwards';
            setTimeout(() => row.remove(), 300);
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
                        wrapper.style.animation = 'fadeIn 0.4s ease-out';

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

        // Map Logic
        document.getElementById('gmap_input').addEventListener('change', function() {
            const url = this.value;
            const regex = /@?([-0-9.]+),([-0-9.]+)/;
            const match = url.match(regex);
            if (match && match.length >= 3) {
                document.getElementById('lat_field').value = match[1];
                document.getElementById('lng_field').value = match[2];
                // Visual feedback
                this.style.borderColor = 'var(--accent)';
                this.style.boxShadow = '0 0 0 4px rgba(16, 185, 129, 0.1)';
            }
        });
    </script>
</body>

</html>