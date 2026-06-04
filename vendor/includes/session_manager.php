<?php
// vendor/includes/session_manager.php

/**
 * ENFORCE PERMANENT SESSION (30 DAYS)
 */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 2592000); 
    
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $cookie_domain = '';
    if (!empty($host)) {
        $host = explode(':', $host)[0];
        if (strpos($host, 'surpriseville.co.in') !== false) {
            $cookie_domain = '.surpriseville.co.in';
        } elseif (strpos($host, 'btnevents.in') !== false) {
            $cookie_domain = '.btnevents.in';
        }
    }
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
              (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 2592000,
            'path' => '/',
            'domain' => $cookie_domain,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params(2592000, '/; samesite=Lax', $cookie_domain, $secure, true);
    }
    session_start();
}

/**
 * ATTEMPT AUTO-LOGIN
 * If the user is not logged in, check for the 'vendor_remember_token' cookie.
 */
function attemptAutoLogin($conn) {
    if (isset($_SESSION['vendor_logged_in']) && $_SESSION['vendor_logged_in'] === true) {
        return true;
    }

    if (isset($_COOKIE['vendor_remember_token'])) {
        $token = $_COOKIE['vendor_remember_token'];
        
        // Lookup token in DB
        $stmt = $conn->prepare("SELECT id, name, business_name, email, current_device_id FROM vendors WHERE remember_token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Restore Session
            $_SESSION['vendor_logged_in'] = true;
            $_SESSION['vendor_id'] = $row['id'];
            $_SESSION['vendor_name'] = $row['name'];
            $_SESSION['vendor_business_name'] = $row['business_name'];
            $_SESSION['vendor_email'] = $row['email'];
            $_SESSION['current_device_id'] = $row['current_device_id'];
            
            // Log for debugging
            error_log("Auto-login successful for vendor ID: " . $row['id']);
            return true;
        }
    }
    
    return false;
}
