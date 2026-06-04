<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';
require_once '../db_main.php';
require_once '../vendor/gig_helper.php';

date_default_timezone_set('Asia/Kolkata');

// Helper function to check if the category is allowed under the package
function is_category_allowed($conn, $mainConn, $package_id, $type, $details) {
    // First, check if the package has any category restrictions
    $resQ = $conn->query("SELECT category_id FROM package_categories WHERE package_id = $package_id");
    if (!$resQ || $resQ->num_rows == 0) {
        return true; // No restrictions, allow all
    }
    
    $allowed_ids = [];
    while ($row = $resQ->fetch_assoc()) {
        $allowed_ids[] = intval($row['category_id']);
    }
    
    $order_category_id = null;
    if ($type == 'shop_order') {
        // Get service category name from main DB
        $srvCatId = intval($details['category_id'] ?? 0);
        if ($srvCatId > 0) {
            $mainCatQ = $mainConn->query("SELECT name FROM categories WHERE id = $srvCatId");
            if ($mainCatQ && $mainCatQ->num_rows > 0) {
                $mainCat = $mainCatQ->fetch_assoc();
                $catName = $mainCat['name'];
                // Find matching category in partners DB gig_categories
                $gigCatQ = $conn->prepare("SELECT id FROM gig_categories WHERE name = ?");
                $gigCatQ->bind_param("s", $catName);
                $gigCatQ->execute();
                $gigCatRes = $gigCatQ->get_result();
                if ($gigCatRes && $gigCatRes->num_rows > 0) {
                    $order_category_id = intval($gigCatRes->fetch_assoc()['id']);
                }
            }
        }
    } elseif ($type == 'crm_booking') {
        // Try to match decoration_type by name in gig_categories
        $decType = $details['decoration_type'] ?? '';
        if (!empty($decType)) {
            $gigCatQ = $conn->prepare("SELECT id FROM gig_categories WHERE name LIKE ? OR ? LIKE CONCAT('%', name, '%')");
            $likePattern = "%" . $decType . "%";
            $gigCatQ->bind_param("ss", $likePattern, $decType);
            $gigCatQ->execute();
            $gigCatRes = $gigCatQ->get_result();
            if ($gigCatRes && $gigCatRes->num_rows > 0) {
                $order_category_id = intval($gigCatRes->fetch_assoc()['id']);
            }
        }
    } else { // manual_task
        $order_category_id = intval($details['category_id'] ?? 0);
    }
    
    if ($order_category_id === null) {
        return false; 
    }
    
    return in_array($order_category_id, $allowed_ids);
}

$msg = "";
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$task_id = isset($_GET['task_id']) ? intval($_GET['task_id']) : 0;
$crm_id = isset($_GET['crm_id']) ? intval($_GET['crm_id']) : 0;
$type = isset($_GET['type']) ? $_GET['type'] : 'shop_order';

if ($type == 'gig') {
    $order_id = 0; 
    $crm_id = 0;
} elseif ($type == 'crm_booking') {
    $order_id = 0;
    $task_id = 0;
} else {
    $task_id = 0;
    $crm_id = 0;
}

