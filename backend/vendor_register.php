<?php
// backend/vendor_register.php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get POST data
$name = trim($_POST['name'] ?? '');
$business_name = trim($_POST['business_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$password = $_POST['password'] ?? '';
$city = trim($_POST['city'] ?? '');
$vendor_type = trim($_POST['vendor_type'] ?? 'decoration');
$otp = trim($_POST['otp'] ?? ''); // Mandatory OTP

if (!in_array($vendor_type, ['activity', 'decoration'])) {
    $vendor_type = 'decoration';
}


// Validation
$errors = [];
if (empty($name)) $errors[] = 'Name is required';
if (empty($business_name)) $errors[] = 'Business name is required';
if (empty($email)) $errors[] = 'Email is required';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
if (empty($phone)) $errors[] = 'Phone is required';
if (empty($password)) $errors[] = 'Password is required';
if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters';
if (empty($city)) $errors[] = 'City is required';
if (empty($otp)) $errors[] = 'Phone verification is required (OTP)';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// 1. VERIFY OTP AGAIN (Security check)
$stmtOtp = $conn->prepare("SELECT id FROM user_otps WHERE phone = ? AND otp = ? AND expiry > NOW() ORDER BY id DESC LIMIT 1");
$stmtOtp->bind_param("ss", $phone, $otp);
$stmtOtp->execute();
if ($stmtOtp->get_result()->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired OTP. Please verify your phone again.']);
    exit;
}
$stmtOtp->close();

// 2. Check for duplicate email or phone
$stmtCheck = $conn->prepare("SELECT id FROM vendors WHERE email = ? OR phone = ?");
$stmtCheck->bind_param("ss", $email, $phone);
$stmtCheck->execute();
if ($stmtCheck->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email or Mobile number already registered']);
    exit;
}
$stmtCheck->close();

// 3. Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Begin transaction
$conn->begin_transaction();

try {
    // Insert vendor
    $stmt = $conn->prepare("INSERT INTO vendors (name, business_name, email, phone, password, city, vendor_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("sssssss", $name, $business_name, $email, $phone, $hashed_password, $city, $vendor_type);
    $stmt->execute();
    $vendor_id = $conn->insert_id;
    $stmt->close();

    // Clear OTP
    $del = $conn->prepare("DELETE FROM user_otps WHERE phone = ?");
    $del->bind_param("s", $phone);
    $del->execute();
    $del->close();



    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Registration successful! You can now login.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
}

$conn->close();
?>
