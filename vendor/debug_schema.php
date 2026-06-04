<?php
require_once '../db.php';
$res = $conn->query("SHOW TABLES");
while($row = $res->fetch_array()) {
    echo $row[0] . "\n";
}
echo "\n--- vendor_categories ---\n";
$res = $conn->query("DESCRIBE vendor_categories");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
echo "\n--- vendor_subcategories ---\n";
$res = $conn->query("DESCRIBE vendor_subcategories");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
