<?php
function testSignal($label, $postFields) {
    $ch = curl_init('https://surpriseville.co.in/ajax/webrtc_signal.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    echo "$label: $res\n";
    curl_close($ch);
}

// Case 6: Admin identifying as caller_type='user' (customer)
testSignal("6. Admin as user calling", [
    'action' => 'initiate_call',
    'order_id' => 28,
    'call_type' => 'audio',
    'sdp_offer' => '{"type":"offer","sdp":"mock"}',
    'caller_type' => 'user',
    'caller_id' => 1,
    'admin_secret' => 'sv_admin_chat_key_2024'
]);
