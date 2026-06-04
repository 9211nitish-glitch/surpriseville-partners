<?php
// vendor/header.php
require_once '../db.php';
require_once 'includes/session_manager.php';
attemptAutoLogin($conn);

$page_title = $page_title ?? 'Vendor Portal';
$vendor_name = $_SESSION['vendor_name'] ?? 'Vendor';
$vendor_id = $_SESSION['vendor_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="../assets/style.css">

    <!-- Google Fonts: Inter & Material Symbols -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet" />

    <!-- Custom WebRTC + Chat Engines (loaded from main site) -->
    <script src="https://surpriseville.co.in/assets/js/chat_engine.js"></script>
    <script src="https://surpriseville.co.in/assets/js/webrtc_client.js"></script>

    <style>
        /* ---------- PREMIUM SAAS THEME (LIGHT/DARK) ---------- */
        :root[data-theme="light"] {
            --bg-body: #f4f6f9;
            --bg-card: #ffffff;
            --bg-header: #ffffff;
            --bg-sidebar: rgba(255, 255, 255, 0.95);
            --text-main: #2c3e50;
            --text-muted: #666666;
            --border-color: rgba(0, 0, 0, 0.08);
            --card-shadow: 0 8px 32px rgba(31, 38, 135, 0.05);
            --primary: #135bec;

            /* Stat Base Colors */
            --stat-bg-1: #fff1e6;
            /* Soft Orange */
            --stat-bg-2: #eaffe4;
            /* Soft Emerald */
            --stat-bg-3: #fef0f0;
            /* Soft Rose */
            --stat-bg-4: #f7f1ff;
            /* Light Violet */
            --stat-bg-5: #e8f4fd;
            /* Sky Blue */
        }

        :root[data-theme="dark"] {
            --bg-body: #0d1117;
            --bg-card: #161b22;
            --bg-header: #1e2530;
            /* Made slightly lighter and solid for visibility */
            --bg-sidebar: #161b22;
            --text-main: #e6edf3;
            --text-muted: #8b949e;
            --border-color: rgba(255, 255, 255, 0.1);
            --card-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            --primary: #3b82f6;

            /* Dark Mode Stat Glows */
            --stat-bg-1: rgba(255, 152, 0, 0.15);
            --stat-bg-2: rgba(76, 175, 80, 0.15);
            --stat-bg-3: rgba(244, 67, 54, 0.15);
            --stat-bg-4: rgba(156, 39, 176, 0.15);
            --stat-bg-5: rgba(33, 150, 243, 0.15);
        }

        /* ---------- CUSTOM SCROLLBAR ---------- */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.1);
            border-radius: 10px;
        }

        :root[data-theme="dark"] ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.2);
        }

        :root[data-theme="dark"] ::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* ---------- BASE STYLES ---------- */
        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            transition: background-color 0.3s ease, color 0.3s ease;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            padding: 12px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ---------- LAYOUT STRUCTURE ---------- */
        .app-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ---------- DESKTOP SIDEBAR ---------- */
        .sidebar {
            width: 280px;
            background-color: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 25px 0;
            z-index: 1002;
            /* Above header */
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 25px;
            margin-bottom: 40px;
        }

        .sidebar-brand .logo-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), #0056b3);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 8px 16px rgba(19, 91, 236, 0.2);
        }

        .sidebar-brand h1 {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0;
            letter-spacing: -0.5px;
        }

        .sidebar-brand p {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 2px 0 0 0;
        }

        .sidebar-nav {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 0 15px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .sidebar-nav a.active {
            background-color: rgba(19, 91, 236, 0.1);
            color: var(--primary);
            border: 1px solid rgba(19, 91, 236, 0.2);
        }

        .sidebar-nav a:hover:not(.active) {
            background-color: var(--border-color);
            color: var(--text-main);
        }

        .sidebar-footer {
            padding: 20px 15px 0;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .sidebar-footer .logout-btn {
            color: #ef4444;
        }

        .sidebar-footer .logout-btn:hover {
            background-color: rgba(239, 68, 68, 0.1);
            color: #dc2626;
        }

        /* ---------- MAIN CONTENT AREA ---------- */
        .main-content {
            flex: 1;
            margin-left: 280px;
            /* Offset by sidebar width */
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* ---------- HEADER ---------- */
        .header {
            background-color: var(--bg-header) !important;
            color: var(--text-main);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 1001;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .header-title {
            display: flex;
            flex-direction: column;
        }

        .header-title h2 {
            font-size: 24px;
            margin: 0;
            font-weight: 700;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }

        .header-title p {
            font-size: 14px;
            color: var(--text-muted);
            margin: 4px 0 0 0;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .search-bar {
            display: flex;
            align-items: center;
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 8px 15px;
            gap: 8px;
        }

        .search-bar input {
            background: transparent;
            border: none;
            outline: none;
            color: var(--text-main);
            font-size: 14px;
            width: 200px;
        }

        .notification-btn {
            position: relative;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .notification-btn:hover {
            color: var(--text-main);
            background: var(--border-color);
        }

        .notification-btn .badge {
            position: absolute;
            top: 10px;
            right: 12px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ff8a3d;
            box-shadow: 0 0 10px rgba(255, 138, 61, 0.5);
        }

        .profile-widget {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 6px 15px 6px 6px;
            border-radius: 999px;
        }

        .profile-widget img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }

        .profile-info {
            display: flex;
            flex-direction: column;
        }

        .profile-info .name {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1;
        }

        .profile-info .role {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        .menu-btn {
            display: none;
            font-size: 24px;
            cursor: pointer;
            margin-right: 15px;
            background: none;
            border: none;
            padding: 0;
            color: var(--text-main);
        }

        /* Theme Toggle Button */
        .theme-toggle {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            transition: background 0.2s;
            border: 1px solid var(--border-color);
        }

        .theme-toggle:hover {
            background: var(--border-color);
        }

        /* ---------- MOBILE SIDEBAR OVERLAY ---------- */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 998;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .overlay.active {
            display: block;
            opacity: 1;
        }

        /* ---------- MODULE SPECIFIC TWEAKS PRE-LOADED ---------- */
        .card {
            background: var(--bg-card);
            padding: 25px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            transition: background 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }

        /* ---------- MOBILE RESPONSIVE LOGIC ---------- */
        @media (max-width: 900px) {
            .app-wrapper {
                flex-direction: column;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
            }

            .header {
                padding: 10px;
                flex-wrap: wrap;
                gap: 10px;
            }

            .header-title {
                display: none;
                /* Hide welcome text on mobile to save space */
            }

            .header-actions {
                width: 100%;
                justify-content: space-between;
                gap: 10px;
            }

            .search-bar {
                flex: 1;
                max-width: none;
            }

            .search-bar input {
                width: 100%;
            }

            .profile-info {
                display: none;
                /* Hide names */
            }

            .menu-btn {
                display: block;
            }

            /* Convert fixed sidebar drawer for mobile */
            .sidebar {
                /* IMPORTANT FIX FROM USER */
                background: #fff !important;
                /* light mode solid */
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
                opacity: 1 !important;

                position: fixed;
                top: 0;
                left: -280px;
                /* Hide initially */
                height: 100vh;
                width: 280px;
                z-index: 1005;
                /* Bring above everything */
                box-shadow: 2px 0 10px rgba(0, 0, 0, 0.15);
                transition: left 0.3s ease;
                overflow-y: auto;
            }

            /* Dark mode mobile fix */
            :root[data-theme="dark"] .sidebar {
                background: #161b22 !important;
            }

            .sidebar.active {
                left: 0;
            }

            .container {
                padding: 8px;
            }

            #globalCallPopup {
                width: 90% !important;
                max-width: 320px !important;
                right: 5% !important;
            }

            #globalMessagePopup {
                width: 90% !important;
                max-width: 300px !important;
                right: 5% !important;
            }

            .main-content-inner {
                padding: 12px !important;
            }
        }

        .main-content-inner {
            padding: 30px;
        }

        .mobile-logout {
            display: none;
        }

        /* ---------- STITCH DASHBOARD UTILITIES ---------- */
        .glass-panel {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
        }

        :root[data-theme="light"] .glass-panel {
            background: #ffffff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .flex {
            display: flex;
        }

        .items-center {
            align-items: center;
        }

        .justify-between {
            justify-content: space-between;
        }

        .gap-2 {
            gap: 8px;
        }

        .gap-3 {
            gap: 12px;
        }

        .gap-4 {
            gap: 16px;
        }

        .text-slate-400 {
            color: #94a3b8;
        }

        .w-full {
            width: 100%;
        }

        .h-full {
            height: 100%;
        }

        /* Notification Badge Animation */
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(19, 91, 236, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(19, 91, 236, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(19, 91, 236, 0);
            }
        }

        /* Material Icons Setup */
        .material-symbols-outlined {
            font-family: 'Material Symbols Outlined';
            font-weight: normal;
            font-style: normal;
            font-size: 24px;
            line-height: 1;
            letter-spacing: normal;
            text-transform: none;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            -webkit-font-feature-settings: 'liga';
            -webkit-font-smoothing: antialiased;
        }
        /* Sidebar Badge Styling */
        .sidebar-badge {
            margin-left: auto;
            background: #ef4444; /* Vibrant Red */
            color: white;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            min-width: 18px;
            height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
        }

        #sb-shop-count {
            background: #10b981; /* Success Green */
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
        }

        #sb-gig-count {
            background: #f59e0b; /* Warning Amber */
            box-shadow: 0 4px 8px rgba(245, 158, 11, 0.3);
        }
    </style>


    <script>
        // Theme Initialization (Run immediately to prevent flash)
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();

        // Badge update logic globally across vendor portal
        function refreshBadge() {
            fetch('ajax/sidebar_counts.php').then(r => r.json()).then(data => {
                // Update Top Header Badge (Existing Notification Dot)
                const headerBadge = document.querySelector('.notification-btn .badge');
                if (headerBadge) {
                    headerBadge.style.display = data.pending > 0 ? 'block' : 'none';
                }

                // Update Sidebar Pending
                const pb = document.getElementById('sb-pending-count');
                if (pb) {
                    pb.textContent = data.pending;
                    pb.style.display = data.pending > 0 ? 'inline-flex' : 'none';
                }

                // Update Sidebar Shop (Now All Orders)
                const sb = document.getElementById('sb-shop-count');
                if (sb) {
                    let totalOrders = (parseInt(data.shop) || 0) + (parseInt(data.gigs) || 0);
                    sb.textContent = totalOrders;
                    sb.style.display = totalOrders > 0 ? 'inline-flex' : 'none';
                }

                // Update Sidebar Gigs (Legacy, kept to avoid errors if element exists elsewhere)
                const gb = document.getElementById('sb-gig-count');
                if (gb) {
                    gb.textContent = data.gigs;
                    gb.style.display = data.gigs > 0 ? 'inline-flex' : 'none';
                }
            }).catch(e => console.log('Badge refresh error', e));
        }
        setInterval(refreshBadge, 10000);
        refreshBadge(); // Initial call
        // Trigger location request on load and then every 60 seconds
        window.addEventListener('load', requestLocation);
        setInterval(requestLocation, 60000); // 60 seconds

        function requestLocation() {
            if ("geolocation" in navigator) {
                const blocker = document.getElementById('location-blocker');

                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        // Success - always hide blocker if we get a position
                        blocker.style.display = 'none';
                        let lastUpdate = sessionStorage.getItem('last_loc_update');
                        let now = Date.now();

                        if (!lastUpdate || (now - parseInt(lastUpdate)) > 55000) {
                            fetch('../backend/check_new_orders.php?vendor_id=<?= $vendor_id ?>&lat=' + position.coords.latitude + '&lng=' + position.coords.longitude)
                            .then(r => r.json())
                            .then(data => {
                                sessionStorage.setItem('last_loc_update', now);
                                if (data.new_order) {
                                    if (data.type === 'reminder') {
                                        showReminderPopup();
                                    } else {
                                        if (typeof showIncomingOrderPopup === 'function') {
                                            showIncomingOrderPopup();
                                        } else {
                                            document.getElementById('incomingOverlay').style.display = 'flex';
                                        }
                                    }
                                }
                            }).catch(e => console.error("Location/Order update failed:", e));
                        }
                    },
                    function(error) {
                        // ONLY BLOCK IF PERMISSION IS EXPLICITLY DENIED
                        if (error.code === error.PERMISSION_DENIED) {
                            blocker.style.display = 'flex';
                            document.getElementById('loc-error-msg').style.display = 'block';
                            console.warn("Location permission denied by user.");
                        } else {
                            // For Timeout (3) or Position Unavailable (2), we don't block the screen.
                            // We just log it and wait for the next interval (60s) to try again.
                            console.log("Location temporary issue (Code: " + error.code + "): " + error.message);
                            // If blocker is already shown, keep it, but if it's hidden, don't show it for a simple timeout.
                        }
                    }, {
                        enableHighAccuracy: true,
                        timeout: 30000, // Increased to 30 seconds
                        maximumAge: 0
                    }
                );
            } else {
                alert("Geolocation is not supported by your browser.");
            }
        }
    </script>
