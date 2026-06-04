<?php
// vendor/vendor_accept_job.php
// Accept job: locks vendor notification (vendor DB), locks order (main DB), assigns vendor, marks others missed, credits wallet

session_start();
header('Content-Type: application/json');

require_once '../db.php';       // $conn = vendor DB (mysqli)
require_once '../db_main.php';  // $mainConn = main DB (mysqli)
require_once '../backend/whatsapp_helper.php';

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    echo json_encode(['success'=>false,'message'=>'Please login first']);
    exit;
}

$vendor_id = (int)$_SESSION['vendor_id'];
$order_id = intval($_POST['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid order id']);
    exit;
}

try {
    // Start vendor DB transaction (we use vendor DB to lock notifications; main DB updates follow)
    $conn->begin_transaction();

    // 1) Ensure this vendor still has pending notification for this order (lock row)
    $stmt = $conn->prepare("SELECT id FROM order_vendor_notifications WHERE order_id = ? AND vendor_id = ? AND status = 'pending' FOR UPDATE");
    $stmt->bind_param("ii",$order_id,$vendor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows === 0) {
        throw new Exception("Job no longer available.");
    }

    // Verify active subscription (removed date-based expires_at >= NOW() check)
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

    // Query order's category and subcategory
    $cat_stmt = $mainConn->prepare("SELECT s.category_id, s.subcategory_id FROM orders o JOIN services s ON o.service_id = s.id WHERE o.id = ?");
    $cat_stmt->bind_param("i", $order_id);
    $cat_stmt->execute();
    $cat_row = $cat_stmt->get_result()->fetch_assoc();
    $cat_stmt->close();

    if (!$cat_row) {
        throw new Exception("Order not found or has no category.");
    }
    $order_category_id = (int)$cat_row['category_id'];
    $order_subcategory_id = $cat_row['subcategory_id'] !== null ? (int)$cat_row['subcategory_id'] : null;

    // Check category/subcategory limitation
    $pc_chk = $conn->prepare("SELECT COUNT(*) as cnt FROM package_categories WHERE package_id = ?");
    $pc_chk->bind_param("i", $package_id);
    $pc_chk->execute();
    $pc_count = (int)$pc_chk->get_result()->fetch_assoc()['cnt'];
    $pc_chk->close();

    if ($pc_count > 0) {
        if ($order_subcategory_id !== null) {
            $pc_allowed = $conn->prepare("SELECT id FROM package_categories WHERE package_id = ? AND category_id = ? AND (subcategory_id IS NULL OR subcategory_id = ?) LIMIT 1");
            $pc_allowed->bind_param("iii", $package_id, $order_category_id, $order_subcategory_id);
        } else {
            $pc_allowed = $conn->prepare("SELECT id FROM package_categories WHERE package_id = ? AND category_id = ? AND subcategory_id IS NULL LIMIT 1");
            $pc_allowed->bind_param("ii", $package_id, $order_category_id);
        }
        $pc_allowed->execute();
        $is_allowed = ($pc_allowed->get_result()->num_rows > 0);
        $pc_allowed->close();
        
        if (!$is_allowed) {
            throw new Exception("Your subscription package does not allow accepting tasks in this category/subcategory.");
        }
    }

    // 2) Lock order row in main DB
    $stmt = $mainConn->prepare("SELECT assigned_vendor_id FROM orders WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i",$order_id);
    $stmt->execute();
    $resOrder = $stmt->get_result();
    $orderRow = $resOrder->fetch_assoc();
    $stmt->close();

    if (!empty($orderRow['assigned_vendor_id'])) {
        throw new Exception("Another vendor already accepted this job.");
    }

    // 3) Get order amount (sum of order_items.price) from main DB
    $stmt = $mainConn->prepare("SELECT COALESCE(SUM(price),0) AS total_amount FROM order_items WHERE order_id = ?");
    $stmt->bind_param("i",$order_id);
    $stmt->execute();
    $resAmt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $order_amount = floatval($resAmt['total_amount'] ?? 0.0);

    // 4) Assign vendor in main DB
    $stmt = $mainConn->prepare("UPDATE orders SET assigned_vendor_id = ?, status = 'assigned', assigned_at = NOW() WHERE id = ?");
    $stmt->bind_param("ii",$vendor_id,$order_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Failed to assign order: " . $mainConn->error);
    }
    $stmt->close();

    // 5) Update this vendor's notification -> accepted (vendor DB)
    $responded_at = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE order_vendor_notifications SET status = 'accepted', responded_at = ?, remark = ? WHERE order_id = ? AND vendor_id = ?");
    $remark = "Accepted by Vendor #{$vendor_id}";
    $stmt->bind_param("ssii",$responded_at,$remark,$order_id,$vendor_id);
    $stmt->execute();
    $stmt->close();

    // 6) Mark all other vendor notifications as missed
    $stmt = $conn->prepare("UPDATE order_vendor_notifications SET status = 'missed', responded_at = ?, remark = ? WHERE order_id = ? AND vendor_id != ? AND status = 'pending'");
    $otherRemark = "Accepted by Vendor #{$vendor_id}";
    $stmt->bind_param("ssii",$responded_at,$otherRemark,$order_id,$vendor_id);
    $stmt->execute();
    $stmt->close();

    // 7) Ensure vendor_wallet exists (vendor DB)
    $stmt = $conn->prepare("INSERT INTO vendor_wallet (vendor_id, balance, total_earned, total_withdrawn) VALUES (?,0,0,0) ON DUPLICATE KEY UPDATE vendor_id = vendor_id");
    $stmt->bind_param("i",$vendor_id);
    $stmt->execute();
    $stmt->close();

    // 8) Credit wallet (vendor DB) - credit full order_amount (or you may want to credit vendor_share only)
    // Here I credit vendor_share = 80% of order_amount (since you show 20% cut)
    if ($order_amount > 0) {
        $vendor_share = round($order_amount * 0.80, 2);

        $stmt = $conn->prepare("UPDATE vendor_wallet SET balance = balance + ?, total_earned = total_earned + ? WHERE vendor_id = ?");
        $stmt->bind_param("dii",$vendor_share,$vendor_share,$vendor_id);
        $stmt->execute();
        $stmt->close();

        $desc = "Credit for Order #{$order_id}";
        $stmt = $conn->prepare("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES (?, ?, 'credit', ?, ?, 'completed', NOW())");
        $stmt->bind_param("iids",$vendor_id,$order_id,$vendor_share,$desc);
        $stmt->execute();
        $stmt->close();
    }

    // Decrement subscription credits
    $new_credits = $credits_remaining - 1;
    $new_status = ($new_credits <= 0) ? 'exhausted' : 'active';
    
    $up_sub_stmt = $conn->prepare("UPDATE vendor_subscriptions SET credits_remaining = ?, status = ? WHERE id = ?");
    $up_sub_stmt->bind_param("isi", $new_credits, $new_status, $sub_id);
    $up_sub_stmt->execute();
    $up_sub_stmt->close();

    // Commit vendor DB transaction
    $conn->commit();

    echo json_encode(['success'=>true,'message'=>"Job accepted! ₹" . number_format($order_amount*0.80,2) . " credited (80% after 20% cut)."]);
    exit;

} catch (Exception $e) {
    // rollback vendor DB transaction
    if ($conn->in_transaction) {
        @$conn->rollback();
    }
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
