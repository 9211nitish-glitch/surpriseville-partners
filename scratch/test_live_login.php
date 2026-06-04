<?php
$ch = curl_init('https://partners.surpriseville.co.in/backend/admin_login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
$postFields = [
    'username' => 'admin',
    'password' => '123456789'
];
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
echo "Response from live Admin login:\n";
echo $res . "\n";
curl_close($ch);
