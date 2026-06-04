<?php
require_once '../db.php';
require_once '../db_main.php';

header('Content-Type: application/json');

// 1. Fetch Shop Categories (from db_main)
$shop_categories = [];
$res_sc = $mainConn->query("SELECT id, name, image, type FROM categories WHERE status = 'active' ORDER BY name ASC");
if ($res_sc) {
    while ($row = $res_sc->fetch_assoc()) {
        $row['icon'] = $row['image']; // Map image to icon so frontend doesn't break
        $shop_categories[] = $row;
    }
} else {
    error_log("Main DB Error: " . $mainConn->error);
}

// 2. Fetch Gig Categories/Skills (from db)
$gig_categories = [];
$res_gc = $conn->query("SELECT id, name, type FROM gig_categories ORDER BY name ASC");
if ($res_gc) {
    while ($row = $res_gc->fetch_assoc()) {
        $gig_categories[] = $row;
    }
}

$mainConn->close();
$conn->close();

echo json_encode([
    'success' => true,
    'shop_categories' => $shop_categories,
    'gig_categories' => $gig_categories
]);
