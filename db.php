<?php
date_default_timezone_set('Asia/Kolkata');

$host = 'swift.herosite.pro';
$user = 'surpriseville_partners';
$pass = 'Sv@123@4567';
$db_name = 'surpriseville_partners'; // CHANGE THIS

$conn = new mysqli($host, $user, $pass, $db_name);
$db = $conn;

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ensure MySQL session matches Asia/Kolkata
$conn->query("SET time_zone = '+05:30'");

// Security Secret for Cross-Domain Recharge
$RECHARGE_SECRET = 'SV_Wallet_Recharge_2024_Security';
