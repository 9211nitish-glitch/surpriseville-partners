<?php
require_once '../db_main.php'; // Main Shop DB
echo "\n--- Shop categories data ---\n";
$res = $mainConn->query("SELECT id, name FROM categories");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
