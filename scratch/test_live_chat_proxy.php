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

// 2. Call live chat_api_proxy.php to get messages
echo "Calling live chat_api_proxy.php with session cookies...\n";
$ch2 = curl_init('https://partners.surpriseville.co.in/chat_api_proxy.php');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POST, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, [
    'action' => 'get_messages',
    'order_id' => 28
]);
curl_setopt($ch2, CURLOPT_COOKIE, $sessionCookie);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
$resChat = curl_exec($ch2);
echo "Chat Proxy Response:\n" . $resChat . "\n";
curl_close($ch2);