// 1. Fetch Order/Gig Details
$details = null;
if ($type == 'shop_order') {
    $stmt = $mainConn->prepare("SELECT o.*, s.vendor_price as s_vendor_price, s.manpower_price as s_manpower_price 
                                FROM orders o 
                                LEFT JOIN services s ON o.service_id = s.id 
                                WHERE o.id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();
    
    // Fallback for vendor_price if not set in orders table
    if ($details && floatval($details['vendor_price'] ?? 0) <= 0) {
        $details['vendor_price'] = $details['s_vendor_price'] ?? 0;
    }
} elseif ($type == 'crm_booking') {
    $stmt = $conn->prepare("SELECT * FROM crm_bookings WHERE id = ?");
    $stmt->bind_param("i", $crm_id);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();
    if ($details) {
        $details['customer_name'] = $details['client_name'];
        $details['service_title'] = $details['decoration_type'];
    }
} else {
    $stmt = $conn->prepare("SELECT mt.*, gc.name as cat_name FROM manual_tasks mt LEFT JOIN gig_categories gc ON mt.category_id = gc.id WHERE mt.id = ?");
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();
}

if (!$details) {
    die("Invalid request or item not found.");
}

// 2. Handle POST (Allocation)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendor_id = intval($_POST['vendor_id']);
    $original_price = floatval($_POST['original_price']);
    $adjusted_price = floatval($_POST['adjusted_price']);
    $admin_id = $_SESSION['admin_id'];

    if ($vendor_id > 0) {
        $conn->begin_transaction();
        try {
            // Check active subscription logic
            $subQ = $conn->query("SELECT * FROM vendor_subscriptions WHERE vendor_id = $vendor_id AND status = 'active' LIMIT 1");
            $vendor_sub = ($subQ && $subQ->num_rows > 0) ? $subQ->fetch_assoc() : null;
            
            if ($vendor_sub) {
                $category_allowed = is_category_allowed($conn, $mainConn, $vendor_sub['package_id'], $type, $details);
                
                if ($vendor_sub['credits_remaining'] <= 0) {
                    throw new Exception("Vendor's active subscription has no remaining task credits.");
                }
                if (!$category_allowed) {
                    throw new Exception("The order category is not allowed under the vendor's subscription package.");
                }

                // Check if price is within package range
                $pkgQ = $conn->query("SELECT order_min_price, order_max_price FROM packages WHERE id = " . intval($vendor_sub['package_id']));
                if ($pkgQ && $pkgRow = $pkgQ->fetch_assoc()) {
                    $orderPrice = 0;
                    if ($type == 'shop_order') {
                        $orderPrice = floatval($details['total_amount'] ?? 0);
                    } elseif ($type == 'crm_booking') {
                        $orderPrice = floatval($details['amount_agreed'] ?? 0);
                    } else { // manual_task
                        $orderPrice = floatval($details['original_price'] ?? 0);
                    }
                    
                    if ($pkgRow['order_min_price'] !== null && $orderPrice < floatval($pkgRow['order_min_price'])) {
                        throw new Exception("The order price (₹" . number_format($orderPrice) . ") is less than the package minimum order amount (₹" . number_format($pkgRow['order_min_price']) . ").");
                    }
                    if ($pkgRow['order_max_price'] !== null && $orderPrice > floatval($pkgRow['order_max_price'])) {
                        throw new Exception("The order price (₹" . number_format($orderPrice) . ") is greater than the package maximum order amount (₹" . number_format($pkgRow['order_max_price']) . ").");
                    }
                }
                
                // Decrement credits
                $new_credits = $vendor_sub['credits_remaining'] - 1;
                $new_status = ($new_credits <= 0) ? 'exhausted' : 'active';
                
                $updSub = $conn->prepare("UPDATE vendor_subscriptions SET credits_remaining = ?, status = ? WHERE id = ?");
                $updSub->bind_param("isi", $new_credits, $new_status, $vendor_sub['id']);
                $updSub->execute();
            }

            if ($type == 'shop_order') {
                // ... (existing shop_order logic)
                $stmt = $mainConn->prepare("UPDATE orders SET assigned_vendor_id = ?, vendor_id = ?, status = 'assigned', assigned_at = NOW() WHERE id = ?");
                $stmt->bind_param("iii", $vendor_id, $vendor_id, $order_id);
                $stmt->execute();

                $checkAsn = $mainConn->prepare("SELECT id FROM order_vendor_assignments WHERE order_id = ? AND service_type = 'decoration' LIMIT 1");
                $checkAsn->bind_param("i", $order_id);
                $checkAsn->execute();
                $asnRes = $checkAsn->get_result();
                
                if ($asnRes->num_rows > 0) {
                    $asnId = $asnRes->fetch_assoc()['id'];
                    $updAsn = $mainConn->prepare("UPDATE order_vendor_assignments SET vendor_id = ?, vendor_price = ?, status = 'assigned', updated_at = NOW() WHERE id = ?");
                    $updAsn->bind_param("idi", $vendor_id, $adjusted_price, $asnId);
                    $updAsn->execute();
                } else {
                    $insAsn = $mainConn->prepare("INSERT INTO order_vendor_assignments (order_id, vendor_id, vendor_price, service_type, status, created_at, updated_at) VALUES (?, ?, ?, 'decoration', 'assigned', NOW(), NOW())");
                    $insAsn->bind_param("iid", $order_id, $vendor_id, $adjusted_price);
                    $insAsn->execute();
                }

                $stmt = $conn->prepare("INSERT INTO order_vendor_notifications (order_id, vendor_id, status, sent_at) VALUES (?, ?, 'accepted', NOW()) ON DUPLICATE KEY UPDATE status='accepted'");
                $stmt->bind_param("ii", $order_id, $vendor_id);
                $stmt->execute();
            } elseif ($type == 'crm_booking') {
                $stmt = $conn->prepare("UPDATE crm_bookings SET assigned_vendor_id = ?, vendor_price = ?, status = 'assigned' WHERE id = ?");
                $stmt->bind_param("idi", $vendor_id, $adjusted_price, $crm_id);
                $stmt->execute();
                
                // Fetch category ID
                $cat_id = 1;
                $decType = $details['decoration_type'] ?? '';
                if (stripos($decType, 'Photographer') !== false) $cat_id = 4;
                elseif (stripos($decType, 'Magician') !== false) $cat_id = 2;
                elseif (stripos($decType, 'Tattoo') !== false) $cat_id = 3;
                
                $reach_dt = date('Y-m-d H:i:s', strtotime($details['event_date']));
                $remarks = "CRM Booking #$crm_id\nDetails: " . ($details['details'] ?? '');
                $locality = "";
                
                // Insert corresponding manual task as assigned
                $insTask = $conn->prepare("INSERT INTO manual_tasks (category_id, service_title, client_name, client_phone, original_price, locality, full_address, inclusions, remarks, status, created_at, reach_datetime, crm_booking_id, assigned_vendor_id, vendor_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'assigned', NOW(), ?, ?, ?, ?)");
                $insTask->bind_param("isssdssssiidd", $cat_id, $details['decoration_type'], $details['client_name'], $details['client_phone'], $details['amount_agreed'], $locality, $details['location'], $details['inclusions'], $remarks, $reach_dt, $crm_id, $vendor_id, $adjusted_price);
                $insTask->execute();
                $new_task_id = $insTask->insert_id;
                syncStatusToCRM($conn, $new_task_id, 'assigned');
            } else {
                $stmt = $conn->prepare("UPDATE manual_tasks SET assigned_vendor_id = ?, vendor_price = ?, status = 'assigned' WHERE id = ?");
                $stmt->bind_param("idi", $vendor_id, $adjusted_price, $task_id);
                $stmt->execute();
                syncStatusToCRM($conn, $task_id, 'assigned');

                // If this manual task is linked to a CRM booking, update the CRM booking status and vendor details
                $chkCrm = $conn->query("SELECT crm_booking_id FROM manual_tasks WHERE id = $task_id");
                if ($chkCrm && $chkCrmRow = $chkCrm->fetch_assoc()) {
                    $c_id = $chkCrmRow['crm_booking_id'];
                    if ($c_id) {
                        $updCrm = $conn->prepare("UPDATE crm_bookings SET assigned_vendor_id = ?, vendor_price = ?, status = 'assigned' WHERE id = ?");
                        $updCrm->bind_param("idi", $vendor_id, $adjusted_price, $c_id);
                        $updCrm->execute();
                    }
                }
            }

            $stmt = $conn->prepare("INSERT INTO manual_allocation_logs (order_id, task_id, vendor_id, original_price, adjusted_price, admin_id, allocation_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiiddis", $order_id, $task_id, $vendor_id, $original_price, $adjusted_price, $admin_id, $type);
            $stmt->execute();

            $conn->commit();
            $msg = "<div class='alert success'><i class='fa-solid fa-circle-check'></i> Successfully allocated to vendor! Redirecting...</div>";
            echo "<script>setTimeout(() => { window.location.href = '" . ($type == 'shop_order' ? 'orders.php' : 'manage_gigs.php') . "'; }, 1500);</script>";
        } catch (Exception $e) {
            $conn->rollback();
            $msg = "<div class='alert error'><i class='fa-solid fa-circle-xmark'></i> Error: " . $e->getMessage() . "</div>";
        }
    }
}

