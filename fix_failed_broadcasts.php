<?php
// fix_failed_broadcasts.php
session_start();
require 'db.php';

echo "<h2>Fixing Failed Broadcasts</h2>";

// 1. Find tasks that have NO alerts in task_alerts and are 'open' or 'unfilled'
$sql = "
    SELECT id, category_id, client_name 
    FROM manual_tasks 
    WHERE id NOT IN (
        SELECT DISTINCT task_id FROM task_alerts
    )
    AND status IN ('open', 'unfilled')
";

$res = $conn->query($sql);

if ($res && $res->num_rows > 0) {
    while ($task = $res->fetch_assoc()) {
        $taskId = $task['id'];
        $catId = $task['category_id'];

        echo "<p>Processing Task #$taskId ({$task['client_name']})...</p>";

        // 2. Fallback logic: Broadcast to ALL active vendors matching the category skill
        $v_fallback_sql = "
            SELECT v.id 
            FROM vendors v
            JOIN vendor_gig_skills vgs ON vgs.vendor_id = v.id
            WHERE vgs.category_id = ? 
            AND v.status = 'active'
        ";

        $stmtFallback = $conn->prepare($v_fallback_sql);
        $stmtFallback->bind_param("i", $catId);
        $stmtFallback->execute();
        $resFallback = $stmtFallback->get_result();

        $alertsSent = 0;
        if ($resFallback->num_rows > 0) {
            while ($row = $resFallback->fetch_assoc()) {
                $vid = $row['id'];
                // Double check to be completely safe
                $chk = $conn->query("SELECT id FROM task_alerts WHERE task_id=$taskId AND vendor_id=$vid");
                if ($chk->num_rows == 0) {
                    $conn->query("INSERT INTO task_alerts (task_id, vendor_id, status, sent_at) VALUES ($taskId, $vid, 'pending', NOW())");
                    $alertsSent++;
                }
            }

            // Mark task as open and update radius to indicate fallback broadcast
            $conn->query("UPDATE manual_tasks SET search_radius = 999, status = 'open', last_radius_update = NOW() WHERE id = $taskId");

            echo "<span style='color:green;'>-- Successfully sent $alertsSent alerts for Task #$taskId!</span><br>";
        } else {
            echo "<span style='color:red;'>-- No active vendors found with required skill (Category ID: $catId) for Task #$taskId!</span><br>";
        }
    }
    echo "<br><b>All missing broadcasts have been processed.</b>";
} else {
    echo "No missing broadcasts found. All tasks seem to have alerts if vendors were available.";
}
