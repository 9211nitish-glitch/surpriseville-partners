<?php
// ajax/ajax_get_services.php
require_once '../db_main.php';
header('Content-Type: application/json');

$category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
$subcategory_id = isset($_GET['subcategory_id']) && $_GET['subcategory_id'] !== '' ? (int)$_GET['subcategory_id'] : null;

$sql = "SELECT id, name, COALESCE(base_price, discount_price, 0) AS base_price, category_id, subcategory_id 
        FROM services WHERE status='active' ";
$params = [];
$types = '';

if ($category_id) { $sql .= " AND category_id = ?"; $params[] = $category_id; $types .= 'i'; }
if ($subcategory_id) { $sql .= " AND subcategory_id = ?"; $params[] = $subcategory_id; $types .= 'i'; }
$sql .= " ORDER BY name";

$stmt = $mainConn->prepare($sql);
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($data);
