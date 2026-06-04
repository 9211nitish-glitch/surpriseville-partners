<?php
session_start();
// admin/manage_packages.php

// Check Admin Login
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once "../db.php";
require_once "../db_main.php";

$msg = "";
$err = "";

// --- DELETE PACKAGE ---
if (isset($_GET['del'])) {
    $del_id = intval($_GET['del']);
    $conn->query("DELETE FROM package_categories WHERE package_id = $del_id");
    if ($conn->query("DELETE FROM packages WHERE id = $del_id")) {
        $msg = "Package deleted successfully!";
    } else {
        $err = "Error deleting package: " . $conn->error;
    }
}

// --- TOGGLE STATUS ---
if (isset($_GET['toggle_status'])) {
    $toggle_id = intval($_GET['toggle_status']);
    $res = $conn->query("SELECT status FROM packages WHERE id = $toggle_id");
    if ($res && $row = $res->fetch_assoc()) {
        $new_status = ($row['status'] == 'active') ? 'inactive' : 'active';
        $conn->query("UPDATE packages SET status = '$new_status' WHERE id = $toggle_id");
        $msg = "Package status updated!";
    }
}

// --- ADD / EDIT PACKAGE SUBMIT ---
if (isset($_POST['save_package'])) {
    $package_id   = isset($_POST['package_id']) ? intval($_POST['package_id']) : 0;
    $name         = trim($_POST['name']);
    $price        = floatval($_POST['price']);
    $task_credits = intval($_POST['task_credits']);
    $validity_days = 36500;
    $status       = $_POST['status'];
    $description  = trim($_POST['description']);
    $order_min    = $_POST['order_min'] !== '' ? floatval($_POST['order_min']) : null;
    $order_max    = $_POST['order_max'] !== '' ? floatval($_POST['order_max']) : null;

    // Ensure columns exist
    $conn->query("ALTER TABLE packages ADD COLUMN IF NOT EXISTS order_min_price DECIMAL(10,2) DEFAULT NULL");
    $conn->query("ALTER TABLE packages ADD COLUMN IF NOT EXISTS order_max_price DECIMAL(10,2) DEFAULT NULL");

    if (empty($name)) {
        $err = "Package name is required.";
    } else {
        if ($package_id > 0) {
            $stmt = $conn->prepare("UPDATE packages SET name=?, price=?, task_credits=?, validity_days=?, status=?, description=?, order_min_price=?, order_max_price=? WHERE id=?");
            $stmt->bind_param("sdiissdd i", $name, $price, $task_credits, $validity_days, $status, $description, $order_min, $order_max, $package_id);
            if ($stmt->execute()) { $msg = "Package updated successfully!"; } else { $err = "Error updating package: " . $conn->error; }
        } else {
            $stmt = $conn->prepare("INSERT INTO packages (name, price, task_credits, validity_days, status, description, order_min_price, order_max_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sdiissdd", $name, $price, $task_credits, $validity_days, $status, $description, $order_min, $order_max);
            if ($stmt->execute()) { $package_id = $conn->insert_id; $msg = "Package created successfully!"; } else { $err = "Error creating package: " . $conn->error; }
        }

        if ($package_id > 0) {
            $conn->query("DELETE FROM package_categories WHERE package_id = $package_id");
            if (isset($_POST['categories']) && is_array($_POST['categories'])) {
                $stmt_cat = $conn->prepare("INSERT INTO package_categories (package_id, category_id, subcategory_id) VALUES (?, ?, ?)");
                foreach ($_POST['categories'] as $cat_id) {
                    $cat_id = intval($cat_id);
                    if (isset($_POST['subcategories'][$cat_id]) && is_array($_POST['subcategories'][$cat_id]) && count($_POST['subcategories'][$cat_id]) > 0) {
                        foreach ($_POST['subcategories'][$cat_id] as $subcat_id) {
                            $subcat_id = intval($subcat_id);
                            $stmt_cat->bind_param("iii", $package_id, $cat_id, $subcat_id);
                            $stmt_cat->execute();
                        }
                    } else {
                        $null_val = null;
                        $stmt_cat->bind_param("iii", $package_id, $cat_id, $null_val);
                        $stmt_cat->execute();
                    }
                }
            }
        }
    }
}

// --- FETCH CATEGORIES AND SUBCATEGORIES FROM MAIN DB ---
$main_categories = [];
$cat_names = [];
$subcat_names = [];

