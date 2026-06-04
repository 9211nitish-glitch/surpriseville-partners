<?php
// vendor/includes/auth_check.php

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/session_manager.php';

// Try to auto-login if session is not active but remember cookie exists
attemptAutoLogin($conn);

if (!isset($_SESSION['vendor_logged_in']) || !isset($_SESSION['vendor_id'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['redirect_to'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}
