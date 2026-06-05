<?php
// vendor/vendor_accept_job.php
session_start();

ini_set("display_errors", 0);
ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/accept_job_error.log");
error_reporting(E_ALL);

header('Content-Type: application/json');

require_once '../db.php';       // vendor DB
require_once '../db_main.php';  // main DB
require_once '../backend/whatsapp_helper.php';

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login']);
    exit;
}

$vendor_id = (int)$_SESSION['vendor_id'];
$order_id  = (int)($_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order']);
    exit;
}

try {
    // --- WALLET DEBT CHECK ---
    $wStmt = $conn->prepare("SELECT balance FROM vendor_wallet WHERE vendor_id = ?");
    $wStmt->bind_param("i", $vendor_id);
    $wStmt->execute();
    $wRes = $wStmt->get_result();
    if ($wRow = $wRes->fetch_assoc()) {
        if (floatval($wRow['balance']) < 0) {
            throw new Exception("Wallet recharge required! Your balance is negative. Please recharge to accept new jobs.");
        }
    }
    $wStmt->close();
    // -------------------------

    $conn->begin_transaction();

    // 1. Lock Notification
    $stmt = $conn->prepare("SELECT id FROM order_vendor_notifications WHERE order_id = ? AND vendor_id = ? AND status = 'pending' FOR UPDATE");
    $stmt->bind_param("ii", $order_id, $vendor_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows == 0) throw new Exception("This job is no longer available.");
    $stmt->close();

    // 2. Get Service Type/Category/Subcategory
    // We need service_id to find the Main Category and Subcategory
    $stmt = $mainConn->prepare("SELECT o.service_id, s.category_id as main_cat_id, s.subcategory_id as main_subcat_id 
                                FROM orders o 
                                JOIN services s ON o.service_id = s.id 
                                WHERE o.id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $orderRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orderRow) throw new Exception("Order not found");
    $mainCatId = $orderRow['main_cat_id'];
    $mainSubcatId = $orderRow['main_subcat_id'] !== null ? (int)$orderRow['main_subcat_id'] : null;

    // Subscription & credits check (removed date-based expires_at >= NOW() check)
    $sub_stmt = $conn->prepare("SELECT vs.id, vs.credits_remaining, vs.package_id 
                                FROM vendor_subscriptions vs 
                                WHERE vs.vendor_id = ? AND vs.status = 'active' AND vs.credits_remaining > 0 
                                LIMIT 1");
    $sub_stmt->bind_param("i", $vendor_id);
    $sub_stmt->execute();
    $sub_row = $sub_stmt->get_result()->fetch_assoc();
    $sub_stmt->close();

    if (!$sub_row) {
        throw new Exception("You need an active subscription package with task credits to accept this job.");
    }

    $sub_id = $sub_row['id'];
    $credits_remaining = (int)$sub_row['credits_remaining'];
    $package_id = (int)$sub_row['package_id'];

    // Category/Subcategory limitation check
    $pc_chk = $conn->prepare("SELECT COUNT(*) as cnt FROM package_categories WHERE package_id = ?");
    $pc_chk->bind_param("i", $package_id);
    $pc_chk->execute();
    $pc_count = (int)$pc_chk->get_result()->fetch_assoc()['cnt'];
    $pc_chk->close();

    if ($pc_count > 0) {
        if ($mainSubcatId !== null) {
            $pc_allowed = $conn->prepare("SELECT id FROM package_categories WHERE package_id = ? AND ( (category_id = ? AND subcategory_id IS NULL) OR (subcategory_id = ?) ) LIMIT 1");
            $pc_allowed->bind_param("iii", $package_id, $mainCatId, $mainSubcatId);
        } else {
            $pc_allowed = $conn->prepare("SELECT id FROM package_categories WHERE package_id = ? AND category_id = ? AND subcategory_id IS NULL LIMIT 1");
            $pc_allowed->bind_param("ii", $package_id, $mainCatId);
        }
        $pc_allowed->execute();
        $is_allowed = ($pc_allowed->get_result()->num_rows > 0);
        $pc_allowed->close();
        
        if (!$is_allowed) {
            throw new Exception("Your subscription package does not allow accepting tasks in this category/subcategory.");
        }
    }

    // 3. Get MY Categories
    $myCats = [];
    $stmt = $conn->prepare("SELECT category_id FROM vendor_categories WHERE vendor_id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $myCats[] = $r['category_id'];
    $stmt->close();

    // 3b. Get vendor role (internal/external)
    $vendor_role = 'external';
    $stmt = $conn->prepare("SELECT role FROM vendors WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $roleRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($roleRow['role'])) {
        $vendor_role = strtolower(trim($roleRow['role']));
    }

    // 4. Determine Capability (Can I do Main?)
    $canDoMain = in_array($mainCatId, $myCats);

    // 5. UPDATE ASSIGNMENTS TABLE
    // Logic: Look for a PENDING Assignment
    // If I can do Main, I look for 'decoration' (Main Service).
    // If I can't, I look for pending 'addon' (or specific activity if rows existed).
    // Currently, rows might NOT exist if checkount didn't create them.
    // If rows don't exist, we must CREATE one.

    // Check pending assignments
    $stmt = $mainConn->prepare("SELECT id, service_type, vendor_id FROM order_vendor_assignments WHERE order_id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $resA = $stmt->get_result();
    $assignments = [];
    while ($row = $resA->fetch_assoc()) $assignments[] = $row;
    $stmt->close();

    $targetAssignmentId = 0;
    $roleTaken = '';

    // A. Try to take Main Service (Decoration)
    if ($canDoMain) {
        foreach ($assignments as $asn) {
            if (strtolower($asn['service_type']) == 'decoration' && empty($asn['vendor_id'])) {
                $targetAssignmentId = $asn['id'];
                $roleTaken = 'decoration';
                break;
            }
        }
        // If not found, maybe create it?
        if (!$targetAssignmentId) {
            // Check if Main is already taken in `orders` table to be safe
            $checkO = $mainConn->query("SELECT vendor_id FROM orders WHERE id=$order_id")->fetch_assoc();
            if (empty($checkO['vendor_id'])) {
                // Insert new assignment row for Main
                $stmt = $mainConn->prepare("INSERT INTO order_vendor_assignments (order_id, service_type, status, created_at) VALUES (?, 'decoration', 'pending', NOW())");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $targetAssignmentId = $stmt->insert_id;
                $roleTaken = 'decoration';
                $stmt->close();
            }
        }
    }

    // B. If not Main, or Main taken, try Other (Addons)
    if (!$targetAssignmentId) {
        // Look for other pending slots that match vendor's role
        foreach ($assignments as $asn) {
            if (strtolower($asn['service_type']) != 'decoration' && empty($asn['vendor_id'])) {
                // To be truly exclusive, we should check if this vendor is eligible for this specific addon
                // But for now, since notifications are already filtered, we can trust the assignments
                $targetAssignmentId = $asn['id'];
                $roleTaken = $asn['service_type']; 
                break;
            }
        }
    }

    if (!$targetAssignmentId) {
        throw new Exception("Sorry, this job (or the specific role you were notified for) has already been taken by another vendor.");
    }

    // 6. Assign Me
    $loc_accepted = $_POST['loc'] ?? null;
    $stmt = $mainConn->prepare("UPDATE order_vendor_assignments SET vendor_id = ?, status = 'assigned', loc_accepted = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("isi", $vendor_id, $loc_accepted, $targetAssignmentId);
    $stmt->execute();
    $stmt->close();

    // 7. Update Legacy Columns in Orders Table (Sync)
    if ($roleTaken == 'decoration') {
        $mainConn->query("UPDATE orders SET vendor_id = $vendor_id, status='assigned', assigned_at=NOW() WHERE id=$order_id");
    } else {
        $mainConn->query("UPDATE orders SET addon_vendor_id = $vendor_id, addon_status='assigned' WHERE id=$order_id");
    }
    
    // Send WhatsApp Notification for assignment
    sendOrderStatusNotification($mainConn, $order_id, 'assigned');

    // 8. Update Notification
    $now = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE order_vendor_notifications SET status = 'accepted', responded_at = ? WHERE order_id = ? AND vendor_id = ?");
    $stmt->bind_param("sii", $now, $order_id, $vendor_id);
    $stmt->execute();
    $stmt->close();

    // 9. Credit Vendor Wallet (aligned with alert payout)
    // Ensure wallet exists
    $stmt = $conn->prepare("INSERT INTO vendor_wallet (vendor_id, balance, total_earned, total_withdrawn) VALUES (?,0,0,0) ON DUPLICATE KEY UPDATE vendor_id = vendor_id");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $stmt->close();

    // Determine payout based on role and assignment
    $payout = 0.0;
    if ($roleTaken === 'decoration') {
        $stmt = $mainConn->prepare("SELECT s.vendor_price, s.manpower_price, o.base_amount FROM orders o JOIN services s ON o.service_id = s.id WHERE o.id = ? LIMIT 1");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $svcRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $vendor_price = isset($svcRow['vendor_price']) ? floatval($svcRow['vendor_price']) : 0;
        $manpower_price = isset($svcRow['manpower_price']) ? floatval($svcRow['manpower_price']) : 0;
        $base_price = isset($svcRow['base_amount']) ? floatval($svcRow['base_amount']) : 0;

        if ($vendor_role === 'internal') {
            $payout = $manpower_price > 0 ? $manpower_price : ($vendor_price > 0 ? $vendor_price : $base_price);
        } else {
            $payout = $vendor_price > 0 ? $vendor_price : $base_price;
        }
    } else {
        // Addon payout: first addon matching vendor categories
        $addonQ = $mainConn->query("SELECT a.id, a.price, a.category_id FROM order_addons oa JOIN addons a ON oa.addon_id = a.id WHERE oa.order_id = $order_id");
        if ($addonQ) {
            while ($addon = $addonQ->fetch_assoc()) {
                if (in_array((int)$addon['category_id'], $myCats)) {
                    $payout = floatval($addon['price']);
                    break;
                }
            }
        }
    }

    if ($payout > 0) {
        $stmt = $conn->prepare("UPDATE vendor_wallet SET balance = balance + ?, total_earned = total_earned + ? WHERE vendor_id = ?");
        $stmt->bind_param("dii", $payout, $payout, $vendor_id);
        $stmt->execute();
        $stmt->close();

        $desc = "Credit for Order #{$order_id}";
        $stmt = $conn->prepare("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES (?, ?, 'credit', ?, ?, 'completed', NOW())");
        $stmt->bind_param("iids", $vendor_id, $order_id, $payout, $desc);
        $stmt->execute();
        $stmt->close();
    }

    // 9. Cancel Competitors (Simplified)
    // If I took 'decoration', cancel other decorators.
    if ($roleTaken == 'decoration') {
        // Find other decorators
        // ... (Similar logic to before) ...
    }
    // For now, let's keep it simple: If Main took, cancel pending duplicate Mains.
    // If Addon took, cancel ? Maybe wait.
    // Decrement subscription credits
    $new_credits = $credits_remaining - 1;
    $new_status = ($new_credits <= 0) ? 'exhausted' : 'active';
    
    $up_sub_stmt = $conn->prepare("UPDATE vendor_subscriptions SET credits_remaining = ?, status = ? WHERE id = ?");
    $up_sub_stmt->bind_param("isi", $new_credits, $new_status, $sub_id);
    $up_sub_stmt->execute();
    $up_sub_stmt->close();

    $conn->commit();
    $msgPayout = $payout > 0 ? (" | Credited: ₹" . number_format($payout, 2)) : "";
    echo json_encode(['success' => true, 'message' => "Job assigned successfully as $roleTaken{$msgPayout}"]);
} catch (Exception $e) {
    @$conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
