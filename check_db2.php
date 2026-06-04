<?php
require 'db.php';
$res = $conn->query("SHOW COLUMNS FROM vendors LIKE 'role'");
if ($res) {
    print_r($res->fetch_assoc());
} else {
    echo "No role column or error: " . $conn->error;
}
