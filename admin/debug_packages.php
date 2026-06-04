<?php
session_start();
// Temporary diagnostic file - DELETE AFTER USE
if (!isset($_SESSION['admin_logged_in'])) {
    die("Access denied - admin login required");
}

require_once "../db.php";
require_once "../db_main.php";

echo "<pre style='font-family:monospace; padding:20px; background:#111; color:#0f0; font-size:13px;'>";
echo "=== PACKAGE SYSTEM DATABASE DIAGNOSTIC ===\n\n";

// 1. Check packages table
echo "--- 1. packages table (surpriseville_partners) ---\n";
$r = $conn->query("SHOW TABLES LIKE 'packages'");
if ($r && $r->num_rows > 0) {
    echo "  [OK] packages table EXISTS\n";
    $cols = $conn->query("SHOW COLUMNS FROM packages");
    echo "  Columns: ";
    while ($c = $cols->fetch_assoc()) echo $c['Field'] . "(" . $c['Type'] . ") | ";
    echo "\n";
    $cnt = $conn->query("SELECT COUNT(*) as n FROM packages")->fetch_assoc();
    echo "  Row count: " . $cnt['n'] . "\n";
} else {
    echo "  [MISSING] packages table DOES NOT EXIST - needs migration!\n";
}

// 2. Check package_categories table
echo "\n--- 2. package_categories table (surpriseville_partners) ---\n";
$r = $conn->query("SHOW TABLES LIKE 'package_categories'");
if ($r && $r->num_rows > 0) {
    echo "  [OK] package_categories table EXISTS\n";
    $cols = $conn->query("SHOW COLUMNS FROM package_categories");
    echo "  Columns: ";
    $has_subcat = false;
    while ($c = $cols->fetch_assoc()) {
        echo $c['Field'] . "(" . $c['Type'] . ") | ";
        if ($c['Field'] === 'subcategory_id') $has_subcat = true;
    }
    echo "\n";
    if (!$has_subcat) {
        echo "  [WARNING] subcategory_id column MISSING - needs ALTER TABLE!\n";
    } else {
        echo "  [OK] subcategory_id column present\n";
    }
} else {
    echo "  [MISSING] package_categories table DOES NOT EXIST - needs migration!\n";
}

// 3. Check vendor_subscriptions table
echo "\n--- 3. vendor_subscriptions table (surpriseville_partners) ---\n";
$r = $conn->query("SHOW TABLES LIKE 'vendor_subscriptions'");
if ($r && $r->num_rows > 0) {
    echo "  [OK] vendor_subscriptions table EXISTS\n";
    $cols = $conn->query("SHOW COLUMNS FROM vendor_subscriptions");
    echo "  Columns: ";
    while ($c = $cols->fetch_assoc()) echo $c['Field'] . "(" . $c['Type'] . ") | ";
    echo "\n";
} else {
    echo "  [MISSING] vendor_subscriptions table DOES NOT EXIST!\n";
}

// 4. Check categories table in MAIN DB (surpriseville_emp)
echo "\n--- 4. categories table (surpriseville_emp - main DB) ---\n";
$r = $mainConn->query("SHOW TABLES LIKE 'categories'");
if ($r && $r->num_rows > 0) {
    echo "  [OK] categories table EXISTS in main DB\n";
    $cols = $mainConn->query("SHOW COLUMNS FROM categories");
    echo "  Columns: ";
    while ($c = $cols->fetch_assoc()) echo $c['Field'] . "(" . $c['Type'] . ") | ";
    echo "\n";
    $cnt = $mainConn->query("SELECT COUNT(*) as n FROM categories")->fetch_assoc();
    echo "  Row count: " . $cnt['n'] . "\n";
    if ($cnt['n'] > 0) {
        $samp = $mainConn->query("SELECT id, name FROM categories LIMIT 5");
        echo "  Sample rows:\n";
        while ($s = $samp->fetch_assoc()) echo "    id=" . $s['id'] . " name=" . $s['name'] . "\n";
    }
} else {
    echo "  [MISSING] categories table DOES NOT EXIST in main DB!\n";
    // Show what tables exist in main DB
    $tables = $mainConn->query("SHOW TABLES");
    echo "  Tables in surpriseville_emp:\n";
    while ($t = $tables->fetch_row()) echo "    " . $t[0] . "\n";
}

// 5. Check subcategories table in MAIN DB
echo "\n--- 5. subcategories table (surpriseville_emp - main DB) ---\n";
$r = $mainConn->query("SHOW TABLES LIKE 'subcategories'");
if ($r && $r->num_rows > 0) {
    echo "  [OK] subcategories table EXISTS in main DB\n";
    $cols = $mainConn->query("SHOW COLUMNS FROM subcategories");
    echo "  Columns: ";
    while ($c = $cols->fetch_assoc()) echo $c['Field'] . "(" . $c['Type'] . ") | ";
    echo "\n";
    $cnt = $mainConn->query("SELECT COUNT(*) as n FROM subcategories")->fetch_assoc();
    echo "  Row count: " . $cnt['n'] . "\n";
    if ($cnt['n'] > 0) {
        $samp = $mainConn->query("SELECT id, category_id, name FROM subcategories LIMIT 5");
        echo "  Sample rows:\n";
        while ($s = $samp->fetch_assoc()) echo "    id=" . $s['id'] . " cat_id=" . $s['category_id'] . " name=" . $s['name'] . "\n";
    }
} else {
    echo "  [MISSING] subcategories table DOES NOT EXIST in main DB!\n";
}

echo "\n=== END DIAGNOSTIC ===\n";
echo "</pre>";
?>
