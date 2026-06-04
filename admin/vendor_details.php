<?php
session_start();

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once '../db.php';
require_once '../db_main.php';

$vid = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($vid == 0) {
    header("Location: vendors.php");
    exit;
}

// Update logic remains same
if (isset($_POST['update_account_status'])) {
    $new_status = $_POST['status'];
    $conn->query("UPDATE vendors SET status='$new_status' WHERE id=$vid");
}
if (isset($_POST['update_kyc_status'])) {
    $kyc_status = $_POST['kyc_status'];
    $conn->query("UPDATE vendors SET aadhaar_status='$kyc_status' WHERE id=$vid");
}
if (isset($_POST['save_vendor_type'])) {
    $new_vendor_type = $_POST['vendor_type'] ?? '';
    if (in_array($new_vendor_type, ['decoration', 'activity'])) {

        // --- Category IDs ---
        // App Marketplace (surprise_main → categories table)
        $decoration_shop_ids = [1, 4, 5, 6, 16, 17, 18, 19];
        $activity_shop_ids   = [7, 8, 9, 10, 15, 20];

        // Service/Gig (btnevents → gig_categories table)
        $decoration_gig_ids = [1];         // Balloon Decoration
        $activity_gig_ids   = [2, 3, 4];   // Activities & Entertainment, Tattoo Artist, Photographer

        $shop_ids = ($new_vendor_type === 'decoration') ? $decoration_shop_ids : $activity_shop_ids;
        $gig_ids  = ($new_vendor_type === 'decoration') ? $decoration_gig_ids  : $activity_gig_ids;

        // 1. Update vendor_type in vendors table
        $conn->query("UPDATE vendors SET vendor_type='$new_vendor_type' WHERE id=$vid");

        // 2. Replace Shop/Marketplace Categories (vendor_categories — btnevents DB)
        $conn->query("DELETE FROM vendor_categories WHERE vendor_id = $vid");
        foreach ($shop_ids as $sid) {
            $conn->query("INSERT IGNORE INTO vendor_categories (vendor_id, category_id) VALUES ($vid, $sid)");
        }

        // 3. Replace Gig Categories (vendor_selected_categories — btnevents DB)
        $conn->query("DELETE FROM vendor_selected_categories WHERE vendor_id = $vid");
        foreach ($gig_ids as $gid) {
            $conn->query("INSERT IGNORE INTO vendor_selected_categories (vendor_id, category_id) VALUES ($vid, $gid)");
        }

        $msg_cats = "Vendor type set to '" . ucfirst($new_vendor_type) . "' and all categories auto-assigned successfully!";
    } else {
        $err_cats = "Invalid vendor type selected.";
    }
}

// Manage Subscription Submit
if (isset($_POST['manage_subscription'])) {
    $package_id = intval($_POST['package_id']);
    $credits_total = intval($_POST['credits_total']);

    $checkSub = $conn->query("SELECT id FROM vendor_subscriptions WHERE vendor_id = $vid");
    if ($checkSub && $checkSub->num_rows > 0) {
        $sub_id_row = $checkSub->fetch_assoc();
        $sub_id = $sub_id_row['id'];
        $stmt_sub = $conn->prepare("UPDATE vendor_subscriptions SET package_id=?, credits_total=?, credits_remaining=?, starts_at=NOW(), expires_at='2099-12-31 23:59:59', status='active' WHERE id=?");
        $stmt_sub->bind_param("iiii", $package_id, $credits_total, $credits_total, $sub_id);
    } else {
        $stmt_sub = $conn->prepare("INSERT INTO vendor_subscriptions (vendor_id, package_id, credits_total, credits_remaining, starts_at, expires_at, status) VALUES (?, ?, ?, ?, NOW(), '2099-12-31 23:59:59', 'active')");
        $stmt_sub->bind_param("iiii", $vid, $package_id, $credits_total, $credits_total);
    }
    
    if ($stmt_sub->execute()) {
        $msg_sub = "Subscription assigned successfully!";
    } else {
        $err_sub = "Error assigning subscription: " . $conn->error;
    }
}

// Save Price Range for Vendor
if (isset($_POST['save_price_range'])) {
    $min_price = $_POST['min_price'] !== '' ? floatval($_POST['min_price']) : null;
    $max_price = $_POST['max_price'] !== '' ? floatval($_POST['max_price']) : null;
    // Ensure column exists (ALTER TABLE safe)
    $conn->query("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS pkg_min_price DECIMAL(10,2) DEFAULT NULL");
    $conn->query("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS pkg_max_price DECIMAL(10,2) DEFAULT NULL");
    if ($min_price === null && $max_price === null) {
        $conn->query("UPDATE vendors SET pkg_min_price=NULL, pkg_max_price=NULL WHERE id=$vid");
        $msg_range = "Price range cleared. Vendor will see all eligible packages.";
    } else {
        $stmt_range = $conn->prepare("UPDATE vendors SET pkg_min_price=?, pkg_max_price=? WHERE id=?");
        $stmt_range->bind_param("ddi", $min_price, $max_price, $vid);
        $stmt_range->execute();
        $stmt_range->close();
        $msg_range = "Price range set: ₹" . number_format($min_price,2) . " – ₹" . number_format($max_price,2);
    }
}

