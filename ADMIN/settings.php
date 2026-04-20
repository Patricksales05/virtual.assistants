<?php
require_once 'db_config.php';

// Role Guard
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];

// Fetch current user data for sync
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$admin_info = $stmt->fetch();

// Fetch System Settings & Telemetry (Isolated Blocks)
try {
    $total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
} catch (PDOException $e) {
    $total_users = 0;
}

try {
    $capacity_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_node_capacity'");
    $max_capacity = (int)($capacity_stmt->fetchColumn() ?: 200);
} catch (PDOException $e) {
    $max_capacity = 200;
}
$capacity_percentage = $max_capacity > 0 ? min(100, round(($total_users / $max_capacity) * 100)) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Account Settings - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #6366f1;
            --bg-dark: #0f172a;
            --card-glass: rgba(15, 23, 42, 0.4);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --sidebar-width: 200px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-dark); }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }

        body { 
            background: #020617;
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
            overflow-x: hidden;
            font-size: 0.75rem;
        }

        .sidebar {
            width: 250px;
            background: #0f172a;
            border-right: 1px solid var(--card-border);
            padding: 2rem 1.25rem;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 1000;
        }
        .logo-container { margin-bottom: 2.5rem; display: flex; align-items: center; justify-content: center; }
        .logo-img { width: 180px; filter: brightness(0) invert(1); image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges; }
        .nav-menu { list-style: none; flex: 1; margin-top: 1rem; }
        .nav-item { margin-bottom: 0.5rem; }
        .nav-link { 
            text-decoration: none; 
            color: #94a3b8; 
            padding: 0.85rem 1.25rem; 
            border-radius: 12px; 
            display: flex; 
            align-items: center; 
            gap: 1rem; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            font-weight: 600; 
            font-size: 0.85rem;
        }
        .nav-link i { width: 1.2rem; text-align: center; font-size: 1rem; }
        .nav-link:hover { color: #6366f1; background: rgba(99, 102, 241, 0.05); }
        .nav-link.active { 
            background: #6366f1; 
            color: white !important; 
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3); 
            font-weight: 700;
        }
        .nav-link.active i { color: white; }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 1.5rem;
            border-top: 1px solid var(--card-border);
        }
        .logout-link {
            color: #ff4d4d !important;
            font-weight: 800;
        }
        .logout-link i { color: #ff4d4d !important; }
        .logout-link:hover { background: rgba(239, 68, 68, 0.1); }

        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 1.5rem 2.5rem;
            width: calc(100% - 250px);
            background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.08) 0%, transparent 50%);
        }

        .theme-card {
            background: var(--card-glass);
            backdrop-filter: blur(20px);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 2.5rem;
            animation: fadeInUp 0.6s ease;
        }

        .form-group { margin-bottom: 1.25rem; }
        .form-label { 
            display: block; 
            font-size: 0.6rem; 
            font-weight: 800; 
            color: var(--text-muted); 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            margin-bottom: 0.5rem; 
        }
        .form-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.75rem 1rem;
            color: white;
            font-size: 0.8rem;
            font-weight: 600;
            transition: 0.3s;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: rgba(99, 102, 241, 0.05);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
        .btn-submit {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.85rem 2rem;
            border-radius: 14px;
            font-weight: 800;
            font-size: 0.8rem;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.15);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .btn-submit:hover {
            background: #4f46e5;
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(99, 102, 241, 0.25);
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Logo" class="logo-img">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Users</span></a></li>
            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Reports</span></a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link active"><i class="fas fa-cog"></i><span>Settings</span></a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="nav-link logout-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: flex-end;">
            <div>
                <h2 style="font-size: 1.25rem; font-weight: 800; letter-spacing: -0.02em;">Account Configuration</h2>
                <p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Root administrator synchronization protocol.</p>
            </div>
            <div style="text-align: right;">
                <div style="display: flex; align-items: center; gap: 0.5rem; background: rgba(16, 185, 129, 0.05); padding: 0.35rem 0.75rem; border-radius: 100px; border: 1px solid rgba(16, 185, 129, 0.1);">
                    <div style="width: 5px; height: 5px; background: #10b981; border-radius: 50%; box-shadow: 0 0 8px #10b981;"></div>
                    <span style="font-size: 0.55rem; font-weight: 800; color: #10b981; text-transform: uppercase;">System Secure</span>
                </div>
            </div>
        </header>

        <section style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 2rem; align-items: start;">
            <!-- Left: Settings Form -->
            <div class="theme-card">
                <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2.5rem; border-bottom: 1px solid var(--card-border); padding-bottom: 2rem;">
                    <div style="width: 56px; height: 56px; background: rgba(99, 102, 241, 0.1); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--primary-color); border: 1px solid rgba(99, 102, 241, 0.2);">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.1rem; font-weight: 800; color: white;">Profile Synchronization</h3>
                        <p style="font-size: 0.75rem; color: var(--text-muted);">Manage your operational identity and security credentials.</p>
                    </div>
                </div>

                <!-- Read-Only Account Identifiers -->
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 2.5rem; background: rgba(255,255,255,0.02); padding: 1.25rem; border-radius: 16px; border: 1px solid var(--card-border);">
                    <div style="text-align: left;">
                        <span style="display: block; font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Staff ID</span>
                        <span style="font-size: 0.85rem; font-weight: 700; color: white; letter-spacing: 0.05em;">ADM-<?php echo str_pad($admin_info['id'], 4, '0', STR_PAD_LEFT); ?></span>
                    </div>
                    <div style="text-align: left;">
                        <span style="display: block; font-size: 0.5rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Username</span>
                        <span style="font-size: 0.85rem; font-weight: 700; color: white;">@<?php echo htmlspecialchars($admin_info['username']); ?></span>
                    </div>
                </div>

                <form id="profile-sync-form">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($admin_info['full_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Authorized Email</label>
                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($admin_info['email']); ?>" required>
                    </div>
                </div>

                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--card-border); border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;">
                    <h4 style="font-size: 0.7rem; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1rem; font-weight: 800;">Security Protocol Override</h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div style="position: relative;">
                                <input type="password" id="new_password" name="new_password" class="form-input" placeholder="Maintain current if blank" style="padding-right: 2.5rem;">
                                <i class="fas fa-eye" id="toggleNew" style="position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 0.8rem;"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Key</label>
                            <div style="position: relative;">
                                <input type="password" id="confirm_password" name="confirm_password" class="form-input" placeholder="Verify security key" style="padding-right: 2.5rem;">
                                <i class="fas fa-eye" id="toggleConfirm" style="position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 0.8rem;"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Synchronize Profile</button>
            </form>
        </div>

        <!-- Right: Command Protocol & Dashboard Settings -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <div style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.75rem; position: relative; overflow: hidden; min-height: 480px; display: flex; flex-direction: column; justify-content: space-between;">
                <video autoplay muted loop style="position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0.15; filter: grayscale(1); pointer-events: none;">
                    <source src="https://assets.mixkit.co/videos/preview/mixkit-city-traffic-at-night-1008-large.mp4" type="video/mp4">
                </video>
                <div style="position: relative; z-index: 1;">
                    <h4 style="font-size: 0.8rem; font-weight: 800; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 1rem;">Command Protocol</h4>
                    <p style="font-size: 0.75rem; color: white; line-height: 1.6; font-weight: 600; opacity: 0.9; margin-bottom: 2rem;">Authorized administrator nodes verified. Multi-role telemetry auditing in progress to drive operational excellence.</p>
                    
                    <div style="background: rgba(0,0,0,0.4); padding: 1.25rem; border-radius: 16px; border: 1px solid var(--card-border); margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="font-size: 0.65rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase;">Resource Capacity</span>
                            <span id="capacity-counter" style="font-size: 0.75rem; color: white; font-weight: 800;"><?php echo $total_users; ?> / <?php echo $max_capacity; ?></span>
                        </div>
                        <div style="height: 6px; background: rgba(255,255,255,0.05); border-radius: 10px; overflow: hidden;">
                            <div id="capacity-bar" style="width: <?php echo $capacity_percentage; ?>%; height: 100%; background: linear-gradient(90deg, #6366f1, #10b981); box-shadow: 0 0 15px rgba(99, 102, 241, 0.4); transition: width 0.5s ease;"></div>
                        </div>
                    </div>

                    <div style="background: rgba(0,0,0,0.4); padding: 1.25rem; border-radius: 16px; border: 1px solid var(--card-border);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.6rem;">
                                <div style="width: 6px; height: 6px; background: var(--primary-color); border-radius: 50%; box-shadow: 0 0 10px var(--primary-color);"></div>
                                <span style="font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Cloud Sync</span>
                            </div>
                            <span style="font-size: 0.65rem; font-weight: 900; color: var(--primary-color);">ACTIVE</span>
                        </div>
                        <div style="height: 6px; background: rgba(255,255,255,0.05); border-radius: 100px; overflow: hidden;">
                            <div style="width: 85%; height: 100%; background: var(--primary-color); box-shadow: 0 0 15px rgba(99, 102, 241, 0.4);"></div>
                        </div>
                    </div>
                </div>

                <div style="position: relative; z-index: 1; padding-top: 1.5rem; border-top: 1px solid var(--card-border); margin-top: 2rem; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em;">Last Audit: <?php echo date('H:i:s'); ?></span>
                </div>
            </div>

            <div style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem;">
                <h4 style="font-size: 0.65rem; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1.25rem; font-weight: 800;">Node Configuration</h4>
                <form id="dashboard-config-form">
                    <div class="form-group" style="margin-bottom: 1.25rem;">
                        <label class="form-label" style="font-size: 0.55rem;">Max Capacity Limit</label>
                        <input type="number" name="max_node_capacity" class="form-input" value="<?php echo htmlspecialchars($max_capacity); ?>" style="padding: 0.65rem 0.85rem; font-size: 0.75rem;" required>
                    </div>
                    <button type="submit" class="btn-submit" style="background: var(--accent-green); padding: 0.7rem 1rem; width: 100%; font-size: 0.7rem; box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);">Update Thresholds</button>
                </form>
            </div>
        </div>
    </section>
    </main>

    <script>
        // Password Toggles
        function setupToggle(toggleId, inputId) {
            document.getElementById(toggleId).addEventListener('click', function() {
                const input = document.getElementById(inputId);
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                this.classList.toggle('fa-eye-slash');
            });
        }
        setupToggle('toggleNew', 'new_password');
        setupToggle('toggleConfirm', 'confirm_password');

        // Dashboard Config Submission
        document.getElementById('dashboard-config-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('update_capacity', '1');
            
            try {
                const response = await fetch('update_dashboard_settings.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Settings Updated',
                        text: 'System capacity has been successfully recalibrated.',
                        background: '#1e293b',
                        color: '#f8fafc',
                        confirmButtonColor: '#10b981'
                    }).then(() => {
                        pollTelemetry(); // Immediate sync without reload
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        text: result.message || 'Error communicating with command protocol.',
                        background: '#1e293b',
                        color: '#f8fafc'
                    });
                }
            } catch (error) {
                console.error('Telemetery update error:', error);
            }
        });

        // Form Submission
        document.getElementById('profile-sync-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            try {
                const response = await fetch('update_profile_process.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Synchronized',
                        text: result.message,
                        background: '#1e293b',
                        color: '#f8fafc',
                        confirmButtonColor: '#6366f1'
                    }).then(() => window.location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Sync Failure',
                        text: result.message,
                        background: '#1e293b',
                        color: '#f8fafc'
                    });
                }
            } catch (error) {
                console.error('Core sync error:', error);
            }
        });

        // Real-time Telemetry Polling
        async function pollTelemetry() {
            try {
                const response = await fetch('fetch_admin_updates.php');
                const data = await response.json();
                if (data.error) return;

                if (document.getElementById('capacity-counter')) {
                    document.getElementById('capacity-counter').innerText = `${data.total_users} / ${data.max_capacity}`;
                }
                if (document.getElementById('capacity-bar')) {
                    document.getElementById('capacity-bar').style.width = `${data.capacity_percentage}%`;
                }
            } catch (e) {
                console.error('Telemetry Sync Failure:', e);
            }
        }

        setInterval(pollTelemetry, 5000);
        pollTelemetry();
    </script>
</body>
</html>
