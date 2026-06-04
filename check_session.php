<?php
session_start();
header('Content-Type: application/json');
echo json_encode([
    'session' => $_SESSION,
    'session_id' => session_id(),
    'cookie' => $_COOKIE
]);