// Fetch active subscription
$subQuery = $conn->query("
    SELECT vs.*, p.name as package_name 
    FROM vendor_subscriptions vs 
    LEFT JOIN packages p ON vs.package_id = p.id 
    WHERE vs.vendor_id = $vid AND vs.status = 'active' AND vs.credits_remaining > 0
    ORDER BY vs.id DESC LIMIT 1
");
$subscription = ($subQuery && $subQuery->num_rows > 0) ? $subQuery->fetch_assoc() : null;

// Fetch all active packages
$active_packages = [];
$pkQuery = $conn->query("SELECT * FROM packages WHERE status='active' ORDER BY price ASC");
if ($pkQuery) {
    while ($row = $pkQuery->fetch_assoc()) {
        $active_packages[] = $row;
    }
}

// Ensure price range columns exist before fetching
$conn->query("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS pkg_min_price DECIMAL(10,2) DEFAULT NULL");
$conn->query("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS pkg_max_price DECIMAL(10,2) DEFAULT NULL");
$vQuery = $conn->query("SELECT v.*, vw.balance, vw.total_earned FROM vendors v LEFT JOIN vendor_wallet vw ON v.id = vw.vendor_id WHERE v.id = $vid");
$vendor = $vQuery->fetch_assoc();
if (!$vendor) die("Strategic Error: Resource Not Found.");
$pkg_min = $vendor['pkg_min_price'];
$pkg_max = $vendor['pkg_max_price'];

// Category fetching remains same for logic
$vShopCats = [];
$selectedShopCatIds = [];
$scQ = $conn->query("SELECT category_id FROM vendor_categories WHERE vendor_id = $vid");
$scIds = [];
while ($row = $scQ->fetch_assoc()) {
    $scIds[] = $row['category_id'];
    $selectedShopCatIds[] = intval($row['category_id']);
}
if (!empty($scIds)) {
    $scIdStr = implode(',', $scIds);
    $scNq = $mainConn->query("SELECT name FROM categories WHERE id IN ($scIdStr)");
    if ($scNq) {
        while ($row = $scNq->fetch_assoc()) $vShopCats[] = $row['name'];
    }
}

$vGigCats = [];
$selectedGigCatIds = [];
$gcQ = $conn->query("SELECT category_id FROM vendor_selected_categories WHERE vendor_id = $vid");
while ($row = $gcQ->fetch_assoc()) {
    $selectedGigCatIds[] = intval($row['category_id']);
}

$gcQ_names = $conn->query("SELECT gc.name FROM vendor_selected_categories vsc JOIN gig_categories gc ON vsc.category_id = gc.id WHERE vsc.vendor_id = $vid");
if ($gcQ_names) {
    while ($row = $gcQ_names->fetch_assoc()) $vGigCats[] = $row['name'];
}

// Fetch all possible categories for rendering checkboxes
$allShopCats = [];
$ascQ = $mainConn->query("SELECT id, name FROM categories ORDER BY name ASC");
if ($ascQ) {
    while ($row = $ascQ->fetch_assoc()) $allShopCats[] = $row;
}

$allGigCats = [];
$agcQ = $conn->query("SELECT id, name FROM gig_categories ORDER BY name ASC");
if ($agcQ) {
    while ($row = $agcQ->fetch_assoc()) $allGigCats[] = $row;
}

$shopOrders = [];
$soQ = $conn->query("SELECT * FROM order_vendor_notifications WHERE vendor_id = $vid ORDER BY sent_at DESC LIMIT 10");
while ($row = $soQ->fetch_assoc()) $shopOrders[] = $row;

$manualGigs = [];
$mgQ = $conn->query("SELECT mt.*, gc.name as cat_name FROM manual_tasks mt LEFT JOIN gig_categories gc ON mt.category_id = gc.id WHERE mt.assigned_vendor_id = $vid ORDER BY mt.created_at DESC LIMIT 10");
while ($row = $mgQ->fetch_assoc()) $manualGigs[] = $row;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Intelligence: <?= htmlspecialchars($vendor['business_name']) ?></title>
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
            --glass: rgba(255, 255, 255, 0.9);
        }

        body {
            background-color: #f1f5f9;
            font-family: 'Inter', sans-serif;
            color: var(--dark);
        }

        .main-content {
            padding: 30px;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 30px;
        }

        .premium-card {
            background: #fff;
            border-radius: 24px;
            border: 1px solid var(--border);
            padding: 30px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.02);
            margin-bottom: 30px;
        }

        .vendor-avatar {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--primary), #3f37c9);
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            box-shadow: 0 10px 20px rgba(67, 97, 238, 0.3);
        }

        .stat-card {
            background: var(--glass);
            padding: 20px;
            border-radius: 16px;
            border: 1px solid var(--border);
            text-align: left;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
        }

        .stat-val {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--dark);
            display: block;
        }

        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .kyc-img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 16px;
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.3s;
        }

        .kyc-img:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .btn-action {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            border: none;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.2);
        }

        .btn-success {
            background: var(--success);
            color: #fff;
        }

        .btn-danger {
            background: var(--danger);
            color: #fff;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .modern-table th {
            text-align: left;
            padding: 15px;
            background: #f8fafc;
            border-bottom: 2px solid var(--border);
        }

        .modern-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border);
        }

        .skill-chip {
            background: #f1f5f9;
            color: #475569;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
            margin: 2px;
        }

        @media (max-width: 1100px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="header" style="background: #fff; border-bottom: 1px solid var(--border); padding: 15px 30px;">
        <h1 style="margin: 0; font-size: 1.5rem; font-weight: 800;">Vendor Intelligence</h1>
        <nav>
            <a href="vendors.php" style="text-decoration: none; color: var(--primary); font-weight: 700;"><i class="fa-solid fa-chevron-left"></i> Return to Directory</a>
        </nav>
    </div>

    <div class="container">
        <div class="dashboard-layout">
            <?php include 'sidebar_fragment.php'; ?>
            <main class="main-content">

                <div class="profile-grid">
                    <!-- Sidebar Info -->
                    <div class="info-sidebar">
                        <div class="premium-card">
                            <div class="vendor-avatar">
                                <?= strtoupper(substr($vendor['business_name'], 0, 1)) ?>
                            </div>
                            <h2 style="margin: 0; font-weight: 800; font-size: 1.5rem;"><?= htmlspecialchars($vendor['business_name']) ?></h2>
                            <p style="color: #64748b; font-weight: 600; margin-bottom: 20px;">Manager: <?= htmlspecialchars($vendor['name']) ?></p>

                            <div style="display: flex; gap: 10px; margin-bottom: 25px;">
                                <span class="badge badge-<?= $vendor['status'] ?>"><?= ucfirst($vendor['status']) ?></span>
                                <span class="badge badge-<?= $vendor['aadhaar_status'] ?: 'pending' ?>">KYC: <?= ucfirst($vendor['aadhaar_status'] ?: 'Pending') ?></span>
                            </div>

                            <a href="tel:<?= $vendor['phone'] ?>" class="btn-action btn-primary"><i class="fa-solid fa-phone"></i> <?= $vendor['phone'] ?></a>
                            <a href="mailto:<?= $vendor['email'] ?>" class="btn-action" style="background: #eef2ff; color: var(--primary);"><i class="fa-solid fa-envelope"></i> Email Venture</a>
                            <a href="track_vendor.php?id=<?= $vendor['id'] ?>" class="btn-action" style="background: #fdf2f8; color: #db2777;"><i class="fa-solid fa-location-crosshairs"></i> Live Surveillance</a>

                            <div style="margin-top: 30px; padding-top: 30px; border-top: 1px solid var(--border);">
                                <h4 class="stat-label" style="margin-bottom: 15px;">Operational Focus</h4>
                                <div style="margin-bottom: 20px;">
                                    <p class="stat-label" style="font-size: 0.65rem;">🛒 App Marketplace</p>
                                    <?php foreach ($vShopCats as $c): ?><span class="skill-chip"><?= $c ?></span><?php endforeach; ?>
                                </div>
                                <div>
                                    <p class="stat-label" style="font-size: 0.65rem;">🛠️ Service Expertise</p>
                                    <?php foreach ($vGigCats as $c): ?><span class="skill-chip" style="background: #e0f2fe; color: #0369a1;"><?= $c ?></span><?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="premium-card" style="padding: 20px;">
                            <h4 class="stat-label" style="margin-bottom: 15px;">Account Management</h4>
                            <form method="POST" style="display: grid; gap: 10px;">
                                <select name="status" class="btn-action" style="background: #f8fafc; border: 1px solid var(--border); color: var(--dark);">
                                    <option value="active" <?= $vendor['status'] == 'active' ? 'selected' : '' ?>>Set Active</option>
                                    <option value="inactive" <?= $vendor['status'] == 'inactive' ? 'selected' : '' ?>>Set Inactive</option>
                                    <option value="pending" <?= $vendor['status'] == 'pending' ? 'selected' : '' ?>>Set Pending</option>
                                </select>
                                <button type="submit" name="update_account_status" class="btn-action btn-primary">Update Status</button>
                            </form>
                        </div>
                    </div>

                    <!-- Main Dashboard Area -->
                    <div class="main-dashboard">
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                            <div class="stat-card">
                                <span class="stat-label">Liquidity</span>
                                <span class="stat-val">₹<?= number_format($vendor['balance'], 2) ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-label">Total Revenue</span>
                                <span class="stat-val">₹<?= number_format($vendor['total_earned'], 2) ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-label">Task Count</span>
                                <span class="stat-val"><?= count($shopOrders) + count($manualGigs) ?></span>
                            </div>
                        </div>

                        <div class="premium-card">
                            <h3 style="margin-top: 0; font-weight: 800; display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-id-card" style="color: var(--primary);"></i> KYC Verification Hub
                            </h3>
                            <?php if (!$vendor['aadhaar_front']): ?>
                                <div style="background: #fef2f2; border: 2px dashed #fecaca; padding: 40px; border-radius: 16px; text-align: center; color: #991b1b;">
                                    <i class="fa-solid fa-triangle-exclamation fa-2x" style="margin-bottom: 10px;"></i>
                                    <p style="font-weight: 700;">Document Sync Required</p>
                                    <p style="font-size: 0.85rem; opacity: 0.7;">Vendor has not yet uploaded identity credentials for verification.</p>
                                </div>
                            <?php else: ?>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
                                    <div>
                                        <p class="stat-label">Aadhaar Front-End</p>
                                        <img src="<?= $vendor['aadhaar_front'] ?>" class="kyc-img" onclick="window.open(this.src)">
                                    </div>
                                    <div>
                                        <p class="stat-label">Aadhaar Back-End</p>
                                        <img src="<?= $vendor['aadhaar_back'] ?>" class="kyc-img" onclick="window.open(this.src)">
                                    </div>
                                </div>
                                <div style="background: var(--bg-light); padding: 20px; border-radius: 16px; margin-top: 20px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                        <p style="font-weight: 800; margin: 0;">Credential: <?= htmlspecialchars($vendor['aadhaar_number']) ?></p>
                                        <?php if ($vendor['aadhaar_status'] == 'approved' || $vendor['aadhaar_status'] == 'rejected'): ?>
                                            <form method="POST" style="margin: 0;">
                                                <input type="hidden" name="kyc_status" value="pending">
                                                <input type="hidden" name="update_kyc_status" value="1">
                                                <button type="submit" style="background: none; border: none; color: var(--primary); font-size: 0.75rem; font-weight: 700; cursor: pointer; text-decoration: underline; padding: 0;">
                                                    <i class="fa-solid fa-rotate-left"></i> Change Status
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($vendor['aadhaar_status'] == 'approved'): ?>
                                        <div style="display: flex; align-items: center; gap: 12px; color: #166534; background: #dcfce7; padding: 20px; border-radius: 16px; border: 1px solid #bbf7d0;">
                                            <div style="width: 40px; height: 40px; background: #fff; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; box-shadow: 0 4px 6px -1px rgba(22, 101, 52, 0.1);">
                                                <i class="fa-solid fa-circle-check"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 800; font-size: 1rem;">Credentials Verified</div>
                                                <div style="font-size: 0.75rem; font-weight: 600; opacity: 0.8;">Identity document has been officially approved.</div>
                                            </div>
                                        </div>
                                    <?php elseif ($vendor['aadhaar_status'] == 'rejected'): ?>
                                        <div style="display: flex; align-items: center; gap: 12px; color: #991b1b; background: #fee2e2; padding: 20px; border-radius: 16px; border: 1px solid #fecaca;">
                                            <div style="width: 40px; height: 40px; background: #fff; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; box-shadow: 0 4px 6px -1px rgba(153, 27, 27, 0.1);">
                                                <i class="fa-solid fa-circle-xmark"></i>
                                            </div>
                                            <div>
                                                <div style="font-weight: 800; font-size: 1rem;">Submission Rejected</div>
                                                <div style="font-size: 0.75rem; font-weight: 600; opacity: 0.8;">Credentials were declined due to discrepancy.</div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" style="display: flex; gap: 15px;">
                                            <button type="submit" name="kyc_status" value="approved" class="btn-action btn-success"><i class="fa-solid fa-check"></i> Approve Credentials</button>
                                            <button type="submit" name="kyc_status" value="rejected" class="btn-action btn-danger"><i class="fa-solid fa-xmark"></i> Reject Submission</button>
                                            <input type="hidden" name="update_kyc_status" value="1">
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Vendor Type & Category Hub -->
                        <div class="premium-card" id="vendor-type-card">
                            <h3 style="margin-top: 0; font-weight: 800; display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <i class="fa-solid fa-layer-group" style="color: var(--primary);"></i> Vendor Type & Category Assignment
                            </h3>
                            <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 24px;">Select vendor type — all relevant categories across both platforms will be auto-assigned instantly.</p>

                            <?php if (isset($msg_cats)): ?>
                                <div style="background: #ecfdf5; color: #065f46; padding: 14px 18px; border-radius: 12px; border: 1px solid #6ee7b7; margin-bottom: 20px; font-weight: 700; font-size: 0.875rem; display: flex; align-items: center; gap: 10px;">
                                    <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg_cats) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($err_cats)): ?>
                                <div style="background: #fef2f2; color: #991b1b; padding: 14px 18px; border-radius: 12px; border: 1px solid #fecaca; margin-bottom: 20px; font-weight: 700; font-size: 0.875rem; display: flex; align-items: center; gap: 10px;">
                                    <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($err_cats) ?>
                                </div>
                            <?php endif; ?>

                            <?php $currentVendorType = $vendor['vendor_type'] ?? 'decoration'; ?>

                            <form method="POST" id="vendorTypeForm">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px;">

                                    <!-- Option 1: Decoration -->
                                    <label for="type_decoration" class="vtype-card <?= $currentVendorType === 'decoration' ? 'vtype-selected' : '' ?>" id="label_decoration">
                                        <input type="radio" name="vendor_type" id="type_decoration" value="decoration" <?= $currentVendorType === 'decoration' ? 'checked' : '' ?> onchange="handleTypeChange(this)" style="display:none;">
                                        <div class="vtype-icon" style="background: linear-gradient(135deg, #4361ee, #3a0ca3);">
                                            🎈
                                        </div>
                                        <div class="vtype-title">Decoration</div>
                                        <div class="vtype-sub">Balloon & Event Decoration</div>
                                        <div class="vtype-cats">
                                            <div class="vtype-cats-section">
                                                <span class="vtype-cats-label">🛒 Marketplace</span>
                                                <div>Couple's Special, Festival, Kid's Special, Premium Setup, Welcome Baby, Office Fest, Halloween, Adults</div>
                                            </div>
                                            <div class="vtype-cats-section" style="margin-top:8px;">
                                                <span class="vtype-cats-label">🛠️ Gig</span>
                                                <div>Balloon Decoration</div>
                                            </div>
                                        </div>
                                    </label>

                                    <!-- Option 2: Activity -->
                                    <label for="type_activity" class="vtype-card <?= $currentVendorType === 'activity' ? 'vtype-selected' : '' ?>" id="label_activity">
                                        <input type="radio" name="vendor_type" id="type_activity" value="activity" <?= $currentVendorType === 'activity' ? 'checked' : '' ?> onchange="handleTypeChange(this)" style="display:none;">
                                        <div class="vtype-icon" style="background: linear-gradient(135deg, #10b981, #047857);">
                                            🎭
                                        </div>
                                        <div class="vtype-title">Activity</div>
                                        <div class="vtype-sub">Entertainment & Services</div>
                                        <div class="vtype-cats">
                                            <div class="vtype-cats-section">
                                                <span class="vtype-cats-label">🛒 Marketplace</span>
                                                <div>Activity, Catering, Photography, Videography, DJ Service, Special Entry</div>
                                            </div>
                                            <div class="vtype-cats-section" style="margin-top:8px;">
                                                <span class="vtype-cats-label">🛠️ Gig</span>
                                                <div>Activities & Entertainment, Tattoo Artist, Photographer</div>
                                            </div>
                                        </div>
                                    </label>

                                </div>

                                <!-- Preview of what will be assigned -->
                                <div id="assignPreview" style="background: #f8fafc; border: 1px solid var(--border); border-radius: 14px; padding: 18px; margin-bottom: 20px; display: none;">
                                    <p style="font-weight: 800; font-size: 0.85rem; margin: 0 0 12px 0; color: #1e293b;"><i class="fa-solid fa-eye" style="color: var(--primary);"></i> Will be assigned:</p>
                                    <div id="previewContent" style="font-size: 0.82rem; color: #475569; display: grid; grid-template-columns: 1fr 1fr; gap: 12px;"></div>
                                </div>

                                <div style="display: flex; justify-content: flex-end;">
                                    <button type="submit" name="save_vendor_type" id="saveTypeBtn" class="btn-action btn-primary" style="margin: 0; width: auto; padding: 12px 28px; font-size: 0.875rem; border-radius: 10px; gap: 8px;">
                                        <i class="fa-solid fa-bolt"></i> Apply Type & Auto-Assign Categories
                                    </button>
                                </div>
                            </form>
                        </div>

                        <style>
                        .vtype-card {
                            display: flex;
                            flex-direction: column;
                            align-items: flex-start;
                            gap: 8px;
                            padding: 22px;
                            border: 2px solid var(--border);
                            border-radius: 18px;
                            cursor: pointer;
                            transition: all 0.25s ease;
                            background: #fff;
                            position: relative;
                        }
                        .vtype-card:hover {
                            border-color: #93c5fd;
                            box-shadow: 0 4px 20px rgba(67, 97, 238, 0.1);
                            transform: translateY(-2px);
                        }
                        .vtype-selected {
                            border-color: var(--primary) !important;
                            background: #eef2ff !important;
                            box-shadow: 0 4px 20px rgba(67, 97, 238, 0.15) !important;
                        }
                        .vtype-selected::after {
                            content: '✓';
                            position: absolute;
                            top: 14px;
                            right: 16px;
                            width: 24px;
                            height: 24px;
                            background: var(--primary);
                            color: #fff;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-size: 13px;
                            font-weight: 800;
                        }
                        .vtype-icon {
                            width: 52px;
                            height: 52px;
                            border-radius: 14px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-size: 24px;
                            margin-bottom: 4px;
                        }
                        .vtype-title {
                            font-size: 1.05rem;
                            font-weight: 800;
                            color: #1e293b;
                        }
                        .vtype-sub {
                            font-size: 0.78rem;
                            color: #64748b;
                            font-weight: 600;
                            margin-bottom: 8px;
                        }
                        .vtype-cats {
                            width: 100%;
                            background: rgba(0,0,0,0.03);
                            border-radius: 10px;
                            padding: 12px;
                            font-size: 0.76rem;
                            color: #475569;
                            line-height: 1.5;
                        }
                        .vtype-cats-label {
                            font-weight: 800;
                            color: #334155;
                            display: block;
                            margin-bottom: 2px;
                            font-size: 0.72rem;
                            text-transform: uppercase;
                            letter-spacing: 0.3px;
                        }
                        .vtype-cats-section {
                            padding: 0;
                        }
                        </style>

                        <div class="premium-card">
                            <h3 style="margin-top: 0; font-weight: 800; display: flex; align-items: center; gap: 10px;">
                                <i class="fa-solid fa-box-open" style="color: var(--primary);"></i> Active Subscription & Allocation Limits
                            </h3>
                            
                            <?php if (isset($msg_sub)): ?>
                                <div style="background: #ecfdf5; color: #065f46; padding: 1rem; border-radius: 12px; border: 1px solid #6ee7b7; margin: 15px 0; font-weight: 700;">
                                    <?= htmlspecialchars($msg_sub) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($err_sub)): ?>
                                <div style="background: #fef2f2; color: #991b1b; padding: 1rem; border-radius: 12px; border: 1px solid #fecaca; margin: 15px 0; font-weight: 700;">
                                    <?= htmlspecialchars($err_sub) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($subscription): ?>
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin: 20px 0;">
                                    <div style="background: rgba(79, 70, 229, 0.05); padding: 20px; border-radius: 16px; border: 1px solid rgba(79, 70, 229, 0.1);">
                                        <span class="stat-label">Active Package</span>
                                        <span style="font-size: 1.4rem; font-weight: 800; color: var(--primary); display: block; margin-top: 5px;">
                                            <?= htmlspecialchars($subscription['package_name']) ?>
                                        </span>
                                        <span class="badge badge-active" style="display: inline-block; margin-top: 10px;">Status: Active</span>
                                    </div>
                                    
                                    <div style="background: rgba(16, 185, 129, 0.05); padding: 20px; border-radius: 16px; border: 1px solid rgba(16, 185, 129, 0.1);">
                                        <span class="stat-label">Task Credits Usage</span>
                                        <span style="font-size: 1.4rem; font-weight: 800; color: var(--success); display: block; margin-top: 5px;">
                                            <?= intval($subscription['credits_remaining']) ?> / <?= intval($subscription['credits_total']) ?> Remaining
                                        </span>
                                        <div style="background: #e2e8f0; height: 8px; border-radius: 4px; margin-top: 15px; overflow: hidden;">
                                            <?php 
                                            $pct = ($subscription['credits_total'] > 0) ? ($subscription['credits_remaining'] / $subscription['credits_total']) * 100 : 0;
                                            ?>
                                            <div style="background: var(--success); width: <?= $pct ?>%; height: 100%;"></div>
                                        </div>
                                    </div>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 25px;">
                                    <div>
                                        <span class="stat-label" style="font-size: 0.65rem;">Subscription Activated At</span>
                                        <strong style="display: block; font-size: 0.9rem; margin-top: 2px;"><?= date('d M Y, h:i A', strtotime($subscription['starts_at'])) ?></strong>
                                    </div>
                                    <div>
                                        <span class="stat-label" style="font-size: 0.65rem;">Subscription Expires At</span>
                                        <strong style="display: block; font-size: 0.9rem; margin-top: 2px;"><?= date('d M Y, h:i A', strtotime($subscription['expires_at'])) ?></strong>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="background: #f1f5f9; border: 1px dashed var(--border); padding: 30px; border-radius: 16px; text-align: center; color: #64748b; margin: 20px 0;">
                                    <i class="fa-solid fa-box-open fa-2x" style="margin-bottom: 10px; opacity: 0.5;"></i>
                                    <p style="font-weight: 700;">No Active Subscription Package</p>
                                    <p style="font-size: 0.85rem; opacity: 0.7;">Vendor does not have any active allocations. Assign one below.</p>
                                </div>
                            <?php endif; ?>

                            <!-- Manual Assignment / Override Section -->
                            <div style="border-top: 1px solid var(--border); padding-top: 25px; margin-top: 25px;">
                                <h4 style="margin-top: 0; margin-bottom: 15px; font-weight: 800; color: #1e293b;">
                                    <i class="fa-solid fa-user-gear" style="color: var(--warning);"></i> Manual Assignment / Override
                                </h4>
                                <form method="POST" style="display: grid; gap: 15px;">
                                    <input type="hidden" name="expires_at" value="2099-12-31">
                                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px;">
                                        <div>
                                            <label class="stat-label" style="font-size: 0.65rem; display: block; margin-bottom: 5px;">Select Package</label>
                                            <select name="package_id" id="package_select" class="protocol-input" style="padding: 10px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.85rem;" required>
                                                <option value="" disabled selected>-- Choose Package --</option>
                                                <?php foreach ($active_packages as $pkg): ?>
                                                    <option value="<?= $pkg['id'] ?>" data-credits="<?= $pkg['task_credits'] ?>">
                                                        <?= htmlspecialchars($pkg['name']) ?> (₹<?= number_format($pkg['price'], 2) ?> - <?= $pkg['task_credits'] ?> Credits)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="stat-label" style="font-size: 0.65rem; display: block; margin-bottom: 5px;">Credits</label>
                                            <input type="number" min="0" name="credits_total" id="custom_credits" class="protocol-input" style="padding: 10px; border-radius: 8px; border: 1px solid var(--border); font-size: 0.85rem;" placeholder="Credits" required>
                                        </div>
                                    </div>
                                    <div style="display: flex; justify-content: flex-end;">
                                        <button type="submit" name="manage_subscription" class="btn-action btn-primary" style="margin: 0; width: auto; padding: 10px 24px; font-size: 0.85rem; border-radius: 8px;">
                                            <i class="fa-solid fa-save"></i> Assign & Activate Subscription
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Package Price Range Control -->
                            <div style="border-top: 1px solid var(--border); padding-top: 25px; margin-top: 25px;">
                                <h4 style="margin-top: 0; margin-bottom: 6px; font-weight: 800; color: #1e293b; display:flex; align-items:center; gap:8px;">
                                    <i class="fa-solid fa-filter-circle-dollar" style="color: #8b5cf6;"></i> Package Visibility Range
                                </h4>
                                <p style="font-size:0.8rem; color:#64748b; margin:0 0 16px;">Set the price range of packages this vendor can see on their dashboard. Leave both blank to show all eligible packages.</p>

                                <?php if (isset($msg_range)): ?>
                                    <div style="background:#ecfdf5; color:#065f46; padding:12px 16px; border-radius:10px; border:1px solid #6ee7b7; margin-bottom:15px; font-weight:700; font-size:0.85rem; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg_range) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($pkg_min !== null || $pkg_max !== null): ?>
                                    <div style="background: linear-gradient(135deg, rgba(139,92,246,0.08), rgba(67,97,238,0.06)); border:1px solid rgba(139,92,246,0.2); padding:14px 18px; border-radius:12px; margin-bottom:15px; display:flex; align-items:center; gap:12px;">
                                        <i class="fa-solid fa-tag" style="color:#8b5cf6;"></i>
                                        <span style="font-weight:700; font-size:0.85rem; color:#1e293b;">
                                            Current Range: 
                                            <span style="color:#8b5cf6;">₹<?= number_format($pkg_min ?? 0, 2) ?></span>
                                            &nbsp;→&nbsp;
                                            <span style="color:#8b5cf6;">₹<?= number_format($pkg_max ?? 9999999, 2) ?></span>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div style="background:#f8fafc; border:1px dashed var(--border); padding:12px 16px; border-radius:10px; margin-bottom:15px; font-size:0.82rem; color:#64748b; display:flex; align-items:center; gap:8px;">
                                        <i class="fa-solid fa-infinity"></i> No range set — vendor sees all packages matching their category.
                                    </div>
                                <?php endif; ?>

                                <form method="POST" style="display:grid; gap:12px;">
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                                        <div>
                                            <label class="stat-label" style="font-size:0.65rem; display:block; margin-bottom:5px;">Min Price (₹)</label>
                                            <input type="number" min="0" step="0.01" name="min_price" placeholder="e.g. 500" value="<?= htmlspecialchars($pkg_min ?? '') ?>" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border); font-size:0.85rem; box-sizing:border-box;">
                                        </div>
                                        <div>
                                            <label class="stat-label" style="font-size:0.65rem; display:block; margin-bottom:5px;">Max Price (₹)</label>
                                            <input type="number" min="0" step="0.01" name="max_price" placeholder="e.g. 5000" value="<?= htmlspecialchars($pkg_max ?? '') ?>" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border); font-size:0.85rem; box-sizing:border-box;">
                                        </div>
                                    </div>
                                    <div style="display:flex; gap:10px; justify-content:flex-end;">
                                        <button type="submit" name="save_price_range" class="btn-action btn-primary" style="margin:0; width:auto; padding:10px 20px; font-size:0.85rem; border-radius:8px; background:#8b5cf6; box-shadow:0 4px 12px rgba(139,92,246,0.25);">
                                            <i class="fa-solid fa-filter"></i> Set Range
                                        </button>
                                        <button type="submit" name="save_price_range" onclick="document.querySelector('[name=min_price]').value=''; document.querySelector('[name=max_price]').value='';" class="btn-action" style="margin:0; width:auto; padding:10px 20px; font-size:0.85rem; border-radius:8px; background:#f1f5f9; color:#475569; border:none;">
                                            <i class="fa-solid fa-xmark"></i> Clear Range
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="premium-card" style="padding: 0; overflow: hidden;">
                            <div style="padding: 25px; border-bottom: 1px solid var(--border);">
                                <h3 style="margin: 0; font-weight: 800;">Recent Project History</h3>
                            </div>
                            <div style="padding: 10px;">
                                <table class="modern-table">
                                    <thead>
                                        <tr>
                                            <th>Deployment ID</th>
                                            <th>Classification</th>
                                            <th>Strategy/Service</th>
                                            <th>Valuation</th>
                                            <th>Condition</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($manualGigs as $gig): ?>
                                            <tr>
                                                <td style="font-weight: 700; color: #64748b;">#MG-<?= $gig['id'] ?></td>
                                                <td><span class="skill-chip">Manual Gig</span></td>
                                                <td style="font-weight: 700;"><?= htmlspecialchars($gig['service_title']) ?></td>
                                                <td style="font-weight: 800; color: var(--success);">₹<?= number_format($gig['vendor_price']) ?></td>
                                                <td><span class="badge" style="background: #eef2ff; color: var(--primary);"><?= ucfirst($gig['status']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php foreach ($shopOrders as $so): ?>
                                            <tr>
                                                <td style="font-weight: 700; color: #64748b;">#SO-<?= $so['order_id'] ?></td>
                                                <td><span class="skill-chip" style="background: #fdf2f8; color: #db2777;">Shop Order</span></td>
                                                <td style="font-weight: 700;">Marketplace Fulfilment</td>
                                                <td style="font-weight: 800; color: var(--success);">-</td>
                                                <td><span class="badge" style="background: #f0fdf4; color: #166534;">Accepted</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>

            </main>
        </div>
    </div>

    <script>
        document.getElementById('package_select')?.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const credits = selected.getAttribute('data-credits') || 0;
            const customCreditsInput = document.getElementById('custom_credits');
            if (customCreditsInput) customCreditsInput.value = credits;
        });

        const DECORATION_PREVIEW = {
            shop: ['Couple\'s Special', 'Festival Decorations', 'Kid\'s Special', 'Premium Setup', 'Welcome Baby', 'Office Fest', 'Halloween', 'Adults Decorations'],
            gig:  ['Balloon Decoration']
        };
        const ACTIVITY_PREVIEW = {
            shop: ['Activity', 'Catering', 'Photography', 'Videography', 'DJ Service', 'Special Entry'],
            gig:  ['Activities & Entertainment', 'Tattoo Artist', 'Photographer']
        };

        function handleTypeChange(radio) {
            // Update card styles
            document.querySelectorAll('.vtype-card').forEach(c => c.classList.remove('vtype-selected'));
            const label = document.getElementById('label_' + radio.value);
            if (label) label.classList.add('vtype-selected');

            // Show preview
            const preview = document.getElementById('assignPreview');
            const content = document.getElementById('previewContent');
            const data = radio.value === 'decoration' ? DECORATION_PREVIEW : ACTIVITY_PREVIEW;

            content.innerHTML = `
                <div>
                    <div style="font-weight:700; color:#4361ee; margin-bottom:6px; font-size:0.78rem;">🛒 Marketplace Categories (${data.shop.length})</div>
                    ${data.shop.map(s => `<span style="display:inline-block; background:#eef2ff; color:#4361ee; padding:3px 8px; border-radius:6px; font-size:0.75rem; margin:2px; font-weight:600;">${s}</span>`).join('')}
                </div>
                <div>
                    <div style="font-weight:700; color:#10b981; margin-bottom:6px; font-size:0.78rem;">🛠️ Gig/Service Categories (${data.gig.length})</div>
                    ${data.gig.map(g => `<span style="display:inline-block; background:#ecfdf5; color:#047857; padding:3px 8px; border-radius:6px; font-size:0.75rem; margin:2px; font-weight:600;">${g}</span>`).join('')}
                </div>
            `;
            preview.style.display = 'block';
        }

        // Show preview on page load if type already selected
        const checkedType = document.querySelector('input[name="vendor_type"]:checked');
        if (checkedType) handleTypeChange(checkedType);
    </script>
</body>

</html>