<?php
require_once '../db.php';
echo "\n--- gig_categories data ---\n";
$res = $conn->query("SELECT * FROM gig_categories");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