// 3. Fetch Vendors with Category Info
$vendors = [];
$city = trim($details['city'] ?? $details['locality'] ?? '');

$v_query = "
    SELECT v.id, v.name, v.business_name, v.city, v.phone, GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') as categories
    FROM vendors v
    LEFT JOIN vendor_selected_categories vsc ON v.id = vsc.vendor_id
    LEFT JOIN gig_categories c ON vsc.category_id = c.id
    WHERE v.status = 'active'
    GROUP BY v.id
    ORDER BY v.business_name ASC";

$v_res = $conn->query($v_query);
if ($v_res) {
    while ($v = $v_res->fetch_assoc()) $vendors[] = $v;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Premium Allocation | Antigravity</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --dark: #1e293b;
            --bg-light: #f8fafc;
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
            color: var(--dark);
        }

        .main-content { padding: 30px; }

        .glass-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
            padding: 30px;
            margin-bottom: 30px;
        }

        .resource-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            background: var(--bg-light);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--border);
            margin-bottom: 30px;
        }

        .res-label { font-size: 0.7rem; text-transform: uppercase; color: #64748b; font-weight: 800; margin-bottom: 5px; }
        .res-value { font-weight: 700; font-size: 1rem; color: var(--dark); }

        .excel-toolbar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
            background: #fff;
            padding: 15px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .search-input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.2s;
            box-sizing: border-box;
        }

        .search-input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1); }

        .excel-container {
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }

        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .excel-table th {
            background: #f8fafc;
            padding: 15px;
            text-align: left;
            font-weight: 700;
            color: #475569;
            border-bottom: 2px solid var(--border);
            position: sticky;
            top: 0;
        }

        .excel-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .excel-table tr:hover { background: #f1f5f9; cursor: pointer; }
        .excel-table tr.selected { background: #eff6ff; }

        .btn-select {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            font-weight: 700;
            color: var(--dark);
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-select.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        .badge-city {
            background: #eef2ff;
            color: var(--primary);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .badge-match { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

        .action-zone {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px dashed var(--border);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--primary);
        }

        .btn-confirm {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #4361ee, #3f37c9);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 10px 15px -3px rgba(67, 97, 238, 0.4);
            margin-top: 30px;
        }

        .btn-confirm:disabled { opacity: 0.5; cursor: not-allowed; }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 700;
        }

        .alert.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* Custom Scrollbar */
        .excel-scroll { max-height: 400px; overflow-y: auto; overflow-x: auto; }
        .excel-scroll::-webkit-scrollbar { width: 8px; }
        .excel-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .sidebar-toggle {
            display: flex;
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

        @media (max-width: 600px) {
            .action-zone {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</head>
<body>

    <div class="header" style="background: #fff; border-bottom: 1px solid var(--border); padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; gap: 15px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div class="sidebar-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></div>
            <h1 style="margin: 0; font-size: 1.5rem; font-weight: 800;">Strategic Allocation</h1>
        </div>
        <nav>
            <?php
            $returnUrl = 'orders.php';
            if ($type == 'gig') $returnUrl = 'manage_gigs.php';
            if ($type == 'crm_booking') $returnUrl = 'crm_bookings.php';
            ?>
            <a href="<?= $returnUrl ?>" style="text-decoration: none; color: var(--primary); font-weight: 700;">
                <i class="fa-solid fa-chevron-left"></i> Return to Terminal
            </a>
        </nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">
            <?php include 'sidebar_fragment.php'; ?>
            <main class="main-content">
                <?= $msg ?>

                <div class="glass-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <h2 style="margin: 0; font-weight: 800;">Asset Assignment: #<?= $type == 'shop_order' ? $order_id : ($type == 'crm_booking' ? $crm_id : $task_id) ?></h2>
                        <div id="selection-status" style="background: var(--bg-light); padding: 8px 16px; border-radius: 30px; font-weight: 700; font-size: 0.85rem;">
                            Selected: <span id="vendor-label" style="color: var(--primary);">None</span>
                        </div>
                    </div>

                    <div class="resource-overview">
                        <div class="info-block">
                            <div class="res-label">Entity Name</div>
                            <div class="res-value"><?= htmlspecialchars($details['customer_name'] ?? $details['client_name'] ?? $details['name'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-block">
                            <div class="res-label">Operation Zone</div>
                            <div class="res-value" style="color: var(--danger);"><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($city) ?></div>
                        </div>
                        <div class="info-block">
                            <div class="res-label">Baseline Price</div>
                            <div class="res-value" style="color: var(--success);">₹<?= number_format($details['vendor_price'] ?? 0, 2) ?></div>
                        </div>
                        <div class="info-block">
                            <div class="res-label">Requirement</div>
                            <div class="res-value"><?= htmlspecialchars($details['service_title'] ?? 'Full Package') ?></div>
                        </div>
                    </div>

                    <div class="excel-toolbar">
                        <input type="text" id="filter-name" class="search-input" placeholder="🔍 Search Business / Name">
                        <input type="text" id="filter-phone" class="search-input" placeholder="📱 Search Contact Number">
                        <input type="text" id="filter-loc" class="search-input" placeholder="📍 Search Deployment City" value="<?= htmlspecialchars($city) ?>">
                        <input type="text" id="filter-cat" class="search-input" placeholder="🛠️ Search Skills/Categories">
                    </div>

                    <div class="excel-container">
                        <div class="excel-scroll">
                            <table class="excel-table" id="vTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Business Venture</th>
                                        <th>Manager</th>
                                        <th>Connectivity</th>
                                        <th>Location</th>
                                        <th>Categories</th>
                                        <th>Execution</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vendors as $v): 
                                        $isLocMatch = ($city && stripos($v['city'], $city) !== false);
                                    ?>
                                        <tr class="v-row" 
                                            onclick="toggleSelect(this, '<?= $v['id'] ?>', '<?= htmlspecialchars($v['business_name'] ?: $v['name']) ?>')"
                                            data-name="<?= strtolower($v['name']) ?>" 
                                            data-biz="<?= strtolower($v['business_name']) ?>" 
                                            data-phone="<?= $v['phone'] ?>" 
                                            data-loc="<?= strtolower($v['city']) ?>"
                                            data-cat="<?= strtolower($v['categories']) ?>">
                                            <td style="color: #94a3b8; font-weight: 700;">#<?= $v['id'] ?></td>
                                            <td style="font-weight: 800; color: var(--dark);"><?= htmlspecialchars($v['business_name'] ?: 'Proprietor') ?></td>
                                            <td style="font-weight: 600;"><?= htmlspecialchars($v['name']) ?></td>
                                            <td style="color: var(--primary); font-weight: 700;"><?= htmlspecialchars($v['phone']) ?></td>
                                            <td>
                                                <span class="badge-city <?= $isLocMatch ? 'badge-match' : '' ?>">
                                                    <?= htmlspecialchars($v['city']) ?>
                                                </span>
                                            </td>
                                            <td style="font-size: 0.75rem; color: #64748b; font-weight: 600; max-width: 200px;"><?= htmlspecialchars($v['categories']) ?></td>
                                            <td>
                                                <button type="button" class="btn-select">Assign</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form method="POST" id="allocationTerminal">
                        <input type="hidden" name="vendor_id" id="vid" value="" required>
                        <input type="hidden" name="original_price" value="<?= $details['vendor_price'] ?? 0 ?>">

                        <div class="action-zone">
                            <div class="form-group">
                                <label class="res-label">Contract Value (Locked)</label>
                                <input type="text" value="₹<?= number_format($details['vendor_price'] ?? 0, 2) ?>" disabled style="background: #f1f5f9; color: #94a3b8;" class="form-control">
                            </div>
                            <div class="form-group">
                                <label class="res-label">Negotiated Vendor Payout (₹)</label>
                                <input type="number" name="adjusted_price" step="0.01" value="<?= $details['vendor_price'] ?? 0 ?>" required class="form-control">
                            </div>
                        </div>

                        <button type="submit" class="btn-confirm" id="confirmBtn" disabled>Authorize Resource Allocation</button>
                    </form>
                </div>
            </main>
        </div>
    </div>

    <script>
        function toggleSelect(row, id, name) {
            document.querySelectorAll('.v-row').forEach(r => r.classList.remove('selected'));
            document.querySelectorAll('.btn-select').forEach(b => {
                b.textContent = 'Assign';
                b.classList.remove('active');
            });

            row.classList.add('selected');
            const btn = row.querySelector('.btn-select');
            btn.textContent = 'Selected ✓';
            btn.classList.add('active');

            document.getElementById('vid').value = id;
            document.getElementById('vendor-label').textContent = name;
            document.getElementById('confirmBtn').disabled = false;
        }

        const filters = {
            name: document.getElementById('filter-name'),
            phone: document.getElementById('filter-phone'),
            loc: document.getElementById('filter-loc'),
            cat: document.getElementById('filter-cat')
        };

        function processFilters() {
            const criteria = {
                name: filters.name.value.toLowerCase(),
                phone: filters.phone.value.toLowerCase(),
                loc: filters.loc.value.toLowerCase(),
                cat: filters.cat.value.toLowerCase()
            };

            document.querySelectorAll('.v-row').forEach(row => {
                const data = {
                    name: row.dataset.name + ' ' + row.dataset.biz,
                    phone: row.dataset.phone,
                    loc: row.dataset.loc,
                    cat: row.dataset.cat
                };

                const match = data.name.includes(criteria.name) && 
                              data.phone.includes(criteria.phone) && 
                              data.loc.includes(criteria.loc) && 
                              data.cat.includes(criteria.cat);

                row.style.display = match ? '' : 'none';
            });
        }

        Object.values(filters).forEach(f => f.addEventListener('input', processFilters));
        
        // Boot search with existing location if present
        if(filters.loc.value) processFilters();

        document.getElementById('allocationTerminal').onsubmit = function() {
            if(!document.getElementById('vid').value) {
                alert('Strategic Error: No vendor selected for allocation.');
                return false;
            }
            return true;
        }
    </script>
</body>
</html>