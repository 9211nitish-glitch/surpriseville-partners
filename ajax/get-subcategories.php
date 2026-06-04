<?php
require_once __DIR__ . '/../db_main.php';
header('Content-Type: application/json');

$catIds = $_GET['category_ids'] ?? '';
$catIds = trim($catIds);
if ($catIds === '') { echo json_encode([]); exit; }

$ids = array_filter(array_map('intval', explode(',', $catIds)));
if (empty($ids)) { echo json_encode([]); exit; }

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT id, category_id, name FROM subcategories WHERE category_id IN ($placeholders) ORDER BY name";
$stmt = $mainConn->prepare($sql);
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$res = $stmt->get_result();
$out = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($out);
