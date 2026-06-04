<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../db.php';        // vendor DB
require_once '../db_main.php';   // main DB

$now = time();
$timeout = 1800; // 30 minutes

// Step 1 — Find alerts older than 30 minutes and still pending
$stmt = $conn->prepare("
    SELECT order_id, vendor_id, sent_at
    FROM order_vendor_notifications
    WHERE status = 'pending'
");
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $orderId   = $row['order_id'];
    $sentTs    = strtotime($row['sent_at']);

    // Skip if still inside 30 minutes
    if (($now - $sentTs) < $timeout) continue;

    // Check if order already accepted
    $check = $conn->prepare("SELECT assigned_vendor_id FROM orders WHERE id = ?");
    $check->bind_param("i", $orderId);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    $check->close();

    if (!empty($result['assigned_vendor_id'])) {
        continue; // someone already accepted
    }

    // Step 2 — Get order city & category
    $stmt2 = $mainConn->prepare("
        SELECT o.pincode, oi.service_id
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.id = ?
    ");
    $stmt2->bind_param("i", $orderId);
    $stmt2->execute();
    $order = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if (!$order) continue;

    $pincode   = $order['pincode'];
    $serviceId = $order['service_id'];

    // Step 3 — Find already alerted vendors
    $prevStmt = $conn->prepare("
        SELECT vendor_id FROM order_vendor_notifications
        WHERE order_id = ?
    ");
    $prevStmt->bind_param("i", $orderId);
    $prevStmt->execute();
    $prevRes = $prevStmt->get_result();

    $alertedVendors = [];
    while ($v = $prevRes->fetch_assoc()) {
        $alertedVendors[] = $v['vendor_id'];
    }
    $prevStmt->close();

    $alertedList = implode(",", $alertedVendors);
    if ($alertedList == "") $alertedList = "0";

    // Get the category_id from the main database for this service
    $catStmt = $mainConn->prepare("SELECT category_id FROM services WHERE id = ? LIMIT 1");
    $catStmt->bind_param("i", $serviceId);
    $catStmt->execute();
    $catRow = $catStmt->get_result()->fetch_assoc();
    $catStmt->close();
    $cat_id = $catRow ? (int)$catRow['category_id'] : 0;

    // STEP 4 — Find next 5 VENDORS in SAME PINCODE/CITY
    $stmt3 = $conn->prepare("
        SELECT v.id 
        FROM vendors v
        INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
        WHERE v.pincode = ?
        AND v.status = 'active'
        AND v.id NOT IN ($alertedList)
        AND (
            (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
            OR EXISTS (SELECT 1 FROM package_categories WHERE package_id = vs.package_id AND category_id = ?)
        )
        LIMIT 5
    ");
    $stmt3->bind_param("si", $pincode, $cat_id);
    $stmt3->execute();
    $sameCityVendors = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3->close();

    // If same city < 5, find nearest vendors by PINCODE radius
    if (count($sameCityVendors) < 5) {
        $stmt4 = $conn->prepare("
            SELECT v.id 
            FROM vendors v
            INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
            WHERE v.status = 'active'
            AND v.id NOT IN ($alertedList)
            AND (
                (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
                OR EXISTS (SELECT 1 FROM package_categories WHERE package_id = vs.package_id AND category_id = ?)
            )
            ORDER BY ABS(v.pincode - ?) ASC
            LIMIT 5
        ");
        $stmt4->bind_param("is", $cat_id, $pincode);
        $stmt4->execute();
        $nearbyVendors = $stmt4->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt4->close();
    } else {
        $nearbyVendors = [];
    }

    // Merge lists
    $finalVendors = array_merge($sameCityVendors, $nearbyVendors);
    // Step 5 — Insert new notifications
    foreach ($finalVendors as $vendor) {
        $insert = $conn->prepare("
            INSERT INTO order_vendor_notifications (order_id, vendor_id, status, sent_at)
            VALUES (?, ?, 'pending', NOW())
        ");
        $insert->bind_param("ii", $orderId, $vendor['id']);
        $insert->execute();
        $insert->close();
    }
}

echo "Reallocation script executed.";
