<?php
/**
 * admin/api/simulate_test_call.php
 * =================================
 * Simulates a complete vendor→admin call to test the pipeline.
 * After running fix_call_columns.php, use this to verify everything works.
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die('<b style="color:red">Not logged in as admin.</b>');
}

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../db_main.php';
header('Content-Type: text/html; charset=utf-8');

echo '<pre style="background:#111;color:#0f0;padding:20px;font-family:monospace;">';
echo "=== Simulate Vendor→Admin Call Test ===\n\n";

// Get a real vendor ID
$vendorRes = $conn->query("SELECT id, name FROM vendors LIMIT 1");
$vendor = $vendorRes ? $vendorRes->fetch_assoc() : null;

if (!$vendor) {
    echo "<span style='color:#f55'>✗ No vendors found in DB!</span>\n";
    exit;
}

$vendor_id = intval($vendor['id']);
$vendor_name = $vendor['name'];
echo "Using vendor: ID=$vendor_id, Name=$vendor_name\n\n";

// Get a real order
$orderRes = $mainConn->query("SELECT id FROM orders LIMIT 1");
$order = $orderRes ? $orderRes->fetch_assoc() : null;

if (!$order) {
    // Try offline tasks
    $taskRes = $conn->query("SELECT id FROM manual_tasks WHERE assigned_vendor_id = $vendor_id LIMIT 1");
    $task = $taskRes ? $taskRes->fetch_assoc() : null;
    $order_id = $task ? intval($task['id']) : 1;
    $is_offline = 1;
} else {
    $order_id = intval($order['id']);
    $is_offline = 0;
}

echo "Using order_id: $order_id (is_offline=$is_offline)\n\n";

// Step 1: INSERT a test call session (vendor→admin)
echo "STEP 1: Inserting test call_session (vendor→admin, ringing)...\n";

$fakeSdp = json_encode(['type' => 'offer', 'sdp' => 'v=0\r\no=- TEST_SDP\r\n']);

$stmt = $mainConn->prepare(
    "INSERT INTO call_sessions 
     (order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at)
     VALUES (?, 'vendor', ?, 'admin', 1, 'audio', 'ringing', ?, NOW())"
);

if (!$stmt) {
    echo "<span style='color:#f55'>✗ Prepare failed: " . $mainConn->error . "</span>\n";
    echo "This means callee_type/callee_id columns are still missing!\n";
    echo "Please run: <a href='fix_call_columns.php' style='color:#fa0'>fix_call_columns.php</a> first!\n";
    exit;
}

$stmt->bind_param('iis', $order_id, $vendor_id, $fakeSdp);
if ($stmt->execute()) {
    $call_id = $stmt->insert_id;
    echo "<span style='color:#0f0'>✓ Call session created! ID=$call_id</span>\n\n";
} else {
    echo "<span style='color:#f55'>✗ INSERT failed: " . $stmt->error . "</span>\n";
    exit;
}
$stmt->close();

// Step 2: Check if admin polling would detect it
echo "STEP 2: Checking if admin polling detects the ringing call...\n";

$checkStmt = $mainConn->prepare(
    "SELECT id, order_id, caller_type, caller_id, callee_type, callee_id, status 
     FROM call_sessions 
     WHERE callee_type='admin' AND status='ringing' AND created_at >= NOW() - INTERVAL 5 MINUTE
     ORDER BY id DESC LIMIT 1"
);
$checkStmt->execute();
$found = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($found) {
    echo "<span style='color:#0f0'>✓ Admin CAN detect the incoming call!</span>\n";
    echo "  Call: " . json_encode($found) . "\n\n";
} else {
    echo "<span style='color:#f55'>✗ Admin CANNOT detect the incoming call — something is still wrong!</span>\n\n";
}

// Step 3: Cleanup test call
echo "STEP 3: Cleaning up test call (setting to ended)...\n";
$mainConn->query("UPDATE call_sessions SET status='ended', ended_at=NOW() WHERE id=$call_id");
echo "<span style='color:#0f0'>✓ Test call cleaned up.</span>\n\n";

// Step 4: Test the proxy API directly
echo "STEP 4: Testing /ajax/webrtc_signal.php API directly...\n";
$host = $_SERVER['HTTP_HOST'];
$protocol = isset($_SERVER['HTTPS']) ? 'https' : 'http';
$apiUrl = "$protocol://$host/ajax/webrtc_signal.php";

$ch = curl_init($apiUrl);
$postData = [
    'action'       => 'poll_signal',
    'order_id'     => $order_id,
    'caller_type'  => 'admin',
    'caller_id'    => 1,
    'admin_secret' => 'sv_admin_chat_key_2024'
];
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
if (!empty($_SERVER['HTTP_COOKIE'])) {
    curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE']);
}
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "  HTTP $code → ";
$decoded = json_decode($resp, true);
if ($decoded && isset($decoded['success'])) {
    echo "<span style='color:#0f0'>✓ API responding correctly</span>\n";
    echo "  Response: " . json_encode($decoded) . "\n\n";
} else {
    echo "<span style='color:#f55'>✗ API returned unexpected: $resp</span>\n\n";
}

echo "=== SUMMARY ===\n";
if ($found && $code === 200) {
    echo "<span style='color:#0f0;font-size:1.1em'>✅ EVERYTHING LOOKS GOOD!</span>\n";
    echo "Now try a real call:\n";
    echo "  1. Vendor se call karo → Admin ko 4 seconds mein ring aana chahiye\n";
    echo "  2. Admin se karo → tracking.php pe call button click karo\n";
} else {
    echo "<span style='color:#f55'>⚠ Some issues remain. Check errors above.</span>\n";
}

echo '</pre>';
