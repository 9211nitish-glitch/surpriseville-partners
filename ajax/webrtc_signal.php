<?php
/**
 * ajax/webrtc_signal.php
 * =====================
 * LOCAL WebRTC signaling server for partners.surpriseville.co.in
 * Directly manages call_sessions and webrtc_signals tables.
 * 
 * Supported actions:
 *   initiate_call  — Caller posts SDP offer, creates call_sessions row
 *   poll_signal    — Both sides poll for signals/status changes
 *   answer_call    — Callee posts SDP answer
 *   send_ice       — Send ICE candidate to the other side
 *   decline_call   — Callee declines
 *   end_call       — Either side ends the call
 */

if (file_exists(__DIR__ . '/../vendor/includes/session_manager.php')) {
    require_once __DIR__ . '/../vendor/includes/session_manager.php';
} else {
    session_start();
}
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_clean(); exit(0);
}

// ── Auth: determine who is calling ────────────────────────────────────────────
$caller_type = null;
$caller_id   = null;

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $caller_type = 'admin';
    $caller_id   = 1;
} elseif (isset($_SESSION['vendor_id'])) {
    $caller_type = 'vendor';
    $caller_id   = intval($_SESSION['vendor_id']);
} else {
    // Allow proxy-injected credentials (from webrtc_signal_proxy.php)
    $injected_type = $_POST['caller_type'] ?? null;
    $admin_secret  = $_POST['admin_secret'] ?? '';
    if ($admin_secret === 'sv_admin_chat_key_2024' && $injected_type) {
        $caller_type = $injected_type;
        $caller_id   = ($injected_type === 'admin') ? 1 : intval($_POST['caller_id'] ?? $_POST['vendor_id'] ?? 0);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

// ── DB Connection ──────────────────────────────────────────────────────────────
$dbDir = __DIR__ . '/..';
if (file_exists($dbDir . '/db_main.php')) {
    require_once $dbDir . '/db_main.php';
} else {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'DB config not found']);
    exit;
}

// $mainConn is the PDO/mysqli connection from db_main.php
// Ensure we have mainConn
if (!isset($mainConn)) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// ── Helpers ────────────────────────────────────────────────────────────────────
function jsonOut($data) {
    ob_clean();
    echo json_encode($data);
    exit;
}

