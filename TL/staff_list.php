<?php
require_once 'db_config.php';
require_once '../accrual_helper.php';
date_default_timezone_set('Asia/Manila');

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

// Fetch all staff users with their latest attendance for today
try {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT u.*, a.time_in, a.time_out 
        FROM users u 
        LEFT JOIN attendance a ON u.id = a.user_id AND a.attendance_date = ?
        WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER') 
        ORDER BY u.username ASC
    ");
    $stmt->execute([$today]);
    $staff_list = $stmt->fetchAll();
} catch (PDOException $e) {
    $staff_list = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff List - Seamless Assist</title>
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
            --main-bg: #111827;
            --card-glass: rgba(15, 23, 42, 0.4);
            --header-glass: rgba(15, 23, 42, 0.8);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --card-border: rgba(255, 255, 255, 0.05);
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --accent-gold: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { 
            background: #0f172a;
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

        .nav-item {
            margin-bottom: 0.5rem;
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
            font-weight: 500;
            font-size: 0.9rem;
        }

        .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.03);
        }

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
            align-items: center;
            gap: 1rem;
            transition: 0.3s;
            margin-top: auto;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 260px;
            padding: 2.5rem;
            min-height: 100vh;
            background: #111827;
        }

        /* Top Header */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3rem;
        }

        .back-btn-container {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            color: white;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: 0.3s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(-3px);
        }

        .page-title h2 {
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: white;
        }

        .user-pill {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 100px;
            padding: 0.4rem 0.6rem 0.4rem 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            backdrop-filter: blur(10px);
        }

        .user-name {
            font-weight: 700;
            font-size: 0.85rem;
            color: white;
        }

        .avatar-small {
            width: 32px;
            height: 32px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.8rem;
            color: white;
        }

        /* Content Card */
        .content-card {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            backdrop-filter: blur(12px);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: white;
        }

        .section-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 1.25rem 1rem;
            font-size: 0.65rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border-bottom: 1px solid var(--card-border);
        }

        td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.02);
            font-size: 0.9rem;
            vertical-align: middle;
        }

        .member-cell {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .avatar-box {
            width: 42px;
            height: 42px;
            background: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1rem;
            color: white;
        }

        .member-info {
            display: flex;
            flex-direction: column;
            gap: 0.15rem;
        }

        .member-name {
            font-weight: 700;
            color: white;
            font-size: 0.95rem;
        }

        .member-user {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .status-active { background: rgba(245, 158, 11, 0.1); color: var(--accent-gold); border: 1px solid rgba(245, 158, 11, 0.2); }
        .status-completed { background: rgba(16, 185, 129, 0.1); color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.2); }
        .status-offline { background: rgba(148, 163, 184, 0.05); color: var(--text-muted); border: 1px solid rgba(148, 163, 184, 0.1); }

        .time-log {
            font-weight: 700;
            color: white;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Seamless Assist" style="width: 100%; max-width: 190px; filter: brightness(0) invert(1);">
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="staff_list.php" class="nav-link active">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">Staff List</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="operations.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    <span class="nav-text">Operations</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="settings.php" class="nav-link">
                    <i class="fas fa-cog"></i>
                    <span class="nav-text">Settings</span>
                </a>
            </li>
        </ul>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div class="back-btn-container">
                <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
                <div class="page-title">
                    <h2>Staff List</h2>
                </div>
            </div>
            <div class="user-pill">
                <div style="text-align: right; margin-right: 0.5rem;">
                    <div class="user-name" style="line-height: 1.2; font-weight: 700; color: white; font-size: 0.85rem;"><?php echo htmlspecialchars($user_name); ?></div>
                    <div style="font-size: 0.6rem; color: #6366f1; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">Team Leader</div>
                </div>
                <div class="avatar-small" style="box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);"><?php echo strtoupper(substr($user_name, 0, 1)); ?></div>
            </div>
        </header>

        <section class="content-card">
            <div class="section-header">
                <div>
                    <h3 class="section-title">Personnel Overview</h3>
                    <span class="section-subtitle" id="total-members"><?php echo count($staff_list); ?> Total Members</span>
                </div>
                <div style="text-align: right; background: rgba(99, 102, 241, 0.05); padding: 0.75rem 1.5rem; border-radius: 16px; border: 1px solid rgba(99, 102, 241, 0.1);">
                    <span style="font-size: 0.65rem; color: var(--primary-color); text-transform: uppercase; font-weight: 800; letter-spacing: 0.1em; display: block;">Global PTO Credits</span>
                    <?php 
                        $total_accrued = 0;
                        foreach($staff_list as $s) { $total_accrued += calculate_realtime_pto($s['id'], $pdo); }
                    ?>
                    <h2 id="total-pto-credits" style="font-size: 1.4rem; font-weight: 800; color: white;"><?php echo number_format($total_accrued, 4); ?> <small style="font-size: 0.7rem; opacity: 0.6;">HRS</small></h2>
                </div>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Username</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>PTO Credits</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="staff-table-body">
                        <?php 
                        foreach($staff_list as $staff): 
                            $pto = calculate_realtime_pto($staff['id'], $pdo);
                        ?>
                        <tr>
                            <td>
                                <div class="member-cell">
                                    <div class="avatar-box"><?php echo strtoupper(substr($staff['username'], 0, 1)); ?></div>
                                    <div class="member-info">
                                        <span class="member-name"><?php echo htmlspecialchars($staff['username']); ?></span>
                                        <span class="member-user">ID: #<?php echo str_pad($staff['id'], 3, '0', STR_PAD_LEFT); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td style="color: var(--text-muted); font-weight: 600;">@<?php echo strtolower($staff['username']); ?></td>
                            <td class="time-log">
                                <?php echo $staff['time_in'] ? date('h:i A', strtotime($staff['time_in'])) : '--:-- --'; ?>
                            </td>
                            <td class="time-log">
                                <?php echo $staff['time_out'] ? date('h:i A', strtotime($staff['time_out'])) : '--:-- --'; ?>
                            </td>
                            <td style="font-weight: 800; color: var(--primary-color);">
                                <?php echo number_format($pto, 4); ?> <small style="font-size: 0.6rem; opacity: 0.6;">HRS</small>
                            </td>
                            <td>
                                <?php if (!$staff['time_in']): ?>
                                    <span class="status-badge status-offline">OFFLINE</span>
                                <?php elseif ($staff['time_in'] && !$staff['time_out']): ?>
                                    <span class="status-badge status-active">ACTIVE</span>
                                <?php else: ?>
                                    <span class="status-badge status-completed">COMPLETED</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($staff_list)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 4rem;">No registered members detected.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        function updateStaffList() {
            fetch('fetch_staff_updates.php')
                .then(response => response.json())
                .then(data => {
                    if (data.error) return;
                    if (document.getElementById('staff-table-body')) document.getElementById('staff-table-body').innerHTML = data.staff_html;
                    if (document.getElementById('total-members')) document.getElementById('total-members').innerText = `${data.total_staff} Total Members`;
                    if (document.getElementById('total-pto-credits')) document.getElementById('total-pto-credits').innerHTML = `${data.total_pto_credits} <small style="font-size: 0.7rem; opacity: 0.6;">HRS</small>`;
                })
                .catch(err => console.error('Relay error:', err));
        }

        setInterval(updateStaffList, 5000);
    </script>
</body>
</html>
