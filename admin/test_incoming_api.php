<?php
require_once __DIR__ . '/../db_main.php';
header('Content-Type: application/json');

$res = $mainConn->query("SELECT * FROM call_sessions WHERE status = 'ringing'");
$ringing_calls = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ringing_calls[] = $row;
    }
}

// Also get the current MySQL timestamps
$res2 = $mainConn->query("SELECT NOW() as mysql_now, UTC_TIMESTAMP() as mysql_utc");
$times = $res2 ? $res2->fetch_assoc() : [];

echo json_encode([
    'ringing_calls' => $ringing_calls,
    'times' => $times,
    'query_check_sql' => "SELECT id, order_id, caller_id, call_type, created_at FROM call_sessions WHERE (callee_type = 'admin' OR (callee_type = 'user' AND callee_id = 1)) AND status = 'ringing'"
], JSON_PRETTY_PRINT);
