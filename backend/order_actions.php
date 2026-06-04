<?php
/**
 * Vendor Order Actions Handler
 * Path: backend/order_actions.php
 */

ob_start();
require_once '../vendor/includes/session_manager.php';
header('Content-Type: application/json');

// Register shutdown function to catch any fatal errors and return valid JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal PHP Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
        ]);
        exit;
    }
});

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please login.']);
    exit;
}

require_once '../db.php';       
require_once '../db_main.php';  
require_once 'order_helpers.php'; 
require_once 'whatsapp_helper.php';

$action        = $_POST['action'] ?? '';
$assignment_id = intval($_POST['assignment_id'] ?? 0);
$order_param   = intval($_POST['order_id'] ?? 0);
$vendor_id     = intval($_SESSION['vendor_id']);
$lat           = $_POST['latitude'] ?? '';
$lng           = $_POST['longitude'] ?? '';
$loc           = ($lat && $lng) ? ($lat . "," . $lng) : '';

try {
    // 1. Resolve Assignment ID
    if ($assignment_id <= 0 && $order_param > 0) {
        $fStmt = $mainConn->prepare("SELECT id FROM order_vendor_assignments WHERE order_id = ? AND vendor_id = ? AND status != 'completed' LIMIT 1");
        $fStmt->bind_param("ii", $order_param, $vendor_id);
        $fStmt->execute();
        $fRes = $fStmt->get_result();
        if ($r = $fRes->fetch_assoc()) {
            $assignment_id = intval($r['id']);
        }
        $fStmt->close();
    }

    if ($assignment_id <= 0) {
        error_log("Order Action Error: Assignment not found for order_id=$order_param, vendor_id=$vendor_id");
        echo json_encode(['success' => false, 'message' => "Job Assignment not found. Please refresh and try again."]);
        exit;
    }

    // 2. Fetch Job Details
    $stmt = $mainConn->prepare("SELECT id, order_id, service_type FROM order_vendor_assignments WHERE id = ? AND vendor_id = ?");
    $stmt->bind_param("ii", $assignment_id, $vendor_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Job not authorized.']);
        exit;
    }

    $order_id     = intval($row['order_id']);
    $service_type = strtolower($row['service_type']);

    // 3. Process Actions
    if ($action === 'start_journey') {
        $stmt = $mainConn->prepare("UPDATE order_vendor_assignments SET status = 'out_for_service', out_for_service_at = NOW(), loc_out = ? WHERE id = ?");
        $stmt->bind_param("si", $loc, $assignment_id);
        if ($stmt->execute()) {
            syncLegacy($mainConn, $order_id, $service_type, 'out_for_service', 'out_for_service_at', 'out', $loc);
            sendOrderStatusNotification($mainConn, $order_id, 'out_for_service');
            echo json_encode(['success' => true, 'message' => 'Journey Started!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
    } 
    elseif ($action === 'mark_reached') {
        $res = uploadFiles($_FILES['reached_proof'] ?? null, '../uploads/proofs/');
        if (!$res['success']) {
            $err = !empty($res['errors']) ? implode(" | ", $res['errors']) : "No file selected or file too large.";
            echo json_encode(['success' => false, 'message' => $err]);
            exit;
        }
        
        $proofJson = json_encode($res['files']);
        $stmt = $mainConn->prepare("UPDATE order_vendor_assignments SET status = 'reached', reached_at = NOW(), loc_reached = ?, reached_proof = ? WHERE id = ?");
        $stmt->bind_param("ssi", $loc, $proofJson, $assignment_id);
        if ($stmt->execute()) {
            syncLegacy($mainConn, $order_id, $service_type, 'reached', 'reached_at', 'reached', $loc, 'reached_proof', $proofJson);
            sendOrderStatusNotification($mainConn, $order_id, 'reached');
            echo json_encode(['success' => true, 'message' => 'Arrival Marked!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'DB Update failed.']);
        }
    } 
    elseif ($action === 'start_work') {
        $stmt = $mainConn->prepare("UPDATE order_vendor_assignments SET status = 'started', started_at = NOW(), loc_started = ? WHERE id = ?");
        $stmt->bind_param("si", $loc, $assignment_id);
        if ($stmt->execute()) {
            syncLegacy($mainConn, $order_id, $service_type, 'started', 'started_at', 'started', $loc);
            sendOrderStatusNotification($mainConn, $order_id, 'started');
            echo json_encode(['success' => true, 'message' => 'Work Started!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
    } 
    elseif ($action === 'complete_job') {
        $payment_mode = $_POST['payment_mode'] ?? 'cash';
        $vendor_notes = $_POST['vendor_notes'] ?? '';
        
        $res = uploadFiles($_FILES['work_proof'] ?? null, '../uploads/proofs/');
        if (!$res['success']) {
            $err = !empty($res['errors']) ? implode(" | ", $res['errors']) : "Please upload work completion photos.";
            echo json_encode(['success' => false, 'message' => $err]);
            exit;
        }
        $workJson = json_encode($res['files']);
        
        $payment_proof = '';
        if ($payment_mode === 'online' && !empty($_FILES['payment_proof'])) {
            $pRes = uploadFiles($_FILES['payment_proof'], '../uploads/proofs/');
            if ($pRes['success']) $payment_proof = $pRes['files'][0];
        }

        $stmt = $mainConn->prepare("UPDATE order_vendor_assignments SET status = 'completed', completed_at = NOW(), loc_completed = ?, work_proof = ?, payment_method = ?, payment_proof = ?, notes = ? WHERE id = ?");
        $stmt->bind_param("sssssi", $loc, $workJson, $payment_mode, $payment_proof, $vendor_notes, $assignment_id);
        
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            // 2. Legacy Sync (Update Main Orders table)
            syncLegacy($mainConn, $order_id, $service_type, 'completed', 'completed_at', 'completed', $loc, 'work_proof', $workJson);
            sendOrderStatusNotification($mainConn, $order_id, 'completed');

            // --- SYNC TO PARTNERS DB ---
            // Update notification status so it doesn't show in "Accepted Orders" count on dashboard
            $stmtSync = $conn->prepare("UPDATE order_vendor_notifications SET status = 'completed' WHERE order_id = ? AND vendor_id = ?");
            $stmtSync->bind_param("ii", $order_id, $vendor_id);
            $stmtSync->execute();
            $stmtSync->close();
            // ---------------------------

            // --- DECORATOR VIDEO UPLOADS ---
            // Handle optional video uploads (before_video, after_video, selfie_video)
            $video_uploaded = false;
            foreach (['before_video', 'after_video', 'selfie_video'] as $file_key) {
                if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                    $video_uploaded = true;
                    break;
                }
            }
            if ($video_uploaded) {
                try {
                    require_once 'decorator_video_uploader.php';
                    $uploader = new DecoratorVideoUploader($conn, __DIR__ . '/../uploads');
                    foreach (['before_video' => 'before', 'after_video' => 'after', 'selfie_video' => 'selfie'] as $file_key => $db_type) {
                        if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                            $res = $uploader->uploadVideo($_FILES[$file_key], $vendor_id, $order_id, $db_type);
                            if (!$res['success']) {
                                error_log("Decorator video upload error ($file_key): " . $res['message']);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    error_log("Decorator video upload exception: " . $e->getMessage());
                }
            }
            // -------------------------------
            
            $qS = $mainConn->prepare("SELECT a.vendor_price, s.manpower_price, s.vendor_price as s_vendor_price, s.name as service_name, o.base_amount, o.remaining_amount FROM order_vendor_assignments a JOIN orders o ON a.order_id = o.id JOIN services s ON o.service_id = s.id WHERE a.id = ?");
            $qS->bind_param("i", $assignment_id);
            if (!$qS->execute()) {
                error_log("Order Action Error: Failed to fetch prices for assignment_id=$assignment_id. Error: " . $mainConn->error);
                echo json_encode(['success' => false, 'message' => "Failed to process payment data. Please contact admin."]);
                exit;
            }
            $pRes = $qS->get_result();
            $pRow = $pRes->fetch_assoc();
            $qS->close();

            if ($pRow) {
                // If assignment vendor_price is 0, fallback to the service vendor_price. If still 0, fallback to base_amount.
                $vPriceInput = (float)($pRow['vendor_price'] > 0 ? $pRow['vendor_price'] : ($pRow['s_vendor_price'] ?? 0));
                $mPrice      = (float)($pRow['manpower_price'] ?? 0);
                $bPrice      = (float)($pRow['base_amount'] ?? 0);
                $collectAmt  = (float)($pRow['remaining_amount'] ?? 0);
                $svcName     = htmlspecialchars($pRow['service_name'] ?? '');
                
                $vRole = 'external';
                $qv = $conn->query("SELECT role FROM vendors WHERE id = $vendor_id LIMIT 1");
                if ($rv = $qv->fetch_assoc()) $vRole = strtolower(trim($rv['role']));
                $payout = ($vRole === 'internal') ? ($mPrice > 0 ? $mPrice : ($vPriceInput > 0 ? $vPriceInput : $bPrice)) : ($vPriceInput > 0 ? $vPriceInput : $bPrice);
                
                $conn->query("INSERT IGNORE INTO vendor_wallet (vendor_id, balance, total_earned, total_withdrawn) VALUES ($vendor_id, 0, 0, 0)");
                
                // 1. Log Cash Collected (type 'cash') - visual '+' only, no balance impact
                if ($payment_mode === 'cash' && $collectAmt > 0) {
                    $d_cash = "Cash Collected in Hand: Order #$order_id - $svcName";
                    $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $order_id, 'cash', $collectAmt, '" . $conn->real_escape_string($d_cash) . "', 'completed', NOW())");
                }

                // 2. Earnings (+ to balance)
                if ($payout > 0) {
                    $conn->query("UPDATE vendor_wallet SET balance = balance + $payout, total_earned = total_earned + $payout WHERE vendor_id = $vendor_id");
                    $d1 = "Earnings: Order #$order_id - $svcName";
                    $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $order_id, 'credit', $payout, '" . $conn->real_escape_string($d1) . "', 'completed', DATE_ADD(NOW(), INTERVAL 1 SECOND))");
                }

                // 3. Cash Settlement (- from balance)
                if ($payment_mode === 'cash' && $collectAmt > 0) {
                    $conn->query("UPDATE vendor_wallet SET balance = balance - $collectAmt WHERE vendor_id = $vendor_id");
                    $d_settle = "Cash Settlement Deducted: Order #$order_id - $svcName";
                    $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $order_id, 'debit', $collectAmt, '" . $conn->real_escape_string($d_settle) . "', 'completed', DATE_ADD(NOW(), INTERVAL 2 SECOND))");
                }

                // 4. PROCESS ADDONS
                $addon_total = floatval($_POST['addon_total'] ?? 0);
                $addon_items_json = $_POST['addon_items_json'] ?? '[]';
                
                if ($addon_total > 0) {
                    // Update Order Table
                    $mainConn->query("UPDATE orders SET 
                        addons_amount = addons_amount + $addon_total, 
                        total_amount = total_amount + $addon_total, 
                        remaining_amount = remaining_amount + $addon_total,
                        addons = IF(addons IS NULL OR addons = '', '$addon_items_json', CONCAT(addons, ' | ', '$addon_items_json'))
                        WHERE id = $order_id");
                    
                    // Vendor gets 80% of addon
                    $addon_earning = $addon_total * 0.8;
                    
                    // Credit 80% to wallet
                    $conn->query("UPDATE vendor_wallet SET balance = balance + $addon_earning, total_earned = total_earned + $addon_earning WHERE vendor_id = $vendor_id");
                    $d_addon = "Addon Earnings (80%): Order #$order_id - $svcName";
                    $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $order_id, 'credit', $addon_earning, '" . $conn->real_escape_string($d_addon) . "', 'completed', DATE_ADD(NOW(), INTERVAL 3 SECOND))");
                    
                    // If Cash, also deduct the 100% of addon from wallet (since they collected it)
                    if ($payment_mode === 'cash') {
                        $conn->query("UPDATE vendor_wallet SET balance = balance - $addon_total WHERE vendor_id = $vendor_id");
                        $d_addon_settle = "Addon Cash Settlement Deducted: Order #$order_id - $svcName";
                        $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $order_id, 'debit', $addon_total, '" . $conn->real_escape_string($d_addon_settle) . "', 'completed', DATE_ADD(NOW(), INTERVAL 4 SECOND))");
                    }
                }
            }
            echo json_encode(['success' => true, 'message' => 'Job Completed Successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed or job already completed.']);
        }
    } 
    else {
        echo json_encode(['success' => false, 'message' => 'Action not recognized.']);
    }

} catch (Throwable $t) {
    error_log("Order Action Fatal Error: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $t->getMessage()]);
    exit;
}
