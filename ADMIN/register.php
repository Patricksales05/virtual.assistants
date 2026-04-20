<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Seamless Assist</title>
    <!-- Modern Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
            --secondary-color: #6366f1; /* Changed from Pink to Blue to match login */
            --secondary-hover: #4f46e5;
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            color: var(--text-main);
            position: relative;
            overflow-x: hidden;
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

        .register-container {
            width: 100%;
            max-width: 700px;
            animation: fadeIn 0.8s ease-out;
        }

        .register-card {
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
            margin-bottom: 2rem;
        }

        .logo-img {
            display: block;
            margin: 0 auto 1rem;
            max-width: 220px;
            height: auto;
            filter: grayscale(1) brightness(100);
        }

        .welcome-text h1 {
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: -0.025em;
            margin-bottom: 0.25rem;
            text-align: center;
            background: linear-gradient(to right, #ffffff, var(--primary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome-text p {
            color: var(--text-muted);
            font-size: 0.95rem;
            text-align: center;
            margin-bottom: 2rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
            position: relative;
            flex: 1;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
        }

        .input-wrapper {
            position: relative;
        }

        .form-input, .form-select {
            width: 100%;
            background: var(--input-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 0.65rem 0.85rem;
            color: var(--text-main);
            font-size: 0.85rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1.25rem;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(30, 41, 59, 0.8);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: var(--text-main);
        }

        .btn-submit {
            width: 100%;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-top: 1rem;
            box-shadow: 0 4px 6px -1px rgba(237, 75, 130, 0.2);
        }

        .btn-submit:hover {
            background: var(--secondary-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(237, 75, 130, 0.3);
        }

        .login-link {
            text-align: center;
            margin-top: 2rem;
            font-size: 0.95rem;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--secondary-color);
            text-decoration: none;
            font-weight: 700;
            margin-left: 0.25rem;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 600px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="video-bg-container">
        <video autoplay muted loop playsinline id="bg-video">
            <source src="../OM/image/a Cinematic Fitness Video...SONY FX6.mp4" type="video/mp4">
        </video>
    </div>

    <div class="register-container">
        <div class="register-card">
            <div class="logo-section">
                <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Seamless Assist Logo" class="logo-img">
                <div class="welcome-text">
                    <h1>Create Account</h1>
                    <p>Please enter your details to register.</p>
                </div>
            </div>

            <form action="register_process.php" method="POST" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group" style="grid-column: span 1;">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-input" placeholder="Choose a username" required>
                </div>

                <div class="form-group" style="grid-column: span 1;">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" placeholder="Enter your email (for recovery)" required>
                </div>

                <div class="form-group" style="grid-column: span 1;">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-input" placeholder="Enter your full name" required>
                </div>

                <div class="form-group" style="grid-column: span 1;">
                    <label class="form-label">Phone Number</label>
                    <input type="text" name="phone_number" id="phone_number" class="form-input" placeholder="09XX-XXX-XXXX" required maxlength="13">
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Complete Address</label>
                    <input type="text" name="address" class="form-input" placeholder="Unit/Lot/Street/Subdivision" required>
                </div>

                <div class="form-group" style="grid-column: span 2; margin-bottom: 0.5rem;">
                    <div class="form-row" style="margin-bottom: 0;">
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label class="form-label">Region</label>
                            <select name="region" class="form-select" required>
                                <option value="">Select Region</option>
                                <option value="NCR">NCR (National Capital Region)</option>
                                <option value="CAR">CAR (Cordillera Administrative Region)</option>
                                <option value="Region I">Region I (Ilocos Region)</option>
                                <option value="Region II">Region II (Cagayan Valley)</option>
                                <option value="Region III">Region III (Central Luzon)</option>
                                <option value="Region IV-A">Region IV-A (CALABARZON)</option>
                                <option value="MIMAROPA">MIMAROPA (Region IV-B)</option>
                                <option value="Region V">Region V (Bicol Region)</option>
                                <option value="Region VI">Region VI (Western Visayas)</option>
                                <option value="Region VII">Region VII (Central Visayas)</option>
                                <option value="Region VIII">Region VIII (Eastern Visayas)</option>
                                <option value="Region IX">Region IX (Zamboanga Peninsula)</option>
                                <option value="Region X">Region X (Northern Mindanao)</option>
                                <option value="Region XI">Region XI (Davao Region)</option>
                                <option value="Region XII">Region XII (SOCCSKSARGEN)</option>
                                <option value="Region XIII">Region XIII (Caraga)</option>
                                <option value="BARMM">BARMM (Bangsamoro Autonomous Region in Muslim Mindanao)</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label class="form-label">City/Municipality</label>
                            <select name="city" class="form-select" required>
                                <option value="">Select City/Municipality</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label class="form-label">Barangay</label>
                            <select name="barangay" class="form-select" required>
                                <option value="">Select Barangay</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Account Role</label>
                    <select name="role" class="form-select" required>
                        <option value="Admin" selected>Admin</option>
                        <option value="Operations Manager">Operations Manager</option>
                        <option value="Team Lead">Team Lead</option>
                        <option value="HR">HR</option>
                        <option value="Staff">Staff</option>
                    </select>
                </div>

                <div class="form-group" style="grid-column: span 1;">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" class="form-input" placeholder="Create a password" required>
                        <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                    </div>
                </div>

                <div class="form-group" style="grid-column: span 1;">
                    <label class="form-label">Confirm Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input" placeholder="Confirm your password" required>
                        <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
                    </div>
                </div>

                <button type="submit" class="btn-submit" style="grid-column: span 2;">Register</button>
            </form>

            <div class="login-link">
                Already have an account? <a href="index.php">Sign In Here</a>
            </div>
        </div>
    </div>

    <script src="../address_handler.js"></script>
    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const toggleConfirmPassword = document.querySelector('#toggleConfirmPassword');
        const confirmPassword = document.querySelector('#confirm_password');
        const phoneNumber = document.querySelector('#phone_number');

        function setupToggle(toggle, input) {
            toggle.addEventListener('click', function (e) {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.classList.toggle('fa-eye-slash');
            });
        }

        setupToggle(togglePassword, password);
        setupToggle(toggleConfirmPassword, confirmPassword);

        // Simple mask for 09XX-XXX-XXXX
        phoneNumber.addEventListener('input', function (e) {
            let x = e.target.value.replace(/\D/g, '').match(/(\d{0,4})(\d{0,3})(\d{0,4})/);
            if (!x) return;
            e.target.value = !x[2] ? x[1] : x[1] + '-' + x[2] + (x[3] ? '-' + x[3] : '');
        });

        // Notifications handling
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) {
            const success = urlParams.get('success');
            if (success === 'registered') {
                Swal.fire({
                    icon: 'success',
                    title: 'Registration Successful!',
                    html: `
                        <div style="font-size: 0.9rem; text-align: center;">
                            <p style="margin-bottom: 1rem;">Your account has been created successfully.</p>
                            <p style="color: #f59e0b; font-weight: 700; border: 1px solid rgba(245, 158, 11, 0.3); padding: 0.75rem; border-radius: 8px; background: rgba(245, 158, 11, 0.1);">
                                <i class="fas fa-clock"></i> PENDING APPROVAL<br>
                                <span style="font-size: 0.75rem; font-weight: 400; color: #94a3b8;">Please wait for the Operations Manager to activate your access.</span>
                            </p>
                        </div>
                    `,
                    background: '#0f172a',
                    color: '#f8fafc',
                    confirmButtonColor: '#6366f1',
                    confirmButtonText: 'Got it',
                    timer: 10000,
                    timerProgressBar: true
                });
            }
        }
        
        if (urlParams.has('error')) {
            const error = urlParams.get('error');
            let message = 'An error occurred during registration.';
            if (error === 'password_mismatch') message = 'Passwords do not match. Please try again.';
            if (error === 'already_exists') message = 'Username or Email is already taken.';
            
            Swal.fire({
                icon: 'error',
                title: 'Oops...',
                text: message,
                background: '#1e293b',
                color: '#f8fafc',
                confirmButtonColor: '#6366f1'
            });
        }
    </script>
</body>
</html>
