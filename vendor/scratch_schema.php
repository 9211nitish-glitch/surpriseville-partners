<?php
require_once '../db.php';

echo "--- TABLES ---\n";
$res = $conn->query("SHOW TABLES");
while($row = $res->fetch_array()) {
    echo $row[0] . "\n";
}

echo "\n--- DESCRIBE decorator_videos ---\n";
$res = $conn->query("DESCRIBE decorator_videos");
if ($res) {
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "decorator_videos table not found or error: " . $conn->error . "\n";
}

echo "\n--- DESCRIBE decorator_video_portfolio ---\n";
$res = $conn->query("DESCRIBE decorator_video_portfolio");
if ($res) {
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "decorator_video_portfolio table not found or error: " . $conn->error . "\n";
}
?>
