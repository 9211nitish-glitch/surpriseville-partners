<?php
require_once __DIR__ . '/../db_main.php';
header('Content-Type: application/json');
$res = $mainConn->query("SELECT * FROM call_sessions ORDER BY id DESC LIMIT 5");
$rows = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
}
echo json_encode($rows, JSON_PRETTY_PRINT);
