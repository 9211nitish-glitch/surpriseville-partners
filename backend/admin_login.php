<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// Validation
if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Username and password are required']);
    exit;
}

// Get admin by username
$stmt = $conn->prepare("SELECT id, username, email, password, role, status FROM admin_users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    exit;
}

$admin = $result->fetch_assoc();
$stmt->close();

// Verify password
if (!password_verify($password, $admin['password'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    exit;
}

// Check admin status
if ($admin['status'] !== 'active') {
    echo json_encode(['success' => false, 'message' => 'Your account is not active. Please contact support.']);
    exit;
}

// Set session variables
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_id'] = $admin['id'];
$_SESSION['admin_username'] = $admin['username'];
$_SESSION['admin_email'] = $admin['email'];
$_SESSION['admin_role'] = $admin['role'];

echo json_encode([
    'success' => true, 
    'message' => 'Login successful',
    'redirect' => '/admin/dashboard.php'
]);

$conn->close();
?>
