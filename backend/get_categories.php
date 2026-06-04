<?php
require_once '../db.php';

header('Content-Type: application/json');

// Get all active categories
$categories = [];
$result = $conn->query("SELECT id, name, icon, slug FROM categories WHERE status = 'active' ORDER BY name ASC");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'categories' => $categories
]);
?>
