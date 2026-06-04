<?php
/**
 * admin/api/test_call_flow.php
 * ============================
 * Debug page to test the complete calling flow.
 * Access: https://partners.surpriseville.co.in/admin/api/test_call_flow.php
 */
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    die('<b>Not logged in as admin.</b>');
}
require_once __DIR__ . '/../../db_main.php';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>WebRTC Call Flow Debug</title>
    <style>
        body { font-family: monospace; background: #111; color: #0f0; padding: 20px; }
        .ok { color: #0f0; } .err { color: #f55; } .warn { color: #fa0; }
        pre { background: #222; padding: 10px; border-radius: 5px; overflow-x: auto; }
        h2 { color: #7df; }
        button { background: #333; color: #fff; border: 1px solid #555; padding: 8px 15px; cursor: pointer; border-radius: 4px; margin: 5px; }
        input { background: #222; color: #fff; border: 1px solid #555; padding: 6px; border-radius: 4px; width: 100px; }
    </style>
</head>
<body>
<h1>🔧 WebRTC Call Flow Debug</h1>

<?php
// 1. DB Connection check
echo "<h2>1. DB Connections</h2>";
echo isset($mainConn) ? "<span class='ok'>✓ mainConn OK</span><br>" : "<span class='err'>✗ mainConn MISSING</span><br>";

// 2. Table existence
echo "<h2>2. Tables</h2>";
foreach (['call_sessions', 'webrtc_signals'] as $table) {
    $r = $mainConn->query("SHOW TABLES LIKE '$table'");
    if ($r && $r->num_rows > 0) {
        echo "<span class='ok'>✓ $table exists</span><br>";
    } else {
        echo "<span class='err'>✗ $table MISSING — run <a href='setup_webrtc_tables.php' style='color:#fa0'>setup_webrtc_tables.php</a> first!</span><br>";
    }
}

// 3. Schema check
echo "<h2>3. call_sessions Schema</h2><pre>";
$res = $mainConn->query("DESCRIBE call_sessions");
if ($res) {
    $cols = [];
    while ($row = $res->fetch_assoc()) $cols[] = $row['Field'];
    echo implode(', ', $cols);
    
    // Check required columns
    $required = ['id','order_id','caller_type','caller_id','callee_type','callee_id','call_type','status','sdp_offer','sdp_answer','created_at'];
    $missing = array_diff($required, $cols);
    if ($missing) {
        echo "\n\n<span class='err'>MISSING COLUMNS: " . implode(', ', $missing) . "</span>";
    } else {
        echo "\n\n<span class='ok'>All required columns present ✓</span>";
    }
} else {
    echo "<span class='err'>Table not found!</span>";
}
echo "</pre>";

// 4. Recent call sessions
echo "<h2>4. Recent call_sessions (Last 10)</h2><pre>";
$recent = $mainConn->query("SELECT id, order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, created_at FROM call_sessions ORDER BY id DESC LIMIT 10");
if ($recent && $recent->num_rows > 0) {
    while ($row = $recent->fetch_assoc()) {
        $color = $row['status'] === 'ringing' ? '#fa0' : ($row['status'] === 'active' ? '#0f0' : '#888');
        echo "<span style='color:$color'>" . json_encode($row) . "</span>\n";
    }
} else {
    echo "<span class='warn'>No call sessions found yet.</span>";
}
echo "</pre>";

// 5. Ringing calls check
echo "<h2>5. Active Ringing Calls (Admin should see)</h2><pre>";
$ringing = $mainConn->query("SELECT * FROM call_sessions WHERE status='ringing' AND callee_type='admin' AND created_at >= NOW() - INTERVAL 5 MINUTE");
if ($ringing && $ringing->num_rows > 0) {
    while ($row = $ringing->fetch_assoc()) {
        echo "<span class='warn'>RINGING: " . json_encode($row) . "</span>\n";
    }
} else {
    echo "<span class='ok'>No ringing calls currently (expected when idle).</span>";
}
echo "</pre>";

// 6. Test local signal API
echo "<h2>6. Local Signal API Test</h2>";
echo "<p>Testing <code>/ajax/webrtc_signal.php</code> with action=poll_signal (needs login cookie):</p>";
echo "<button onclick=\"testApi()\">Run API Test</button><pre id='apiResult'>...</pre>";

// 7. webrtc_signals table
echo "<h2>7. webrtc_signals (Recent 10)</h2><pre>";
$sigs = $mainConn->query("SELECT id, call_session_id, signal_type, LEFT(payload, 50) as payload_preview, created_at FROM webrtc_signals ORDER BY id DESC LIMIT 10");
if ($sigs && $sigs->num_rows > 0) {
    while ($row = $sigs->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
} else {
    echo "<span class='warn'>No signals yet.</span>";
}
echo "</pre>";
?>

<h2>8. Simulate Call (Admin → Vendor)</h2>
<p>Order ID: <input type="number" id="testOrderId" value="1"></p>
<button onclick="simulateCall()">Test initiate_call (Admin calling Vendor)</button>
<pre id="simResult">...</pre>

<script>
async function testApi() {
    const fd = new FormData();
    fd.append('action', 'poll_signal');
    fd.append('order_id', '1');
    const r = await fetch('/ajax/webrtc_signal.php', {method:'POST', body:fd, credentials:'include'});
    const j = await r.json();
    document.getElementById('apiResult').innerHTML = JSON.stringify(j, null, 2);
}

async function simulateCall() {
    const orderId = document.getElementById('testOrderId').value;
    const fd = new FormData();
    fd.append('action', 'initiate_call');
    fd.append('order_id', orderId);
    fd.append('call_type', 'audio');
    fd.append('sdp_offer', JSON.stringify({type:'offer', sdp:'test_sdp'}));
    
    const r = await fetch('/webrtc_signal_proxy.php', {method:'POST', body:fd, credentials:'include'});
    const j = await r.json();
    document.getElementById('simResult').innerHTML = JSON.stringify(j, null, 2);
}
</script>
</body>
</html>
