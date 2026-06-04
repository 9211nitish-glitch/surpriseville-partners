<?php
session_start();
require_once '../db.php';
require_once '../db_main.php';

header('Content-Type: application/json');

// Check if vendor is logged in
if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$vendor_id = $_SESSION['vendor_id'];

// Helper function to check package allowances
function isAllowed($c, $s, $has_rules, $pc_rules) {
    if (!$has_rules) {
        return true; // No rules means all allowed
    }
    if (!isset($pc_rules[$c])) {
        return false; // Category not allowed
    }
    if (in_array(null, $pc_rules[$c], true) || empty($pc_rules[$c])) {
        return true; // All subcategories allowed under this category
    }
    return $s !== null && in_array((int)$s, $pc_rules[$c], true);
}

// 1. Get Vendor's Categories (Deprecated, removed)
$vCats = [];


// 2. Get Vendor's active subscription package and its allowed rules
$package_id = null;
$pc_rules = [];
$has_rules = false;

$sub_stmt = $conn->prepare("SELECT package_id FROM vendor_subscriptions WHERE vendor_id = ? AND status = 'active' AND credits_remaining > 0 LIMIT 1");
$sub_stmt->bind_param("i", $vendor_id);
$sub_stmt->execute();
$sub_res = $sub_stmt->get_result();
if ($sub_row = $sub_res->fetch_assoc()) {
    $package_id = (int)$sub_row['package_id'];
}
$sub_stmt->close();

if ($package_id !== null) {
    $pc_stmt = $conn->prepare("SELECT category_id, subcategory_id FROM package_categories WHERE package_id = ?");
    $pc_stmt->bind_param("i", $package_id);
    $pc_stmt->execute();
    $pc_res = $pc_stmt->get_result();
    while ($pc_row = $pc_res->fetch_assoc()) {
        $has_rules = true;
        $c = (int)$pc_row['category_id'];
        $s = $pc_row['subcategory_id'] !== null ? (int)$pc_row['subcategory_id'] : null;
        if (!isset($pc_rules[$c])) {
            $pc_rules[$c] = [];
        }
        $pc_rules[$c][] = $s;
    }
    $pc_stmt->close();
}

// 3. Get pending notifications for this vendor
$stmt = $conn->prepare("SELECT id as notification_id, order_id, sent_at FROM order_vendor_notifications WHERE vendor_id = ? AND status = 'pending' ORDER BY sent_at DESC");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$res = $stmt->get_result();
$notifications = [];
while ($row = $res->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

$alerts = [];
if (!empty($notifications)) {
    $orderIds = array_map(function($n) { return (int)$n['order_id']; }, $notifications);
    $orderIdStr = implode(',', $orderIds);
    
    // Fetch details of these orders from main DB
    $sql = "SELECT 
                o.id as order_id,
                o.created_at,
                o.city,
                oi.service_id,
                s.category_id as main_service_cat,
                s.subcategory_id as main_service_subcat,
                s.name as design_name,
                s.description as includes,
                s.main_image as image,
                oi.price
            FROM orders o
            LEFT JOIN services s ON o.service_id = s.id
            LEFT JOIN order_items oi ON o.id = oi.order_id AND oi.service_id = o.service_id
            WHERE o.id IN ($orderIdStr)";
            
    $resMain = $mainConn->query($sql);
    $orderDetails = [];
    if ($resMain) {
        while ($row = $resMain->fetch_assoc()) {
            $orderDetails[$row['order_id']] = $row;
        }
    }
    
    // Fetch addons for these orders from main DB
    $addons = [];
    $aq = "SELECT oa.order_id, a.category_id, a.subcategory_id, a.name, a.description, a.image, a.price 
           FROM order_addons oa
           JOIN addons a ON oa.addon_id = a.id
           WHERE oa.order_id IN ($orderIdStr)";
    $resAddons = $mainConn->query($aq);
    if ($resAddons) {
        while ($row = $resAddons->fetch_assoc()) {
            $addons[$row['order_id']][] = $row;
        }
    }
    
    // Process and filter alerts
    foreach ($notifications as $notif) {
        $order_id = (int)$notif['order_id'];
        if (!isset($orderDetails[$order_id])) continue;
        
        $row = $orderDetails[$order_id];
        $row['notification_id'] = $notif['notification_id'];
        $row['sent_at'] = $notif['sent_at'];
        
        $isAddon = false;
        
        $main_cat = $row['main_service_cat'] !== null ? (int)$row['main_service_cat'] : null;
        $main_subcat = $row['main_service_subcat'] !== null ? (int)$row['main_service_subcat'] : null;
        
        $allowedBySub = isAllowed($main_cat, $main_subcat, $has_rules, $pc_rules);
        
        if ($allowedBySub) {
            $alerts[] = [
                'notification_id' => $row['notification_id'],
                'order_id' => $row['order_id'],
                'service_id' => $row['service_id'],
                'design_name' => $row['design_name'],
                'includes' => strip_tags($row['includes'], '<strong><br>'),
                'price' => $row['price'],
                'city' => $row['city'],
                'image' => $row['image'],
                'sent_at' => $row['sent_at'],
                'created_at' => $row['created_at'],
                'is_addon' => false
            ];
        } else {
            // Check addons
            if (isset($addons[$order_id])) {
                foreach ($addons[$order_id] as $addon) {
                    $addon_cat = $addon['category_id'] !== null ? (int)$addon['category_id'] : null;
                    $addon_subcat = $addon['subcategory_id'] !== null ? (int)$addon['subcategory_id'] : null;
                    
                    $addonAllowedBySub = isAllowed($addon_cat, $addon_subcat, $has_rules, $pc_rules);
                    
                    if ($addonAllowedBySub) {
                        $alerts[] = [
                            'notification_id' => $row['notification_id'],
                            'order_id' => $row['order_id'],
                            'service_id' => $row['service_id'],
                            'design_name' => $addon['name'] . " (Addon)",
                            'includes' => "Addon Task: " . strip_tags($addon['description'], '<strong><br>'),
                            'price' => $addon['price'],
                            'city' => $row['city'],
                            'image' => $addon['image'],
                            'sent_at' => $row['sent_at'],
                            'created_at' => $row['created_at'],
                            'is_addon' => true
                        ];
                        break;
                    }
                }
            }
        }
    }
}

$conn->close();
$mainConn->close();

echo json_encode(['success' => true, 'alerts' => $alerts, 'count' => count($alerts)]);
?>
