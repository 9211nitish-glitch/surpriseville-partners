<?php
$ch = curl_init('https://surpriseville.co.in/ajax/chat_api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
$postFields = [
    'action' => 'get_messages',
    'order_id' => 28,
    'caller_type' => 'admin',
    'caller_id' => 1,
    'admin_secret' => 'sv_admin_chat_key_2024'
];
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
echo "Response from remote chat API:\n";
echo $res . "\n";
curl_close($ch);
