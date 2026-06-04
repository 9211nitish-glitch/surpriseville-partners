<?php
// chat_api_proxy.php
// Forwards chat API calls to the main Surpriseville site,
// while injecting the caller's identity (admin/vendor session).

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- Start session to read who the caller is ---
session_start();

$targetUrl = 'https://surpriseville.co.in/ajax/chat_api.php';

// Build POST fields from incoming request
$postFields = $_POST;

// Inject caller identity so remote chat_api.php knows who sent it
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    // Admin is calling — inject admin identity
    $postFields['caller_type']  = 'admin';
    $postFields['caller_id']    = 1; // Default admin ID
    $postFields['admin_secret'] = 'sv_admin_chat_key_2024'; // Shared secret for remote validation
} elseif (isset($_SESSION['vendor_id'])) {
    // Check for phone numbers in vendor messages
    $msgToCheck = $_POST['message'] ?? '';
    $digitsOnly = preg_replace('/[^0-9]/', '', $msgToCheck);
    if (preg_match('/\d{10,}/', $digitsOnly)) {
        echo json_encode(['success' => false, 'message' => 'Sharing phone numbers is not allowed. / फ़ोन नंबर साझा करने की अनुमति नहीं है।']);
        exit;
    }
    // Vendor is calling — inject vendor identity
    $postFields['caller_type'] = 'vendor';
    $postFields['caller_id']   = intval($_SESSION['vendor_id']);
    $postFields['caller_name'] = $_SESSION['vendor_name'] ?? 'Vendor';
}

// Close session immediately after reading (don't block other requests)
session_write_close();

$ch = curl_init();

// Build URL with GET params if any
$url = $targetUrl;
if (!empty($_GET)) {
    $url .= '?' . http_build_query($_GET);
}
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);

// Forward safe request headers (skip host, content-length, encoding)
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        if (in_array(strtolower($name), ['host', 'content-length', 'accept-encoding'])) {
            continue;
        }
        $headers[] = "$name: $value";
    }
}
// Forward original cookies so remote session might still work
if (!empty($_SERVER['HTTP_COOKIE'])) {
    curl_setopt($ch, CURLOPT_COOKIE, $_SERVER['HTTP_COOKIE']);
}
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Forward any uploaded files
foreach ($_FILES as $key => $file) {
    if ($file['error'] === UPLOAD_ERR_OK) {
        $postFields[$key] = new CURLFile($file['tmp_name'], $file['type'], $file['name']);
    }
}

curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo json_encode(['error' => 'Proxy request failed: ' . curl_error($ch), 'code' => curl_errno($ch)]);
} elseif ($httpCode >= 400) {
    echo json_encode(['error' => 'Remote server error', 'http_code' => $httpCode, 'response' => $response]);
} else {
    echo $response;
}

curl_close($ch);
?>
