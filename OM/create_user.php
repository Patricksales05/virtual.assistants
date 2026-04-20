<?php
require_once 'db_config.php';
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    header("Location: index.php");
    exit();
}
$user_name = $_SESSION['username'];
$role = $_SESSION['role'];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_user = $_POST['username'];
    $new_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $new_full_name = $_POST['full_name'];
    $new_email = $_POST['email'];
    $new_phone = $_POST['phone_number'];
    $new_address = $_POST['address'];
    $new_region = $_POST['region'];
    $new_city = $_POST['city'];
    $new_brgy = $_POST['barangay'];
    $new_role = $_POST['role'];

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone_number, address, region, city, brgy, role, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$new_user, $new_pass, $new_full_name, $new_email, $new_phone, $new_address, $new_region, $new_city, $new_brgy, $new_role]);
        header("Location: users.php?success=created");
        exit();
    } catch (PDOException $e) {
        $error_msg = "Username or Email already exists.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Add Member - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #6366f1;
            --bg-dark: #0f172a;
            --card-glass: rgba(15, 23, 42, 0.7);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background: linear-gradient(-45deg, #0f172a, #111827, #1e293b, #1e1b4b);
            background-size: 300% 300%;
            animation: gradientBG 15s ease infinite;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
        }

        @keyframes gradientBG {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        .sidebar {
            width: 260px;
            background: rgba(15, 23, 42, 0.9);
            border-right: 1px solid var(--card-border);
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
        }

        .logo-img {
            max-width: 100%;
            height: auto;
            margin-bottom: 3rem;
            filter: brightness(0) invert(1);
        }

        .nav-link {
            text-decoration: none;
            color: var(--text-muted);
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s;
            margin-bottom: 0.5rem;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-color);
        }

        .nav-link.active {
            background: var(--primary-color);
            color: white;
        }

        .main {
            flex: 1;
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .form-card {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 2.5rem;
            width: 100%;
            max-width: 850px;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .form-input,
        .form-select {
            width: 100%;
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            color: white;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(30, 41, 59, 0.8);
        }

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.3s;
        }

        .password-toggle:hover {
            color: white;
        }

        .btn-submit {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 1rem 2rem;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            margin-top: 2rem;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3);
        }
    </style>
</head>

<body>
    <aside class="sidebar">
        <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        <a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> <span class="nav-text">Home</span></a>
        <a href="users.php" class="nav-link active"><i class="fas fa-users"></i> <span class="nav-text">Dashboard</span></a>
        <a href="reports.php" class="nav-link"><i class="fas fa-chart-line"></i> <span class="nav-text">Staff Audit</span></a>
        <a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> <span class="nav-text">Settings</span></a>
    </aside>

    <main class="main">
        <div
            style="margin-bottom: 2rem; display: flex; align-items: center; gap: 1.25rem; width: 100%; max-width: 850px;">
            <a href="users.php" style="color: var(--text-muted); font-size: 1.5rem;"><i
                    class="fas fa-arrow-left"></i></a>
            <h2 style="font-size: 1.75rem; font-weight: 800;">Add New Member</h2>
        </div>

        <div class="form-card">
            <form action="create_user.php" method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-input" placeholder="e.g. jdoe24" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Role</label>
                        <select name="role" class="form-select" required>
                            <option value="Staff">Staff</option>
                            <option value="Team Lead">Team Lead</option>
                            <option value="Operations Manager">Operations Manager</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-input" placeholder="Full legal name" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input" placeholder="corporate@email.com" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone_number" id="phone_number" class="form-input"
                            placeholder="09XX-XXX-XXXX" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" class="form-input"
                                placeholder="Default password" required>
                            <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Complete Address</label>
                        <input type="text" name="address" class="form-input" placeholder="Unit/Street/Village" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Region</label>
                        <select name="region" id="regionSelect" class="form-select" required>
                            <option value="">Select Region</option>
                            <option value="NCR">NCR (National Capital Region)</option>
                            <option value="CAR">CAR (Cordillera Administrative Region)</option>
                            <option value="Region I">Region I (Ilocos Region)</option>
                            <option value="Region II">Region II (Cagayan Valley)</option>
                            <option value="Region III">Region III (Central Luzon)</option>
                            <option value="Region IV-A">Region IV-A (CALABARZON)</option>
                            <option value="MIMAROPA">MIMAROPA (Southwestern Tagalog)</option>
                            <option value="Region V">Region V (Bicol Region)</option>
                            <option value="Region VI">Region VI (Western Visayas)</option>
                            <option value="Region VII">Region VII (Central Visayas)</option>
                            <option value="Region VIII">Region VIII (Eastern Visayas)</option>
                            <option value="Region IX">Region IX (Zamboanga Peninsula)</option>
                            <option value="Region X">Region X (Northern Mindanao)</option>
                            <option value="Region XI">Region XI (Davao Region)</option>
                            <option value="Region XII">Region XII (SOCCSKSARGEN)</option>
                            <option value="Region XIII">Region XIII (Caraga Region)</option>
                            <option value="BARMM">BARMM (Muslim Mindanao)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">City / Municipality</label>
                        <select name="city" id="citySelect" class="form-select" required>
                            <option value="">Select City/Municipality</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Barangay</label>
                        <select name="barangay" id="brgySelect" class="form-select" required>
                            <option value="">Select Barangay</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Create Member Account</button>
            </form>
        </div>
    </main>

    <script src="../address_handler.js"></script>
    <script>
        // Password toggle
        const togglePassword = document.querySelector('#togglePassword');
        const passwordInput = document.querySelector('#password');
        togglePassword.addEventListener('click', function () {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye-slash');
        });

        // Ensure manual trigger for region population
        document.addEventListener('DOMContentLoaded', () => {
            const rSelect = document.querySelector('select[name="region"]');
            if (rSelect && rSelect.value) {
                rSelect.dispatchEvent(new Event('change'));
            }
        });

        // Phone mask
        document.getElementById('phone_number').addEventListener('input', function (e) {
            let x = e.target.value.replace(/\D/g, '').match(/(\d{0,4})(\d{0,3})(\d{0,4})/);
            e.target.value = !x[2] ? x[1] : x[1] + '-' + x[2] + (x[3] ? '-' + x[3] : '');
        });

        <?php if (isset($error_msg)): ?>
            Swal.fire({ icon: 'error', title: 'Oops...', text: '<?php echo $error_msg; ?>', background: '#1e293b', color: '#f8fafc' });
        <?php endif; ?>
    </script>
</body>

</html>