</head>

<body>
    <?php
    // --- GLOBAL WALLET DEBT ALERT ---
    $global_balance = 0;
    if (isset($_SESSION['vendor_id'])) {
        $vid = (int)$_SESSION['vendor_id'];
        $vwQ = $conn->query("SELECT balance FROM vendor_wallet WHERE vendor_id = $vid");
        if ($vwQ && $vwR = $vwQ->fetch_assoc()) {
            $global_balance = floatval($vwR['balance']);
        }
    }
    if ($global_balance < 0): ?>
        <div style="background: #ef4444; color: white; padding: 12px 20px; text-align: center; font-weight: 700; position: sticky; top: 0; z-index: 10000; display: flex; align-items: center; justify-content: center; gap: 10px; border-bottom: 2px solid rgba(0,0,0,0.1);">
            <span class="material-symbols-outlined">warning</span>
            <span>Wallet Recharge Required! Your balance is negative (₹<?= number_format($global_balance, 2) ?>). Please recharge to accept new jobs.</span>
            <a href="recharge.php" style="background: white; color: #ef4444; padding: 6px 16px; border-radius: 8px; text-decoration: none; font-size: 14px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">Recharge Now</a>
        </div>
    <?php endif; ?>
    <!-- LOCATION BLOCKER OVERLAY -->
    <div id="location-blocker" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.95); z-index:99999; flex-direction:column; align-items:center; justify-content:center; color:white; text-align:center; padding:20px; font-family:'Inter', sans-serif; backdrop-filter: blur(10px);">
        <span class="material-symbols-outlined" style="font-size:72px; color:#ef4444; margin-bottom:20px; text-shadow: 0 0 20px rgba(239,68,68,0.5);">location_off</span>
        <h2 style="margin:0 0 10px 0; font-size:28px; font-weight:800; letter-spacing:-0.5px;">Location Access Required</h2>
        <p style="margin:0 0 30px 0; max-width:450px; color:#a1a1aa; font-size:16px; line-height:1.6;">You must allow location access in your browser to receive nearby gig orders. Without your precise location, the system cannot verify if you are within range.</p>
        <button onclick="requestLocation()" style="padding:14px 28px; background:var(--primary); color:white; border:none; border-radius:12px; font-weight:600; font-size:16px; cursor:pointer; box-shadow: 0 8px 16px rgba(19,91,236,0.3); transition: transform 0.2s;">Grant Permission</button>
        <p id="loc-error-msg" style="color:#fca5a5; margin-top:20px; font-size:14px; max-width: 400px; display:none; background: rgba(239,68,68,0.1); padding: 10px; border-radius: 8px; border: 1px solid rgba(239,68,68,0.2);"><strong>Denied:</strong> Please check your browser's site settings, change location access to <strong>"Allow"</strong>, and then refresh this page.</p>
    </div>

    <div class="app-wrapper">
        <!-- Overlay for Mobile Sidebar -->
        <div class="overlay" id="overlay" onclick="toggleMenu()"></div>

        <!-- Desktop / Mobile Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand" style="justify-content: center; margin-bottom: 30px;">
                <img src="../surpriseville-logo.png" alt="Surprise Ville" style="max-width: 200px; max-height: 50px; object-fit: contain;">
            </div>

            <nav class="sidebar-nav">
                <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">dashboard</span>
                    Dashboard
                </a>
                <a href="pending-alerts.php" class="<?= basename($_SERVER['PHP_SELF']) == 'pending-alerts.php' ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">schedule</span>
                    Pending Orders
                    <span id="sb-pending-count" class="sidebar-badge" style="display:none;"></span>
                </a>
                <a href="shop-orders.php" class="<?= in_array(basename($_SERVER['PHP_SELF']), ['shop-orders.php', 'my-jobs.php', 'my-gigs.php']) ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">work</span>
                    My Orders
                    <span id="sb-shop-count" class="sidebar-badge" style="display:none;"></span>
                </a>
                <a href="completed-orders.php" class="<?= basename($_SERVER['PHP_SELF']) == 'completed-orders.php' ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">history</span>
                    Completed Jobs
                </a>
                <a href="wallet.php" class="<?= basename($_SERVER['PHP_SELF']) == 'wallet.php' ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">account_balance_wallet</span>
                    Wallet
                </a>
                <a href="packages.php" class="<?= basename($_SERVER['PHP_SELF']) == 'packages.php' ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">inventory_2</span>
                    My Packages
                </a>
                <a href="my-reviews.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-reviews.php' ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">star</span>
                    My Reviews
                </a>
                <a href="submit_journey.php" class="<?= basename($_SERVER['PHP_SELF']) == 'submit_journey.php' ? 'active' : '' ?>">
                    <span class="material-symbols-outlined">video_library</span>
                    Journey Video
                </a>
            </nav>

            <div class="sidebar-footer">
                <a href="profile.php" class="sidebar-nav-link <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>" style="display:flex; align-items:center; gap:12px; padding:12px 15px; color:var(--text-muted); text-decoration:none; font-weight:600; font-size:14px; border-radius:12px;">
                    <span class="material-symbols-outlined">person_outline</span>
                    Profile
                </a>
                <a href="logout.php" class="logout-btn" style="display:flex; align-items:center; gap:12px; padding:12px 15px; text-decoration:none; font-weight:600; font-size:14px; border-radius:12px; transition:all 0.2s ease;">
                    <span class="material-symbols-outlined">logout</span>
                    Logout
                </a>
            </div>
        </aside>

        <!-- Main Content Wrapper -->
        <main class="main-content">
            <!-- Top Header Panel -->
            <header class="header">
                <div class="flex items-center gap-3" style="display:flex; align-items:center;">
                    <button class="menu-btn" onclick="toggleMenu()" style="font-size:24px; cursor:pointer; background:none; border:none; color:var(--text-main);">
                        <span class="material-symbols-outlined">menu</span>
                    </button>
                    <div class="header-title">
                        <h2>Welcome back, <?= htmlspecialchars($vendor_name) ?>!</h2>
                        <p>Here's what's happening with your services today.</p>
                    </div>
                </div>

                <div class="header-actions">
                    <div class="search-bar">
                        <span class="material-symbols-outlined text-slate-400" style="color:#94a3b8; font-size:20px;">search</span>
                        <input type="text" placeholder="Search orders..." />
                    </div>

                    <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle Theme">
                        <span class="material-symbols-outlined" id="theme-icon">light_mode</span>
                    </button>

                    <button class="notification-btn">
                        <span class="material-symbols-outlined">notifications</span>
                        <span class="badge"></span>
                    </button>

                    <div class="profile-widget">
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($vendor_name) ?>&background=135bec&color=fff&size=64&bold=true" alt="Profile avatar">
                        <div class="profile-info">
                            <span class="name"><?= htmlspecialchars($vendor_name) ?></span>
                            <span class="role">Top Vendor</span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- WhatsApp Style Calling Popup -->
            <div id="globalCallPopup" style="display:none; position:fixed; top:20px; right:20px; width:320px; background:var(--bg-card); border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,0.2); z-index:99999; border:1px solid var(--border-color); overflow:hidden; animation: slideInRight 0.3s ease;">
                <div style="background:var(--primary); color:#fff; padding:15px; display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; background:rgba(255,255,255,0.2); border-radius:50%; display:flex; align-items:center; justify-content:center;">
                        <span class="material-symbols-outlined">call</span>
                    </div>
                    <div style="flex:1;">
                        <div style="font-size:12px; opacity:0.8;">Incoming Call</div>
                        <div id="callSenderName" style="font-weight:700; font-size:15px;">Customer</div>
                    </div>
                </div>
                <div style="padding:15px; display:flex; gap:10px; background:var(--bg-card);">
                    <button id="declineCallBtn" style="flex:1; background:#fef4f4; color:#ef4444; border:1px solid #fee2e2; padding:10px; border-radius:8px; cursor:pointer; font-weight:600; font-size:13px;">Decline</button>
                    <button id="acceptCallBtn" style="flex:1; background:#22c55e; color:#fff; border:none; padding:10px; border-radius:8px; cursor:pointer; font-weight:600; font-size:13px;">Accept</button>
                </div>
                <audio id="ringtoneAudio" loop src="https://assets.mixkit.co/active_storage/sfx/1358/1358-preview.mp3"></audio>
                <audio id="reminderAudio" src="https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3"></audio>
                <audio id="chatNotifyAudio" src="https://assets.mixkit.co/active_storage/sfx/2357/2357-preview.mp3"></audio>
            </div>

            <!-- New Message Popup -->
            <div id="globalMessagePopup" style="display:none; position:fixed; top:85px; right:20px; width:300px; background:var(--bg-card); border-radius:12px; box-shadow:0 10px-40px rgba(0,0,0,0.2); z-index:99998; border:1px solid var(--border-color); overflow:hidden; animation: slideInRight 0.3s ease; cursor:pointer;">
                <div style="padding:15px; display:flex; align-items:center; gap:12px;">
                    <div style="width:40px; height:40px; background:var(--primary); color:#fff; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <span class="material-symbols-outlined">chat</span>
                    </div>
                    <div style="flex:1; overflow:hidden;">
                        <div id="msgSenderName" style="font-weight:700; font-size:14px; color:var(--text-main); margin-bottom:2px;">Name</div>
                        <div id="msgSnippet" style="font-size:12px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">New message received</div>
                    </div>
                </div>
            </div>

            <!-- Reminder Popup -->
            <div id="reminderPopup" style="display:none; position:fixed; top:20px; left:50%; transform:translateX(-50%); width:90%; max-width:400px; background:#fff3cd; border:2px solid #ffc107; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,0.2); z-index:999999; padding:20px; animation: slideDown 0.4s ease;">
                <div style="display:flex; align-items:center; gap:15px;">
                    <div style="font-size:30px;">⏰</div>
                    <div style="flex:1;">
                        <div style="font-weight:800; color:#856404; font-size:16px;">UPCOMING TASK REMINDER</div>
                        <div style="font-size:14px; color:#666;">Your task starts in 2 hours! Please reach the venue on time.</div>
                    </div>
                </div>
                <div style="margin-top:15px; text-align:right;">
                    <button onclick="dismissReminder()" style="background:#856404; color:#fff; border:none; padding:8px 20px; border-radius:8px; font-weight:700; cursor:pointer;">OK, I'm on it!</button>
                </div>
            </div>

            <style>
                @keyframes slideInRight {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideDown {
                    from { transform: translate(-50%, -100%); opacity: 0; }
                    to { transform: translate(-50%, 0); opacity: 1; }
                }
            </style>
            
            <script>
                function showReminderPopup() {
                    document.getElementById('reminderPopup').style.display = 'block';
                    const audio = document.getElementById('reminderAudio');
                    if (audio) {
                        audio.play().catch(e => console.log('Audio play blocked'));
                    }
                }

                function dismissReminder() {
                    document.getElementById('reminderPopup').style.display = 'none';
                    const audio = document.getElementById('reminderAudio');
                    if (audio) audio.pause();
                }

                function showIncomingOrderPopup() {
                    // This could be integrated into the existing call popup or a custom one
                    // For now, let's redirect/alert or show the standard overlay visible in pending-alerts.php
                    if (document.getElementById('incomingOverlay')) {
                        document.getElementById('incomingOverlay').style.display = 'flex';
                    } else {
                        // Standard browser alert if overlay not present on current page
                        // alert("New Order Received! Please check Pending Alerts.");
                    }
                }
            </script>

            <!-- Script for layout functionality -->
            <script>
                function toggleMenu() {
                    document.getElementById('sidebar').classList.toggle('active');
                    document.getElementById('overlay').classList.toggle('active');
                    document.body.style.overflow = document.getElementById('sidebar').classList.contains('active') ? 'hidden' : '';
                }

                // Theme setup
                const savedTheme = localStorage.getItem('theme') || 'light';
                document.documentElement.setAttribute('data-theme', savedTheme);
                document.getElementById('theme-icon').textContent = savedTheme === 'dark' ? 'light_mode' : 'dark_mode';

                function toggleTheme() {
                    const currentTheme = document.documentElement.getAttribute('data-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

                    document.documentElement.setAttribute('data-theme', newTheme);
                    localStorage.setItem('theme', newTheme);

                    document.getElementById('theme-icon').textContent = newTheme === 'dark' ? 'light_mode' : 'dark_mode';
                }

                // Global Notification Polling
                let lastCallId = 0;
                let lastMsgId  = 0;
                let _notifPending = false;

                function pollForNotifications() {
                    if (window.location.pathname.includes('order-chat.php')) {
                         // On chat page, just update the badge count
                         fetchNotifications(true);
                         return;
                    } 

                    fetchNotifications(false);
                }

                function fetchNotifications(onlyBadge = false) {
                    const formData = new FormData();
                    formData.append('action', 'check_notifications');
                    formData.append('order_id', '0'); 
                    <?php if(isset($_SESSION['vendor_id'])): ?>
                    formData.append('vendor_id', <?= $_SESSION['vendor_id'] ?>);
                    <?php endif; ?>

                    fetch('../chat_api_proxy.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'include'
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (!res || !res.success) return;

                        // 1. Update Badge
                        const badge = document.querySelector('.notification-btn .badge');
                        if (badge) {
                            badge.innerText = res.counts > 0 ? res.counts : '';
                            badge.style.display = res.counts > 0 ? 'flex' : 'none';
                        }

                        if (onlyBadge) return;

                        // 2. Handle Calls
                        if (res.call && parseInt(res.call.id) > lastCallId) {
                            lastCallId = parseInt(res.call.id);
                            showCallPopup(res.call);
                        }

                        // 3. Handle Messages — use parseInt to avoid string vs number mismatch
                        if (res.chat && res.chat.id && parseInt(res.chat.id) > lastMsgId) {
                            lastMsgId = parseInt(res.chat.id);
                            showChatPopup(res.chat);
                        }
                    })
                    .catch(e => console.error('Notification Poll Error:', e));
                }

                function showChatPopup(chat) {
                    const popup = document.getElementById('globalMessagePopup');
                    const name = document.getElementById('msgSenderName');
                    const snippet = document.getElementById('msgSnippet');
                    const audio = document.getElementById('chatNotifyAudio');
                    if (!popup) return;

                    name.innerText = chat.sender_name || 'Admin';
                    snippet.innerText = (chat.message || '').substring(0, 80);
                    popup.style.display = 'block';

                    try { if (audio) audio.play(); } catch(e) {}

                    const dest = chat.link || ('order-chat.php?order_id=' + (chat.order_id || ''));
                    popup.onclick = () => { window.location.href = dest; };

                    setTimeout(() => { if (popup) popup.style.display = 'none'; }, 8000);
                }

                function showCallPopup(call) {
                    const popup = document.getElementById('globalCallPopup');
                    const name = document.getElementById('callSenderName');
                    const ringtone = document.getElementById('ringtoneAudio');
                    
                    name.innerText = call.sender_name || 'Customer';
                    popup.style.display = 'block';
                    
                    try { ringtone.play(); } catch(e) {}

                    document.getElementById('acceptCallBtn').onclick = () => {
                        ringtone.pause();
                        popup.style.display = 'none';
                        // Redirect to order chat page — WebRTC call will auto-accept via call_id param
                        window.location.href = 'order-chat.php?order_id=' + call.order_id + '&call_id=' + call.id;
                    };

                    document.getElementById('declineCallBtn').onclick = () => {
                        ringtone.pause();
                        popup.style.display = 'none';
                        // Decline call via WebRTC signal API
                        const fd = new FormData();
                        fd.append('action', 'decline_call');
                        fd.append('call_session_id', call.id);
                        fd.append('vendor_id', <?= $vendor_id ?>);
                        fetch('/webrtc_signal_proxy.php', {
                            method: 'POST', body: fd, credentials: 'include'
                        });
                    };

                    // Auto hide after 30 seconds (missed call)
                    setTimeout(() => {
                        ringtone.pause();
                        popup.style.display = 'none';
                    }, 30000);
                }

                <?php if(isset($_SESSION['vendor_id'])): ?>
                function sendHeartbeat() {
                    const fd = new FormData();
                    fd.append('action', 'heartbeat');
                    fd.append('order_id', '0');
                    fd.append('vendor_id', <?= $vendor_id ?>);
                    fetch('../chat_api_proxy.php', { method: 'POST', body: fd, credentials: 'include' });
                }
                setInterval(sendHeartbeat, 20000);
                sendHeartbeat();

                setInterval(pollForNotifications, 20000);
                pollForNotifications();

                // Fallback: also poll local unread chats for popup (catches admin messages)
                let lastLocalMsgKey = '';
                async function pollLocalMessages() {
                    if (window.location.pathname.includes('order-chat.php')) return;
                    try {
                        const res = await fetch('../ajax_unread_chats.php', { credentials: 'include' });
                        const data = await res.json();
                        if (!data || !data.success || data.count === 0) return;
                        
                        // Find the first conversation in the list that actually has unread messages
                        const newestUnread = data.chats.find(c => c.unread_count > 0);
                        if (!newestUnread) return;
                        
                        const key = newestUnread.id + '_' + newestUnread.time;
                        if (key !== lastLocalMsgKey) {
                            lastLocalMsgKey = key;
                            showChatPopup({
                                id: newestUnread.id,
                                sender_name: newestUnread.title,
                                message: newestUnread.message,
                                order_id: newestUnread.id,
                                link: newestUnread.link
                            });
                        }
                    } catch(e) {}
                }
                setInterval(pollLocalMessages, 25000);
                pollLocalMessages();
                <?php endif; ?>
            </script>

            <!-- Opening tag for page content grids, must be closed in actual pages (e.g. dashboard.php, bottom of my-jobs.php, etc) that include header.php -->
            <div class="main-content-inner">