$res_cats = $mainConn->query("SELECT id, name, type FROM categories ORDER BY name ASC");
if ($res_cats) {
    while ($row = $res_cats->fetch_assoc()) {
        $main_categories[$row['id']] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'type' => $row['type'] ?? 'decoration',
            'subcategories' => []
        ];
        $cat_names[$row['id']] = $row['name'];
    }
}

$res_sub = $mainConn->query("SELECT id, category_id, name FROM subcategories ORDER BY name ASC");
if ($res_sub) {
    while ($row = $res_sub->fetch_assoc()) {
        $cat_id = $row['category_id'];
        if (isset($main_categories[$cat_id])) {
            $main_categories[$cat_id]['subcategories'][] = $row;
        }
        $subcat_names[$row['id']] = $row['name'];
    }
}

// Categorize into Activities vs Decorations
$activity_categories = [];
$decoration_categories = [];

function isActivityCategory($catId, $name, $dbType = null) {
    if ($dbType !== null) {
        return ($dbType === 'addon');
    }
    // Exact IDs matching surpriseville.co.in structure
    $activityIds = [7, 8, 9, 10, 15, 20];
    if (in_array(intval($catId), $activityIds)) {
        return true;
    }
    
    $decorIds = [1, 4, 5, 6, 16, 17, 18, 19];
    if (in_array(intval($catId), $decorIds)) {
        return false;
    }

    // Fallback heuristic based on name
    $nameLower = strtolower($name);
    $activityKeywords = ['activity', 'catering', 'photography', 'videography', 'dj', 'entry', 'music', 'sound', 'mascot', 'magician', 'clown', 'artist', 'show'];
    foreach ($activityKeywords as $kw) {
        if (strpos($nameLower, $kw) !== false) {
            return true;
        }
    }
    return false; // Default to decoration
}

foreach ($main_categories as $cat) {
    if (isActivityCategory($cat['id'], $cat['name'], $cat['type'])) {
        $activity_categories[] = $cat;
    } else {
        $decoration_categories[] = $cat;
    }
}