function dbErr($mainConn, $context = '') {
    jsonOut(['success' => false, 'error' => 'DB error' . ($context ? " ($context)" : ''), 'detail' => $mainConn->error]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: initiate_call
// Caller sends SDP offer → creates call_sessions row
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'initiate_call') {
    $order_id  = intval($_POST['order_id'] ?? 0);
    $call_type = in_array($_POST['call_type'] ?? '', ['audio', 'video']) ? $_POST['call_type'] : 'audio';
    $sdp_offer = $_POST['sdp_offer'] ?? '';

    if ($order_id <= 0 || !$sdp_offer) {
        jsonOut(['success' => false, 'error' => 'Missing order_id or sdp_offer']);
    }

    // Determine callee: if admin calls → callee is vendor; if vendor calls → callee is admin
    if ($caller_type === 'admin') {
        $callee_type = 'vendor';
        // Find vendor for this order
        $stmt = $mainConn->prepare("SELECT vendor_id FROM order_vendor_assignments WHERE order_id = ? LIMIT 1");
        if (!$stmt) {
            // Try offline order
            $stmt2 = $mainConn->prepare("SELECT assigned_vendor_id as vendor_id FROM manual_tasks WHERE id = ? LIMIT 1");
            if ($stmt2) {
                $stmt2->bind_param('i', $order_id);
                $stmt2->execute();
                $r2 = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
                $callee_id = intval($r2['vendor_id'] ?? 0);
            } else {
                $callee_id = 0;
            }
        } else {
            $stmt->bind_param('i', $order_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $callee_id = intval($r['vendor_id'] ?? 0);
            
            // If not found in shop orders, try offline tasks
            if (!$callee_id) {
                // Try vendor DB (conn from db.php)
                if (file_exists($dbDir . '/db.php') && !isset($conn)) {
                    require_once $dbDir . '/db.php';
                }
                if (isset($conn)) {
                    $stmt3 = $conn->prepare("SELECT assigned_vendor_id FROM manual_tasks WHERE id = ? LIMIT 1");
                    if ($stmt3) {
                        $stmt3->bind_param('i', $order_id);
                        $stmt3->execute();
                        $r3 = $stmt3->get_result()->fetch_assoc();
                        $stmt3->close();
                        $callee_id = intval($r3['assigned_vendor_id'] ?? 0);
                    }
                }
            }
        }
    } else {
        // Vendor calling → callee is admin
        $callee_type = 'admin';
        $callee_id   = 1;
    }

    // Cancel any previous ringing calls for same order
    $cancelStmt = $mainConn->prepare(
        "UPDATE call_sessions SET status='ended', ended_at=NOW() 
         WHERE order_id=? AND status='ringing' AND caller_type=? AND caller_id=?"
    );
    if ($cancelStmt) {
        $cancelStmt->bind_param('isi', $order_id, $caller_type, $caller_id);
        $cancelStmt->execute();
        $cancelStmt->close();
    }

    // Insert new call session
    $ins = $mainConn->prepare(
        "INSERT INTO call_sessions 
         (order_id, caller_type, caller_id, callee_type, callee_id, call_type, status, sdp_offer, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'ringing', ?, NOW())"
    );
    if (!$ins) { dbErr($mainConn, 'insert call_sessions'); }
    $ins->bind_param('isisiss', $order_id, $caller_type, $caller_id, $callee_type, $callee_id, $call_type, $sdp_offer);
    if (!$ins->execute()) {
        jsonOut(['success' => false, 'error' => 'Failed to create call session: ' . $ins->error]);
    }
    $call_session_id = $ins->insert_id;
    $ins->close();

    jsonOut(['success' => true, 'call_session_id' => $call_session_id]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: poll_signal
// Both sides poll for status changes, SDP answer, ICE candidates
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'poll_signal') {
    $call_session_id = intval($_POST['call_session_id'] ?? 0);
    $order_id        = intval($_POST['order_id'] ?? 0);
    $last_signal_id  = intval($_POST['last_signal_id'] ?? 0);

    if (!$call_session_id && !$order_id) {
        jsonOut(['success' => false, 'error' => 'call_session_id or order_id required']);
    }

    // Get the call session
    if ($call_session_id) {
        $stmt = $mainConn->prepare("SELECT * FROM call_sessions WHERE id=? LIMIT 1");
        $stmt->bind_param('i', $call_session_id);
    } else {
        $stmt = $mainConn->prepare(
            "SELECT * FROM call_sessions WHERE order_id=? 
             AND (caller_type=? AND caller_id=? OR callee_type=? AND callee_id=?)
             AND status IN ('ringing','active')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('isisi', $order_id, $caller_type, $caller_id, $caller_type, $caller_id);
    }

    if (!$stmt) { dbErr($mainConn, 'poll_signal select'); }
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$session) {
        jsonOut(['success' => true, 'status' => 'not_found', 'signals' => []]);
    }

    // Get new signals since last_signal_id (excluding signals from the same side)
    $sigStmt = $mainConn->prepare(
        "SELECT id, signal_type, payload, created_at FROM webrtc_signals 
         WHERE call_session_id=? AND id>? 
         ORDER BY id ASC LIMIT 50"
    );
    $signals = [];
    if ($sigStmt) {
        $sigStmt->bind_param('ii', $session['id'], $last_signal_id);
        $sigStmt->execute();
        $res = $sigStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            // Normalize signal_type: 'ice_candidate' -> 'ice' for client compatibility
            if ($row['signal_type'] === 'ice_candidate') {
                $row['signal_type'] = 'ice';
            }
            $signals[] = $row;
        }
        $sigStmt->close();
    }

    jsonOut([
        'success'         => true,
        'status'          => $session['status'],
        'call_status'     => $session['status'],
        'call_session_id' => intval($session['id']),
        'sdp_offer'       => $session['sdp_offer'],
        'sdp_answer'      => $session['sdp_answer'],
        'call_type'       => $session['call_type'],
        'signals'         => $signals
    ]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: answer_call
// Callee sends SDP answer
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'answer_call') {
    $call_session_id = intval($_POST['call_session_id'] ?? 0);
    $sdp_answer      = $_POST['sdp_answer'] ?? '';

    if (!$call_session_id || !$sdp_answer) {
        jsonOut(['success' => false, 'error' => 'call_session_id and sdp_answer required']);
    }

    $stmt = $mainConn->prepare(
        "UPDATE call_sessions SET status='active', sdp_answer=?, answered_at=NOW() WHERE id=? AND status='ringing'"
    );
    if (!$stmt) { dbErr($mainConn, 'answer_call'); }
    $stmt->bind_param('si', $sdp_answer, $call_session_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    jsonOut(['success' => true, 'updated' => $affected]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: send_ice
// Send an ICE candidate to the other peer
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'send_ice') {
    $call_session_id = intval($_POST['call_session_id'] ?? 0);
    $candidate       = $_POST['candidate'] ?? $_POST['ice_candidate'] ?? '';

    if (!$call_session_id || !$candidate) {
        jsonOut(['success' => false, 'error' => 'call_session_id and candidate required']);
    }

    // Use 'ice_candidate' to match existing webrtc_signals data; include from_type
    $from = ($caller_type === 'admin') ? 'user' : $caller_type; // from_type ENUM: user/vendor
    if (!in_array($from, ['user', 'vendor'])) $from = 'vendor';

    $stmt = $mainConn->prepare(
        "INSERT INTO webrtc_signals (call_session_id, from_type, signal_type, payload, created_at) VALUES (?, ?, 'ice_candidate', ?, NOW())"
    );
    if (!$stmt) { dbErr($mainConn, 'send_ice'); }
    $stmt->bind_param('iss', $call_session_id, $from, $candidate);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    jsonOut(['success' => true, 'signal_id' => $id]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: decline_call
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'decline_call') {
    $call_session_id = intval($_POST['call_session_id'] ?? 0);
    if (!$call_session_id) {
        jsonOut(['success' => false, 'error' => 'call_session_id required']);
    }

    $stmt = $mainConn->prepare(
        "UPDATE call_sessions SET status='declined', ended_at=NOW() WHERE id=? AND status IN ('ringing','active')"
    );
    if (!$stmt) { dbErr($mainConn, 'decline_call'); }
    $stmt->bind_param('i', $call_session_id);
    $stmt->execute();
    $stmt->close();

    jsonOut(['success' => true]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: end_call
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'end_call') {
    $call_session_id = intval($_POST['call_session_id'] ?? 0);
    $duration        = intval($_POST['duration'] ?? 0);
    if (!$call_session_id) {
        jsonOut(['success' => false, 'error' => 'call_session_id required']);
    }

    $stmt = $mainConn->prepare(
        "UPDATE call_sessions SET status='ended', ended_at=NOW(), duration_seconds=? WHERE id=?"
    );
    if (!$stmt) { dbErr($mainConn, 'end_call'); }
    $stmt->bind_param('ii', $duration, $call_session_id);
    $stmt->execute();
    $stmt->close();

    // Signal the other side that the call has ended
    $from = ($caller_type === 'admin') ? 'user' : $caller_type;
    if (!in_array($from, ['user', 'vendor'])) $from = 'vendor';

    $sigStmt = $mainConn->prepare(
        "INSERT INTO webrtc_signals (call_session_id, from_type, signal_type, payload, created_at) VALUES (?, ?, 'end', '{}', NOW())"
    );
    if ($sigStmt) {
        $payload = '{}';
        $sigStmt->bind_param('iss', $call_session_id, $from, $payload);
        $sigStmt->execute();
        $sigStmt->close();
    }

    jsonOut(['success' => true]);
}

// ══════════════════════════════════════════════════════════════════════════════
// ACTION: check_incoming (for admin polling — lightweight)
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'check_incoming') {
    if ($caller_type === 'admin') {
        $stmt = $mainConn->prepare(
            "SELECT id, order_id, caller_type, caller_id, call_type, created_at 
             FROM call_sessions 
             WHERE callee_type='admin' AND status='ringing' 
             AND created_at >= NOW() - INTERVAL 5 MINUTE
             ORDER BY id DESC LIMIT 1"
        );
    } else {
        $stmt = $mainConn->prepare(
            "SELECT id, order_id, caller_type, caller_id, call_type, created_at 
             FROM call_sessions 
             WHERE callee_type='vendor' AND callee_id=? AND status='ringing'
             AND created_at >= NOW() - INTERVAL 5 MINUTE
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $caller_id);
    }
    if (!$stmt) { dbErr($mainConn, 'check_incoming'); }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    jsonOut(['success' => true, 'incoming' => $row ? true : false, 'call' => $row]);
}

// Unknown action
jsonOut(['success' => false, 'error' => 'Unknown action: ' . $action]);
