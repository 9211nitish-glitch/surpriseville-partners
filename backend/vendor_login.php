<?php
// backend/vendor_login.php

// 1. SESSION MANAGEMENT
require_once '../vendor/includes/session_manager.php';
header('Content-Type: application/json');
require_once '../db.php';

$auth_type = $_POST['auth_type'] ?? 'password'; // 'password' or 'otp'
$identifier = $_POST['identifier'] ?? $_POST['email'] ?? ''; // Can be email or phone
$password = $_POST['password'] ?? '';
$otp = $_POST['otp'] ?? '';
$device_id = $_POST['device_id'] ?? '';

if (empty($identifier)) {
    echo json_encode(['success' => false, 'message' => 'Email or Phone is required']);
    exit;
}

if ($auth_type === 'password') {
    if (empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Password is required']);
        exit;
    }

    // Check credentials by Email OR Phone
    $stmt = $conn->prepare("SELECT id, name, business_name, email, phone, password FROM vendors WHERE email = ? OR phone = ?");
    $stmt->bind_param("ss", $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            complete_login($row, $conn, $device_id);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid Password']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Account not found']);
    }
    $stmt->close();

} elseif ($auth_type === 'otp') {
    if (empty($otp)) {
        echo json_encode(['success' => false, 'message' => 'OTP is required']);
        exit;
    }

    // 1. Verify OTP
    $stmt = $conn->prepare("SELECT id FROM user_otps WHERE phone = ? AND otp = ? AND expiry > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ss", $identifier, $otp);
    $stmt->execute();
    $otp_res = $stmt->get_result();

    if ($otp_res->num_rows > 0) {
        // OTP Valid - Delete it
        $del = $conn->prepare("DELETE FROM user_otps WHERE phone = ?");
        $del->bind_param("s", $identifier);
        $del->execute();
        $del->close();

        // 2. Fetch Vendor
        $stmtV = $conn->prepare("SELECT id, name, business_name, email, phone FROM vendors WHERE phone = ? OR email = ?");
        $stmtV->bind_param("ss", $identifier, $identifier);
        $stmtV->execute();
        $v_res = $stmtV->get_result();

        if ($row = $v_res->fetch_assoc()) {
            complete_login($row, $conn, $device_id);
        } else {
            echo json_encode(['success' => false, 'message' => 'Account not found for this number. Please register first.']);
        }
        $stmtV->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP']);
    }
    $stmt->close();
}

function complete_login($row, $conn, $device_id) {
    // 3. SECURE DEVICE ID
    if (!empty($device_id)) {
        $upd = $conn->prepare("UPDATE vendors SET current_device_id = ? WHERE id = ?");
        $upd->bind_param("si", $device_id, $row['id']);
        $upd->execute();
        $upd->close();
    }

    // 4. SET SESSION VARIABLES
    $_SESSION['vendor_logged_in'] = true;
    $_SESSION['vendor_id'] = $row['id'];
    $_SESSION['vendor_name'] = $row['name'];
    $_SESSION['vendor_business_name'] = $row['business_name'];
    $_SESSION['vendor_email'] = $row['email'];
    $_SESSION['vendor_phone'] = $row['phone'];
    $_SESSION['current_device_id'] = $device_id;

    // 5. PERSISTENT LOGIN (REMEMBER ME)
    $token = bin2hex(random_bytes(32));
    $updToken = $conn->prepare("UPDATE vendors SET remember_token = ? WHERE id = ?");
    $updToken->bind_param("si", $token, $row['id']);
    $updToken->execute();
    $updToken->close();

    setcookie('vendor_remember_token', $token, time() + 2592000, "/", "", false, true);

    $redirect = 'pending-alerts.php';
    if (isset($_SESSION['redirect_to'])) {
        $redirect = $_SESSION['redirect_to'];
        unset($_SESSION['redirect_to']);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Login Successful! Redirecting...',
        'redirect' => $redirect,
        'vendor_id' => $row['id']
    ]);
    exit;
}

$conn->close();
?>
