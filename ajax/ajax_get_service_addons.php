<?php
// ajax/ajax_get_service_addons.php
require_once '../db_main.php';
header('Content-Type: application/json');

$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;
if (!$service_id) { echo json_encode([]); exit; }

$sql = "SELECT a.id, a.name, COALESCE(a.price, 0) AS price
        FROM service_addons sa
        JOIN addons a ON sa.addon_id = a.id
        WHERE sa.service_id = ?
        ORDER BY a.name";
$stmt = $mainConn->prepare($sql);
$stmt->bind_param("i", $service_id);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($data);
