<?php
/**
 * webrtc_signal_proxy.php
 * =======================
 * Routes WebRTC signaling to the LOCAL signaling API (/ajax/webrtc_signal.php).
 * This avoids cross-origin session issues with the remote main site.
 * 
 * webrtc_client.js calls this file → we forward to local ajax/webrtc_signal.php
 * with session identity injected.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Start session to identify caller
if (file_exists(__DIR__ . '/vendor/includes/session_manager.php')) {
    require_once __DIR__ . '/vendor/includes/session_manager.php';
} else {
    session_start();
}

// Determine caller identity from session
$postFields = $_POST;

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $postFields['caller_type']  = 'admin';
    $postFields['caller_id']    = 1;
    $postFields['admin_secret'] = 'sv_admin_chat_key_2024';
} elseif (isset($_SESSION['vendor_id'])) {
    $postFields['caller_type']  = 'vendor';
    $postFields['caller_id']    = intval($_SESSION['vendor_id']);
    $postFields['vendor_id']    = intval($_SESSION['vendor_id']);
    $postFields['admin_secret'] = 'sv_admin_chat_key_2024';
} else {
    echo json_encode(['success' => false, 'error' => 'Unauthorized — no active session']);
    exit;
}

// Close session so we don't block concurrent requests
session_write_close();

// Merge any GET params into POST
if (!empty($_GET)) {
    foreach ($_GET as $k => $v) {
        if (!isset($postFields[$k])) {
            $postFields[$k] = $v;
        }
    }
}

// Store merged fields back to $_POST for the required script
$_POST = $postFields;

$localScript = __DIR__ . '/ajax/webrtc_signal.php';
if (file_exists($localScript)) {
    require $localScript;
    exit;
}

// Fallback to cURL loopback if local file not found
$localUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/ajax/webrtc_signal.php';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $localUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Forward cookies so session is re-established on local request
if (!empty($_SERVER['HTTP_COOKIE'])) {
    curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE']);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Signal proxy curl failed: ' . curl_error($ch),
        'url'     => $localUrl
    ]);
} elseif ($httpCode >= 400) {
    echo json_encode([
        'success'   => false,
        'error'     => 'Local signal API error',
        'http_code' => $httpCode,
        'response'  => $response
    ]);
} else {
    echo $response;
}

curl_close($ch);
