<?php
// vendor/gig_actions.php
session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

require_once '../db.php';
require_once '../backend/whatsapp_helper.php';
require_once 'gig_helper.php';

// 1. Auth Check
if (!isset($_SESSION['vendor_id'])) {
    echo json_encode(['success' => false, 'message' => 'Auth Error: Please login']);
    exit;
}

$vendor_id = (int)$_SESSION['vendor_id'];
$action = $_POST['action'] ?? '';
$task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;

if ($task_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Task ID']);
    exit;
}

try {
    // ======================================================
    // ACTION: DECLINE GIG
    // ======================================================
    if ($action === 'decline') {
        $stmt = $conn->prepare("UPDATE task_alerts SET status='declined', responded_at=NOW() WHERE task_id=? AND vendor_id=?");
        $stmt->bind_param("ii", $task_id, $vendor_id);

        if ($stmt->execute()) {
            $check = $conn->query("SELECT COUNT(*) as cnt FROM task_alerts WHERE task_id=$task_id AND status='pending'");
            $row = $check->fetch_assoc();

            if ($row['cnt'] == 0) {
                expandRadiusLoop($conn, $task_id);
                echo json_encode(['success' => true, 'message' => 'Declined. Finding new vendors nearby.']);
            } else {
                echo json_encode(['success' => true, 'message' => 'Gig offer declined.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error during decline.']);
        }
        exit;
    }

    // ======================================================
    // ACTION: ACCEPT GIG
    // ======================================================
    if ($action === 'accept') {
        $wStmt = $conn->prepare("SELECT balance FROM vendor_wallet WHERE vendor_id = ?");
        $wStmt->bind_param("i", $vendor_id);
        $wStmt->execute();
        $wRes = $wStmt->get_result();
        if ($wRow = $wRes->fetch_assoc()) {
            if (floatval($wRow['balance']) < 0) {
                echo json_encode(['success' => false, 'message' => "Wallet recharge required! Balance is negative (" . $wRow['balance'] . ")."]);
                exit;
            }
        }
        $wStmt->close();

        $check = $conn->query("SELECT status FROM manual_tasks WHERE id = $task_id LIMIT 1");
        $row = $check->fetch_assoc();

        if (!$row || $row['status'] !== 'open') {
            echo json_encode(['success' => false, 'message' => 'Task is no longer available.']);
            exit;
        }

        $conn->begin_transaction();
        try {
            $loc_accepted = $_POST['loc'] ?? null;
            $stmt = $conn->prepare("UPDATE manual_tasks SET assigned_vendor_id=?, status='assigned', vendor_status='assigned', loc_accepted=? WHERE id=? AND status='open'");
            $stmt->bind_param("isi", $vendor_id, $loc_accepted, $task_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $conn->query("UPDATE task_alerts SET status='accepted', responded_at=NOW() WHERE task_id=$task_id AND vendor_id=$vendor_id");
                $conn->query("UPDATE task_alerts SET status='missed', missed_reason='Taken by another' WHERE task_id=$task_id AND vendor_id!=$vendor_id");
                $conn->commit();
                
                sendOrderStatusNotification($conn, $task_id, 'assigned', true);
                syncStatusToCRM($conn, $task_id, 'assigned');
                echo json_encode(['success' => true, 'message' => 'Job Assigned Successfully!']);
            } else {
                throw new Exception("Task already taken.");
            }
        } catch (Exception $e) {
            $conn->rollback();
            $conn->query("UPDATE task_alerts SET status='missed', missed_reason='Taken while processing' WHERE task_id=$task_id AND vendor_id=$vendor_id");
            echo json_encode(['success' => false, 'message' => 'Missed it! Someone else accepted it.']);
        }
        exit;
    }

    // ======================================================
    // ACTION: START JOURNEY (GIG)
    // ======================================================
    if ($action === 'start_journey') {
        $lat = $_POST['latitude'] ?? '';
        $lng = $_POST['longitude'] ?? '';
        $loc = ($lat && $lng) ? ($lat . "," . $lng) : '';

        $stmt = $conn->prepare("UPDATE manual_tasks SET vendor_status = 'out_for_service', out_for_service_at = NOW(), vendor_loc_out = ? WHERE id = ? AND assigned_vendor_id = ?");
        $stmt->bind_param("sii", $loc, $task_id, $vendor_id);
        if ($stmt->execute()) {
            sendOrderStatusNotification($conn, $task_id, 'out_for_service', true);
            syncStatusToCRM($conn, $task_id, 'out_for_service');
            echo json_encode(['success' => true, 'message' => 'Journey Started!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
        exit;
    }

    // ======================================================
    // ACTION: MARK REACHED (GIG)
    // ======================================================
    if ($action === 'mark_reached') {
        $lat = $_POST['latitude'] ?? '';
        $lng = $_POST['longitude'] ?? '';
        $loc = ($lat && $lng) ? ($lat . "," . $lng) : '';

        $dir = '../uploads/proofs/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $proofs = [];
        if (isset($_FILES['reached_proof']['name'][0])) {
            foreach ($_FILES['reached_proof']['name'] as $k => $name) {
                if ($_FILES['reached_proof']['error'][$k] === 0) {
                    $n = time() . "_reached_" . basename($name);
                    if (move_uploaded_file($_FILES['reached_proof']['tmp_name'][$k], $dir . $n)) {
                        $proofs[] = $n;
                    }
                }
            }
        }
        $proof_json = json_encode($proofs);

        $stmt = $conn->prepare("UPDATE manual_tasks SET vendor_status = 'reached', reached_at = NOW(), vendor_loc_reached = ?, reached_proof = ? WHERE id = ? AND assigned_vendor_id = ?");
        $stmt->bind_param("ssii", $loc, $proof_json, $task_id, $vendor_id);
        if ($stmt->execute()) {
            sendOrderStatusNotification($conn, $task_id, 'reached', true);
            syncStatusToCRM($conn, $task_id, 'reached');
            echo json_encode(['success' => true, 'message' => 'Arrival Marked!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
        exit;
    }

    // ======================================================
    // ACTION: START WORK (GIG)
    // ======================================================
    if ($action === 'start_work') {
        $lat = $_POST['latitude'] ?? '';
        $lng = $_POST['longitude'] ?? '';
        $loc = ($lat && $lng) ? ($lat . "," . $lng) : '';

        $stmt = $conn->prepare("UPDATE manual_tasks SET vendor_status = 'started', started_at = NOW(), vendor_loc_started = ? WHERE id = ? AND assigned_vendor_id = ?");
        $stmt->bind_param("sii", $loc, $task_id, $vendor_id);
        if ($stmt->execute()) {
            sendOrderStatusNotification($conn, $task_id, 'started', true);
            syncStatusToCRM($conn, $task_id, 'started');
            echo json_encode(['success' => true, 'message' => 'Work Started!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Update failed.']);
        }
        exit;
    }

    // ======================================================
    // ACTION: COMPLETE GIG
    // ======================================================
    if ($action === 'complete') {
        $mode = $_POST['payment_mode'] ?? 'cash';
        $lat = $_POST['latitude'] ?? '';
        $lng = $_POST['longitude'] ?? '';
        $loc = ($lat && $lng) ? ($lat . "," . $lng) : '';

        $dir = '../uploads/proofs/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $proofs = [];
        $payment_proof = "";

        if (isset($_FILES['job_proof']['name'][0])) {
            foreach ($_FILES['job_proof']['name'] as $k => $name) {
                if ($_FILES['job_proof']['error'][$k] === 0) {
                    $n = time() . "_job_" . basename($name);
                    if (move_uploaded_file($_FILES['job_proof']['tmp_name'][$k], $dir . $n)) {
                        $proofs[] = $n;
                    }
                }
            }
        }

        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['size'] > 0) {
            $payment_proof = time() . "_pay_" . basename($_FILES['payment_proof']['name']);
            move_uploaded_file($_FILES['payment_proof']['tmp_name'], $dir . $payment_proof);
        }

        $proof_json = json_encode($proofs);

        $stmt = $conn->prepare("INSERT INTO task_completions (task_id, vendor_id, proof_media, payment_mode, payment_screenshot) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $task_id, $vendor_id, $proof_json, $mode, $payment_proof);

        if ($stmt->execute()) {
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
                    require_once '../backend/decorator_video_uploader.php';
                    $uploader = new DecoratorVideoUploader($conn, __DIR__ . '/../uploads');
                    foreach (['before_video' => 'before', 'after_video' => 'after', 'selfie_video' => 'selfie'] as $file_key => $db_type) {
                        if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                            $res = $uploader->uploadVideo($_FILES[$file_key], $vendor_id, $task_id, $db_type);
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
            $tRes = $conn->query("SELECT vendor_price, amount_to_collect, payment_status FROM manual_tasks WHERE id=$task_id");
            if ($tRow = $tRes->fetch_assoc()) {
                $vPrice = (float)$tRow['vendor_price'];
                $collectAmt = (float)$tRow['amount_to_collect'];

                $wCheck = $conn->query("SELECT id FROM vendor_wallet WHERE vendor_id=$vendor_id");
                if ($wCheck->num_rows == 0) {
                    $conn->query("INSERT INTO vendor_wallet (vendor_id, balance, total_earned, total_withdrawn) VALUES ($vendor_id, 0.00, 0.00, 0.00)");
                }

                if ($vPrice > 0) {
                    $conn->query("UPDATE vendor_wallet SET balance = balance + $vPrice, total_earned = total_earned + $vPrice WHERE vendor_id=$vendor_id");
                    $desc = "Earnings for Manual Task #$task_id";
                    $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $task_id, 'credit', $vPrice, '$desc', 'completed', NOW())");
                }

                $pStatus = $tRow['payment_status'] ?? 'pending';
                if ($mode === 'cash' && $collectAmt > 0 && $pStatus !== 'paid') {
                    $conn->query("UPDATE vendor_wallet SET balance = balance - $collectAmt WHERE vendor_id=$vendor_id");
                    $desc2 = "Cash Collected from Client for Task #$task_id";
                    $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $task_id, 'cash', $collectAmt, '$desc2', 'completed', NOW())");
                }

                // 4. PROCESS ADDONS
                $addon_total = floatval($_POST['addon_total'] ?? 0);
                $addon_items_json = $_POST['addon_items_json'] ?? '[]';
                
                if ($addon_total > 0) {
                    // Update Task Table
                    $conn->query("UPDATE manual_tasks SET 
                        addons_amount = addons_amount + $addon_total, 
                        amount_to_collect = amount_to_collect + $addon_total,
                        addons = IF(addons IS NULL OR addons = '', '$addon_items_json', CONCAT(addons, ' | ', '$addon_items_json'))
                        WHERE id = $task_id");
                    
                    // Vendor gets 80% of addon
                    $addon_earning = $addon_total * 0.8;
                    
                    // Credit 80% to wallet
                    $conn->query("UPDATE vendor_wallet SET balance = balance + $addon_earning, total_earned = total_earned + $addon_earning WHERE vendor_id = $vendor_id");
                    $d_addon = "Addon Earnings (80%): Task #$task_id";
                    $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $task_id, 'credit', $addon_earning, '" . $conn->real_escape_string($d_addon) . "', 'completed', DATE_ADD(NOW(), INTERVAL 3 SECOND))");
                    
                    // If Cash, also deduct the 100% of addon from wallet (since they collected it)
                    if ($mode === 'cash') {
                        $conn->query("UPDATE vendor_wallet SET balance = balance - $addon_total WHERE vendor_id = $vendor_id");
                        $d_addon_settle = "Addon Cash Settlement Deducted: Task #$task_id";
                        $conn->query("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status, created_at) VALUES ($vendor_id, $task_id, 'debit', $addon_total, '" . $conn->real_escape_string($d_addon_settle) . "', 'completed', DATE_ADD(NOW(), INTERVAL 4 SECOND))");
                    }
                }
            }

            $conn->query("UPDATE manual_tasks SET status='completed', vendor_status='completed', completed_at=NOW(), vendor_loc_completed='$loc', work_proof='$proof_json' WHERE id=$task_id");
            sendOrderStatusNotification($conn, $task_id, 'completed', true);
            syncStatusToCRM($conn, $task_id, 'completed');
            echo json_encode(['success' => true, 'message' => 'Submitted and Wallet updated.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid Action']);

} catch (Throwable $t) {
    error_log("Gig Action Fatal Error: " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine());
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $t->getMessage()]);
    exit;
}

