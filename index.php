<?php
require_once 'db_config.php';
if (isset($_SESSION['user_id'])) {
    $role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
    if ($role === 'operations manager') {
        header("Location: dashboard.php");
        exit();
    } elseif ($role === 'admin') {
        header("Location: ../ADMIN/dashboard.php");
        exit();
    } elseif ($role === 'team lead' || $role === 'team-lead') {
        header("Location: ../TL/dashboard.php");
        exit();
    } elseif ($role === 'staff' || $role === 'staff member') {
        header("Location: ../STAFF/dashboard.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Seamless Assist</title>
    <!-- Modern Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
            --bg-dark: #0f172a;
            --card-glass: rgba(15, 23, 42, 0.7);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --input-bg: rgba(30, 41, 59, 0.5);
            --card-border: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg-dark);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            color: var(--text-main);
        }

        .video-bg-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        #bg-video {
            position: absolute;
            top: 50%;
            left: 50%;
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            transform: translate(-50%, -50%);
            object-fit: cover;
            filter: brightness(0.4) blur(1px);
        }

        .login-container {
            width: 100%;
            max-width: 350px;
            padding: 1.5rem;
            animation: fadeIn 0.8s ease-out;
        }

        .login-card {
            background: var(--card-glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .logo-section {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .logo-img {
            display: block;
            margin: 0 auto 1.5rem;
            max-width: 180px;
            height: auto;
            filter: brightness(0) invert(1);
        }

        .welcome-text h1 {
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-bottom: 0.25rem;
            background: linear-gradient(to right, #ffffff, var(--primary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome-text p {
            color: var(--text-muted);
            font-size: 0.7rem;
        }

        .form-group {
            margin-bottom: 2rem;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 0.65rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 1.1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            transition: all 0.3s ease;
            z-index: 10;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 0.5rem 2.8rem 0.5rem 2.8rem;
            color: var(--text-main);
            font-size: 0.75rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(30, 41, 59, 0.8);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        .form-input:focus + i {
            color: var(--primary-color);
        }

        .password-toggle {
            position: absolute;
            right: 1.1rem;
            left: auto !important;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 10;
            pointer-events: auto !important;
        }

        .password-toggle:hover {
            color: var(--text-main);
        }

        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }

        .forgot-pass {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .forgot-pass:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        .btn-submit {
            width: 100%;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 0.65rem;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.2);
        }

        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .register-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 700;
            margin-left: 0.25rem;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 0.75rem;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            .login-container {
                padding: 1rem;
            }
            .login-card {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="video-bg-container">
        <video autoplay muted loop playsinline id="bg-video">
            <source src="image/a Cinematic Fitness Video...SONY FX6.mp4" type="video/mp4">
        </video>
    </div>

    <div class="login-container">
        <div class="login-card">
            <div class="logo-section">
                <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Seamless Assist Logo" class="logo-img">
                <div class="welcome-text">
                    <h1>Welcome Back</h1>
                    <p>Please enter your details to sign in.</p>
                </div>
            </div>


            <form action="login_process.php" method="POST">

                <div class="form-group">
                    <label class="form-label">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" name="username" class="form-input" placeholder="Enter your username" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="password" class="form-input" placeholder="Enter your password" required>
                        <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                    </div>
                </div>

                <div class="options-row">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" style="width: 1rem; height: 1rem;"> Remember me
                    </label>
                    <a href="javascript:void(0)" class="forgot-pass" id="forgot-password">Forgot password?</a>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Sign In</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="register-link">
                Don't have an account? <a href="register.php" style="color: var(--secondary-color);">Register Here</a>
            </div>
        </div>
    </div>

    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function (e) {
            // toggle the type attribute
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            // toggle the eye icon
            this.classList.toggle('fa-eye-slash');
        });

        const urlParams = new URLSearchParams(window.location.search);


        if (urlParams.get('error') === 'not_approved') {
            Swal.fire({
                icon: 'warning',
                title: 'Account Pending',
                text: 'Your account is currently waiting for approval from the Operations Manager.',
                background: '#1e293b',
                color: '#f8fafc',
                confirmButtonColor: '#6366f1'
            });
        }

        if (urlParams.get('error') === '1') {
            Swal.fire({
                icon: 'error',
                title: 'Login Failed',
                text: 'Invalid username or password. Please verify your credentials.',
                background: '#1e293b',
                color: '#f8fafc',
                confirmButtonColor: '#ef4444'
            });
        }

        if (urlParams.get('success') === 'registered') {
            Swal.fire({
                icon: 'success',
                title: 'Registration Successful',
                text: 'Your account has been created! Please wait for the OM to approve your access.',
                background: '#1e293b',
                color: '#f8fafc',
                confirmButtonColor: '#6366f1',
                confirmButtonText: 'Understood'
            });
        }
        // Forgot Password Logic
        document.getElementById('forgot-password').addEventListener('click', async function() {
            const { value: account } = await Swal.fire({
                title: 'Password Recovery',
                html: `
                    <div style="text-align: center; margin-bottom: 20px;">
                        <p style="color: #94a3b8; font-size: 0.9rem;">Enter your registered username or email to receive a secure recovery code.</p>
                    </div>
                    <div style="text-align: left; margin-bottom: 0;">
                        <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">Username / Email</label>
                        <div style="position: relative;">
                            <i class="fas fa-envelope" style="position: absolute; left: 1.1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; z-index: 10;"></i>
                            <input type="text" id="swal-account" placeholder="Enter your details..." style="width: 100%; border-radius: 12px; padding: 0.65rem 1.1rem 0.65rem 2.8rem; background: rgba(30, 41, 59, 0.5); color: #fff; border: 1px solid rgba(255,255,255,0.1); outline: none;">
                        </div>
                    </div>
                `,
                background: '#1e293b',
                color: '#f8fafc',
                confirmButtonColor: '#6366f1',
                confirmButtonText: 'Send Verification Code',
                showCancelButton: true,
                cancelButtonColor: 'rgba(255,255,255,0.1)',
                focusConfirm: false,
                preConfirm: () => {
                    const val = document.getElementById('swal-account').value;
                    if (!val) { Swal.showValidationMessage('Information required'); return false; }
                    return val;
                }
            });

            if (account) {
                Swal.fire({
                    title: 'Sending OTP...',
                    text: 'Connecting to secure relay...',
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); }
                });

                $.post('../forgot_password_handler.php', { action: 'send_otp', account: account }, async function(response) {
                    if (response.success) {
                        // For development/XAMPP testing:
                        if (response.debug_otp) {
                            Swal.fire({
                                icon: 'info',
                                title: 'Security Relay Local',
                                html: `Encrypted Relay Code: <strong style='font-size: 1.5rem; color: #6366f1; letter-spacing: 2px;'>${response.debug_otp}</strong><br><br><small style='color: #94a3b8;'>Use this code if external email delivery is blocked by storage limits.</small>`,
                                background: '#1e293b',
                                color: '#f8fafc',
                                confirmButtonColor: '#6366f1'
                            });
                        }

                        const { value: formValues } = await Swal.fire({
                            title: 'Complete Reset',
                            html: `
                                <p style="font-size: 0.9rem; color: #94a3b8; margin-bottom: 1.5rem;">A secure code was sent to <strong style="color: #6366f1;">${response.email}</strong></p>
                                
                                <div style="text-align: left; margin-bottom: 1.5rem;">
                                    <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">Verification Code (OTP)</label>
                                    <div style="position: relative;">
                                        <input id="swal-otp" placeholder="000000" style="width: 100%; text-align: center; letter-spacing: 12px; font-weight: 800; font-size: 1.25rem; border-radius: 12px; background: rgba(30, 41, 59, 0.5); color: #6366f1; border: 1px dashed rgba(99, 102, 241, 0.5); padding: 12px; outline: none;">
                                    </div>
                                </div>

                                <div style="text-align: left; margin-bottom: 1.5rem;">
                                    <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">New Password</label>
                                    <div style="position: relative;">
                                        <i class="fas fa-lock" style="position: absolute; left: 1.1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; z-index: 10;"></i>
                                        <input id="swal-new-pass" type="password" placeholder="Enter new password" style="width: 100%; border-radius: 12px; padding: 0.65rem 2.8rem 0.65rem 2.8rem; background: rgba(30, 41, 59, 0.5); color: #fff; border: 1px solid rgba(255,255,255,0.1); outline: none;">
                                        <i class="fas fa-eye password-toggle" id="toggleSwalPass1" style="position: absolute; right: 1.1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; z-index: 10;"></i>
                                    </div>
                                </div>

                                <div style="text-align: left; margin-bottom: 0;">
                                    <label style="display: block; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em;">Confirm Password</label>
                                    <div style="position: relative;">
                                        <i class="fas fa-check-circle" style="position: absolute; left: 1.1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; z-index: 10;"></i>
                                        <input id="swal-confirm-pass" type="password" placeholder="Confirm new password" style="width: 100%; border-radius: 12px; padding: 0.65rem 2.8rem 0.65rem 2.8rem; background: rgba(30, 41, 59, 0.5); color: #fff; border: 1px solid rgba(255,255,255,0.1); outline: none;">
                                        <i class="fas fa-eye password-toggle" id="toggleSwalPass2" style="position: absolute; right: 1.1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; z-index: 10;"></i>
                                    </div>
                                </div>
                            `,
                            background: '#1e293b',
                            color: '#f8fafc',
                            confirmButtonColor: '#6366f1',
                            confirmButtonText: 'Secure My Account',
                            focusConfirm: false,
                            didOpen: () => {
                                // Add styling and listeners locally
                                const toggle1 = document.getElementById('toggleSwalPass1');
                                const input1 = document.getElementById('swal-new-pass');
                                toggle1.addEventListener('click', () => {
                                    input1.type = input1.type === 'password' ? 'text' : 'password';
                                    toggle1.classList.toggle('fa-eye-slash');
                                });
                                
                                const toggle2 = document.getElementById('toggleSwalPass2');
                                const input2 = document.getElementById('swal-confirm-pass');
                                toggle2.addEventListener('click', () => {
                                    input2.type = input2.type === 'password' ? 'text' : 'password';
                                    toggle2.classList.toggle('fa-eye-slash');
                                });
                            },
                            preConfirm: () => {
                                const otp = document.getElementById('swal-otp').value;
                                const newPass = document.getElementById('swal-new-pass').value;
                                const confirmPass = document.getElementById('swal-confirm-pass').value;

                                if (!otp) { Swal.showValidationMessage('OTP is required'); return false; }
                                if (newPass.length < 6) { Swal.showValidationMessage('Password must be at least 6 characters'); return false; }
                                if (newPass !== confirmPass) { Swal.showValidationMessage('Passwords do not match'); return false; }
                                
                                return { otp: otp, new_password: newPass };
                            }
                        });

                        if (formValues) {
                            $.post('../forgot_password_handler.php', { 
                                action: 'reset_password', 
                                account: account, 
                                otp: formValues.otp, 
                                new_password: formValues.new_password 
                            }, function(resetRes) {
                                if (resetRes.success) {
                                    Swal.fire({ icon: 'success', title: 'Success', text: resetRes.message, background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#10b981' });
                                } else {
                                    Swal.fire({ icon: 'error', title: 'Error', text: resetRes.message, background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#ef4444' });
                                }
                            });
                        }
                    } else {
                        Swal.fire({ icon: 'error', title: 'Failed', text: response.message, background: '#1e293b', color: '#f8fafc', confirmButtonColor: '#ef4444' });
                    }
                });
            }
        });
    </script>
</body>
</html>
