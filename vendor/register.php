<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Registration</title>
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        /* BASE STYLES */
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f6f9;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* HEADER */
        .header {
            background: #fff;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .header h1 {
            font-size: 20px;
            margin: 0;
            font-weight: 700;
            color: #2c3e50;
        }

        .header nav a {
            color: #007bff;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }

        /* CONTAINER */
        .container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* CARD */
        .card {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            box-sizing: border-box;
        }

        .card h2 {
            margin-top: 0;
            text-align: center;
            color: #333;
            margin-bottom: 25px;
        }

        /* FORM ELEMENTS */
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #555;
            font-size: 13px;
        }

        input[type="text"],
        input[type="email"],
        input[type="tel"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            /* Prevents zoom on mobile */
            box-sizing: border-box;
            transition: border 0.2s;
        }

        input:focus {
            border-color: #007bff;
            outline: none;
        }

        /* BUTTON */
        .btn-primary {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: #007bff;
            color: white;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        /* ALERTS */
        .alert {
            padding: 12px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 20px;
            text-align: center;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* CATEGORY GRID */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #eee;
            padding: 10px;
            border-radius: 8px;
            background: #fafafa;
        }

        .checkbox-item {
            position: relative;
        }

        .checkbox-item input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkbox-item label {
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 10px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            height: 100%;
            margin: 0;
            box-sizing: border-box;
        }

        /* Selected State */
        .checkbox-item input:checked+label {
            background: #e7f1ff;
            border-color: #007bff;
            color: #007bff;
            font-weight: bold;
        }

        /* TERMS & CONDITIONS */
        .terms-box {
            background: #fafafa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .terms-content {
            max-height: 200px;
            overflow-y: auto;
            font-size: 13px;
            color: #555;
            padding-right: 10px;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .terms-content h4 {
            margin: 10px 0 5px 0;
            color: #333;
            font-size: 13px;
        }

        .terms-content p {
            margin: 0 0 10px 0;
            line-height: 1.4;
        }

        .terms-checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .terms-checkbox-container input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .terms-checkbox-container label {
            margin: 0;
            cursor: pointer;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }

        /* LINKS */
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }

        .login-link a {
            color: #007bff;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 480px) {
            .card {
                padding: 15px;
            }
            .container {
                padding: 10px;
            }
        }
    </style>
</head>

<body>

    <div class="header" style="padding: 10px 20px;">
        <img src="../surpriseville-logo.png" alt="Surprise Ville" style="max-height: 40px; max-width: 160px; object-fit: contain;">
        <nav>
            <a href="login.php">Login</a>
        </nav>
    </div>

    <div class="container">
        <div class="card">
            <div style="text-align: center; margin-bottom: 20px;">
                <img src="../surpriseville-logo.png" alt="Surprise Ville" style="max-height: 55px; max-width: 200px; object-fit: contain; margin-bottom: 10px;">
                <h2 style="margin: 0; font-size: 1.5rem; color: #1e293b;">Create Account</h2>
                <p style="margin: 5px 0 0 0; color: #64748b; font-size: 0.9rem;">Register as a Partner</p>
            </div>

            <div id="message"></div>

            <form id="registerForm">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" placeholder="e.g. Rahul Sharma" required>
                </div>

                <div class="form-group">
                    <label for="business_name">Business Name</label>
                    <input type="text" id="business_name" name="business_name" placeholder="e.g. Sharma Catering" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="name@example.com" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" placeholder="10-digit mobile number" pattern="[0-9]{10}" required>
                </div>

                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" placeholder="e.g. Delhi" required>
                </div>

                <div class="form-group">
                    <label for="vendor_type">Vendor Category / Type</label>
                    <select id="vendor_type" name="vendor_type" required style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; box-sizing: border-box; background: #fff; color: #333; outline: none; transition: border-color 0.2s;">
                        <option value="" disabled selected>Select Category Type</option>
                        <option value="activity">Activity (Photography, Magician, DJ, Mascot, etc.)</option>
                        <option value="decoration">Decoration & Setups</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Create a strong password" required>
                </div>



                <div class="terms-box">
                    <label style="font-size: 14px; margin-bottom: 10px; display: block; color: #2c3e50; font-weight: bold;">Vendor Terms & Conditions (Mandatory Acceptance)</label>
                    <div class="terms-content">
                        <p>Platform me login karte hi aap neeche diye gaye sabhi rules aur guidelines follow karne ke liye agree karte hain.</p>

                        <h4>1. Time Management</h4>
                        <p>Aapko assigned location par time se pehle ya exact time par pahunchna hoga.<br>
                            Bina valid reason ke late hone par aapke future assignments affect ho sakte hain.</p>

                        <h4>2. Material Responsibility</h4>
                        <p>Aapko ensure karna hoga ki saara required saman (balloons, tools, decoration items, etc.) pehle se check karke saath laya gaya ho.<br>
                            Site par kisi bhi saman ki kami aapki responsibility hogi.</p>

                        <h4>3. Professional Behavior</h4>
                        <p>Client ke saath hamesha polite, respectful aur professional behavior maintain karein.<br>
                            Kisi bhi type ka rude ya unprofessional behavior acceptable nahi hoga.</p>

                        <h4>4. Clean Work Area</h4>
                        <p>Setup ke dauraan aur baad me cleanliness maintain karna zaroori hai.<br>
                            Client ke ghar ya venue ka dhyan rakhein aur saara waste properly dispose karein.</p>

                        <h4>5. Personal Grooming</h4>
                        <p>Aapka neat & clean rehna aur presentable hona duty ke dauraan mandatory hai.</p>

                        <h4>6. Dress Code</h4>
                        <p>Assignment ke dauraan company T-shirt ya uniform pehna compulsory hai.<br>
                            Dress code follow na karne par action liya ja sakta hai.</p>

                        <h4>7. Work Completion Proof</h4>
                        <p>Decoration complete hone ke baad vendor ko decoration ke saath apni (self/photo proof) capture karni hogi.<br>
                            Yeh photos proof of work completion aur quality verification ke liye use hongi.<br>
                            Required photos submit na karne par payment ya future assignments affect ho sakte hain.</p>

                        <p style="margin-top: 15px;"><strong>Main confirm karta hoon ki maine sabhi terms & conditions ko padh liya hai, samajh liya hai aur main inhe follow karunga.</strong></p>
                        <p><strong>Agar main rules follow nahi karta hoon, to mujhe suspend ya platform se remove kiya ja sakta hai.</strong></p>
                    </div>
                    <div class="terms-checkbox-container">
                        <input type="checkbox" id="terms_agree" name="terms_agree" required>
                        <label for="terms_agree">I Agree to the Terms & Conditions</label>
                    </div>
                </div>

                <!-- Registration Button (Initially visible) -->
                <button type="button" class="btn-primary" id="requestOtpBtn">Register Now</button>

                <!-- OTP Verification Group (Initially hidden) -->
                <div id="otpGroup" style="display: none; margin-top: 20px; border-top: 2px dashed #eee; padding-top: 20px;">
                    <div class="form-group">
                        <label for="otp" style="color: #28a745; font-weight: bold;">Enter 4-Digit OTP sent to WhatsApp</label>
                        <input type="text" id="otp" name="otp" placeholder="• • • •" maxlength="4" style="text-align: center; font-size: 20px; letter-spacing: 10px;">
                    </div>
                    <button type="submit" class="btn-primary" id="verifyBtn" style="background: #28a745;">Verify & Complete Registration</button>
                    <p style="text-align: center; margin-top: 10px;">
                        <button type="button" id="resendBtn" style="background:none; border:none; color:#007bff; cursor:pointer; font-size:13px;" disabled>Resend OTP (30s)</button>
                    </p>
                </div>
            </form>

            <div class="login-link">
                Already have an account? <a href="login.php">Login Here</a>
            </div>
        </div>
    </div>

    <script>


        // Handle "Register Now" click (Step 1: Request OTP)
        document.getElementById('requestOtpBtn').addEventListener('click', function() {
            const form = document.getElementById('registerForm');
            const messageDiv = document.getElementById('message');
            const phone = document.getElementById('phone').value;
            const btn = this;

            // Basic validation check before sending OTP
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }



            btn.disabled = true;
            btn.innerText = "Sending OTP...";
            messageDiv.innerHTML = '';

            const formData = new FormData();
            formData.append('phone', phone);

            fetch('../backend/otp_handler.php?action=request_otp', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
                        document.getElementById('otpGroup').style.display = 'block';
                        btn.style.display = 'none'; // Hide the first button
                        startResendTimer();
                    } else {
                        messageDiv.innerHTML = '<div class="alert alert-error">' + (data.message || 'Failed to send OTP') + '</div>';
                        btn.disabled = false;
                        btn.innerText = "Register Now";
                    }
                })
                .catch(error => {
                    messageDiv.innerHTML = '<div class="alert alert-error">Server error while sending OTP.</div>';
                    btn.disabled = false;
                    btn.innerText = "Register Now";
                });
        });

        function startResendTimer() {
            const resendBtn = document.getElementById('resendBtn');
            let timeLeft = 30;
            resendBtn.disabled = true;
            
            const timer = setInterval(() => {
                timeLeft--;
                resendBtn.innerText = `Resend OTP (${timeLeft}s)`;
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    resendBtn.disabled = false;
                    resendBtn.innerText = "Resend OTP";
                }
            }, 1000);
        }

        document.getElementById('resendBtn').addEventListener('click', function() {
            document.getElementById('requestOtpBtn').click();
        });

        // Handle Form Submit (Step 2: Verify & Register)
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const btn = document.getElementById('verifyBtn');
            const messageDiv = document.getElementById('message');
            const otpValue = document.getElementById('otp').value;

            if (otpValue.length < 4) {
                messageDiv.innerHTML = '<div class="alert alert-error">Please enter the 4-digit OTP.</div>';
                return;
            }

            // Disable button
            btn.disabled = true;
            btn.innerText = "Verifying & Registering...";
            messageDiv.innerHTML = '';

            const formData = new FormData(this);

            fetch('../backend/vendor_register.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
                        document.getElementById('registerForm').reset();
                        window.scrollTo(0, 0);
                        setTimeout(() => {
                            window.location.href = 'login.php';
                        }, 2000);
                    } else {
                        messageDiv.innerHTML = '<div class="alert alert-error">' + (data.message || 'Registration failed') + '</div>';
                        btn.disabled = false;
                        btn.innerText = "Verify & Complete Registration";
                    }
                })
                .catch(error => {
                    messageDiv.innerHTML = '<div class="alert alert-error">Server error. Please try again later.</div>';
                    console.error('Error:', error);
                    btn.disabled = false;
                    btn.innerText = "Verify & Complete Registration";
                });
        });
    </script>
</body>

</html>