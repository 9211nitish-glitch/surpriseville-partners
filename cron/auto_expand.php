<?php
// cron/auto_expand.php
require_once __DIR__ . '/../db.php';

// SETTINGS
$MAX_RADIUS = 30;       // Max limit
$EXPAND_BY = 5;         // Step size
$TIMEOUT_LIMIT = "10 MINUTE"; // 10 min wait time

echo "Checking for Timed-Out Tasks...<br>";

// 1. Fetch Orders jo 'open' hain aur 10 min se update nahi huye
$sql = "SELECT id, category_id, subcategory_id, event_latitude, event_longitude, search_radius 
        FROM manual_tasks 
        WHERE status = 'open' 
        AND last_radius_update < (NOW() - INTERVAL $TIMEOUT_LIMIT)";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while($task = $result->fetch_assoc()) {
        $tid = $task['id'];
        $cat_id = $task['category_id'];
        $subcat_id = $task['subcategory_id'] !== null ? (int)$task['subcategory_id'] : null;
        $lat = $task['event_latitude'];
        $lng = $task['event_longitude'];
        $current_radius = $task['search_radius'];

        // If Lat/Lng missing, skip
        if (!$lat || !$lng) continue;

        // Check if Max Limit already reached
        if ($current_radius >= $MAX_RADIUS) {
            // 30km complete, still open -> Admin Review (Unfilled)
            $conn->query("UPDATE manual_tasks SET status = 'unfilled' WHERE id = $tid");
            echo "Order #$tid: Time over & Max limit reached. Marked 'Unfilled'.<br>";
            continue;
        }

        // 🚀 EXPANSION LOOP (Timeout hua hai, to ab tab tak badhao jab tak naya banda na mile)
        $next_radius = $current_radius + $EXPAND_BY;
        $found_new = false;

        while ($next_radius <= $MAX_RADIUS) {
            
            $subcat_cond = $subcat_id === null 
                ? "subcategory_id IS NULL" 
                : "(subcategory_id IS NULL OR subcategory_id = $subcat_id)";

            // Find Vendors in New Radius who were NOT notified before and have valid subscriptions
            $find_sql = "
                SELECT v.id, 
                ( 6371 * acos( cos( radians($lat) ) * cos( radians( v.latitude ) ) * cos( radians( v.longitude ) - radians($lng) ) + sin( radians($lat) ) * sin( radians( v.latitude ) ) ) ) AS distance 
                FROM vendors v
                JOIN vendor_gig_skills vgs ON vgs.vendor_id = v.id
                INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
                WHERE vgs.category_id = $cat_id
                AND v.status = 'active' 
                AND (
                    (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
                    OR 
                    EXISTS (
                        SELECT 1 FROM package_categories 
                        WHERE package_id = vs.package_id 
                        AND category_id = $cat_id 
                        AND $subcat_cond
                    )
                )
                HAVING distance <= $next_radius 
                AND id NOT IN (SELECT vendor_id FROM task_alerts WHERE task_id = $tid)
            ";
            
            $vendors = $conn->query($find_sql);

            if ($vendors->num_rows > 0) {
                // New Batch Found!
                $count = 0;
                while($v = $vendors->fetch_assoc()) {
                    $vid = $v['id'];
                    $conn->query("INSERT INTO task_alerts (task_id, vendor_id, status, sent_at) VALUES ($tid, $vid, 'pending', NOW())");
                    $count++;
                }
                
                // Update Radius & Reset Timer (NOW())
                $conn->query("UPDATE manual_tasks SET search_radius = $next_radius, last_radius_update = NOW() WHERE id = $tid");
                
                echo "Order #$tid: Timeout. Expanded to {$next_radius}km. Alerts sent to $count vendors.<br>";
                $found_new = true;
                break; // Found someone, stop expanding
            }

            // Agar is radius mein bhi koi nahi mila, to Loop continue karo (+5km more)
            $next_radius += $EXPAND_BY;
        }

        // Agar Loop 30km tak chala gaya aur koi nahi mila
        if (!$found_new) {
            $conn->query("UPDATE manual_tasks SET status = 'unfilled' WHERE id = $tid");
            echo "Order #$tid: Searched up to 30km but no new vendors found. Marked 'Unfilled'.<br>";
        }
    }
} else {
    echo "No pending tasks needing expansion.<br>";
}
?>