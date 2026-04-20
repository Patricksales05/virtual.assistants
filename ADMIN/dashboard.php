<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    if ($current_role !== '') {
        if ($current_role === 'operations manager') {
            header("Location: ../OM/dashboard.php");
            exit();
        } elseif ($current_role === 'team lead' || $current_role === 'team-lead') {
            header("Location: ../TL/dashboard.php");
            exit();
        } elseif ($current_role === 'staff' || $current_role === 'staff member') {
            header("Location: ../STAFF/dashboard.php");
            exit();
        } else {
            header("Location: index.php");
            exit();
        }
    } else {
        header("Location: index.php");
        exit();
    }
}

$user_name = $_SESSION['username'];
$role = $_SESSION['role'];
date_default_timezone_set('Asia/Manila');

// Fetch stats
try {
    $total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0;
    $pending_approvals = $pdo->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetchColumn() ?: 0;
    $staff_count = $pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(role) IN ('staff', 'staff member')")->fetchColumn() ?: 0;
    $recent_users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll() ?: [];
} catch (PDOException $e) {
    $total_users = 0; $pending_approvals = 0; $staff_count = 0; $recent_users = [];
}

// Fetch Capacity Settings (Independent Block)
try {
    $capacity_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_node_capacity'");
    $db_capacity = $capacity_stmt->fetchColumn();
    $max_capacity = $db_capacity ? (int)$db_capacity : 200;
} catch (PDOException $e) {
    $max_capacity = 200;
}
$capacity_percentage = $max_capacity > 0 ? min(100, round(($total_users / $max_capacity) * 100)) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Seamless Assist</title>
    <!-- Modern Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
            --bg-dark: #0f172a;
            --card-glass: rgba(15, 23, 42, 0.7);
            --header-glass: rgba(15, 23, 42, 0.9);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --card-border: rgba(255, 255, 255, 0.1);
            --accent-green: #10b981;
            --accent-red: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body { 
            background: #020617;
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
        }



        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Sidebar Styling */
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

        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
            animation: fadeInUp 0.6s ease 0.1s both;
        }

        .welcome-msg h2 {
            font-size: 1.85rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .welcome-msg p {
            color: var(--text-muted);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 1rem;
            background: var(--card-glass);
            padding: 0.5rem 1.25rem;
            border-radius: 100px;
            border: 1px solid var(--card-border);
        }

        .avatar {
            width: 32px;
            height: 32px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stats-grid > div {
            animation: fadeInUp 0.6s ease 0.2s both;
        }

        .stat-card {
            background: var(--card-glass);
            backdrop-filter: blur(10px);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.5rem;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary-color);
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .icon-blue { background: rgba(99, 102, 241, 0.15); color: #6366f1; }
        .icon-green { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .icon-gold { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .icon-red { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 800;
        }

        /* Activity Table */
        .content-card {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            padding: 1rem;
            border-bottom: 1px solid var(--card-border);
        }

        td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid var(--card-border);
            font-size: 0.95rem;
        }

        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-success { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .badge-warning { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }

        @media (max-width: 1024px) {
            .sidebar { width: 80px; padding: 2rem 1rem; }
            .logo-text, .nav-text, .logout-text, .user-details { display: none; }
            .nav-link { justify-content: center; padding: 1rem; }
        }
    </style>
</head>
<body style="background-color: #020617; color: white;">

    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Logo" class="logo-img">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link active"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a href="users.php" class="nav-link"><i class="fas fa-users"></i><span>Users</span></a></li>
            <li class="nav-item"><a href="reports.php" class="nav-link"><i class="fas fa-chart-line"></i><span>Reports</span></a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i><span>Settings</span></a></li>
        </ul>
        <div class="sidebar-footer">
            <a href="logout.php" class="nav-link logout-link"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header" style="margin-bottom: 1.5rem;">
            <div class="welcome-msg">
                <h2 style="font-size: 1.2rem; font-weight: 800; letter-spacing: -0.02em;">Welcome, <?php echo htmlspecialchars($user_name); ?>!</h2>
                <p style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;">Role: <span style="color: var(--primary-color); text-transform: uppercase;">ADMIN</span> | Node: <span style="color: white;"><?php echo php_uname('n'); ?></span></p>
            </div>
            
            <div style="display: flex; align-items: center; gap: 0.75rem;">

                <div id="node-sync-pulse" style="display: flex; align-items: center; gap: 0.5rem; background: rgba(16, 185, 129, 0.05); padding: 0.35rem 0.75rem; border-radius: 100px; border: 1px solid rgba(16, 185, 129, 0.1);">
                    <div style="width: 5px; height: 5px; background: #10b981; border-radius: 50%; box-shadow: 0 0 8px #10b981;"></div>
                    <span id="node-system-status-indicator" style="font-size: 0.55rem; font-weight: 800; color: #10b981; text-transform: uppercase; letter-spacing: 0.05em;">Node Connected</span>
                </div>
                
                <div style="background: rgba(255,255,255,0.03); padding: 0.35rem 0.75rem; border-radius: 100px; border: 1px solid var(--card-border); text-align: center; min-width: 200px;">
                    <div id="digital-clock" style="font-size: 0.75rem; font-weight: 800; color: white; letter-spacing: 0.05em;">00:00:00 AM</div>
                    <div id="current-date" style="font-size: 0.45rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 1px;">-</div>
                </div>

                <div class="user-profile" style="background: rgba(30, 41, 59, 0.5); padding: 0.25rem 0.35rem 0.25rem 0.85rem; border-radius: 100px; border: 1px solid var(--card-border); gap: 0.5rem; display: flex; align-items: center;">
                    <span class="user-details" style="font-size: 0.65rem; font-weight: 700; color: white;"><?php echo htmlspecialchars($user_name); ?></span>
                    <div class="avatar" style="width: 24px; height: 24px; font-size: 0.65rem;"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
                </div>
            </div>
        </header>

        <section class="stats-grid" style="grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.25rem;">
            <div class="stat-card" style="padding: 1rem 1.25rem; border-radius: 16px;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div class="stat-icon icon-blue" style="width: 36px; height: 36px; font-size: 0.95rem; border-radius: 10px;"><i class="fas fa-users"></i></div>
                    <div>
                        <span class="stat-label" style="font-size: 0.6rem; display: block; margin-bottom: 2px;">Total Staff</span>
                        <div class="stat-value" id="stat-total-staff" style="font-size: 1.25rem; line-height: 1.1;"><?php echo number_format($staff_count); ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem; border-radius: 16px;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div class="stat-icon icon-gold" style="width: 36px; height: 36px; font-size: 0.95rem; border-radius: 10px;"><i class="fas fa-user-clock"></i></div>
                    <div>
                        <span class="stat-label" style="font-size: 0.6rem; display: block; margin-bottom: 2px;">Pending Approval</span>
                        <div class="stat-value" id="stat-pending-approvals" style="font-size: 1.25rem; line-height: 1.1; color: #f59e0b;"><?php echo number_format($pending_approvals); ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem; border-radius: 16px;">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div class="stat-icon icon-blue" style="width: 36px; height: 36px; font-size: 0.95rem; border-radius: 10px;"><i class="fas fa-layer-group"></i></div>
                    <div>
                        <span class="stat-label" style="font-size: 0.6rem; display: block; margin-bottom: 2px;">Staff Directory</span>
                        <div class="stat-value" id="stat-total-accounts" style="font-size: 1.25rem; line-height: 1.1;"><?php echo number_format($total_users); ?></div>
                    </div>
                </div>
            </div>
            <div class="stat-card" style="padding: 1rem 1.25rem; border-radius: 16px;">
                <div style="display: flex; flex-direction: column; gap: 0.5rem; justify-content: center; height: 100%;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span class="stat-label" style="font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 800; color: var(--primary-color);">Resource Capacity</span>
                        <span id="capacity-counter" style="font-size: 0.75rem; font-weight: 800; color: white;"><?php echo $total_users; ?> / <?php echo $max_capacity; ?></span>
                    </div>
                    <div style="height: 6px; background: rgba(255,255,255,0.05); border-radius: 10px; overflow: hidden;">
                        <div id="capacity-bar" style="width: <?php echo $capacity_percentage; ?>%; height: 100%; background: linear-gradient(90deg, #6366f1, #10b981); box-shadow: 0 0 10px rgba(99, 102, 241, 0.3); transition: width 0.5s ease;"></div>
                    </div>
                </div>
            </div>
        </section>

        <div style="display: grid; grid-template-columns: 1fr; gap: 1rem; margin-bottom: 1rem;">
            <!-- Register Audit Node (Full Width Panel) -->
            <section class="content-card" style="padding: 1.25rem; border-radius: 20px; display: flex; flex-direction: column;">
                <div class="card-header" style="margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="font-size: 0.95rem; font-weight: 800; letter-spacing: -0.01em;">Latest Registrations</h3>
                    <a href="users.php" class="nav-link" style="padding: 0.4rem 0.85rem; font-size: 0.7rem; border: 1px solid var(--card-border); background: rgba(255,255,255,0.02); border-radius: 8px;">View All</a>
                </div>
                <div class="table-container" style="flex: 1;">
                    <table style="font-size: 0.65rem;">
                        <thead>
                            <tr style="background: rgba(255,255,255,0.02);">
                                <th style="padding: 0.4rem 0.6rem; border-radius: 6px 0 0 6px;">User Identifier</th>
                                <th style="padding: 0.4rem 0.6rem;">Role</th>
                                <th style="padding: 0.4rem 0.6rem;">Reg. Date</th>
                                <th style="padding: 0.4rem 0.6rem; border-radius: 0 6px 6px 0;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="registrations-relay">
                            <?php if (empty($recent_users)): ?>
                                <tr><td colspan="3" style="text-align: center; padding: 1rem;">No registered nodes.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_users as $row): ?>
                                    <tr>
                                        <td style="padding: 0.5rem 0.6rem;">
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div class="avatar" style="width: 20px; height: 20px; font-size: 0.55rem; background: var(--primary-color); border: none; shadow: 0 0 10px rgba(99, 102, 241, 0.3);"><?php echo strtoupper(substr($row['username'], 0, 1)); ?></div>
                                                <div>
                                                    <div style="font-weight:700; color: white; line-height: 1;"><?php echo htmlspecialchars($row['username']); ?></div>
                                                    <div style="font-size: 0.55rem; color: var(--text-muted); margin-top: 2px;"><?php echo htmlspecialchars($row['email']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding: 0.5rem 0.6rem; font-weight: 600; color: var(--text-muted);"><?php echo htmlspecialchars($row['role']); ?></td>
                                        <td style="padding: 0.5rem 0.6rem; font-weight: 600; color: var(--text-muted);"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                        <td style="padding: 0.5rem 0.6rem;">
                                            <?php if ($row['is_approved']): ?>
                                                <span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #10b981; font-size: 0.45rem; padding: 0.1rem 0.4rem; border: 1px solid rgba(16, 185, 129, 0.15);">APPROVED</span>
                                            <?php else: ?>
                                                <span class="badge" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; font-size: 0.45rem; padding: 0.1rem 0.4rem; border: 1px solid rgba(245, 158, 11, 0.15);">PENDING</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>


    <script>
        function updateClock() {
            const clockEl = document.getElementById('digital-clock');
            const dateEl = document.getElementById('current-date');
            
            if (clockEl || dateEl) {
                const now = new Date();
                
                if (clockEl) {
                    clockEl.innerText = now.toLocaleTimeString('en-US', { hour12: true });
                }
                
                if (dateEl) {
                    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                    dateEl.innerText = `${days[now.getDay()]}, ${months[now.getMonth()]} ${String(now.getDate()).padStart(2, '0')}, ${now.getFullYear()}`;
                }
            }
        }

        async function pollAdminUpdates() {
            try {
                const response = await fetch('fetch_admin_updates.php');
                const data = await response.json();
                if (data.error) return;

                if (document.getElementById('stat-total-staff')) document.getElementById('stat-total-staff').innerText = (data.total_staff || 0).toLocaleString();
                if (document.getElementById('stat-total-accounts')) document.getElementById('stat-total-accounts').innerText = (data.total_users || 0).toLocaleString();
                if (document.getElementById('stat-pending-approvals')) {
                     const pending = data.total_users - (data.total_staff || 0) - (data.total_om || 0) - (data.total_tl || 0);
                     document.getElementById('stat-pending-approvals').innerText = Math.max(0, pending).toLocaleString();
                }
                
                if (document.getElementById('registrations-relay')) document.getElementById('registrations-relay').innerHTML = data.recent_users_html;

                if (document.getElementById('capacity-counter')) document.getElementById('capacity-counter').innerText = `${data.total_users} / ${data.max_capacity}`;
                if (document.getElementById('capacity-bar')) document.getElementById('capacity-bar').style.width = `${data.capacity_percentage}%`;

                if (document.getElementById('current-server-time')) document.getElementById('current-server-time').innerText = data.server_time;

                const indicator = document.getElementById('node-system-status-indicator');
                if (indicator) {
                    indicator.innerText = 'ONLINE';
                    indicator.style.color = '#10b981';
                }
                
                // Active Pulse feedback
                const pulse = document.getElementById('node-sync-pulse');
                if (pulse) {
                    pulse.style.opacity = '1';
                    setTimeout(() => pulse.style.opacity = '0.8', 500);
                }
            } catch (e) {
                console.error('Core Sync Failure:', e);
                const indicator = document.getElementById('node-system-status-indicator');
                if (indicator) {
                    indicator.innerText = 'OFFLINE';
                    indicator.style.color = '#ef4444';
                }
            }
        }

        // Initialize Operational Engines
        setInterval(pollAdminUpdates, 5000);
        setInterval(updateClock, 1000);
        pollAdminUpdates();
        updateClock();
    </script>
</body>
</html>
