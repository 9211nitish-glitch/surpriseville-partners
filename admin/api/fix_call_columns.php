<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die('Not logged in as admin.');
}
require_once __DIR__ . '/../../db_main.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== WebRTC DB Fix ===\n\n";

if (!isset($mainConn)) {
    die("ERROR: mainConn not available\n");
}

// Get current columns
$res = $mainConn->query("SHOW COLUMNS FROM call_sessions");
$cols = array();
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cols[] = $row['Field'];
    }
}
echo "Current columns: " . implode(', ', $cols) . "\n\n";

// Add callee_type
if (!in_array('callee_type', $cols)) {
    $r = $mainConn->query("ALTER TABLE call_sessions ADD COLUMN callee_type ENUM('admin','vendor','user') NOT NULL DEFAULT 'admin' AFTER caller_id");
    echo ($r ? "OK" : "FAIL: " . $mainConn->error) . " — callee_type added\n";
} else {
    echo "SKIP — callee_type exists\n";
}

// Add callee_id
if (!in_array('callee_id', $cols)) {
    $r = $mainConn->query("ALTER TABLE call_sessions ADD COLUMN callee_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER callee_type");
    echo ($r ? "OK" : "FAIL: " . $mainConn->error) . " — callee_id added\n";
} else {
    echo "SKIP — callee_id exists\n";
}

// Add sdp_offer
if (!in_array('sdp_offer', $cols)) {
    $r = $mainConn->query("ALTER TABLE call_sessions ADD COLUMN sdp_offer MEDIUMTEXT NULL AFTER status");
    echo ($r ? "OK" : "FAIL: " . $mainConn->error) . " — sdp_offer added\n";
} else {
    echo "SKIP — sdp_offer exists\n";
}

// Add sdp_answer
if (!in_array('sdp_answer', $cols)) {
    $r = $mainConn->query("ALTER TABLE call_sessions ADD COLUMN sdp_answer MEDIUMTEXT NULL AFTER sdp_offer");
    echo ($r ? "OK" : "FAIL: " . $mainConn->error) . " — sdp_answer added\n";
} else {
    echo "SKIP — sdp_answer exists\n";
}

// Add answered_at
if (!in_array('answered_at', $cols)) {
    $mainConn->query("ALTER TABLE call_sessions ADD COLUMN answered_at DATETIME NULL");
    echo "OK — answered_at added\n";
} else {
    echo "SKIP — answered_at exists\n";
}

// Add ended_at
if (!in_array('ended_at', $cols)) {
    $mainConn->query("ALTER TABLE call_sessions ADD COLUMN ended_at DATETIME NULL");
    echo "OK — ended_at added\n";
} else {
    echo "SKIP — ended_at exists\n";
}

// Add duration_seconds
if (!in_array('duration_seconds', $cols)) {
    $mainConn->query("ALTER TABLE call_sessions ADD COLUMN duration_seconds INT UNSIGNED NULL DEFAULT 0");
    echo "OK — duration_seconds added\n";
} else {
    echo "SKIP — duration_seconds exists\n";
}

echo "\n=== New Schema ===\n";
$res2 = $mainConn->query("SHOW COLUMNS FROM call_sessions");
$newCols = array();
if ($res2) {
    while ($row = $res2->fetch_assoc()) {
        $newCols[] = $row['Field'];
        echo "  " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
}

echo "\n=== Test INSERT ===\n";
$testSdp = '{"type":"offer","sdp":"test"}';
$testStmt = $mainConn->prepare(
    "INSERT INTO call_sessions (order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at) VALUES (1, 'vendor', 1, 'admin', 1, 'audio', 'ringing', ?, NOW())"
);
if (!$testStmt) {
    echo "PREPARE FAILED: " . $mainConn->error . "\n";
    echo "callee_type column still missing!\n";
} else {
    $testStmt->bind_param('s', $testSdp);
    if ($testStmt->execute()) {
        $tid = $testStmt->insert_id;
        echo "INSERT OK — test call_session ID=$tid\n";
        $mainConn->query("UPDATE call_sessions SET status='ended', ended_at=NOW() WHERE id=$tid");
        echo "Cleaned up test row.\n";
    } else {
        echo "INSERT FAILED: " . $testStmt->error . "\n";
    }
    $testStmt->close();
}

echo "\n=== Admin Poll Test ===\n";
$pStmt = $mainConn->prepare("SELECT id FROM call_sessions WHERE callee_type='admin' AND status='ringing' AND created_at >= NOW() - INTERVAL 5 MINUTE ORDER BY id DESC LIMIT 1");
if ($pStmt) {
    $pStmt->execute();
    $pRes = $pStmt->get_result()->fetch_assoc();
    $pStmt->close();
    echo ($pRes ? "Found ringing call ID=" . $pRes['id'] : "No ringing calls (expected when idle)") . "\n";
} else {
    echo "QUERY FAILED: " . $mainConn->error . "\n";
}

echo "\n=== DONE ===\n";
echo "If all lines above show OK or SKIP, calling should work.\n";
echo "Now test: Vendor se call karo → Admin ko 4 seconds mein ring aana chahiye.\n";
