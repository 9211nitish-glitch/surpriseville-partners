<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

echo "LAST 20 TASK ALERTS:\n";
echo str_repeat("-", 80) . "\n";

$sql = "
SELECT 
    mt.id AS task_id, 
    mt.status AS task_status, 
    mt.search_radius, 
    ta.vendor_id, 
    v.name, 
    v.role, 
    ta.status AS vendor_status, 
    ta.sent_at 
FROM manual_tasks mt 
JOIN task_alerts ta ON mt.id = ta.task_id 
JOIN vendors v ON ta.vendor_id = v.id 
ORDER BY mt.id DESC, ta.sent_at DESC 
LIMIT 20
";

$res = $conn->query($sql);

if (!$res) {
    echo "Query Error: " . $conn->error . "\n";
    exit;
}

if ($res->num_rows === 0) {
    echo "No task alerts found.\n";
} else {
    while ($r = $res->fetch_assoc()) {
        printf(
            "Task #%d (Status: %s, Radius: %d) -> Vendor #%d (%s, Role: %s) -> Alert Status: %s | Sent: %s\n",
            $r['task_id'],
            $r['task_status'],
            $r['search_radius'],
            $r['vendor_id'],
            $r['name'],
            $r['role'] ? $r['role'] : 'NULL',
            $r['vendor_status'],
            $r['sent_at']
        );
    }
}
echo str_repeat("-", 80) . "\n";
