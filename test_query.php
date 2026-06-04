<?php
require_once __DIR__ . '/db.php';
$query = "
            SELECT dv.*, v.name, v.business_name
            FROM decorator_videos dv
            JOIN vendors v ON dv.vendor_id = v.id
            WHERE dv.video_status = 'pending'
            ORDER BY dv.created_at ASC
            LIMIT 50
        ";
$stmt = $conn->prepare($query);
if (!$stmt) {
    echo "Error: " . $conn->error;
} else {
    echo "Prepared successfully.";
}
?>