// --- FETCH ALL PACKAGES ---
// Ensure columns exist before fetching
$conn->query("ALTER TABLE packages ADD COLUMN IF NOT EXISTS order_min_price DECIMAL(10,2) DEFAULT NULL");
$conn->query("ALTER TABLE packages ADD COLUMN IF NOT EXISTS order_max_price DECIMAL(10,2) DEFAULT NULL");
$packagesResult = $conn->query("SELECT * FROM packages ORDER BY price ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Packages | Surprise Ville</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass: rgba(255, 255, 255, 0.75);
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

        .dashboard-container {
            display: flex;
            gap: 2rem;
            padding: 2.5rem;
            max-width: 1400px;
            margin: 0 auto;
        }

        .main-content { flex: 1; min-width: 0; }

        .premium-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border-radius: 32px;
            border: 1px solid var(--glass-border);
            padding: 2.5rem;
            box-shadow: var(--shadow);
            margin-bottom: 2.5rem;
        }

        .table-responsive { overflow-x: auto; border-radius: 24px; }
        .modern-table { width: 100%; border-collapse: collapse; }
        .modern-table th {
            background: rgba(248, 250, 252, 0.5);
            padding: 1.25rem 1.5rem;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--glass-border);
        }
        .modern-table td { 
            padding: 1.25rem 1.5rem; 
            border-bottom: 1px solid rgba(226, 232, 240, 0.5);
            vertical-align: middle;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .badge-all { background: #f1f5f9; color: #475569; }

        .skill-chip {
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
            margin: 2px;
        }

        .btn-add {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.9rem 1.8rem;
            border-radius: 16px;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
            font-family: inherit;
        }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(79, 70, 229, 0.3); }

        .btn-action-small {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            margin-right: 4px;
        }
        .btn-edit-small { color: var(--primary); }
        .btn-edit-small:hover { background: var(--primary); color: white; border-color: var(--primary); transform: scale(1.05); }
        
        .btn-toggle-small { color: var(--warning); }
        .btn-toggle-small:hover { background: var(--warning); color: white; border-color: var(--warning); transform: scale(1.05); }

        .btn-delete-small { color: var(--danger); }
        .btn-delete-small:hover { background: var(--danger); color: white; border-color: var(--danger); transform: scale(1.05); }

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

        /* Modal styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(8px);
        }
        .modal-content {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(16px);
            margin: 5% auto;
            padding: 2.5rem;
            border-radius: 32px;
            width: 90%;
            max-width: 650px;
            box-shadow: 0 20px 45px -5px rgba(0, 0, 0, 0.2);
            position: relative;
            animation: modalFadeIn 0.3s ease-out;
        }
        @keyframes modalFadeIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-close {
            position: absolute;
            top: 24px;
            right: 24px;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
            transition: color 0.2s;
        }
        .modal-close:hover { color: var(--danger); }

        .protocol-input {
            width: 100%;
            padding: 1.1rem;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            font-family: inherit;
            font-weight: 600;
            background: white;
            outline: none;
            transition: all 0.2s;
        }
        .protocol-input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); }

        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-size: 0.65rem;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .checkbox-container {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem;
            max-height: 200px;
            overflow-y: auto;
        }
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 12px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
        }
        .checkbox-item input {
            cursor: pointer;
            width: 16px;
            height: 16px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .dashboard-container { flex-direction: column; padding: 1.5rem; gap: 1rem; }
            .header { padding: 1rem 1.5rem; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
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
                <i class="fa-solid fa-box-open"></i>
            </div>
            <h1 style="margin: 0; font-size: 1.4rem; font-weight: 800; letter-spacing: -0.5px;">Manage Packages</h1>
        </div>
        <nav style="display: flex; align-items: center; gap: 20px;">
            <span style="font-weight: 700; color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-user-shield"></i> Welcome Admin</span>
        </nav>
    </header>

    <div class="dashboard-container">
        <?php include 'sidebar_fragment.php'; ?>
        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="margin: 0; font-weight: 800; font-size: 1.8rem; letter-spacing: -0.5px;">Vendor Packages</h2>
                    <p style="color: #64748b; font-weight: 600; margin-top: 5px;">Configure vendor subscription packages & limits</p>
                </div>
                <button onclick="openCreateModal()" class="btn-add">
                    <i class="fa-solid fa-plus"></i> Create Package
                </button>
            </div>

            <?php if (empty($main_categories)): ?>
                <div style="background: #fefce8; color: #854d0e; padding: 1.25rem; border-radius: 20px; border: 1px solid #fde68a; margin-bottom: 2rem; font-weight: 600; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 1.2rem; color: #d97706;"></i>
                    <span>
                        <strong>Categories Not Loaded:</strong> The category list from your main site (surpriseville.co.in) could not be fetched.
                        Please <a href="fix_packages_db.php" style="color: #4f46e5; font-weight: 800;">run the DB fix script</a> or
                        <a href="debug_packages.php" style="color: #4f46e5; font-weight: 800;">view diagnostics</a> to identify the issue.
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($msg): ?>
                <div style="background: #ecfdf5; color: #065f46; padding: 1.25rem; border-radius: 20px; border: 1px solid #6ee7b7; margin-bottom: 2rem; font-weight: 700; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>

            <?php if ($err): ?>
                <div style="background: #fef2f2; color: #991b1b; padding: 1.25rem; border-radius: 20px; border: 1px solid #fecaca; margin-bottom: 2rem; font-weight: 700; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-circle-xmark" style="font-size: 1.2rem;"></i> <?= htmlspecialchars($err) ?>
                </div>
            <?php endif; ?>

            <div class="premium-card" style="padding: 0;">
                <div class="table-responsive">
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Price</th>
                                <th>Task Credits</th>
                                <th>Order Amount Range</th>
                                <th>Allowed Categories</th>
                                <th>Status</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($packagesResult && $packagesResult->num_rows > 0): ?>
                                <?php while ($row = $packagesResult->fetch_assoc()): 
                                    // Fetch allowed categories and subcategories for this package
                                    $p_id = $row['id'];
                                    $cat_res = $conn->query("SELECT category_id, subcategory_id FROM package_categories WHERE package_id = $p_id");
                                    $allowed_data = [];
                                    $allowed_names = [];
                                    if ($cat_res) {
                                        while ($c_row = $cat_res->fetch_assoc()) {
                                            $c_id = intval($c_row['category_id']);
                                            $s_id = $c_row['subcategory_id'] !== null ? intval($c_row['subcategory_id']) : null;
                                            $allowed_data[] = ['category_id' => $c_id, 'subcategory_id' => $s_id];
                                            
                                            $c_name = isset($cat_names[$c_id]) ? $cat_names[$c_id] : "Unknown Cat #$c_id";
                                            if ($s_id === null) {
                                                $allowed_names[] = $c_name . " (All)";
                                            } else {
                                                $s_name = isset($subcat_names[$s_id]) ? $subcat_names[$s_id] : "Unknown Subcat #$s_id";
                                                $allowed_names[] = $c_name . " &raquo; " . $s_name;
                                            }
                                        }
                                    }
                                    $row['allowed_items'] = $allowed_data;
                                    $json_data = json_encode($row);
                                ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 800; color: #0f172a; font-size: 1.1rem;"><?= htmlspecialchars($row['name']) ?></div>
                                            <?php if ($row['description']): ?>
                                                <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;"><?= htmlspecialchars($row['description']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-weight: 800; color: #475569;">₹<?= number_format($row['price'], 2) ?></td>
                                        <td style="font-weight: 700; color: var(--primary);"><?= number_format($row['task_credits']) ?> Credits</td>
                                        <td>
                                            <?php
                                            $rMin = $row['order_min_price'] ?? null;
                                            $rMax = $row['order_max_price'] ?? null;
                                            if ($rMin === null && $rMax === null) {
                                                echo '<span class="status-badge badge-all">All Amounts</span>';
                                            } elseif ($rMin !== null && $rMax !== null) {
                                                echo '<span style="font-weight:800; font-size:0.85rem;">₹' . number_format($rMin,0) . ' – ₹' . number_format($rMax,0) . '</span>';
                                            } elseif ($rMin !== null) {
                                                echo '<span style="font-weight:700; font-size:0.85rem;">Min ₹' . number_format($rMin,0) . '</span>';
                                            } else {
                                                echo '<span style="font-weight:700; font-size:0.85rem;">Max ₹' . number_format($rMax,0) . '</span>';
                                            }
                                            ?>
                                        </td>
                                            <?php 
                                            if (empty($allowed_names)) {
                                                echo '<span class="status-badge badge-all">All Categories</span>';
                                            } else {
                                                foreach ($allowed_names as $name_str) {
                                                    echo '<span class="skill-chip">' . htmlspecialchars($name_str) . '</span>';
                                                }
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <span class="status-badge badge-<?= $row['status'] ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <button class="btn-action-small btn-edit-small" onclick='openEditModal(<?= htmlspecialchars($json_data, ENT_QUOTES, 'UTF-8') ?>)' title="Edit">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <a href="?toggle_status=<?= $row['id'] ?>" class="btn-action-small btn-toggle-small" title="Toggle Status">
                                                <i class="fa-solid fa-power-off"></i>
                                            </a>
                                            <a href="?del=<?= $row['id'] ?>" onclick="return confirm('Delete this package? All category mappings will be deleted.')" class="btn-action-small btn-delete-small" title="Delete">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 100px; color: #94a3b8;">
                                        <i class="fa-solid fa-box-open fa-3x" style="margin-bottom: 20px; opacity: 0.5;"></i><br>
                                        No subscription packages found. Click "Create Package" to get started.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- CREATE/EDIT MODAL -->
    <div id="packageModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle" style="margin-top: 0; margin-bottom: 1.5rem; font-weight: 800; color: var(--primary); font-size: 1.4rem; letter-spacing: -0.5px;">Create Package</h3>
            
            <form method="POST">
                <input type="hidden" name="package_id" id="package_id" value="">

                <div class="form-group">
                    <label>Package Name</label>
                    <input type="text" name="name" id="name" class="protocol-input" placeholder="e.g. Starter Pack, Unlimited Plan" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" id="description" class="protocol-input" placeholder="Short description of benefits">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Price (₹)</label>
                        <input type="number" step="0.01" min="0" name="price" id="price" class="protocol-input" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                        <label>Task Credits</label>
                        <input type="number" min="0" name="task_credits" id="task_credits" class="protocol-input" placeholder="Number of credits" required>
                    </div>
                </div>

                <!-- Order Amount Range -->
                <div class="form-group" style="background: linear-gradient(135deg, rgba(139,92,246,0.06), rgba(67,97,238,0.04)); border: 1px solid rgba(139,92,246,0.18); border-radius: 18px; padding: 20px;">
                    <label style="color: #7c3aed;"><i class="fa-solid fa-filter-circle-dollar" style="margin-right:5px;"></i> Order Amount Range <span style="color:#94a3b8; font-weight:500; text-transform:none; font-size:0.75rem;">(optional — leave blank = no restriction)</span></label>
                    <p style="font-size:0.78rem; color:#64748b; margin: -4px 0 14px; font-weight:500;">This package will only match orders whose total falls within this range.</p>
                    <div class="form-row" style="margin:0;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="color:#8b5cf6;">Min Order Amount (₹)</label>
                            <input type="number" step="0.01" min="0" name="order_min" id="order_min" class="protocol-input" placeholder="e.g. 500">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label style="color:#8b5cf6;">Max Order Amount (₹)</label>
                            <input type="number" step="0.01" min="0" name="order_max" id="order_max" class="protocol-input" placeholder="e.g. 10000">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status" class="protocol-input" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Allowed Categories &amp; Subcategories <span style="color:#94a3b8; font-weight:500; text-transform:none; font-size:0.75rem;">(Leave all unchecked = allow all categories)</span></label>
                    <div class="checkbox-container" style="max-height: 350px; overflow-y: auto;">
                        <?php if (empty($main_categories)): ?>
                            <div style="padding: 20px; text-align: center; color: #94a3b8;">
                                <i class="fa-solid fa-circle-exclamation" style="font-size: 1.5rem; margin-bottom: 8px; color: #fbbf24;"></i><br>
                                <strong style="color: #64748b;">No categories found.</strong><br>
                                <span style="font-size: 0.8rem;">Make sure categories exist in your main site database (surpriseville_emp).<br>
                                <a href="fix_packages_db.php" style="color: #4f46e5;">Run DB Fix</a> | <a href="debug_packages.php" style="color: #4f46e5;">View Diagnostics</a></span>
                            </div>
                        <?php else: ?>
                            <!-- ACTIVITIES SECTION -->
                            <?php if (!empty($activity_categories)): ?>
                                <div style="font-weight: 800; font-size: 0.85rem; color: var(--primary); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; padding: 6px 12px; background: rgba(79, 70, 229, 0.08); border-radius: 8px; display: flex; align-items: center; gap: 8px; position: sticky; top: 0; z-index: 5;">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Activities (Photography, Magician, DJ, Mascot, etc.)
                                </div>
                                <?php foreach ($activity_categories as $cat): ?>
                                    <div class="category-block" style="margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; padding-left: 8px; padding-right: 8px;">
                                        <label class="checkbox-item" style="font-weight: 700; color: #1e293b;">
                                            <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" class="cat-checkbox" id="cat_cb_<?= $cat['id'] ?>" onchange="toggleCategorySubs(<?= $cat['id'] ?>, this.checked)">
                                            <span><?= htmlspecialchars($cat['name']) ?></span>
                                        </label>
                                        <?php if (!empty($cat['subcategories'])): ?>
                                            <div class="subcategory-list" id="subs_block_<?= $cat['id'] ?>" style="margin-left: 24px; margin-top: 8px; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;">
                                                <?php foreach ($cat['subcategories'] as $sub): ?>
                                                    <label class="checkbox-item" style="font-weight: 500; font-size: 0.8rem; color: #475569;">
                                                        <input type="checkbox" name="subcategories[<?= $cat['id'] ?>][]" value="<?= $sub['id'] ?>" class="subcat-checkbox" id="subcat_cb_<?= $cat['id'] ?>_<?= $sub['id'] ?>" onchange="checkParentCat(<?= $cat['id'] ?>)">
                                                        <span><?= htmlspecialchars($sub['name']) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- DECORATIONS SECTION -->
                            <?php if (!empty($decoration_categories)): ?>
                                <div style="font-weight: 800; font-size: 0.85rem; color: var(--success); text-transform: uppercase; letter-spacing: 1px; margin-top: 20px; margin-bottom: 12px; padding: 6px 12px; background: rgba(16, 185, 129, 0.08); border-radius: 8px; display: flex; align-items: center; gap: 8px; position: sticky; top: 0; z-index: 5;">
                                    <i class="fa-solid fa-gift"></i> Decorations & setups
                                </div>
                                <?php foreach ($decoration_categories as $cat): ?>
                                    <div class="category-block" style="margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; padding-left: 8px; padding-right: 8px;">
                                        <label class="checkbox-item" style="font-weight: 700; color: #1e293b;">
                                            <input type="checkbox" name="categories[]" value="<?= $cat['id'] ?>" class="cat-checkbox" id="cat_cb_<?= $cat['id'] ?>" onchange="toggleCategorySubs(<?= $cat['id'] ?>, this.checked)">
                                            <span><?= htmlspecialchars($cat['name']) ?></span>
                                        </label>
                                        <?php if (!empty($cat['subcategories'])): ?>
                                            <div class="subcategory-list" id="subs_block_<?= $cat['id'] ?>" style="margin-left: 24px; margin-top: 8px; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;">
                                                <?php foreach ($cat['subcategories'] as $sub): ?>
                                                    <label class="checkbox-item" style="font-weight: 500; font-size: 0.8rem; color: #475569;">
                                                        <input type="checkbox" name="subcategories[<?= $cat['id'] ?>][]" value="<?= $sub['id'] ?>" class="subcat-checkbox" id="subcat_cb_<?= $cat['id'] ?>_<?= $sub['id'] ?>" onchange="checkParentCat(<?= $cat['id'] ?>)">
                                                        <span><?= htmlspecialchars($sub['name']) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 15px; margin-top: 2rem;">
                    <button type="button" onclick="closeModal()" class="btn-add" style="background: #f1f5f9; color: #475569; box-shadow: none;">Cancel</button>
                    <button type="submit" name="save_package" class="btn-add">Save Package</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                if (window.innerWidth > 1024) {
                    sidebar.classList.toggle('collapsed');
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                } else {
                    sidebar.classList.toggle('active');
                }
            }
        }

        function openCreateModal() {
            document.getElementById('modalTitle').innerText = 'Create Package';
            document.getElementById('package_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('price').value = '';
            document.getElementById('task_credits').value = '';
            document.getElementById('status').value = 'active';
            document.getElementById('description').value = '';
            document.getElementById('order_min').value = '';
            document.getElementById('order_max').value = '';
            
            const checkboxes = document.querySelectorAll('.cat-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            const subCheckboxes = document.querySelectorAll('.subcat-checkbox');
            subCheckboxes.forEach(cb => cb.checked = false);
            
            document.getElementById('packageModal').style.display = 'block';
        }

        function toggleCategorySubs(catId, isChecked) {
            const block = document.getElementById('subs_block_' + catId);
            if (block) {
                const subCheckboxes = block.querySelectorAll('.subcat-checkbox');
                subCheckboxes.forEach(cb => {
                    cb.checked = isChecked;
                });
            }
        }

        function checkParentCat(catId) {
            const catCb = document.getElementById('cat_cb_' + catId);
            const block = document.getElementById('subs_block_' + catId);
            if (catCb && block) {
                const checkedSubs = block.querySelectorAll('.subcat-checkbox:checked');
                if (checkedSubs.length > 0) {
                    catCb.checked = true;
                }
            }
        }

        function openEditModal(packageData) {
            document.getElementById('modalTitle').innerText = 'Edit Package';
            document.getElementById('package_id').value = packageData.id;
            document.getElementById('name').value = packageData.name;
            document.getElementById('price').value = packageData.price;
            document.getElementById('task_credits').value = packageData.task_credits;
            document.getElementById('status').value = packageData.status;
            document.getElementById('description').value = packageData.description || '';
            document.getElementById('order_min').value = packageData.order_min_price || '';
            document.getElementById('order_max').value = packageData.order_max_price || '';
            
            const checkboxes = document.querySelectorAll('.cat-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            const subCheckboxes = document.querySelectorAll('.subcat-checkbox');
            subCheckboxes.forEach(cb => cb.checked = false);
            
            if (packageData.allowed_items) {
                packageData.allowed_items.forEach(item => {
                    const cb = document.getElementById('cat_cb_' + item.category_id);
                    if (cb) cb.checked = true;
                    if (item.subcategory_id) {
                        const subcatCb = document.getElementById('subcat_cb_' + item.category_id + '_' + item.subcategory_id);
                        if (subcatCb) subcatCb.checked = true;
                    }
                });
            }
            
            document.getElementById('packageModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('packageModal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('packageModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
