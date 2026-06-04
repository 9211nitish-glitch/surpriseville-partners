<?php
session_start();
// admin/fix_packages_db.php — Run once to fix all package-related DB issues
// DELETE THIS FILE AFTER RUNNING

if (!isset($_SESSION['admin_logged_in'])) {
    die("Access denied - admin login required");
}

require_once "../db.php";
require_once "../db_main.php";

$log = [];
$errors = [];

function run_sql($conn, $label, $sql) {
    global $log, $errors;
    if ($conn->query($sql)) {
        $log[] = "✅ $label";
    } else {
        $errors[] = "❌ $label — Error: " . $conn->error;
    }
}

// ============================================================
// 1. Fix packages table (surpriseville_partners)
// ============================================================
$r = $conn->query("SHOW TABLES LIKE 'packages'");
if (!$r || $r->num_rows == 0) {
    run_sql($conn, "CREATE packages table", "
        CREATE TABLE `packages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `task_credits` INT NOT NULL DEFAULT 0,
            `validity_days` INT NOT NULL DEFAULT 36500,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} else {
    $log[] = "ℹ️ packages table already exists — checking columns...";
    // Ensure validity_days column exists
    $ck = $conn->query("SHOW COLUMNS FROM packages LIKE 'description'");
    if (!$ck || $ck->num_rows == 0) {
        run_sql($conn, "ADD description to packages", "ALTER TABLE packages ADD COLUMN `description` TEXT DEFAULT NULL AFTER `name`");
    }
}

// ============================================================
// 2. Fix package_categories table
// ============================================================
$r = $conn->query("SHOW TABLES LIKE 'package_categories'");
if (!$r || $r->num_rows == 0) {
    run_sql($conn, "CREATE package_categories table", "
        CREATE TABLE `package_categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `package_id` INT NOT NULL,
            `category_id` INT NOT NULL,
            `subcategory_id` INT DEFAULT NULL,
            INDEX `idx_pkg_cat` (`package_id`, `category_id`),
            FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} else {
    $log[] = "ℹ️ package_categories table already exists — checking subcategory_id...";
    $ck = $conn->query("SHOW COLUMNS FROM package_categories LIKE 'subcategory_id'");
    if (!$ck || $ck->num_rows == 0) {
        run_sql($conn, "ADD subcategory_id to package_categories", "ALTER TABLE package_categories ADD COLUMN `subcategory_id` INT DEFAULT NULL");
        $log[] = "   → subcategory_id column added!";
    } else {
        $log[] = "   ✅ subcategory_id column already present";
    }
}

// ============================================================
// 3. Fix vendor_subscriptions table
// ============================================================
$r = $conn->query("SHOW TABLES LIKE 'vendor_subscriptions'");
if (!$r || $r->num_rows == 0) {
    run_sql($conn, "CREATE vendor_subscriptions table", "
        CREATE TABLE `vendor_subscriptions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `vendor_id` INT NOT NULL,
            `package_id` INT NOT NULL,
            `credits_total` INT NOT NULL DEFAULT 0,
            `credits_remaining` INT NOT NULL DEFAULT 0,
            `starts_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL DEFAULT '9999-12-31 23:59:59',
            `status` ENUM('active','expired','exhausted','cancelled') NOT NULL DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_vendor_status` (`vendor_id`, `status`),
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} else {
    $log[] = "ℹ️ vendor_subscriptions table already exists";
    // Check expires_at allows far future
}

// ============================================================
// 4. Fix package_purchases table  
// ============================================================
$r = $conn->query("SHOW TABLES LIKE 'package_purchases'");
if (!$r || $r->num_rows == 0) {
    run_sql($conn, "CREATE package_purchases table", "
        CREATE TABLE `package_purchases` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `vendor_id` INT NOT NULL,
            `package_id` INT NOT NULL,
            `amount_paid` DECIMAL(10,2) NOT NULL,
            `payment_method` VARCHAR(50) NOT NULL DEFAULT 'wallet',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_vendor` (`vendor_id`),
            FOREIGN KEY (`vendor_id`) REFERENCES `vendors`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} else {
    $log[] = "ℹ️ package_purchases table already exists";
}

// ============================================================
// 5. Check main DB (surpriseville_emp) for categories/subcategories
// ============================================================
$log[] = "\n--- Main DB (surpriseville_emp) check ---";
$r = $mainConn->query("SHOW TABLES LIKE 'categories'");
if (!$r || $r->num_rows == 0) {
    $errors[] = "❌ categories table MISSING in surpriseville_emp (main DB) — cannot show category list in package form!";
    $errors[] = "   → You need to add categories via your main site (surpriseville.co.in admin panel)";
    
    // List what tables exist
    $tables_res = $mainConn->query("SHOW TABLES");
    $table_list = [];
    if ($tables_res) while ($t = $tables_res->fetch_row()) $table_list[] = $t[0];
    $errors[] = "   → Tables currently in surpriseville_emp: " . implode(", ", $table_list);
} else {
    $cnt = $mainConn->query("SELECT COUNT(*) as n FROM categories")->fetch_assoc();
    $log[] = "✅ categories table exists in main DB — " . $cnt['n'] . " categories";
    if ($cnt['n'] == 0) {
        $errors[] = "⚠️ categories table is EMPTY — add categories from your main site first!";
    }
}

$r = $mainConn->query("SHOW TABLES LIKE 'subcategories'");
if (!$r || $r->num_rows == 0) {
    $errors[] = "❌ subcategories table MISSING in surpriseville_emp (main DB)";
} else {
    $cnt = $mainConn->query("SELECT COUNT(*) as n FROM subcategories")->fetch_assoc();
    $log[] = "✅ subcategories table exists in main DB — " . $cnt['n'] . " subcategories";
}

// ============================================================
// OUTPUT
// ============================================================
?>
<!DOCTYPE html>
<html>
<head>
    <title>Package DB Fix | Admin</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; padding: 40px; }
        pre { background: #1e293b; border-radius: 12px; padding: 24px; font-size: 14px; line-height: 1.8; }
        .ok { color: #4ade80; }
        .err { color: #f87171; }
        .info { color: #60a5fa; }
        h1 { color: #a78bfa; }
        .btn { display: inline-block; margin-top: 20px; padding: 12px 24px; background: #4f46e5; color: white; border-radius: 10px; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>
    <h1>📦 Package DB Fix Results</h1>
    <pre>
<?php
foreach ($log as $l) {
    if (str_starts_with($l, '✅')) echo '<span class="ok">' . htmlspecialchars($l) . "</span>\n";
    elseif (str_starts_with($l, 'ℹ️')) echo '<span class="info">' . htmlspecialchars($l) . "</span>\n";
    else echo htmlspecialchars($l) . "\n";
}
if (!empty($errors)) {
    echo "\n<span style='color:#fbbf24'>⚠️ Issues Found:</span>\n";
    foreach ($errors as $e) {
        echo '<span class="err">' . htmlspecialchars($e) . "</span>\n";
    }
}
if (empty($errors)) {
    echo "\n\n<span class='ok'>🎉 ALL CHECKS PASSED — Database is correctly set up!</span>\n";
    echo "<span class='ok'>   → Your manage_packages.php should now work correctly.</span>\n";
    echo "<span class='ok'>   → Delete this file (admin/fix_packages_db.php) after use.</span>\n";
}
?>
    </pre>
    <a href="manage_packages.php" class="btn">← Back to Manage Packages</a>
    <a href="debug_packages.php" class="btn" style="background:#0f766e; margin-left:10px;">🔍 Full Diagnostic</a>
</body>
</html>
