<?php
session_start();
require_once '../db.php';

// Assuming you post 'task_id' to this file from Admin Panel
if (isset($_POST['approve_task'])) {
    $tid = (int)$_POST['task_id'];

    $q = $conn->query("SELECT * FROM manual_tasks WHERE id=$tid");
    $task = $q->fetch_assoc();

    if ($task && $task['status'] == 'completed') {
        $vid = $task['assigned_vendor_id'];

        // Fetch Vendor Role
        $vRole = 'external';
        $vQ = $conn->query("SELECT role FROM vendors WHERE id=$vid LIMIT 1");
        if ($vRow = $vQ->fetch_assoc()) {
            $vRole = strtolower(trim($vRow['role']));
        }

        $vPrice = isset($task['vendor_price']) ? floatval($task['vendor_price']) : 0;
        $ivPrice = isset($task['internal_vendor_price']) ? floatval($task['internal_vendor_price']) : 0;

        if ($vRole === 'internal') {
            $amt = $vPrice > 0 ? $vPrice : 0;
        } else {
            $amt = $ivPrice > 0 ? $ivPrice : $vPrice;
        }

        // 1. Update Task
        $conn->query("UPDATE manual_tasks SET status='verified' WHERE id=$tid");
        $conn->query("UPDATE task_completions SET admin_approved=1 WHERE task_id=$tid");

        // 2. CREDIT WALLET (Using EXISTING TABLES)
        // Check if wallet entry exists, if not create it (Safety check)
        $wc = $conn->query("SELECT id FROM vendor_wallet WHERE vendor_id=$vid");
        if ($wc->num_rows == 0) {
            $conn->query("INSERT INTO vendor_wallet (vendor_id, balance, total_earned) VALUES ($vid, 0, 0)");
        }

        // Update Balance
        $conn->query("UPDATE vendor_wallet SET balance = balance + $amt, total_earned = total_earned + $amt WHERE vendor_id = $vid");

        // Add Transaction Record (order_id is NULL for manual tasks)
        $desc = "Earnings for Gig Task #$tid";
        $stmt = $conn->prepare("INSERT INTO wallet_transactions (vendor_id, order_id, type, amount, description, status) VALUES (?, NULL, 'credit', ?, ?, 'completed')");
        $stmt->bind_param("ids", $vid, $amt, $desc);
        $stmt->execute();

        echo "Approved and Credited!";
    }
}
