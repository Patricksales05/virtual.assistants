<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || ($current_role !== 'team lead' && $current_role !== 'team-lead')) {
    if ($current_role !== '') {
        if ($current_role === 'admin') header("Location: ../ADMIN/dashboard.php");
        elseif ($current_role === 'operations manager') header("Location: ../OM/dashboard.php");
        elseif ($current_role === 'staff' || $current_role === 'staff member') header("Location: ../STAFF/dashboard.php");
        else header("Location: index.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];
$role = $_SESSION['role'];
date_default_timezone_set('Asia/Manila');

// Fetch Operational Telemetry
try {
    $today = date('Y-m-d');
    
    // Status Metrics
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('Staff', 'Staff Member', 'STAFF MEMBER')");
    $total_staff = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = ? AND time_out IS NULL");
    $stmt->execute([$today]);
    $active_now = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = ? AND time_out IS NOT NULL");
    $stmt->execute([$today]);
    $completed_shifts = $stmt->fetchColumn();

    // Fetch Recent Deployments
    $stmt = $pdo->prepare("
        SELECT a.*, u.username 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE (u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER')) AND a.attendance_date = ? 
        ORDER BY a.id DESC LIMIT 8
    ");
    $stmt->execute([$today]);
    $deployments = $stmt->fetchAll();

} catch (PDOException $e) {
    $total_staff = 0; $active_now = 0; $completed_shifts = 0; $deployments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operational intelligence - Seamless Assist</title>
    <!-- Modern Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #6366f1;
            --primary-hover: #4f46e5;
            --bg-dark: #0f172a;
            --sidebar-bg: #0f172a;
            --main-bg: #080d1a;
            --card-glass: rgba(15, 23, 42, 0.6);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --card-border: rgba(255, 255, 255, 0.08);
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-gold: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { 
            background: #080d1a;
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
        }

        /* Sidebar Styling (EXACTLY AS DASHBOARD) */
        .sidebar {
            width: 260px;
            background: #0f172a;
            border-right: 1px solid var(--card-border);
            padding: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 1000;
        }

        .logo-container {
            padding: 2.5rem 1.5rem;
            text-align: left;
        }

        .nav-menu {
            list-style: none;
            padding: 0 1rem;
            flex: 1;
        }

        .nav-item { margin-bottom: 0.5rem; }

        .nav-link {
            text-decoration: none;
            color: var(--text-muted);
            padding: 0.85rem 1.25rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .nav-link:hover { color: white; background: rgba(255, 255, 255, 0.03); }

        .nav-link.active {
            background: var(--primary-color);
            color: white;
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
        }

        .logout-btn {
            padding: 1.5rem;
            border-top: 1px solid var(--card-border);
            color: #ef4444;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: flex;
            align-items: center; gap: 1rem;
            transition: 0.3s;
            margin-top: auto;
        }

        /* Main Content Area */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 2.5rem;
            min-height: 100vh;
        }

        /* Header Style */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
        }

        .header-title h2 { font-size: 1.85rem; font-weight: 800; color: white; letter-spacing: -0.03em; }

        .user-pill {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 100px;
            padding: 0.4rem 1.4rem 0.4rem 1.4rem;
            display: flex; align-items: center; gap: 0.8rem;
            backdrop-filter: blur(10px);
        }

        .user-name { font-weight: 700; font-size: 0.85rem; color: white; text-align: right; }
        .role-title { font-size: 0.6rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase; }

        .avatar-small {
            width: 32px; height: 32px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 0.8rem; color: white;
        }

        /* Tactical Grid Design (New Design) */
        .tactical-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }

        .tactical-hub { display: flex; flex-direction: column; gap: 2rem; }

        /* Performance Module */
        .module-card {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 32px;
            padding: 2.5rem;
            backdrop-filter: blur(20px);
            position: relative;
            overflow: hidden;
        }

        .module-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }

        .module-title { font-size: 0.75rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.2em; }

        .efficiency-gauge {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 0;
        }

        .gauge-value {
            font-size: 6rem;
            font-weight: 800;
            color: white;
            line-height: 1;
            letter-spacing: -0.05em;
            text-shadow: 0 0 40px rgba(99, 102, 241, 0.4);
        }

        .gauge-label { color: var(--accent-green); font-weight: 800; font-size: 0.8rem; text-transform: uppercase; margin-top: 1rem; display: flex; align-items: center; gap: 0.5rem; }

        /* Grid of Metrics */
        .stats-tiles {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        .stat-tile {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 1.5rem;
            transition: transform 0.3s ease;
        }

        .stat-tile:hover { transform: translateY(-5px); background: rgba(99, 102, 241, 0.05); }

        .tile-icon { font-size: 1.25rem; color: var(--primary-color); margin-bottom: 1rem; }
        .tile-label { font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; display: block; }
        .tile-value { font-size: 1.5rem; font-weight: 800; color: white; }

        /* Sidebar Activity Design */
        .activity-sidebar {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 32px;
            padding: 2rem;
            height: calc(100vh - 180px);
            display: flex;
            flex-direction: column;
        }

        .sidebar-title { font-size: 0.85rem; font-weight: 800; color: white; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .pulse-icon { width: 8px; height: 8px; background: var(--accent-green); border-radius: 50%; box-shadow: 0 0 10px var(--accent-green); animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; } }

        .activity-list { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 1.25rem; padding-right: 0.5rem; }
        .activity-item { display: flex; align-items: center; gap: 1rem; padding-bottom: 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.02); }
        .activity-avatar { width: 36px; height: 36px; background: var(--primary-color); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; }
        .activity-info { flex: 1; }
        .activity-user { font-weight: 700; font-size: 0.85rem; display: block; }
        .activity-time { font-size: 0.7rem; color: var(--text-muted); font-weight: 500; }
        .activity-status { width: 8px; height: 8px; border-radius: 50%; }

        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Seamless Assist" style="width: 100%; max-width: 190px; filter: brightness(0) invert(1);">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> <span class="nav-text">Dashboard</span></a></li>
            <li class="nav-item"><a href="staff_list.php" class="nav-link"><i class="fas fa-users"></i> <span class="nav-text">Staff List</span></a></li>
            <li class="nav-item"><a href="operations.php" class="nav-link active"><i class="fas fa-chart-line"></i> <span class="nav-text">Operations</span></a></li>
            <li class="nav-item"><a href="settings.php" class="nav-link"><i class="fas fa-cog"></i> <span class="nav-text">Settings</span></a></li>
        </ul>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div class="header-title"><h2>Operational Intelligence</h2></div>
            <div class="user-pill">
                <div style="text-align: right; margin-right: 0.5rem;">
                    <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="role-title">Team Leader</div>
                </div>
                <div class="avatar-small"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
            </div>
        </header>

        <div class="tactical-layout">
            <div class="tactical-hub">
                <section class="module-card">
                    <div class="module-header"><span class="module-title">Command Efficiency Index</span></div>
                    <div class="efficiency-gauge">
                        <div class="gauge-value"><?php 
                            $eff = ($active_now + $completed_shifts) > 0 ? round(($completed_shifts/($active_now + $completed_shifts))*100) : 100;
                            echo $eff . "%";
                        ?></div>
                        <div class="gauge-label"><i class="fas fa-shield-halved"></i> Global Status Optimal</div>
                    </div>
                </section>

                <section class="stats-tiles">
                    <div class="stat-tile">
                        <i class="fas fa-users tile-icon"></i>
                        <span class="tile-label">Force Strength</span>
                        <div class="tile-value"><?php echo $total_staff; ?></div>
                    </div>
                    <div class="stat-tile">
                        <i class="fas fa-bolt tile-icon" style="color:var(--accent-green);"></i>
                        <span class="tile-label">Deployment</span>
                        <div class="tile-value"><?php echo $active_now; ?></div>
                    </div>
                    <div class="stat-tile">
                        <i class="fas fa-circle-check tile-icon" style="color:var(--accent-gold);"></i>
                        <span class="tile-label">Missions</span>
                        <div class="tile-value"><?php echo $completed_shifts; ?></div>
                    </div>
                </section>
                
                <!-- Bottom Analytics Row -->
                <section class="module-card" style="flex:1;">
                    <div class="module-header"><span class="module-title">Fleet Connectivity</span></div>
                    <div style="display: flex; gap: 4rem; justify-content: center; margin-top: 1rem;">
                        <div style="text-align: center;">
                            <span style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800;">DATA RELAY</span>
                            <div style="font-size: 1.1rem; font-weight: 700; color: white;"><?php echo date('h:i:s A'); ?></div>
                        </div>
                        <div style="text-align: center;">
                            <span style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800;">HEALTH STATUS</span>
                            <div style="font-size: 1.1rem; font-weight: 700; color: var(--accent-green);">SECURE</div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Activity Sidebar in the Middle -->
            <section class="activity-sidebar">
                <div class="sidebar-title"><div class="pulse-icon"></div> LIVE ACTIVITY STREAM</div>
                <div class="activity-list" id="activity-stream">
                    <?php if (empty($deployments)): ?>
                        <div style="text-align: center; color: var(--text-muted); font-size: 0.8rem; margin-top: 4rem;">No deployments active.</div>
                    <?php else: ?>
                        <?php foreach ($deployments as $d): ?>
                            <div class="activity-item">
                                <div class="activity-avatar"><?php echo strtoupper(substr($d['username'], 0, 1)); ?></div>
                                <div class="activity-info">
                                    <span class="activity-user"><?php echo htmlspecialchars($d['username']); ?></span>
                                    <span class="activity-time"><?php echo date('h:i A', strtotime($d['time_in'])); ?> - Deployment</span>
                                </div>
                                <div class="activity-status" style="background: <?php echo $d['time_out'] ? 'var(--accent-red)' : 'var(--accent-green)'; ?>"></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>

    <script>
        function updateOperations() {
            fetch('fetch_staff_updates.php')
                .then(response => response.json())
                .then(data => {
                    if (data.error) return;
                    if (document.getElementById('total-staff-ops')) document.getElementById('total-staff-ops').innerText = data.total_staff;
                    if (document.getElementById('active-now-ops')) document.getElementById('active-now-ops').innerText = data.active_now;
                    if (document.getElementById('completed-ops')) document.getElementById('completed-ops').innerText = data.completed_shifts;
                    if (document.getElementById('efficiency-val')) document.getElementById('efficiency-val').innerText = data.efficiency + '%';
                    if (document.getElementById('data-relay-ts')) document.getElementById('data-relay-ts').innerText = data.last_relay;
                    if (document.getElementById('activity-stream')) document.getElementById('activity-stream').innerHTML = data.ops_html;
                })
                .catch(err => console.error('Operations relay error:', err));
        }

        setInterval(updateOperations, 5000);
    </script>
</body>
</html>
