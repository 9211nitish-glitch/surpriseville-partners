<?php
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
$shop_categories = $_POST['shop_categories'] ?? [];
$gig_skills = $_POST['gig_skills'] ?? [];

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
if (empty($shop_categories) && empty($gig_skills)) $errors[] = 'At least one Shop Category OR Gig Skill is required';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

// Check if email already exists
$stmt = $conn->prepare("SELECT id FROM vendors WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Email already registered']);
    exit;
}
$stmt->close();

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Begin transaction
$conn->begin_transaction();

try {
    // Insert vendor
    $stmt = $conn->prepare("INSERT INTO vendors (name, business_name, email, phone, password, city, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("ssssss", $name, $business_name, $email, $phone, $hashed_password, $city);
    $stmt->execute();
    $vendor_id = $conn->insert_id;
    $stmt->close();

    // Insert shop categories
    if (!empty($shop_categories) && is_array($shop_categories)) {
        $stmtSc = $conn->prepare("INSERT INTO vendor_categories (vendor_id, category_id) VALUES (?, ?)");
        foreach ($shop_categories as $shop_cat_id) {
            $shop_cat_id = intval($shop_cat_id);
            $stmtSc->bind_param("ii", $vendor_id, $shop_cat_id);
            $stmtSc->execute();
        }
        $stmtSc->close();
    }

    // Insert gig skills
    if (!empty($gig_skills) && is_array($gig_skills)) {
        $stmtGs = $conn->prepare("INSERT INTO vendor_gig_skills (vendor_id, category_id) VALUES (?, ?)");
        foreach ($gig_skills as $gig_skill_id) {
            $gig_skill_id = intval($gig_skill_id);
            $stmtGs->bind_param("ii", $vendor_id, $gig_skill_id);
            $stmtGs->execute();
        }
        $stmtGs->close();
    }

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Registration successful! You can now login.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
}

$conn->close();
