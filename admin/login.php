<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Admin Access | Surprise Ville</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3f37c9;
            --secondary: #4cc9f0;
            --bg: #f8fafc;
            --text: #1e293b;
            --border: #e2e8f0;
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            font-family: 'Inter', sans-serif;
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }

        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            width: 100%;
            max-width: 440px;
            border: 1px solid #fff;
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .logo-area {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo-area h1 {
            margin: 10px 0 0;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--text);
        }

        .logo-area p {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 5px;
        }

        .icon-box {
            width: 60px;
            height: 60px;
            background: #eef2ff;
            color: var(--primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 8px;
            color: #475569;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            transition: color 0.3s;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px 12px 48px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            background: #fff;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        }

        .form-group input:focus + i {
            color: var(--primary);
        }

        .btn-login {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-login:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(67, 97, 238, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        #message {
            margin-bottom: 20px;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .footer-note {
            text-align: center;
            margin-top: 30px;
            color: #94a3b8;
            font-size: 0.8rem;
        }

        /* Loading Animation */
        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .is-loading .spinner { display: block; }
        .is-loading .btn-text { display: none; }
        .is-loading .btn-icon { display: none; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-area">
            <div class="icon-box">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h1>Command Center</h1>
            <p>Admin Authorization Required</p>
        </div>

        <div id="message"></div>

        <form id="adminLoginForm">
            <div class="form-group">
                <label for="username">Administrative ID</label>
                <div class="input-wrapper">
                    <input type="text" id="username" name="username" placeholder="Enter username" required autocomplete="username">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Security Passkey</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">
                    <i class="fa-solid fa-key"></i>
                </div>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">
                <span class="spinner"></span>
                <span class="btn-text">Authenticate Session</span>
                <i class="fa-solid fa-arrow-right btn-icon"></i>
            </button>
        </form>

        <div class="footer-note">
            <i class="fa-solid fa-lock"></i> Secured by Antigravity OS
        </div>
    </div>

    <script>
        document.getElementById('adminLoginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('loginBtn');
            const formData = new FormData(this);
            const messageDiv = document.getElementById('message');
            
            btn.classList.add('is-loading');
            btn.disabled = true;
            messageDiv.innerHTML = '';
            
            fetch('../backend/admin_login.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageDiv.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> ' + data.message + '</div>';
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 1000);
                } else {
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                    messageDiv.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> ' + data.message + '</div>';
                }
            })
            .catch(error => {
                btn.classList.remove('is-loading');
                btn.disabled = false;
                messageDiv.innerHTML = '<div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i> Communication error. Reconnect and try again.</div>';
                console.error('Error:', error);
            });
        });
    </script>
</body>
</html>
