<?php
require_once '../db_main.php'; // Shop DB
echo "\n--- Subcategories by Category ---\n";
$res = $mainConn->query("
    SELECT c.name as cat_name, s.id, s.name as subcat_name 
    FROM subcategories s 
    JOIN categories c ON s.category_id = c.id 
    ORDER BY c.name, s.name
");
$data = [];
while($row = $res->fetch_assoc()) {
    $data[$row['cat_name']][] = $row;
}
foreach($data as $cat => $subs) {
    echo "[$cat]\n";
    foreach($subs as $s) {
        echo "  - {$s['id']}: {$s['subcat_name']}\n";
    }
}
?>
