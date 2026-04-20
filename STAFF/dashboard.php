<?php
date_default_timezone_set('Asia/Manila');
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['user_id']) || ($current_role !== 'staff' && $current_role !== 'staff member')) {
    if ($current_role !== '') {
        if ($current_role === 'admin') {
            header("Location: ../ADMIN/dashboard.php");
            exit();
        } elseif ($current_role === 'operations manager') {
            header("Location: ../OM/dashboard.php");
            exit();
        } elseif ($current_role === 'team lead' || $current_role === 'team-lead') {
            header("Location: ../TL/dashboard.php");
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

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['username'];
$role = $_SESSION['role'];
$attendance = null;
$shift_status = 'OFF-SHIFT';
$total_today = "00:00:00";
$shift_status = 'OFF-SHIFT';
$total_today = "00:00:00";

// Fetch operational stats for the team view
try {
    $today = date('Y-m-d');
    
    // Latest Attendance Feed for the bottom table (Personal only)
    // 1. Current User's Latest Activity (Relaxed filter for better UX)
    $stmt = $pdo->prepare("
        SELECT a.*, u.username, u.full_name 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.user_id = ? 
        ORDER BY a.id DESC LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $attendance_feed = $stmt->fetchAll();

    // Current user's attendance (Prioritize active sessions)
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $attendance = $stmt->fetch();
    
    if (!$attendance) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id, $today]);
        $attendance = $stmt->fetch();
    }
    
    if ($attendance) {
        $shift_status = $attendance['time_out'] ? 'COMPLETED' : 'ACTIVE';
        $start = new DateTime($attendance['time_in']);
        if ($attendance['time_out']) {
            $end = new DateTime($attendance['time_out']);
            $interval = $start->diff($end);
            $total_today = $interval->format('%H:%I:%S');
        } else {
            $now = new DateTime();
            $interval = $start->diff($now);
            $total_today = $interval->format('%H:%I:%S');
        }
    }
    // Fetch PTO Credits (Now Cutoff-Specific with Reset Logic)
    require_once '../accrual_helper.php';
    $pto_credits = calculate_realtime_pto($user_id, $pdo);
    $total_hours_worked = get_total_cumulative_hours($user_id, $pdo);

    // Fetch user's PTO requests for the history ledger (Optimized for performance)
    $user_pto_stmt = $pdo->prepare("
        SELECT p.*, u.full_name as approver_name 
        FROM pto_requests p 
        LEFT JOIN users u ON p.approved_by = u.id 
        WHERE p.user_id = ? 
        ORDER BY p.created_at DESC
    ");
    $user_pto_stmt->execute([$user_id]);
    $user_pto_requests = $user_pto_stmt->fetchAll();
    
    // 1. View Toggling
    $view = $_GET['view'] ?? 'home';

    // 2. Fetch specific view data
    if ($view === 'attendance') {
        $filter_date = $_GET['filter_date'] ?? date('Y-m-d');
        $query = "SELECT * FROM attendance WHERE user_id = ?";
        $params = [$user_id];
        
        if (isset($_GET['filter_date']) && !empty($_GET['filter_date'])) {
            $query .= " AND attendance_date = ?";
            $params[] = $_GET['filter_date'];
        }
        
        $query .= " ORDER BY id DESC LIMIT 15";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $attendance_history = $stmt->fetchAll();
    } elseif ($view === 'history') {
        $date_start = $_GET['start'] ?? '';
        $date_end = $_GET['end'] ?? '';
        
        $sql = "SELECT * FROM attendance WHERE user_id = :user_id";
        $params = ['user_id' => $user_id];
        
        if (!empty($date_start) && !empty($date_end)) {
            $sql .= " AND attendance_date BETWEEN :start AND :end";
            $params['start'] = $date_start;
            $params['end'] = $date_end;
        }
        
        $sql .= " ORDER BY attendance_date DESC, time_in DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $history_logs = $stmt->fetchAll();
    } elseif ($view === 'profile') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_info = $stmt->fetch();
    }

    $stale = get_stale_active_session($user_id, $pdo);

    $latest_att = $pdo->query("SELECT MAX(updated_at) FROM attendance")->fetchColumn() ?: '';

} catch (PDOException $e) { }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Dashboard - Seamless Assist</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            --sidebar-width: 195px;
        }

        #quick-reply-message::-webkit-scrollbar { display: none; }
        #quick-reply-message { -ms-overflow-style: none; scrollbar-width: none; }
        /* Header & Core Layout */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { 
            background: rgba(99, 102, 241, 0.2); 
            border-radius: 10px; 
            transition: 0.3s;
        }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary-color); }

        .email-stream-item {
            border-left: 3px solid transparent;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .email-stream-item:hover {
            background: rgba(255, 255, 255, 0.02);
        }
        
        .email-stream-item.selected {
            background: rgba(99, 102, 241, 0.08) !important;
            border-left-color: var(--primary-color) !important;
            box-shadow: inset 4px 0 15px rgba(99, 102, 241, 0.1);
        }

        body { 
            background: #020617;
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
            overflow-x: hidden;
            font-size: 0.75rem;
        }

        .sidebar { 
            width: var(--sidebar-width); 
            background: var(--header-glass); 
            border-right: 1px solid var(--card-border); 
            padding: 1rem 0.75rem; 
            display: flex; 
            flex-direction: column; 
            position: fixed; 
            top: 0; 
            left: 0;
            height: 100vh; 
            z-index: 100; 
            overflow-y: auto;
        }

        .logo-container { margin-bottom: 1.5rem; text-align: center; }
        .logo-img { width: 140px; filter: brightness(0) invert(1); transition: all 0.5s ease; cursor: pointer; }
        
        .nav-menu { list-style: none; flex: 1; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { 
            text-decoration: none; 
            color: var(--text-muted); 
            padding: 0.55rem 0.85rem; 
            border-radius: 10px; 
            display: flex; 
            align-items: center; 
            gap: 0.6rem; 
            transition: all 0.3s; 
            font-weight: 600;
            font-size: 0.8rem;
        }
        .nav-link:hover { background: rgba(99, 102, 241, 0.05); color: var(--primary-color); }
        .nav-link.active { background: var(--primary-color); color: white; box-shadow: 0 10px 20px rgba(99, 102, 241, 0.15); }
        .nav-link i { font-size: 0.9rem; width: 20px; text-align: center; }

        .main-content { 
            flex: 1; 
            padding: 1.2rem 1.5rem; 
            margin-left: var(--sidebar-width);
            max-width: calc(100% - var(--sidebar-width));
        }

        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        
        .user-pill {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 100px;
            padding: 0.3rem 0.4rem 0.3rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            backdrop-filter: blur(10px);
        }

        .avatar-small {
            width: 28px;
            height: 28px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.7rem;
            color: white;
        }

        .attendance-card { 
            background: var(--card-glass); 
            backdrop-filter: blur(20px); 
            border-radius: 20px; 
            border: 1px solid var(--card-border); 
            text-align: center; 
            padding: 1.1rem; 
            position: relative; 
            margin-bottom: 1.25rem;
        }

        .digital-clock { font-size: 2rem; font-weight: 800; letter-spacing: -0.04em; color: white; line-height: 1; }
        .date-display { font-size: 0.6rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }

        .btn-attendance { 
            padding: 0.5rem 1.5rem; 
            border: none; 
            border-radius: 100px; 
            font-size: 0.75rem; 
            font-weight: 700; 
            cursor: pointer; 
            transition: 0.3s; 
            display: inline-flex; 
            align-items: center; 
            gap: 0.5rem; 
            text-transform: uppercase; 
        }
        .btn-time-in { background: var(--accent-green); color: white; }
        .btn-time-out { background: var(--accent-red); color: white; }
        .btn-attendance:hover { transform: translateY(-2px); }

        .metrics-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 0.5rem; 
            background: rgba(255,255,255,0.02); 
            padding: 0.85rem; 
            border-radius: 12px; 
            border: 1px solid rgba(255,255,255,0.05); 
        }
        .metric-item span { font-size: 0.55rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800; display: block; }
        .metric-item h3 { font-size: 0.85rem; color: white; margin-top: 0.1rem; }

        /* Settings & Form Utilities */
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
            width: 100%;
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 0.85rem;
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
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(99, 102, 241, 0.25);
        }
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes pulseRed {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); border-color: rgba(239, 68, 68, 0.6); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); border-color: rgba(239, 68, 68, 0.2); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); border-color: rgba(239, 68, 68, 0.6); }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" class="logo-img">
        </div>
        <ul class="nav-menu">
            <li class="nav-item"><a href="dashboard.php?view=home" class="nav-link <?php echo $view === 'home' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>
            <li class="nav-item"><a href="dashboard.php?view=attendance" class="nav-link <?php echo $view === 'attendance' ? 'active' : ''; ?>"><i class="fas fa-clock"></i> Attendance</a></li>
            <li class="nav-item"><a href="dashboard.php?view=pto" class="nav-link <?php echo $view === 'pto' ? 'active' : ''; ?>"><i class="fas fa-calendar-alt"></i> PTO Request</a></li>
            <li class="nav-item">
                <a href="dashboard.php?view=email" class="nav-link <?php echo $view === 'email' ? 'active' : ''; ?>" style="position: relative;">
                    <i class="fas fa-envelope"></i> Email
                    <span class="main-unread-count-badge" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); background: #ef4444; color: white; font-size: 0.6rem; padding: 0.1rem 0.45rem; border-radius: 100px; display: none; font-weight: 800;">0</span>
                </a>
            </li>
            <li class="nav-item"><a href="dashboard.php?view=history" class="nav-link <?php echo $view === 'history' ? 'active' : ''; ?>"><i class="fas fa-history"></i> History</a></li>

            <li class="nav-item"><a href="dashboard.php?view=profile" class="nav-link <?php echo $view === 'profile' ? 'active' : ''; ?>"><i class="fas fa-cog"></i> Settings</a></li>
        </ul>
        <div style="margin-top:auto; padding-top:2rem; border-top:1px solid var(--card-border);">
            <a href="logout.php" class="nav-link" style="color:var(--accent-red);"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div class="page-title">
                <h2 id="module-title-broadcast" style="font-size: 1.15rem;">
                    <?php 
                        if($view === 'profile') echo 'Account Settings';
                        elseif($view === 'attendance') echo 'Deployment History';
                        elseif($view === 'pto') echo 'Leave Management';
                        elseif($view === 'email') echo 'Communications Hub';
                        elseif($view === 'history') echo 'Audit Ledger';
                        else echo 'Operational Command';
                    ?>
                </h2>
                <p style="color: var(--text-muted); font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; margin-top: 0.1rem;">STAFF DASHBOARD</p>
            </div>
            <?php 
                $cutoff = get_current_cutoff_dates();
                $my_days_worked = get_days_worked_in_cutoff($user_id, $pdo, $cutoff['start'], $cutoff['end']);
            ?>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <!-- Total Hours Worked (Lifetime Cumulative) -->
                <div style="text-align: left; padding: 0.35rem 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 10px; border: 1px solid rgba(99, 102, 241, 0.2); backdrop-filter: blur(10px);">
                    <span style="font-size: 0.5rem; color: var(--primary-color); text-transform: uppercase; font-weight: 800; letter-spacing: 0.1em; display: block;">Total Worked Hours</span>
                    <span id="global-total-hours" style="font-size: 0.85rem; font-weight: 800; color: white;"><?php echo number_format($total_hours_worked, 2); ?> <small style="font-size: 0.6rem; opacity: 0.6;">HRS</small></span>
                </div>

                <!-- Earned PTO Credits Pill (Consistent Executive Standard) -->
                <div style="text-align: left; padding: 0.35rem 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 10px; border: 1px solid rgba(16, 185, 129, 0.2); backdrop-filter: blur(10px);">
                    <span style="font-size: 0.5rem; color: var(--accent-green); text-transform: uppercase; font-weight: 800; letter-spacing: 0.1em; display: block;">Earned PTO Credits</span>
                    <span id="global-pto-display" style="font-size: 0.85rem; font-weight: 800; color: white;"><?php echo number_format($pto_credits, 4); ?> <small style="font-size: 0.6rem; opacity: 0.6;">HRS</small></span>
                </div>

                <!-- Cutoff Attendance Pill -->
                <div style="text-align: left; padding: 0.35rem 0.9rem; background: rgba(30, 41, 59, 0.5); border-radius: 10px; border: 1px solid var(--card-border); backdrop-filter: blur(10px);">
                    <span id="cutoff-label-broadcast" style="font-size: 0.5rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800; letter-spacing: 0.1em; display: block;">Cutoff (<?php echo $cutoff['label']; ?>)</span>
                    <span id="cutoff-days-display" style="font-size: 0.85rem; font-weight: 800; color: white;"><?php echo $my_days_worked; ?> <small style="font-size: 0.6rem; opacity: 0.6;">DAYS</small></span>
                </div>

                <div class="user-pill">
                    <div style="text-align: right;">
                        <div style="font-weight: 800; font-size: 0.75rem; color: white; line-height: 1.2;"><?php echo htmlspecialchars($user_name); ?></div>
                        <div style="font-size: 0.55rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">STAFF MEMBER</div>
                    </div>
                    <div class="avatar-small">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                </div>
            </div>
        </header>



        <?php if ($view === 'home'): ?>
        <section class="attendance-card">
            <div class="digital-clock" id="digital-clock-standard">00:00:00</div>
            <div class="date-display" id="date"><?php echo date('l, M d, Y'); ?></div>

            <div style="margin: 1.25rem 0;">
                <div id="attendance-status-container">
                    <?php 
                    $stale_session = get_stale_active_session($user_id, $pdo);
                    if ($stale_session): ?>
                        <div style="background: rgba(239, 68, 68, 0.08); border: 1px dashed rgba(239, 68, 68, 0.3); border-radius: 16px; padding: 1.25rem; margin-bottom: 0.5rem; animation: pulseRed 2s infinite;">
                            <p style="font-size: 0.7rem; color: #ef4444; font-weight: 800; margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em;"><i class="fas fa-exclamation-triangle"></i> Action Required: Forgotten Time-Out (<?php echo date('M d', strtotime($stale_session['attendance_date'])); ?>)</p>
                            <button class="btn-attendance btn-time-out" style="width: 100%; justify-content: center;" onclick="processAttendance('time_out')"><i class="fas fa-history"></i> Resolve Deployment & Time Out</button>
                        </div>
                    <?php elseif (!$attendance): ?>
                        <button class="btn-attendance btn-time-in" onclick="processAttendance('time_in')"><i class="fas fa-sign-in-alt"></i> Time In</button>
                    <?php elseif (!$attendance['time_out']): ?>
                        <button class="btn-attendance btn-time-out" onclick="processAttendance('time_out')"><i class="fas fa-sign-out-alt"></i> Time Out</button>
                    <?php else: ?>
                        <div style="background: rgba(16, 185, 129, 0.05); padding: 0.5rem 1.5rem; border-radius: 100px; color: var(--accent-green); border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.8rem;">
                            <i class="fas fa-check-circle"></i> <span style="font-weight: 800;">SHIFT COMPLETED</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="metrics-grid">
                <div class="metric-item" style="border-right: 1px solid rgba(255,255,255,0.05);">
                    <span>Time In</span>
                    <h3 id="time-in-display"><?php echo $attendance ? date('h:i A', strtotime($attendance['time_in'])) : '--:--'; ?></h3>
                </div>
                <div class="metric-item" style="border-right: 1px solid rgba(255,255,255,0.05);">
                    <span>Time Out</span>
                    <h3 id="time-out-display"><?php echo ($attendance && $attendance['time_out']) ? date('h:i A', strtotime($attendance['time_out'])) : '--:--'; ?></h3>
                </div>
                <div class="metric-item">
                    <span>Duration</span>
                    <h3 id="shift-duration" style="color: var(--primary-color);"><?php echo $total_today; ?></h3>
                </div>
            </div>
        </section>

        <!-- Personal PTO Deployment Summary -->
        <section style="background: var(--card-glass); border-radius: 20px; padding: 1.1rem; border: 1px solid var(--card-border); margin-top: 1.25rem; animation: fadeInUp 0.5s ease both;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="font-size: 0.85rem; font-weight: 800; display: flex; align-items: center; gap: 0.6rem;">
                    <i class="fas fa-calendar-check" style="color: var(--primary-color);"></i> Active PTO Transmissions
                </h3>
                <a href="dashboard.php?view=pto" style="font-size: 0.6rem; color: var(--primary-color); text-decoration: none; font-weight: 700; text-transform: uppercase;">Manage All <i class="fas fa-arrow-right"></i></a>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                <?php 
                $pending_count = 0; $approved_count = 0;
                foreach($user_pto_requests as $pto) {
                    if($pto['status'] === 'Pending') $pending_count++;
                    elseif($pto['status'] === 'Approved') $approved_count++;
                }
                ?>
                <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.1); border-radius: 12px; padding: 0.75rem; display: flex; align-items: center; gap: 0.75rem;">
                    <div style="width: 28px; height: 28px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;"><i class="fas fa-clock-rotate-left"></i></div>
                    <div>
                        <span style="display: block; font-size: 0.5rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800;">Pending Review</span>
                        <span style="font-size: 0.9rem; font-weight: 800; color: white;"><?php echo $pending_count; ?> <small style="font-size: 0.55rem; opacity: 0.5;">Requests</small></span>
                    </div>
                </div>
                <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.1); border-radius: 12px; padding: 0.75rem; display: flex; align-items: center; gap: 0.75rem;">
                    <div style="width: 28px; height: 28px; background: rgba(16, 185, 129, 0.15); color: #10b981; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;"><i class="fas fa-circle-check"></i></div>
                    <div>
                        <span style="display: block; font-size: 0.5rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800;">Authorized Leave</span>
                        <span style="font-size: 0.9rem; font-weight: 800; color: white;"><?php echo $approved_count; ?> <small style="font-size: 0.55rem; opacity: 0.5;">Requests</small></span>
                    </div>
                </div>
            </div>
            
            <?php if(!empty($user_pto_requests)): 
                  $latest = $user_pto_requests[0];
                  $l_color = '#f59e0b';
                  if($latest['status'] === 'Approved') $l_color = '#10b981';
                  elseif($latest['status'] === 'Denied') $l_color = '#ef4444';
            ?>
            <div style="margin-top: 1rem; padding: 0.85rem; border: 1px dashed rgba(255,255,255,0.05); border-radius: 12px; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span style="font-size: 0.65rem; padding: 0.2rem 0.5rem; background: <?php echo $l_color; ?>15; color: <?php echo $l_color; ?>; border-radius: 4px; font-weight: 800; text-transform: uppercase;">Latest</span>
                    <span style="font-size: 0.75rem; color: var(--text-muted);"><strong style="color: white;"><?php echo htmlspecialchars($latest['leave_type']); ?></strong> (<?php echo date('M d', strtotime($latest['start_date'])); ?>)</span>
                </div>
                <span style="font-size: 0.65rem; color: <?php echo $l_color; ?>; font-weight: 800; text-transform: uppercase;"><?php echo $latest['status']; ?></span>
            </div>
            <?php endif; ?>
        </section>

        <section style="background: var(--card-glass); border-radius: 20px; padding: 1.1rem; border: 1px solid var(--card-border); margin-top: 1.25rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <div>
                    <h3 style="font-size: 0.95rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem;"><i class="fas fa-list-ul" style="color: var(--primary-color); font-size: 0.85rem;"></i> Deployment Ledger</h3>
                    <p style="color: var(--text-muted); font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.1rem;">Latest Personal Deployment Synchronization Logs.</p>
                </div>
            </div>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--card-border); color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 800;">
                            <th style="padding: 0.75rem 0.5rem; text-align: left; width: 25%;">Account</th>
                            <th style="padding: 0.75rem 0.5rem; text-align: left; width: 20%;">Time In & Date</th>
                            <th style="padding: 0.75rem 0.5rem; text-align: left; width: 20%;">Time Out & Date</th>
                            <th style="padding: 0.75rem 0.5rem; text-align: left; width: 20%;">Metrics</th>
                            <th style="padding: 0.75rem 0.5rem; text-align: right; width: 15%;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="activity-feed-relay">
                        <?php if (empty($attendance_feed)): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 4rem; color: var(--text-muted);"><i class="fas fa-history" style="display: block; font-size: 2rem; margin-bottom: 1rem; opacity: 0.15;"></i> No activity recorded for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach($attendance_feed as $feed): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.3s;">
                                <td style="padding: 0.85rem 0.75rem;">
                                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                                        <div style="width: 24px; height: 24px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; font-weight: 800; color: white;"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.75rem; color: white;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                                            <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">@<?php echo htmlspecialchars($_SESSION['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 0.85rem 0.75rem;">
                                    <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($feed['time_in'])); ?></div>
                                    <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($feed['time_in'])); ?></div>
                                </td>
                                <td style="padding: 0.85rem 0.75rem;">
                                    <?php if ($feed['time_out']): ?>
                                        <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($feed['time_out'])); ?></div>
                                        <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($feed['time_out'])); ?></div>
                                    <?php else: ?>
                                        <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">PENDING</div>
                                        <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">In Session</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.85rem 0.75rem;">
                                    <?php 
                                        $s_t = new DateTime($feed['time_in']);
                                        $e_t = $feed['time_out'] ? new DateTime($feed['time_out']) : null;
                                        $d_val = '-- : --';
                                        $p_val = '0.0000';
                                        if ($e_t) {
                                            $df = $s_t->diff($e_t);
                                            $d_val = $df->format('%H:%I:%S');
                                            $ds = $e_t->getTimestamp() - $s_t->getTimestamp();
                                            $p_val = number_format(($ds / 3600) * (6.66 / 160), 4);
                                        } else {
                                            $now = new DateTime();
                                            $df = $s_t->diff($now);
                                            $d_val = $df->format('%H:%I:%S');
                                            $ds = $now->getTimestamp() - $s_t->getTimestamp();
                                            $p_val = number_format(($ds / 3600) * (6.66 / 160), 4);
                                        }
                                    ?>
                                    <div style="color: var(--primary-color); font-weight: 800; font-size: 0.75rem;"><?php echo $d_val; ?></div>
                                    <div style="font-size: 0.55rem; color: var(--accent-green); font-weight: 700; text-transform: uppercase;"><?php echo $p_val; ?> <small style="opacity:0.6; font-weight: 700;">HRS</small></div>
                                </td>
                                <td style="padding: 0.85rem 0.75rem; text-align: right;">
                                    <span style="background: <?php echo $feed['time_out'] ? 'rgba(16, 185, 129, 0.08)' : 'rgba(245, 158, 11, 0.08)'; ?>; color: <?php echo $feed['time_out'] ? 'var(--accent-green)' : '#f59e0b'; ?>; padding: 0.2rem 0.6rem; border-radius: 100px; font-size: 0.55rem; font-weight: 800; border: 1px solid <?php echo $feed['time_out'] ? 'rgba(16, 185, 129, 0.2)' : 'rgba(245, 158, 11, 0.2)'; ?>;">
                                        <?php echo $feed['time_out'] ? 'COMPLETED' : 'ONLINE'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php elseif ($view === 'pto'): ?>
            <!-- Compact PTO Request Section -->
            <section style="background: var(--card-glass); border-radius: 16px; padding: 1rem; border: 1px solid var(--card-border); margin-top: 0.25rem; animation: fadeInUp 0.5s ease both; box-shadow: 0 15px 35px rgba(0,0,0,0.25);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                    <div>
                        <h2 style="font-size: 0.95rem; font-weight: 800; letter-spacing: -0.02em; color: white;">Request Paid Time Off</h2>
                        <p style="color: var(--text-muted); font-size: 0.6rem; margin-top: 0.05rem;">Standard organization policy (6.66 HRS/Month) applies.</p>
                    </div>
                </div>

                <form id="pto-request-form" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.6rem;">
                    <div class="form-group" style="background: rgba(255,255,255,0.02); padding: 0.6rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.03);">
                        <label style="display: block; font-size: 0.55rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem;">Start Date</label>
                        <input type="date" name="start_date" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; background: rgba(30, 41, 59, 0.5); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.4rem; color: white; font-size: 0.7rem; font-weight: 600; outline: none; transition: 0.3s;" required>
                    </div>
                    <div class="form-group" style="background: rgba(255,255,255,0.02); padding: 0.6rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.03);">
                        <label style="display: block; font-size: 0.55rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem;">End Date</label>
                        <input type="date" name="end_date" min="<?php echo date('Y-m-d'); ?>" style="width: 100%; background: rgba(30, 41, 59, 0.5); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.4rem; color: white; font-size: 0.7rem; font-weight: 600; outline: none; transition: 0.3s;" required>
                    </div>
                    <div class="form-group" style="grid-column: span 2; background: rgba(255,255,255,0.02); padding: 0.65rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.03);">
                        <label style="display: block; font-size: 0.55rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem;">Leave Classification</label>
                        <select name="leave_type" style="width: 100%; background: rgba(30, 41, 59, 0.5); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.4rem; color: white; font-size: 0.7rem; font-weight: 600; outline: none; cursor: pointer; appearance: none; -webkit-appearance: none; background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%236366f1%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E'); background-repeat: no-repeat; background-position: right 0.75rem center; background-size: 0.5rem auto;" required>
                            <option value="" disabled selected>Select...</option>
                            <option value="Vacation Leave">Vacation (VL)</option>
                            <option value="Sick Leave">Sick (SL)</option>
                            <option value="Emergency Leave">Emergency (EL)</option>
                            <option value="Bereavement Leave">Bereavement (BL)</option>
                            <option value="Maternity/Paternity Leave">Maternity / Paternity</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: span 2; background: rgba(255,255,255,0.02); padding: 0.65rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.03);">
                        <label style="display: block; font-size: 0.55rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem;">Deployment Logic / Reason</label>
                        <textarea name="reason" placeholder="Reason..." style="width: 100%; background: rgba(30, 41, 59, 0.5); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.5rem; color: white; font-size: 0.7rem; font-weight: 500; min-height: 50px; outline: none; transition: 0.3s; resize: none;" required></textarea>
                    </div>
                    <div style="grid-column: span 2; display: flex; justify-content: flex-end; margin-top: 0.2rem;">
                        <button type="submit" style="background: var(--primary-color); border: none; padding: 0.45rem 1.5rem; border-radius: 100px; color: white; font-size: 0.7rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: 0.3s; box-shadow: 0 8px 16px rgba(99, 102, 241, 0.15);">
                            <i class="fas fa-paper-plane" style="font-size: 0.65rem;"></i> TRANSMIT REQUEST
                        </button>
                    </div>
                </form>
            </section>

            <!-- Dynamic History Ledger (Specific Visibility) -->
            <section id="global-audit-ledger" style="background: var(--card-glass); border-radius: 20px; padding: 1rem; border: 1px solid var(--card-border); margin-top: 1.5rem; box-shadow: 0 10px 30px rgba(0,0,0,0.2); animation: fadeInUp 0.6s ease both;">
                <h3 style="font-size: 0.8rem; font-weight: 800; margin-bottom: 0.75rem; color: white; display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-database" style="color: var(--primary-color); font-size: 0.7rem;"></i> AUDIT LEDGER (LEAVE)
                </h3>
                <div id="pto-history-container" style="background: rgba(255,255,255,0.01); border-radius: 12px; overflow: hidden; border: 1px solid rgba(255,255,255,0.03);">
                    <?php if (empty($user_pto_requests)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 1.5rem 0;">
                            <i class="fas fa-folder-open" style="font-size: 1.5rem; opacity: 0.1; display: block; margin-bottom: 0.5rem;"></i>
                            <span style="font-size: 0.65rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase;">No deployment history found.</span>
                        </div>
                    <?php else: ?>
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="text-align: left; color: var(--text-muted); font-size: 0.5rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <th style="padding: 0.6rem 0.8rem;">Classification</th>
                                    <th style="padding: 0.6rem 0.8rem;">Interval</th>
                                    <th style="padding: 0.6rem 0.8rem;">Authorized By</th>
                                    <th style="padding: 0.6rem 0.8rem; text-align: right;">Authorization</th>
                                </tr>
                            </thead>
                            <tbody id="pto-history-relay">
                                <?php foreach ($user_pto_requests as $req): 
                                    $status_color = '#f59e0b'; // Pending
                                    $status_bg = 'rgba(245, 158, 11, 0.05)';
                                    $approver = $req['approver_name'] ?: "System";
                                    
                                    if ($req['status'] === 'Approved') { 
                                        $status_color = '#10b981'; 
                                        $status_bg = 'rgba(16, 185, 129, 0.05)'; 
                                    }
                                    elseif ($req['status'] === 'Denied') { 
                                        $status_color = '#ef4444'; 
                                        $status_bg = 'rgba(239, 68, 68, 0.05)'; 
                                    }
                                ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.02); transition: 0.2s;">
                                    <td style="padding: 0.75rem 0.8rem;">
                                        <div style="font-weight: 700; color: white; font-size: 0.75rem;"><?php echo htmlspecialchars($req['leave_type']); ?></div>
                                        <div style="font-size: 0.6rem; color: var(--text-muted); opacity: 0.6;"><?php echo date('M d, Y', strtotime($req['created_at'])); ?></div>
                                    </td>
                                    <td style="padding: 0.75rem 0.8rem; color: #cbd5e1; font-size: 0.65rem; font-weight: 600;">
                                        <?php echo date('M d', strtotime($req['start_date'])); ?> - <?php echo date('M d', strtotime($req['end_date'])); ?>
                                    </td>
                                    <td style="padding: 0.75rem 0.8rem; color: #10b981; font-weight: 800; font-size: 0.65rem;">
                                        <?php echo htmlspecialchars($approver); ?>
                                    </td>
                                    <td style="padding: 0.75rem 0.8rem; text-align: right;">
                                        <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 0.2rem 0.6rem; border-radius: 100px; font-size: 0.55rem; font-weight: 800; text-transform: uppercase;">
                                            <?php echo htmlspecialchars($req['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($view === 'attendance'): ?>
            <section style="background: var(--card-glass); border-radius: 20px; padding: 0.9rem; border: 1px solid var(--card-border); margin-top: 0.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem;">
                    <div>
                        <h2 style="font-weight: 800; font-size: 0.9rem; letter-spacing: -0.02em; color: white;">Deployment History</h2>
                        <p style="color: var(--text-muted); font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.1rem;">Monitor your historical attendance and operational commitment.</p>
                    </div>
                    <form method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="hidden" name="view" value="attendance">
                        <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>" style="background: rgba(30, 41, 59, 0.5); border: 1px solid var(--card-border); border-radius: 8px; padding: 0.35rem 0.65rem; color: white; outline: none; transition: 0.3s; font-size: 0.65rem; font-weight: 600;">
                        <button type="submit" style="background: var(--primary-color); border: none; padding: 0.35rem 0.75rem; border-radius: 8px; color: white; font-weight: 800; cursor: pointer; transition: 0.3s; font-size: 0.7rem;"><i class="fas fa-filter"></i></button>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--card-border); color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 800;">
                                <th style="padding: 0.75rem 0.5rem; text-align: left;">Time In & Date</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: left;">Time Out & Date</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: left;">Duration</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: left;">PTO Earned</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: right;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($attendance_history as $row): 
                                $s_time = new DateTime($row['time_in']);
                                $e_time = $row['time_out'] ? new DateTime($row['time_out']) : null;
                                $dur = '--:--:--';
                                if ($e_time) {
                                    $diff = $s_time->diff($e_time);
                                    $dur = $diff->format('%H:%I:%S');
                                }
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.3s;">
                                <td style="padding: 0.75rem 0.5rem;">
                                    <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_in'])); ?></div>
                                    <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;"><?php echo date('M d, Y', strtotime($row['time_in'])); ?></div>
                                </td>
                                <td style="padding: 0.75rem 0.5rem;">
                                    <?php if ($row['time_out']): ?>
                                        <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_out'])); ?></div>
                                        <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;"><?php echo date('M d, Y', strtotime($row['time_out'])); ?></div>
                                    <?php else: ?>
                                        <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem; letter-spacing: 0.02em;">PENDING</div>
                                        <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">In Session</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem 0.5rem; color: var(--primary-color); font-weight: 800; font-size: 0.75rem;"><?php echo $dur; ?></td>
                                <td style="padding: 0.75rem 0.5rem; color: #10b981; font-weight: 800; font-size: 0.75rem;">
                                    <?php 
                                        if ($e_time) {
                                            $diff_sec = $e_time->getTimestamp() - $s_time->getTimestamp();
                                            $pto_earned = ($diff_sec / 3600) * (6.66 / 160);
                                            echo number_format($pto_earned, 4) . ' <small style="font-size:0.55rem; opacity:0.6; font-weight: 700;">HRS</small>';
                                        } else {
                                            $now = new DateTime();
                                            $diff_sec = $now->getTimestamp() - $s_time->getTimestamp();
                                            $pto_earned = ($diff_sec / 3600) * (6.66 / 160);
                                            echo number_format($pto_earned, 4) . ' <small style="font-size:0.55rem; opacity:0.6; font-weight: 700;">HRS</small>';
                                        }
                                    ?>
                                </td>
                                <td style="padding: 0.75rem 0.5rem; text-align: right;">
                                    <?php if (!$row['time_out']): ?>
                                        <button onclick="processAttendance('time_out')" style="background: rgba(239, 68, 68, 0.1); color: var(--accent-red); border: 1px solid var(--accent-red); padding: 0.25rem 0.85rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase; white-space: nowrap;">
                                            <i class="fas fa-sign-out-alt"></i> TIME OUT
                                        </button>
                                    <?php else: ?>
                                        <span style="background: rgba(16, 185, 129, 0.05); color: var(--accent-green); padding: 0.25rem 0.75rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; border: 1px solid rgba(16, 185, 129, 0.2); text-transform: uppercase; letter-spacing: 0.05em;">
                                            Completed
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($attendance_history)): ?>
                            <tr>
                                <td colspan="5" style="padding: 3rem 1rem; text-align: center; color: var(--text-muted);">
                                    <i class="fas fa-calendar-times" style="font-size: 1.5rem; opacity: 0.2; display: block; margin-bottom: 0.75rem;"></i>
                                    <span style="font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">No records found for the selected deployment period.</span>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($view === 'history'): ?>
            <section style="background: var(--card-glass); border-radius: 20px; padding: 0.9rem; border: 1px solid var(--card-border); margin-top: 0.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem;">
                    <div>
                        <h2 style="font-weight: 800; font-size: 0.9rem; letter-spacing: -0.02em; color: white; display: flex; align-items: center; gap: 0.5rem;"><i class="fas fa-list-ul" style="color: var(--primary-color); font-size: 0.8rem;"></i> Deployment Ledger</h2>
                        <p style="color: var(--text-muted); font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.1rem;">Latest Personal Deployment Synchronization Logs.</p>
                    </div>
                    <form method="GET" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                        <input type="hidden" name="view" value="history">
                        <div class="filter-input-group">
                            <label style="display: block; font-size: 0.45rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; margin-bottom: 0.15rem;">Start</label>
                            <input type="date" name="start" value="<?php echo htmlspecialchars($date_start ?? ''); ?>" style="background: rgba(30,31,59,0.5); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.35rem 0.5rem; color: white; width: 100px; font-size: 0.65rem; font-weight: 600;">
                        </div>
                        <div class="filter-input-group">
                            <label style="display: block; font-size: 0.45rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; margin-bottom: 0.15rem;">End</label>
                            <input type="date" name="end" value="<?php echo htmlspecialchars($date_end ?? ''); ?>" style="background: rgba(30,31,59,0.5); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.35rem 0.5rem; color: white; width: 100px; font-size: 0.65rem; font-weight: 600;">
                        </div>
                        <button type="submit" style="background: var(--primary-color); border: none; padding: 0.35rem 0.75rem; border-radius: 6px; color: white; font-weight: 800; cursor: pointer; transition: 0.3s; font-size: 0.7rem;"><i class="fas fa-search"></i></button>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--card-border); color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 800;">
                                <th style="padding: 0.75rem 0.5rem; text-align: left; width: 25%;">Account</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: left; width: 20%;">Time In & Date</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: left; width: 20%;">Time Out & Date</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: left; width: 20%;">Metrics</th>
                                <th style="padding: 0.75rem 0.5rem; text-align: right; width: 15%;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($history_logs as $row): 
                                $s_time = new DateTime($row['time_in']);
                                $e_time = $row['time_out'] ? new DateTime($row['time_out']) : null;
                                $dur = '--:--:--';
                                if ($e_time) {
                                    $diff = $s_time->diff($e_time);
                                    $dur = $diff->format('%H:%I:%S');
                                } else {
                                    $n = new DateTime();
                                    $diff = $s_time->diff($n);
                                    $dur = $diff->format('%H:%I:%S');
                                }
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                                <td style="padding: 0.75rem 0.5rem;">
                                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                                        <div style="width: 24px; height: 24px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; font-weight: 800; color: white;"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 700; font-size: 0.75rem; color: white;">Personal Audit</div>
                                            <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">@<?php echo htmlspecialchars($_SESSION['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 0.75rem 0.5rem;">
                                    <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_in'])); ?></div>
                                    <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-top: 0.1rem;"><?php echo date('M d, Y', strtotime($row['time_in'])); ?></div>
                                </td>
                                <td style="padding: 0.75rem 0.5rem;">
                                    <?php if ($row['time_out']): ?>
                                        <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_out'])); ?></div>
                                        <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-top: 0.1rem;"><?php echo date('M d, Y', strtotime($row['time_out'])); ?></div>
                                    <?php else: ?>
                                        <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">PENDING</div>
                                        <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">In Session</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.75rem 0.5rem;">
                                    <div style="color: var(--primary-color); font-weight: 800; font-size: 0.75rem;"><?php echo $dur; ?></div>
                                    <?php 
                                        $pto = '0.0000';
                                        if ($e_time) {
                                            $diff_sec = $e_time->getTimestamp() - $s_time->getTimestamp();
                                            $pto = number_format(($diff_sec / 3600) * (6.66 / 160), 4);
                                        } else {
                                            $n = new DateTime();
                                            $diff_sec = $n->getTimestamp() - $s_time->getTimestamp();
                                            $pto = number_format(($diff_sec / 3600) * (6.66 / 160), 4);
                                        }
                                    ?>
                                    <div style="font-size: 0.55rem; color: #10b981; font-weight: 700; text-transform: uppercase;"><?php echo $pto; ?> <small style="opacity:0.6; font-weight: 700;">HRS</small></div>
                                </td>
                                <td style="padding: 0.75rem 0.5rem; text-align: right;">
                                    <span style="background: <?php echo $row['time_out'] ? 'rgba(16, 185, 129, 0.08)' : 'rgba(245, 158, 11, 0.08)'; ?>; color: <?php echo $row['time_out'] ? 'var(--accent-green)' : '#f59e0b'; ?>; padding: 0.2rem 0.6rem; border-radius: 100px; font-size: 0.55rem; font-weight: 800; border: 1px solid <?php echo $row['time_out'] ? 'rgba(16, 185, 129, 0.2)' : 'rgba(245, 158, 11, 0.2)'; ?>;">
                                        <?php echo $row['time_out'] ? 'COMPLETED' : 'ONLINE'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($history_logs)): ?>
                            <tr><td colspan="5" style="padding: 5rem; text-align: center; color: var(--text-muted);">No records found in Audit Ledger.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>        
        <?php elseif ($view === 'email'): ?>
            <div style="display: grid; grid-template-columns: 240px 1fr; gap: 1.5rem; height: calc(100vh - 160px); animation: fadeInUp 0.6s ease both;">
                <aside style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
                    <button onclick="openComposeWithData()" style="width: 100%; padding: 0.75rem; background: var(--primary-color); color: white; border: none; border-radius: 12px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; cursor: pointer;">Compose</button>
                    <nav id="email-folders-nav" style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <a href="javascript:void(0)" onclick="loadEmails('inbox', this)" class="email-nav-link active" style="padding: 0.75rem; color: var(--primary-color); text-decoration: none; font-size: 0.8rem; font-weight: 700;">Inbox <span id="unread-count-badge" style="display: none; background: #ef4444; color: white; padding: 0.1rem 0.4rem; border-radius: 10px; font-size: 0.6rem;">0</span></a>
                        <a href="javascript:void(0)" onclick="loadEmails('sent', this)" class="email-nav-link" style="padding: 0.75rem; color: var(--text-muted); text-decoration: none; font-size: 0.8rem;">Sent</a>
                    </nav>
                </aside>
                <div style="display: grid; grid-template-columns: 350px 1fr; gap: 1rem;">
                    <div style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; overflow: hidden; display: flex; flex-direction: column;">
                        <input type="text" placeholder="Filter stream..." style="padding: 1rem; background: #0f172a; border: none; color: white; font-size: 0.7rem;" oninput="filterEmailStream(this.value)">
                        <div id="email-stream-relay" style="flex: 1; overflow-y: auto;"></div>
                    </div>
                    <div id="email-preview-pane" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted);">
                        <i class="fas fa-envelope-open" style="font-size: 2rem; opacity: 0.2; margin-bottom: 1rem;"></i>
                        <p>Select a communication</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($view === 'profile'): ?>
            <div style="max-width: 800px; margin: 0 auto; animation: fadeInUp 0.6s ease both;">
                <div class="card" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 24px; padding: 2.5rem; backdrop-filter: blur(20px); box-shadow: 0 25px 50px rgba(0,0,0,0.3);">
                    <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2.5rem; border-bottom: 1px solid var(--card-border); padding-bottom: 2rem;">
                        <div style="width: 56px; height: 56px; background: rgba(99, 102, 241, 0.1); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--primary-color); border: 1px solid rgba(99, 102, 241, 0.2);">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 1.1rem; font-weight: 800; color: white;">Profile Synchronization</h3>
                            <p style="font-size: 0.75rem; color: var(--text-muted);">Manage your operational identity and security credentials.</p>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2.5rem; background: rgba(255,255,255,0.02); padding: 1.25rem; border-radius: 16px; border: 1px solid var(--card-border);">
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Staff ID</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white; letter-spacing: 0.05em;">VA-<?php echo str_pad($user_info['id'], 4, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Username</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;">@<?php echo htmlspecialchars($user_info['username']); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Date Joined</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;"><?php echo date('M d, Y', strtotime($user_info['created_at'])); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--accent-green); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Primary Role</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;">Virtual Assistant</span>
                        </div>
                    </div>

                    <form id="profile-sync-form">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                            <div class="form-group">
                                <label class="form-label">Full Legal Name</label>
                                <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($user_info['full_name'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Authorized Email</label>
                                <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>" placeholder="Update your contact email" required>
                            </div>
                        </div>

                        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--card-border); border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;">
                            <h4 style="font-size: 0.65rem; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1.25rem; font-weight: 800;">Security Protocol Override</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                <div class="form-group">
                                    <label class="form-label">New Password</label>
                                    <div style="position: relative;">
                                        <input type="password" id="sync_new_password" name="new_password" class="form-input" placeholder="Maintain current if blank" style="padding-right: 2.5rem;">
                                        <i class="fas fa-eye" id="toggleSyncNew" style="position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 0.8rem;"></i>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Confirm Key</label>
                                    <div style="position: relative;">
                                        <input type="password" id="sync_confirm_password" name="confirm_password" class="form-input" placeholder="Verify security key" style="padding-right: 2.5rem;">
                                        <i class="fas fa-eye" id="toggleSyncConfirm" style="position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--text-muted); font-size: 0.8rem;"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">
                            <span style="display: flex; align-items: center; justify-content: center; gap: 0.75rem;">
                                <i class="fas fa-sync-alt"></i> Synchronize Profile
                            </span>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>


    </main>

    <script>
        // --- CORE SYNCHRONIZATION ENGINE ---
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            
            if (document.getElementById('digital-clock-standard')) {
                document.getElementById('digital-clock-standard').textContent = timeString;
            }
        }

        function triggerToast(title, text, icon) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                background: '#1e293b',
                color: '#f8fafc'
            });
            Toast.fire({ icon, title, text });
        }

        // --- DASHBOARD TELEMETRY ---
        let lastShiftStatus = '<?php echo $shift_status; ?>';
        function updateStaffDashboard() {
            fetch('fetch_staff_self_updates.php')
                .then(r => r.json())
                .then(data => {
                    if (data.status) {
                        // 1. Update Telemetry Bars
                        const hoursEl = document.getElementById('global-total-hours');
                        const creditsEl = document.getElementById('global-pto-display');
                        const daysEl = document.getElementById('cutoff-days-display');
                        
                        if (hoursEl) hoursEl.innerHTML = `${data.total_hours} <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small>`;
                        if (creditsEl) creditsEl.innerHTML = `${data.pto_credits} <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small>`;
                        if (daysEl) daysEl.innerHTML = `${data.cutoff_days} <small style="font-size: 0.55rem; opacity: 0.6;">DAYS</small>`;

                        // 2. Update Activity Feed Table (if on home view)
                        const feedRelay = document.getElementById('activity-feed-relay');
                        if (feedRelay && data.history_html) {
                            if (feedRelay.innerHTML !== data.history_html) {
                                feedRelay.innerHTML = data.history_html;
                            }
                        }

                        // 3. Update PTO History Table (if on pto view)
                        const ptoRelay = document.getElementById('pto-history-relay');
                        if (ptoRelay && data.pto_history_html) {
                            if (ptoRelay.innerHTML !== data.pto_history_html) {
                                ptoRelay.innerHTML = data.pto_history_html;
                            }
                        }
                        
                        // 4. State-Based UI Updates (Reload only if status changed)
                        if (data.status !== lastShiftStatus) {
                            location.reload();
                        }
                    }
                });
        }

        function processAttendance(type) {
            Swal.fire({
                title: 'Confirm Attendance?',
                text: `You are about to submit a ${type.replace('_', ' ')} protocol.`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: type === 'time_in' ? '#10b981' : '#ef4444',
                cancelButtonColor: '#1e293b',
                confirmButtonText: `Yes, ${type.replace('_', ' ')}`,
                background: '#1e293b',
                color: '#f8fafc'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(`attendance_process.php?action=${type}&ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ 
                                icon: 'success', 
                                title: 'Synchronized!', 
                                text: data.message, 
                                background: '#1e293b', 
                                color: '#f8fafc', 
                                timer: 1500, 
                                showConfirmButton: false 
                            }).then(() => {
                                updateStaffDashboard();
                            });
                        } else {
                            Swal.fire('Operation Error', data.error || 'Sync failed.', 'error');
                        }
                    });
                }
            });
        }

        function updateDurationTicker() {
            const ticker = document.getElementById('shift-duration');
            if (!ticker) return;

            const timeInStr = '<?php echo $attendance ? $attendance['time_in'] : ""; ?>';
            const timeOutStr = '<?php echo ($attendance && $attendance['time_out']) ? $attendance['time_out'] : ""; ?>';
            
            if (!timeInStr) {
                ticker.textContent = "00:00:00";
                return;
            }

            if (timeOutStr) {
                // Shift completed, static display
                return;
            }

            const startTime = new Date(timeInStr).getTime();
            const now = new Date().getTime();
            const diff = Math.max(0, now - startTime);

            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);

            ticker.textContent = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        }

        // --- EMAIL HUB ENGINE ---
        function linkify(text) {
            const div = document.createElement('div');
            div.textContent = text;
            let safeText = div.innerHTML;
            const urlPattern = /(\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/ig;
            return safeText.replace(urlPattern, '<a href="$1" target="_blank" style="color: #6366f1; text-decoration: underline; font-weight: 600;">$1</a>');
        }

        let currentEmailFolder = 'inbox';
        function loadEmails(folder = 'inbox', element = null) {
            currentEmailFolder = folder;
            const container = document.getElementById('email-stream-relay');
            if (!container) return;

            if (element) {
                document.querySelectorAll('.email-nav-link').forEach(l => l.classList.remove('active'));
                element.classList.add('active');
            }

            fetch(`../email_process.php?action=fetch&folder=${folder}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderEmailList(data.emails);
                    }
                });
        }

        function renderEmailList(emails) {
            const container = document.getElementById('email-stream-relay');
            if (emails.length === 0) {
                container.innerHTML = `<div style="padding: 2rem; text-align: center; color: var(--text-muted); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">No communications found.</div>`;
                return;
            }
            container.innerHTML = emails.map(email => `
                <div class="email-stream-item ${email.is_read == 0 ? 'unread' : ''}" onclick="viewEmail(${email.id}, this)" style="padding: 1.25rem; border-bottom: 1px solid var(--card-border); cursor: pointer; transition: 0.3s; position: relative;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span style="font-weight: 800; font-size: 0.75rem; color: ${email.is_read == 0 ? 'white' : 'rgba(255,255,255,0.6)'}; display: flex; align-items: center; gap: 0.5rem;">
                            ${email.is_read == 0 ? '<div style="width: 6px; height: 6px; background: var(--primary-color); border-radius: 50%;"></div>' : ''}
                            <span style="font-size: 0.5rem; padding: 0.1rem 0.4rem; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 4px; color: var(--primary-color); text-transform: uppercase; font-weight: 900; letter-spacing: 0.05em;">${email.participant_role}</span>
                            ${email.participant_name}
                        </span>
                        <span style="font-size: 0.6rem; color: var(--text-muted); font-weight: 600;">${email.display_time}</span>
                    </div>
                    <div style="font-weight: 700; font-size: 0.75rem; color: ${email.is_read == 0 ? 'white' : 'rgba(255,255,255,0.4)'}; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${email.subject}</div>
                    <div style="font-size: 0.65rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; opacity: 0.6;">${email.message}</div>
                </div>
            `).join('');
        }

        function viewEmail(id, element = null) {
            document.querySelectorAll('.email-stream-item').forEach(i => i.classList.remove('selected'));
            if (element) element.classList.add('selected');

            const previewPane = document.getElementById('email-preview-pane');
            previewPane.innerHTML = `<div style="flex: 1; display: flex; align-items: center; justify-content: center; color: var(--text-muted);"><i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i></div>`;

            fetch(`../email_process.php?action=fetch_single&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const email = data.email;
                        previewPane.innerHTML = `
                            <div style="padding: 1.5rem; border-bottom: 1px solid var(--card-border); background: rgba(255,255,255,0.01); display: flex; justify-content: space-between; align-items: center;">
                                <div style="display: flex; align-items: center; gap: 1rem;">
                                    <div style="width: 45px; height: 45px; border-radius: 12px; background: rgba(16, 185, 129, 0.1); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 800; border: 1px solid rgba(16, 185, 129, 0.2);">
                                        <i class="fas fa-user-lock"></i>
                                    </div>
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.1rem;">
                                            <div style="font-weight: 800; color: white; font-size: 0.9rem;">${email.participant_name}</div>
                                            <span style="font-size: 0.55rem; padding: 0.15rem 0.4rem; background: var(--primary-color); color: white; border-radius: 4px; font-weight: 900; text-transform: uppercase;">${email.participant_role}</span>
                                        </div>
                                         <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">PRIVATE RELAY • ${email.display_time_full}</div>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 0.5rem;">
                                    <button onclick="toggleStar(${email.id})" style="width: 35px; height: 35px; border-radius: 10px; border: 1px solid var(--card-border); background: none; color: ${email.is_starred == 1 ? '#f59e0b' : 'var(--text-muted)'}; cursor: pointer; transition: 0.3s;"><i class="${email.is_starred == 1 ? 'fas' : 'far'} fa-star"></i></button>
                                    <button onclick="deleteEmail(${email.id})" style="width: 35px; height: 35px; border-radius: 10px; border: 1px solid var(--card-border); background: none; color: #ef4444; cursor: pointer; transition: 0.3s;"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </div>
                            <div style="flex: 1; display: flex; position: relative; overflow: hidden; background: rgba(0,0,0,0.05);">
                                <div id="email-detail-content" style="flex: 1; padding: 2rem; overflow-y: auto;">
                                    <h2 style="font-size: 1.25rem; font-weight: 800; color: white; margin-bottom: 1.5rem; line-height: 1.4;">${email.subject}</h2>
                                    <div style="font-size: 0.85rem; color: #cbd5e1; line-height: 1.8; white-space: pre-wrap; font-family: 'Inter', sans-serif;">${linkify(email.message)}</div>
                                    ${email.attachment_path ? `<div style="margin-top: 2rem; border-top: 1px solid var(--card-border); padding-top: 1.5rem;">
                                        <a href="../${email.attachment_path}" download="${email.attachment_name}" style="padding: 0.6rem 1rem; background: var(--primary-color); color: white; border-radius: 8px; font-weight: 800; font-size: 0.65rem; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; width: fit-content;">
                                            <i class="fas fa-save"></i> DOWNLOAD ATTACHMENT: ${email.attachment_name}
                                        </a>
                                    </div>` : ''}
                                </div>
                            </div>
                            <div style="padding: 1.25rem; border-top: 1px solid var(--card-border); background: rgba(0,0,0,0.1);">
                                <div style="display: flex; gap: 0.75rem; align-items: flex-end; background: rgba(255,255,255,0.03); padding: 0.5rem 0.5rem 0.5rem 1.25rem; border-radius: 24px; border: 1px solid var(--card-border); transition: 0.3s;">
                                    <textarea id="quick-reply-message" placeholder="Type your secure response..." style="flex: 1; background: transparent; border: none; padding: 0.75rem 0; color: white; font-size: 0.95rem; outline: none; height: 45px; max-height: 200px; resize: none; font-family: 'Inter', sans-serif; line-height: 1.5; overflow-y: auto;"></textarea>
                                    <button onclick="sendQuickReply(${email.participant_id}, \`${email.subject.replace(/`/g, '\\`')}\`)" style="width: 40px; height: 40px; background: var(--primary-color); color: white; border: none; border-radius: 50%; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
                                        <i class="fas fa-paper-plane" style="font-size: 0.9rem;"></i>
                                    </button>
                                </div>
                            </div>
                        `;
                        if (email.is_read == 0 && email.receiver_id == '<?php echo $user_id; ?>') {
                            fetch('../email_process.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=mark_read&id=${id}` }).then(() => pollEmailCounts());
                        }
                    }
                });
        }

        function sendQuickReply(receiverId, subject) {
            const message = document.getElementById('quick-reply-message').value;
            if (!message.trim()) return;
            const formData = new FormData();
            formData.append('action', 'send');
            formData.append('receiver_ids', receiverId);
            formData.append('subject', subject.startsWith('RE:') ? subject : 'RE: ' + subject);
            formData.append('message', message);
            fetch('../email_process.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
                if (data.success) {
                    triggerToast('SENT', 'Reply transmitted.', 'success');
                    document.getElementById('quick-reply-message').value = '';
                    if (currentEmailFolder === 'sent') loadEmails('sent');
                }
            });
        }

        function openComposeWithData(receiverId = null, subject = '') {
            fetch('../email_process.php?action=get_recipients')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const targetUser = receiverId ? data.users.find(u => u.id == receiverId) : null;
                        const userOptions = data.users.map(u => `<option value="${u.id}" ${u.id == receiverId ? 'selected' : ''}>${u.full_name} (${u.role})</option>`).join('');
                        
                        Swal.fire({
                            title: 'SECURE TRANSMISSION',
                            html: `
                                <div style="text-align: left;">
                                    <select id="swal-receiver" class="swal2-input" ${receiverId ? 'disabled' : 'multiple'} style="width: 100%; height: ${receiverId ? '40px' : '100px'};">
                                        ${userOptions}
                                    </select>
                                    <input id="swal-subject" class="swal2-input" value="${subject}" placeholder="Subject" style="width: 100%;">
                                    <textarea id="swal-message" class="swal2-textarea" placeholder="Message" style="width: 100%; height: 150px;"></textarea>
                                </div>
                            `,
                            showCancelButton: true,
                            confirmButtonText: 'SEND',
                            preConfirm: () => {
                                return {
                                    receiver_ids: Array.from(document.getElementById('swal-receiver').selectedOptions).map(o => o.value).join(','),
                                    subject: document.getElementById('swal-subject').value,
                                    message: document.getElementById('swal-message').value
                                }
                            }
                        }).then(result => {
                            if (result.isConfirmed) {
                                const fd = new FormData();
                                fd.append('action', 'send');
                                fd.append('receiver_ids', result.value.receiver_ids);
                                fd.append('subject', result.value.subject);
                                fd.append('message', result.value.message);
                                fetch('../email_process.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if(d.success) triggerToast('SENT', 'Message transmitted.', 'success'); });
                            }
                        });
                    }
                });
        }

        function toggleStar(id) {
            fetch('../email_process.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=toggle_star&id=${id}` }).then(() => loadEmails(currentEmailFolder));
        }

        function deleteEmail(id) {
            fetch('../email_process.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: `action=delete&id=${id}` }).then(() => loadEmails(currentEmailFolder));
        }

        function pollEmailCounts() {
            fetch('../email_process.php?action=get_unread_count')
                .then(r => r.json())
                .then(data => {
                    const count = parseInt(data.count) || 0;
                    if (!isFirstPoll && count > previousUnreadCount) triggerToast('NEW MAIL', 'Check your inbox.', 'info');
                    document.querySelectorAll('.main-unread-count-badge, #unread-count-badge').forEach(b => {
                        b.innerText = count;
                        b.style.display = count > 0 ? 'block' : 'none';
                    });
                    previousUnreadCount = count;
                    isFirstPoll = false;
                });
        }

        function filterEmailStream(query) {
            const q = query.toLowerCase();
            document.querySelectorAll('.email-stream-item').forEach(i => i.style.display = i.innerText.toLowerCase().includes(q) ? 'block' : 'none');
        }

        // --- AUTHORITATIVE INITIALIZATION ---
        let isFirstPoll = true;
        let previousUnreadCount = 0;
        setInterval(updateClock, 1000);
        setInterval(updateStaffDashboard, 5000);
        setInterval(updateDurationTicker, 1000);
        setInterval(pollEmailCounts, 5000);
        
        updateClock();
        updateStaffDashboard();
        updateDurationTicker();
        pollEmailCounts();

        if ('<?php echo $view; ?>' === 'email') loadEmails('inbox');
    </script>
</body>
</html>
