<?php
// vendor/update_location.php
session_start();
require_once '../db.php';

if (!isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$vendor_id = (int)$_SESSION['vendor_id'];
$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;

if ($lat !== null && $lng !== null) {
    if (file_exists('../db.php')) {
        // Only attempt if DB is connected
        $stmt = $conn->prepare("UPDATE vendors SET latitude = ?, longitude = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ddi", $lat, $lng, $vendor_id);
            if ($stmt->execute()) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        }
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Missing coordinates']);
}
