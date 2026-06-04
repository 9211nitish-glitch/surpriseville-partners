<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /admin/login.php');
    exit;
}
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../db_main.php';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>WebRTC Auto-Fix</title>
<style>
body { background:#0f172a; color:#e2e8f0; font-family:monospace; padding:30px; }
h1 { color:#7c3aed; margin-bottom:20px; }
.box { background:#1e293b; border-radius:10px; padding:18px; margin-bottom:14px; border-left:4px solid #334155; }
.ok  { border-left-color:#10b981; }
.err { border-left-color:#ef4444; }
.lbl { font-size:0.75rem; color:#94a3b8; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; }
.ok-t  { color:#10b981; }
.err-t { color:#ef4444; }
.warn-t{ color:#f59e0b; }
pre { background:#0f172a; padding:10px; border-radius:6px; font-size:0.78rem; color:#a5f3fc; margin-top:8px; overflow-x:auto; }
a.btn { display:inline-block; margin:6px 6px 6px 0; padding:10px 20px; border-radius:8px; text-decoration:none; font-weight:bold; font-family:sans-serif; }
.p { background:#7c3aed; color:white; }
.g { background:#10b981; color:white; }
</style>
</head>
<body>
<h1>&#128295; WebRTC Auto-Fix &amp; Diagnostic</h1>

<?php
// Helper
function box($cls, $label, $content) {
    echo '<div class="box ' . $cls . '"><div class="lbl">' . $label . '</div><div>' . $content . '</div></div>';
}

// 1. DB Check
if (!isset($mainConn)) {
    box('err', 'DB Connection', '<span class="err-t">&#10007; mainConn not available - check db_main.php</span>');
    exit;
}
box('ok', 'Step 1 - Database', '<span class="ok-t">&#10003; mainConn connected</span>');

// 2. Get existing columns
$colRes = $mainConn->query("SHOW COLUMNS FROM call_sessions");
$existCols = array();
if ($colRes) {
    while ($r = $colRes->fetch_assoc()) $existCols[] = $r['Field'];
}

// 3. Fix missing columns
$toAdd = array(
    'callee_type'      => "ALTER TABLE call_sessions ADD COLUMN callee_type ENUM('admin','vendor','user') NOT NULL DEFAULT 'admin' AFTER caller_id",
    'callee_id'        => "ALTER TABLE call_sessions ADD COLUMN callee_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER callee_type",
    'sdp_offer'        => "ALTER TABLE call_sessions ADD COLUMN sdp_offer MEDIUMTEXT NULL AFTER status",
    'sdp_answer'       => "ALTER TABLE call_sessions ADD COLUMN sdp_answer MEDIUMTEXT NULL AFTER sdp_offer",
    'answered_at'      => "ALTER TABLE call_sessions ADD COLUMN answered_at DATETIME NULL",
    'ended_at'         => "ALTER TABLE call_sessions ADD COLUMN ended_at DATETIME NULL",
    'duration_seconds' => "ALTER TABLE call_sessions ADD COLUMN duration_seconds INT UNSIGNED NULL DEFAULT 0",
);

$fixLog = '';
$hadMissing = false;
foreach ($toAdd as $col => $sql) {
    if (!in_array($col, $existCols)) {
        $hadMissing = true;
        if ($mainConn->query($sql)) {
            $fixLog .= '<span class="ok-t">&#10003; Added: ' . $col . '</span><br>';
        } else {
            $fixLog .= '<span class="err-t">&#10007; Failed to add ' . $col . ': ' . htmlspecialchars($mainConn->error) . '</span><br>';
        }
    } else {
        $fixLog .= '<span style="color:#64748b">&#8594; ' . $col . ' already exists</span><br>';
    }
}

if (!$hadMissing) {
    box('ok', 'Step 2 - Schema Fix', '<span class="ok-t">&#10003; All columns present, no fix needed</span>');
} else {
    box('ok', 'Step 2 - Schema Fix', $fixLog);
}

// 4. Verify schema now
$colRes2 = $mainConn->query("SHOW COLUMNS FROM call_sessions");
$newCols = array();
if ($colRes2) { while ($r = $colRes2->fetch_assoc()) $newCols[] = $r['Field']; }
$required = array('callee_type','callee_id','sdp_offer','sdp_answer');
$stillMissing = array();
foreach ($required as $c) { if (!in_array($c, $newCols)) $stillMissing[] = $c; }

if (!empty($stillMissing)) {
    box('err', 'Schema Status', '<span class="err-t">&#10007; Still missing: ' . implode(', ', $stillMissing) . '</span><br>Please run ALTER TABLE manually in phpMyAdmin.');
    exit;
} else {
    box('ok', 'Schema Status', '<span class="ok-t">&#10003; All required columns present</span><pre>' . implode(', ', $newCols) . '</pre>');
}

// 5. Test INSERT
$testOk = false;
$testId = 0;
$vendorId = 1;
if (isset($conn)) {
    $vr = $conn->query("SELECT id FROM vendors LIMIT 1");
    if ($vr) { $vrow = $vr->fetch_assoc(); $vendorId = $vrow ? intval($vrow['id']) : 1; }
}

$ins = $mainConn->prepare(
    "INSERT INTO call_sessions (order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at)
     VALUES (1, 'vendor', ?, 'admin', 1, 'audio', 'ringing', '{\"type\":\"offer\",\"sdp\":\"test\"}', NOW())"
);
if ($ins) {
    $ins->bind_param('i', $vendorId);
    if ($ins->execute()) {
        $testId = $ins->insert_id;
        $testOk = true;
    }
    $ins->close();
}

if ($testOk) {
    box('ok', 'Step 3 - Test INSERT', '<span class="ok-t">&#10003; Inserted test call session ID=' . $testId . ' (vendor&#8594;admin, ringing)</span>');
} else {
    box('err', 'Step 3 - Test INSERT', '<span class="err-t">&#10007; INSERT failed: ' . htmlspecialchars($mainConn->error) . '</span>');
    exit;
}

// 6. Admin poll detection
$detectOk = false;
$dStmt = $mainConn->prepare("SELECT id FROM call_sessions WHERE callee_type='admin' AND status='ringing' AND created_at >= NOW() - INTERVAL 5 MINUTE ORDER BY id DESC LIMIT 1");
if ($dStmt) {
    $dStmt->execute();
    $dRow = $dStmt->get_result()->fetch_assoc();
    $dStmt->close();
    $detectOk = !empty($dRow);
}
// Cleanup
if ($testId) $mainConn->query("UPDATE call_sessions SET status='ended', ended_at=NOW() WHERE id=$testId");

if ($detectOk) {
    box('ok', 'Step 4 - Admin Detection', '<span class="ok-t">&#10003; Admin polling CAN see vendor incoming calls!</span>');
} else {
    box('err', 'Step 4 - Admin Detection', '<span class="err-t">&#10007; Admin polling CANNOT detect calls</span>');
}

// 7. Local API test
$proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$apiUrl = $proto . '://' . $_SERVER['HTTP_HOST'] . '/ajax/webrtc_signal.php';
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, array(
    'action'       => 'poll_signal',
    'order_id'     => '1',
    'caller_type'  => 'admin',
    'caller_id'    => '1',
    'admin_secret' => 'sv_admin_chat_key_2024'
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
if (!empty($_SERVER['HTTP_COOKIE'])) curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE']);
$apiResp = curl_exec($ch);
$apiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$apiJson = json_decode($apiResp, true);
$apiOk = ($apiCode === 200 && is_array($apiJson) && isset($apiJson['success']));

box(
    $apiOk ? 'ok' : 'err',
    'Step 5 - Local Signal API',
    ($apiOk
        ? '<span class="ok-t">&#10003; HTTP ' . $apiCode . ' - API working</span>'
        : '<span class="err-t">&#10007; HTTP ' . $apiCode . ' - API error</span>') .
    '<pre>' . htmlspecialchars(json_encode($apiJson, JSON_PRETTY_PRINT) ?: $apiResp) . '</pre>'
);

// 8. Recent calls
$recHtml = '';
$recRes = $mainConn->query("SELECT id, order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, created_at FROM call_sessions ORDER BY id DESC LIMIT 5");
if ($recRes && $recRes->num_rows > 0) {
    while ($row = $recRes->fetch_assoc()) {
        $c = ($row['status'] === 'ringing') ? '#f59e0b' : (($row['status'] === 'active') ? '#10b981' : '#64748b');
        $recHtml .= '<span style="color:' . $c . '">[' . $row['status'] . '] id=' . $row['id'] . ' order=' . $row['order_id'] . ' ' . $row['caller_type'] . '#' . $row['caller_id'] . ' &rarr; ' . $row['callee_type'] . '#' . $row['callee_id'] . ' (' . $row['call_type'] . ') @ ' . $row['created_at'] . '</span><br>';
    }
} else {
    $recHtml = '<span class="warn-t">No call sessions yet.</span>';
}
box('', 'Step 6 - Recent Call Sessions', $recHtml);

// Final result
$allGood = $testOk && $detectOk && $apiOk;
echo '<div class="box ' . ($allGood ? 'ok' : 'err') . '" style="margin-top:20px;font-size:1.1rem;">';
echo '<div class="lbl">&#127937; Final Result</div>';
if ($allGood) {
    echo '<span class="ok-t">&#9989; ALL SYSTEMS GO! Calling should work now.</span><br><br>';
    echo '<span style="color:#94a3b8">Test steps:<br>1. Vendor login &rarr; order pe jaao &rarr; Call button click karo<br>2. Admin me 4 sec mein ring aana chahiye<br>3. Admin se bhi call karo (tracking.php)</span>';
} else {
    echo '<span class="err-t">&#9888; Some issues remain. See errors above.</span>';
}
echo '</div>';
?>

<div style="margin-top:20px;">
    <a href="/admin/api/test_call_flow.php" class="btn p">&#8594; Full Debug</a>
    <a href="/admin/tracking.php" class="btn g">&#8594; Tracking (Test Call)</a>
</div>
</body>
</html>
