<?php
// 1. Authenticate and capture headers
echo "Logging in to live server...\n";
$ch = curl_init('https://partners.surpriseville.co.in/backend/admin_login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'username' => 'admin',
    'password' => '123456789'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
curl_close($ch);

// Parse PHPSESSID from Set-Cookie header
$sessionCookie = '';
if (preg_match('/set-cookie:\s*(PHPSESSID=[^;]+)/i', $header, $matches)) {
    $sessionCookie = $matches[1];
}
echo "Parsed Session Cookie: " . $sessionCookie . "\n\n";

if (!$sessionCookie) {
    die("Failed to parse session cookie!\n");
}

// 2. Call the proxy first with the session cookies (this should inject user_id = 1)
echo "Calling live webrtc_signal_proxy.php with session cookies...\n";
$ch2 = curl_init('https://partners.surpriseville.co.in/webrtc_signal_proxy.php');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, [
    'action' => 'initiate_call',
    'order_id' => 28,
    'call_type' => 'audio',
    'sdp_offer' => '{"type":"offer","sdp":"mock_sdp"}'
]);
curl_setopt($ch2, CURLOPT_COOKIE, $sessionCookie);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
$resProxy = curl_exec($ch2);
echo "Proxy Response:\n" . $resProxy . "\n\n";
curl_close($ch2);

// 3. Call check_session.php after proxy call to check if user_id is now in session
echo "Inspecting live session AFTER proxy call via check_session.php...\n";
$ch3 = curl_init('https://partners.surpriseville.co.in/check_session.php');
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_COOKIE, $sessionCookie);
curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
$resSession = curl_exec($ch3);
echo "Session Data:\n" . $resSession . "\n\n";
curl_close($ch3);
