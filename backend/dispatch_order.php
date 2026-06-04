<?php
// Adjust paths depending on where this is called from
if (file_exists(__DIR__ . '/../db.php')) {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../db_main.php';
} else {
    // Fallback if called from shop root or elsewhere
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../db_main.php';
}

/**
 * Auto-Dispatch Order to Matching Vendors
 * 
 * This script should be called after a new order is created.
 * It finds matching vendors based on city and service category,
 * then creates notifications for up to 5 vendors.
 * 
 * Usage: dispatch_order($order_id);
 * URL: https://vendor.btnevents.in/backend/dispatch_order.php?order_id=123
 */

function dispatch_order($order_id)
{
    global $vn_pdo, $shop_pdo;

    // 0. Robust PDO Initialization (Fallback if not provided by caller)
    if (!isset($vn_pdo) || !isset($shop_pdo)) {
        try {
            // Get credentials from mysqli config if possible, or use defaults from db.php
            if (file_exists(__DIR__ . '/../db.php')) {
                include __DIR__ . '/../db.php'; // sets $host, $user, $pass, $db_name
                if (!isset($vn_pdo)) {
                    $vn_pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                }
            }
            if (file_exists(__DIR__ . '/../db_main.php')) {
                // We need to parse db_main.php or just use common constants if we had them.
                // Since we know the credentials from our earlier view_file, we'll use them as fallback.
                if (!isset($shop_pdo)) {
                    $shop_pdo = new PDO("mysql:host=swift.herosite.pro;dbname=surpriseville_emp;charset=utf8mb4", "surpriseville_emp", "Sv@123@4567", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                }
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'DB initialization failed: ' . $e->getMessage()];
        }
    }

    if (!isset($shop_pdo)) {
        return ['success' => false, 'message' => 'Shop DB connection missing ($shop_pdo)'];
    }
    if (!isset($vn_pdo)) {
        return ['success' => false, 'message' => 'Vendor DB connection missing ($vn_pdo)'];
    }

    // 1. Check Allocation Mode
    $mode = 'auto';
    $stmt = $vn_pdo->query("SELECT value FROM settings WHERE `key` = 'order_allocation_mode' LIMIT 1");
    if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mode = $row['value'];
    }

    if ($mode === 'manual') {
        // Notify Admin (New requirement)
        require_once __DIR__ . '/admin_notify.php';
        sendAdminAlert($order_id, 'manual_allocation_required', $shop_pdo);
        return ['success' => true, 'message' => 'Manual mode enabled. Admin has been notified for allocation.'];
    }

    // 2. Get Order Details from Shop DB
    $stmt = $shop_pdo->prepare("
        SELECT o.city, o.pincode, o.service_id, o.customer_name, o.total_amount 
        FROM orders o 
        WHERE o.id = ? 
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return ['success' => false, 'message' => 'Order not found in Main DB'];
    }

    $city = $order['city'];
    $service_id = $order['service_id'];
    $customer_name = $order['customer_name'] ?? 'Customer';

    if (empty($city)) {
        // Fallback: Try to guess city from Pincode
        $pincode = $order['pincode'];
        if (strpos($pincode, '2013') === 0 || strpos($pincode, '2010') === 0) {
            $city = "Noida";
        } elseif (strpos($pincode, '11') === 0) {
            $city = "Delhi";
        } elseif (strpos($pincode, '122') === 0) {
            $city = "Gurgaon";
        } else {
            return ['success' => false, 'message' => 'Order missing city (and pincode fallback failed)'];
        }
    }

    // 3. Identify Target Categories and Subcategories
    $target_pairs = [];

    // A. Main Service
    if ($service_id > 0) {
        $stmt = $shop_pdo->prepare("SELECT category_id, subcategory_id FROM services WHERE id = ?");
        $stmt->execute([$service_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['category_id']) {
            $target_pairs[] = [
                'category_id' => (int)$row['category_id'],
                'subcategory_id' => $row['subcategory_id'] !== null ? (int)$row['subcategory_id'] : null
            ];
        }
    }

    // B. Addon Categories & Subcategories
    $stmt = $shop_pdo->prepare("
        SELECT a.category_id, a.subcategory_id 
        FROM order_addons oa 
        JOIN addons a ON oa.addon_id = a.id 
        WHERE oa.order_id = ?
    ");
    $stmt->execute([$order_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['category_id']) {
            $target_pairs[] = [
                'category_id' => (int)$row['category_id'],
                'subcategory_id' => $row['subcategory_id'] !== null ? (int)$row['subcategory_id'] : null
            ];
        }
    }

    // De-duplicate target pairs
    $serialized = array_map('serialize', $target_pairs);
    $unique_serialized = array_unique($serialized);
    $target_pairs = array_map('unserialize', $unique_serialized);

    if (empty($target_pairs)) {
        return ['success' => false, 'message' => 'No target categories or subcategories found'];
    }

    // 4. Find Matching Vendors (Vendor DB) - BROADCASTING (Pick up to 5)
    $pairConditions = [];
    foreach ($target_pairs as $pair) {
        $c = (int)$pair['category_id'];
        if ($pair['subcategory_id'] === null) {
            $pairConditions[] = "(
                (
                    (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
                    OR 
                    EXISTS (
                        SELECT 1 FROM package_categories 
                        WHERE package_id = vs.package_id 
                        AND category_id = $c 
                        AND subcategory_id IS NULL
                    )
                )
            )";
        } else {
            $s = (int)$pair['subcategory_id'];
            $pairConditions[] = "(
                (
                    (SELECT COUNT(*) FROM package_categories WHERE package_id = vs.package_id) = 0
                    OR 
                    EXISTS (
                        SELECT 1 FROM package_categories 
                        WHERE package_id = vs.package_id 
                        AND category_id = $c 
                        AND (subcategory_id IS NULL OR subcategory_id = $s)
                    )
                )
            )";
        }
    }
    
    $pairSql = implode(" OR ", $pairConditions);

    $total_amount = (float)($order['total_amount'] ?? 0);

    // Find vendors in City with matching Categories & Subcategories
    // Priority: Active, positive wallet balance, sorted by least total jobs (for load balancing)
    $vQuery = "
        SELECT DISTINCT v.id 
        FROM vendors v
        INNER JOIN vendor_subscriptions vs ON vs.vendor_id = v.id AND vs.status = 'active' AND vs.credits_remaining > 0
        INNER JOIN packages p ON vs.package_id = p.id
        LEFT JOIN vendor_wallet vw ON vw.vendor_id = v.id
        WHERE v.city = ?
        AND ($pairSql)
        AND v.status = 'active'
        AND (vw.balance IS NULL OR vw.balance >= 0)
        AND (p.order_min_price IS NULL OR ? >= p.order_min_price)
        AND (p.order_max_price IS NULL OR ? <= p.order_max_price)
        ORDER BY (SELECT COUNT(*) FROM order_vendor_notifications WHERE vendor_id = v.id AND status='accepted') ASC
        LIMIT 5
    ";

    $stmt = $vn_pdo->prepare($vQuery);
    $stmt->execute([$city, $total_amount, $total_amount]);
    $vendor_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($vendor_ids)) {
        // Fallback: Notify Admin that no vendors matched
        require_once __DIR__ . '/admin_notify.php';
        sendAdminAlert($order_id, 'no_matching_vendors', $shop_pdo);
        return ['success' => false, 'message' => "No matching vendors found in $city. Admin notified."];
    }

    // 5. Insert Notifications (Vendor DB) - BROADCAST to found vendors
    $sent_at = date('Y-m-d H:i:s');
    $notified_count = 0;
    
    foreach ($vendor_ids as $vid) {
        // Check duplicate
        $chk = $vn_pdo->prepare("SELECT id FROM order_vendor_notifications WHERE order_id=? AND vendor_id=?");
        $chk->execute([$order_id, $vid]);
        
        if (!$chk->fetchColumn()) {
            $insStmt = $vn_pdo->prepare("INSERT INTO order_vendor_notifications (order_id, vendor_id, status, sent_at) VALUES (?, ?, 'pending', ?)");
            $insStmt->execute([$order_id, $vid, $sent_at]);
            $notified_count++;
        }
    }

    return ['success' => true, 'message' => "Broadcasting complete. $notified_count vendors notified."];
}

// REST API Handling
if (isset($_GET['order_id']) || isset($_POST['order_id'])) {
    $order_id = intval($_GET['order_id'] ?? $_POST['order_id']);
    header('Content-Type: application/json');

    if ($order_id > 0) {
        try {
            $result = dispatch_order($order_id);
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid Order ID']);
    }
}
