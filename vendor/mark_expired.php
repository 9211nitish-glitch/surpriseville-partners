<?php
// vendor/mark_expired.php
session_start();
header('Content-Type: application/json');
require_once '../db.php';

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo json_encode(['success'=>false,'message'=>'Login required']);
    exit;
}

$vendor_id = intval($_SESSION['vendor_id']);
$order_id = intval($_POST['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid order id']);
    exit;
}

@$conn->query("ALTER TABLE order_vendor_notifications ADD COLUMN remark VARCHAR(255) NULL");

$stmt = $conn->prepare("UPDATE order_vendor_notifications SET status='expired', responded_at=NOW(), remark=? WHERE order_id=? AND vendor_id=? AND status='pending'");
$remark = "Expired (auto after timeout)";
$stmt->bind_param("sii",$remark,$order_id,$vendor_id);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

echo json_encode(['success'=>true,'affected'=>$affected]);
exit;
