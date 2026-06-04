<?php
// vendor/includes/session_manager.php is usually in header, but for logout we start fresh
require_once 'includes/session_manager.php';
require_once '../db.php';

if (isset($_SESSION['vendor_id'])) {
    $vid = $_SESSION['vendor_id'];
    $stmt = $conn->prepare("UPDATE vendors SET remember_token = NULL WHERE id = ?");
    $stmt->bind_param("i", $vid);
    $stmt->execute();
}

// Clear cookie
setcookie('vendor_remember_token', '', time() - 3600, "/");

session_unset();
session_destroy();
header('Location: login.php');
exit;
?>
