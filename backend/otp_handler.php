<?php
// backend/otp_handler.php
session_start();
require_once '../db.php';
require_once 'whatsapp_helper.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'request_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');

    if (!$phone || strlen(preg_replace('/[^0-9]/', '', $phone)) < 10) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid 10-digit mobile number.']);
        exit;
    }

    $otp = rand(1000, 9999);
    // Save OTP to user_otps table
    $stmt = $conn->prepare("INSERT INTO user_otps (phone, otp, expiry) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
    $stmt->bind_param("ss", $phone, $otp);
    
    if ($stmt->execute()) {
        // Send WhatsApp OTP
        $sent = sendWhatsAppOTP($phone, $otp);
        if ($sent) {
            echo json_encode(['success' => true, 'message' => 'OTP has been sent to your WhatsApp.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send WhatsApp message. Please try again later.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
    $stmt->close();
    exit;
}

if ($action === 'verify_otp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $otp = trim($_POST['otp'] ?? '');

    if (!$phone || !$otp) {
        echo json_encode(['success' => false, 'message' => 'Phone and OTP are required.']);
        exit;
    }

    // Verify OTP
    $stmt = $conn->prepare("SELECT id FROM user_otps WHERE phone = ? AND otp = ? AND expiry > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ss", $phone, $otp);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Delete OTP after successful verification
        $del = $conn->prepare("DELETE FROM user_otps WHERE phone = ?");
        $del->bind_param("s", $phone);
        $del->execute();
        $del->close();

        echo json_encode(['success' => true, 'message' => 'OTP verified successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP.']);
    }
    $stmt->close();
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid Action']);
?>
