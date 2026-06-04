<?php
require_once '../db.php';     // partners DB -> $conn
require_once '../db_main.php'; // emp DB -> $mainConn

echo "Starting Baseline Subcategory Sync...\n";

// 1. Fetch all subcategories from Shop DB
$subMap = [];
$res = $mainConn->query("SELECT id, category_id FROM subcategories");
while ($r = $res->fetch_assoc()) {
    $cid = $r['category_id'];
    $subMap[$cid][] = $r['id'];
}
echo "Found mappings for " . count($subMap) . " shop categories.\n";

// 2. Fetch all current vendor categories
$vCats = $conn->query("SELECT vendor_id, category_id FROM vendor_categories");
$syncCount = 0;

while ($vc = $vCats->fetch_assoc()) {
    $vid = $vc['vendor_id'];
    $cid = $vc['category_id'];
    
    if (isset($subMap[$cid])) {
        foreach ($subMap[$cid] as $sid) {
            $conn->query("INSERT IGNORE INTO vendor_subcategories (vendor_id, subcategory_id, created_at) VALUES ($vid, $sid, NOW())");
            if ($conn->affected_rows > 0) $syncCount++;
        }
    }
}

echo "Successfully created $syncCount baseline subcategory entries.\n";
?>
