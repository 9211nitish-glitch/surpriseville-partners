<?php
require_once __DIR__ . '/../db_main.php';
header('Content-Type: application/json');

$catIds = $_GET['category_ids'] ?? '';
$catIds = trim($catIds);
if ($catIds === '') { echo json_encode([]); exit; }

$ids = array_filter(array_map('intval', explode(',', $catIds)));
if (empty($ids)) { echo json_encode([]); exit; }

/*
We fetch addons tied to services that belong to the selected categories.
This works if addons are linked via service_addons -> services (service has category_id).
*/
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$sql = "
SELECT DISTINCT a.id, a.name, COALESCE(a.price, 0) AS price, a.image
FROM addons a
WHERE a.category_id IN ($placeholders)
ORDER BY a.name
";
$stmt = $mainConn->prepare($sql);

$types = str_repeat('i', count($ids));
$stmt->bind_param($types, ...$ids);

$stmt->execute();
$res = $stmt->get_result();
$out = [];
while ($r = $res->fetch_assoc()) {
    // ensure image URL is publicly accessible; if your stored path is relative, adjust path accordingly
    if (!empty($r['image'])) {
    // convert to full URL:
    $r['image'] = "https://shop.btnevents.in/" . ltrim($r['image'], '/');
} else {
    $r['image'] = null;
}

    $out[] = $r;
}
$stmt->close();

echo json_encode($out);
