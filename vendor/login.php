<?php
// vendor/login.php

// 1. SESSION MANAGEMENT
require_once '../db.php';
require_once 'includes/session_manager.php';
attemptAutoLogin($conn);

// 2. Redirect if already logged in
if (isset($_SESSION['vendor_logged_in']) && $_SESSION['vendor_logged_in'] === true) {
    $redirect = 'pending-alerts.php';
    if (isset($_SESSION['redirect_to'])) {
        $redirect = $_SESSION['redirect_to'];
        unset($_SESSION['redirect_to']);
    }
    header('Location: ' . $redirect);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Login | Surprise Ville</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f7f9; }
        .login-card { max-width: 450px; margin: 3rem auto; background: white; padding: 2rem; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .tabs { display: flex; gap: 1rem; margin-bottom: 2rem; border-bottom: 1px solid #eee; }
        .tab { padding: 0.75rem 1rem; cursor: pointer; font-weight: 600; color: #666; border-bottom: 2px solid transparent; }
        .tab.active { color: var(--primary-color, #4361ee); border-bottom-color: var(--primary-color, #4361ee); }
        .form-section { display: none; }
        .form-section.active { display: block; }
        .btn-whatsapp { background: #25D366; color: white; border: none; padding: 12px; border-radius: 8px; width: 100%; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer; }
        .btn-whatsapp:disabled { opacity: 0.6; cursor: not-allowed; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .alert-error { background: #fee2e2; color: #b91c1c; }
        .alert-success { background: #dcfce7; color: #15803d; }
        .otp-input-group { display: none; margin-top: 1rem; }
        .otp-phone-row { display: flex; gap: 8px; }

        @media (max-width: 480px) {
            .otp-phone-row {
                flex-direction: column;
            }
            .otp-phone-row button {
                width: 100% !important;
            }
            .login-card {
                margin: 1.5rem 12px;
                padding: 1.25rem;
            }
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div style="text-align:center; margin-bottom: 2rem;">
            <img src="../surpriseville-logo.png" alt="Surprise Ville" style="max-height: 55px; max-width: 200px; object-fit: contain; margin-bottom: 10px;">
            <h1 style="font-size: 1.3rem; color: #1e293b; margin-top: 5px;">Vendor Portal</h1>
            <p style="color: #64748b; font-size: 0.9rem;">Sign in to manage your bookings</p>
        </div>

        <div class="tabs">
            <div class="tab active" data-target="password-login">Password</div>
            <div class="tab" data-target="otp-login">WhatsApp OTP</div>
        </div>

        <div id="message"></div>

        <!-- Password Login Section -->
        <div id="password-login" class="form-section active">
            <form id="passwordLoginForm">
                <input type="hidden" name="auth_type" value="password">
                <div class="form-group">
                    <label>Email or Mobile Number</label>
                    <input type="text" name="identifier" required placeholder="Enter your email or phone">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-primary" style="width:100%; padding: 12px;">Sign In</button>
            </form>
        </div>

        <!-- OTP Login Section -->
        <div id="otp-login" class="form-section">
            <form id="otpLoginForm">
                <input type="hidden" name="auth_type" value="otp">
                <div class="form-group">
                    <label>WhatsApp Number</label>
                    <div class="otp-phone-row">
                        <input type="text" id="otp_phone" name="identifier" required placeholder="10-digit mobile" maxlength="10">
                        <button type="button" id="sendOtpBtn" class="btn btn-secondary" style="white-space:nowrap;">Get OTP</button>
                    </div>
                </div>
                
                <div class="otp-input-group" id="otpInputGroup">
                    <div class="form-group">
                        <label>Enter 4-Digit OTP</label>
                        <input type="text" name="otp" id="otp_code" maxlength="4" placeholder="• • • •" style="text-align:center; font-size: 1.2rem; letter-spacing: 0.5rem;">
                    </div>
                    <button type="submit" class="btn-whatsapp">Verify & Login</button>
                </div>
            </form>
        </div>

        <div style="margin-top: 2rem; text-align: center; font-size: 0.9rem; color: #64748b;">
            Don't have an account? <a href="register.php" style="color: var(--primary-color, #4361ee); font-weight: 600;">Register as Vendor</a>
        </div>
    </div>

    <script>
        // Tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tab.dataset.target).classList.add('active');
                document.getElementById('message').innerHTML = '';
            });
        });

        const showMsg = (msg, type) => {
            const div = document.getElementById('message');
            div.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
        };

        // Send OTP Logic
        document.getElementById('sendOtpBtn').addEventListener('click', function() {
            const phone = document.getElementById('otp_phone').value;
            if (phone.length < 10) {
                showMsg('Please enter a valid 10-digit mobile number', 'error');
                return;
            }

            const btn = this;
            btn.disabled = true;
            btn.innerText = 'Sending...';

            const formData = new FormData();
            formData.append('phone', phone);

            fetch('../backend/otp_handler.php?action=request_otp', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showMsg(data.message, 'success');
                    document.getElementById('otpInputGroup').style.display = 'block';
                    btn.innerText = 'Resend OTP';
                    setTimeout(() => { btn.disabled = false; }, 30000); // 30s cooldown
                } else {
                    showMsg(data.message, 'error');
                    btn.disabled = false;
                    btn.innerText = 'Get OTP';
                }
            })
            .catch(() => {
                showMsg('Connection error', 'error');
                btn.disabled = false;
            });
        });

        // Handle Logins
        const handleLogin = (formId) => {
            document.getElementById(formId).addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                if (window.AndroidDeviceID) formData.append('device_id', window.AndroidDeviceID);

                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerText;
                btn.disabled = true;
                btn.innerText = 'Authenticating...';

                fetch('../backend/vendor_login.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showMsg(data.message, 'success');
                        setTimeout(() => { window.location.href = data.redirect; }, 1000);
                    } else {
                        showMsg(data.message, 'error');
                        btn.disabled = false;
                        btn.innerText = originalText;
                    }
                })
                .catch(() => {
                    showMsg('Network error', 'error');
                    btn.disabled = false;
                    btn.innerText = originalText;
                });
            });
        };

        handleLogin('passwordLoginForm');
        handleLogin('otpLoginForm');
    </script>
</body>
</html>