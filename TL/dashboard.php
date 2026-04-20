<?php
require_once 'db_config.php';
require_once '../accrual_helper.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['user_id']) || ($current_role !== 'team lead' && $current_role !== 'team-lead')) {
    if ($current_role !== '') {
        if ($current_role === 'admin') {
            header("Location: ../ADMIN/dashboard.php");
            exit();
        } elseif ($current_role === 'operations manager') {
            header("Location: ../OM/dashboard.php");
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

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];

// --- MODULE DATA FETCH RELAY ---
$total_staff = 0; $active_now = 0; $completed_today = 0;
$activity_feed = []; $staff_list = []; $personal_ledger = []; $personal_attendance = null;
$pto_credits = 0; $staff_pto_requests = []; $pending_staff_pto = 0; $active_staff_leave_count = 0;

try {
    $today = date('Y-m-d');
    
    // 1. Dashboard & Operations Telemetry
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(role) IN ('staff', 'staff member')");
    $total_staff = $stmt->fetchColumn();

    $active_now = $pdo->query("SELECT COUNT(*) FROM attendance WHERE time_out IS NULL")->fetchColumn();

    $completed_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(time_out) = '$today'")->fetchColumn();

    // Prioritize active session for personal telemetry
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $personal_attendance = $stmt->fetch();

    if (!$personal_attendance) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id, $today]);
        $personal_attendance = $stmt->fetch();
    }

    $stmt = $pdo->prepare("SELECT a.*, u.username FROM attendance a JOIN users u ON a.user_id = u.id WHERE (a.attendance_date = ? OR DATE(a.time_out) = ? OR a.time_out IS NULL) ORDER BY a.id DESC LIMIT 8");
    $stmt->execute([$today, $today]);
    $activity_feed = $stmt->fetchAll();

    $latest_att = $pdo->query("SELECT MAX(updated_at) FROM attendance")->fetchColumn() ?: '';
    
    $total_today = "00:00:00";
    if ($personal_attendance) {
        $start = new DateTime($personal_attendance['time_in']);
        if ($personal_attendance['time_out']) {
            $end = new DateTime($personal_attendance['time_out']);
            $interval = $start->diff($end);
            $total_today = $interval->format('%H:%I:%S');
        } else {
            $now = new DateTime();
            $interval = $start->diff($now);
            $total_today = $interval->format('%H:%I:%S');
        }
    }

    // 2. Staff List Module Data
    $stmt = $pdo->prepare("
        SELECT u.*, a.time_in, a.time_out, a.attendance_date 
        FROM users u 
        LEFT JOIN attendance a ON a.id = (
            SELECT id FROM attendance 
            WHERE user_id = u.id 
            AND (attendance_date = ? OR time_out IS NULL)
            ORDER BY id DESC LIMIT 1
        )
        WHERE u.role IN ('Staff', 'staff', 'Staff Member', 'STAFF MEMBER')
        ORDER BY u.username ASC
    ");
    $stmt->execute([$today]);
    $staff_list = $stmt->fetchAll();

    // 3. Audit Ledger (Personal)
    $start_date = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-7 days'));
    $end_date = isset($_GET['end']) ? $_GET['end'] : $today;
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC, time_in DESC");
    $stmt->execute([$user_id, $start_date, $end_date]);
    $personal_ledger = $stmt->fetchAll();

    // Fetch ALL attendance logs for Staff Audit module (Date filtered)
    $audit_start = $_GET['audit_start'] ?? date('Y-m-d', strtotime('-7 days'));
    $audit_end = $_GET['audit_end'] ?? date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as user_full_name, u.role, u.username as user_handle 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE (u.role IN ('Staff', 'staff', 'Staff Member', 'STAFF MEMBER') OR u.id = ?)
        AND (a.attendance_date BETWEEN ? AND ? OR DATE(a.time_out) BETWEEN ? AND ? OR a.time_out IS NULL) 
        ORDER BY a.id DESC
    ");
    $stmt->execute([$user_id, $audit_start, $audit_end, $audit_start, $audit_end]);
    $staff_audit_logs = $stmt->fetchAll();
    
    // 4. Personal Performance Metrics (Provisioning)
    require_once '../accrual_helper.php';
    $pto_credits = calculate_realtime_pto($user_id, $pdo);
    $total_hours_worked = get_total_cumulative_hours($user_id, $pdo);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $tl_info = $stmt->fetch();

    // 4. Staff PTO Requests (Pending & Approved/History)
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name, u.username as staff_username,
               approver.full_name as approver_name
        FROM pto_requests p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN users approver ON p.approved_by = approver.id
        WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER')
        ORDER BY p.id DESC
    ");
    $stmt->execute();
    $staff_pto_requests = $stmt->fetchAll();

    // 5. Fetch Active Ongoing PTO for Staff (Active absences)
    $active_staff_leaves = [];
    foreach($staff_pto_requests as $spr) {
        if ($spr['status'] === 'Approved' && strtotime($today) >= strtotime($spr['start_date']) && strtotime($today) <= strtotime($spr['end_date'])) {
            $active_staff_leaves[] = $spr;
        }
    }
    $active_staff_leave_count = count($active_staff_leaves);

    // Increment pending count logic (Authoritative Telemetry)
    $pending_staff_pto = 0; // Explicit reset for synchronization
    foreach($staff_pto_requests as $spr) {
        if ($spr['status'] === 'Pending') $pending_staff_pto++;
    }

    // Build Ongoing Leave HTML for initial load
    $ongoing_leave_html = '';
    if (empty($active_staff_leaves)) {
        $ongoing_leave_html = '<tr><td colspan="7" style="text-align: center; padding: 4rem; color: var(--text-muted);">Zero active leave deployments broadcasted currently.</td></tr>';
    } else {
        foreach($active_staff_leaves as $leave) {
            $ongoing_leave_html .= '
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                    <td style="padding: 1.25rem 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="width: 32px; height: 32px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem; color: white;">' . strtoupper(substr($leave['staff_username'] ?? 'S', 0, 1)) . '</div>
                            <div>
                                <div style="font-weight: 700; color: white;">' . htmlspecialchars($leave['full_name'] ?? 'Staff') . '</div>
                                <div style="font-size: 0.7rem; color: var(--text-muted);">@' . htmlspecialchars($leave['staff_username'] ?? 'unknown') . '</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 1.25rem 1rem;"><span style="font-size: 0.75rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase;">Staff</span></td>
                    <td style="padding: 1.25rem 1rem; color: var(--primary-color); font-weight: 700;">' . htmlspecialchars($leave['leave_type'] ?? 'General') . '</td>
                    <td style="padding: 1.25rem 1rem; color: #cbd5e1; font-weight: 600;">' . date('M d', strtotime($leave['start_date'])) . ' - ' . date('M d', strtotime($leave['end_date'])) . '</td>
                    <td style="padding: 1.25rem 1rem; color: var(--text-muted); font-size: 0.75rem; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">' . htmlspecialchars($leave['reason'] ?? '--') . '</td>
                    <td style="padding: 1.25rem 1rem; color: #10b981; font-weight: 800;">' . htmlspecialchars($leave['approver_name'] ?: 'System') . '</td>
                    <td style="padding: 1.25rem 1rem; text-align: right;"><span style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.3rem 0.8rem; border-radius: 6px; font-size: 0.65rem; font-weight: 800;">AUTHORIZED</span></td>
                </tr>';
        }
    }
        
    // 5. Team Lead's Personal PTO History
    $stmt = $pdo->prepare("SELECT * FROM pto_requests WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $user_pto_requests = $stmt->fetchAll();

} catch (PDOException $e) {
    // Failures logged internally, defaults used for UI stability
}

// Time calculation helper
$p_duration = '--:--:--';
if ($personal_attendance) {
    $start = new DateTime($personal_attendance['time_in']);
    $end = $personal_attendance['time_out'] ? new DateTime($personal_attendance['time_out']) : new DateTime();
    $diff = $start->diff($end);
    $p_duration = $diff->format('%H:%I:%S');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Seamless Assist</title>
    <!-- Modern Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- SweetAlert2 for interactive feedback -->
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
        }

        /* Unified Deep Midnight Blue Scrollbars */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { 
            background: rgba(99, 102, 241, 0.2); 
            border-radius: 10px; 
            transition: 0.3s;
        }
        ::-webkit-scrollbar-thumb:hover { 
            background: var(--primary-color); 
        }

        .search-hidden, .time-hidden { display: none !important; }
        .audit-filter-btn.active { box-shadow: 0 4px 10px rgba(99, 102, 241, 0.3); }

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

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Outfit', sans-serif; }
        body { 
            background: #020617;
            color: var(--text-main); 
            min-height: 100vh; 
            display: flex; 
        }



        /* Sidebar Styling */
        .sidebar {
            width: 190px;
            background: var(--sidebar-bg);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-right: 1px solid var(--card-border);
            padding: 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            z-index: 1000;
            overflow-y: auto;
        }

        .logo-container {
            padding: 1rem 1rem;
            text-align: left;
        }

        .nav-menu {
            list-style: none;
            padding: 0 1rem;
            flex: 1;
        }

        .nav-item {
            margin-bottom: 0.35rem;
        }

        .nav-link {
            text-decoration: none;
            color: var(--text-muted);
            padding: 0.5rem 1rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.8rem;
            white-space: nowrap;
            width: 100%;
            box-sizing: border-box;
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
            padding: 1rem;
            border-top: 1px solid var(--card-border);
            color: #ef4444;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: 0.3s;
            margin-top: auto;
        }

        /* Main Content */
        .main-content {
            margin-left: 190px;
            flex: 1;
            padding: 1rem;
            overflow-y: auto;
            background: #111827;
        }

        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .welcome-msg h2 {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: white;
            margin-bottom: 0.25rem;
        }

        .status-line {
            font-size: 0.55rem;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-badge {
            color: var(--primary-color);
            font-weight: 800;
        }

        .user-pill {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 100px;
            padding: 0.3rem 0.5rem 0.3rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            backdrop-filter: blur(10px);
        }

        .user-name {
            font-weight: 700;
            font-size: 0.75rem;
            color: white;
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

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .stat-card {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 14px;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 0.65rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        .icon-blue { background: rgba(99, 102, 241, 0.15); color: #6366f1; }
        .icon-green { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .icon-gold { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .icon-red { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        .stat-label {
            font-size: 0.55rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-value {
            font-size: 1rem;
            font-weight: 800;
            color: white;
        }

        /* Clock Console */
        .clock-console {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 1rem;
            text-align: center;
            margin-bottom: 1.25rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }

        #digital-clock {
            font-size: 1.6rem;
            font-weight: 800;
            color: white;
            letter-spacing: -0.02em;
            line-height: 1;
        }

        #current-date {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .status-badge-green {
            background: rgba(16, 185, 129, 0.15);
            color: var(--accent-green);
            border: 1px solid rgba(16, 185, 129, 0.3);
            padding: 0.4rem 1rem;
            border-radius: 100px;
            font-size: 0.6rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .time-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1px;
            background: rgba(255, 255, 255, 0.05);
            width: 100%;
            margin-top: 1rem;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--card-border);
        }

        .metric-box {
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(8px);
            padding: 0.6rem;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .metric-label {
            font-size: 0.5rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .metric-value {
            font-size: 0.75rem;
            font-weight: 800;
            color: white;
        }

        /* Attendance Feed */
        .attendance-section {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            padding: 1.25rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
        }

        .section-title {
            font-size: 0.95rem;
            font-weight: 800;
            color: white;
        }

        .section-subtitle {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 0.85rem 1rem;
            font-size: 0.6rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border-bottom: 1px solid var(--card-border);
        }

        td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.02);
            font-size: 0.8rem;
            vertical-align: middle;
        }

        .member-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .member-info {
            display: flex;
            flex-direction: column;
        }

        .member-name {
            font-weight: 700;
            color: white;
        }

        .member-user {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .status-chip {
            padding: 0.4rem 0.8rem;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-completed {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent-green);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .status-active {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary-color);
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        /* Settings & Form Utilities */
        .form-group { margin-bottom: 1.25rem; }
        .form-label { 
            display: block; 
            font-size: 0.65rem; 
            font-weight: 800; 
            color: var(--text-muted); 
            text-transform: uppercase; 
            letter-spacing: 0.05em; 
            margin-bottom: 0.5rem; 
        }
        .form-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 0.8rem 1rem;
            color: white;
            font-size: 0.85rem;
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
            padding: 1rem;
            border-radius: 14px;
            font-weight: 800;
            font-size: 0.85rem;
            cursor: pointer;
            transition: 0.3s;
            box-shadow: 0 10px 20px rgba(99, 102, 241, 0.2);
        }
        .btn-submit:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 15px 30px rgba(99, 102, 241, 0.3);
        }
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not_allowed;
            transform: none;
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
                <a href="javascript:void(0)" onclick="switchModule('module-home', this)" class="nav-link active">
                    <i class="fas fa-home"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-attendance', this)" class="nav-link">
                    <i class="fas fa-user-clock"></i>
                    <span class="nav-text">Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-pto', this)" class="nav-link">
                    <i class="fas fa-calendar-alt"></i>
                    <span class="nav-text">PTO Request</span>
                    <span id="pto-pending-badge" style="<?php echo $pending_staff_pto > 0 ? 'display: inline-block;' : 'display: none;'; ?> background: #ef4444; color: white; font-size: 0.65rem; padding: 0.2rem 0.5rem; border-radius: 100px; margin-left: auto; font-weight: 800; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);">
                        <?php echo $pending_staff_pto; ?>
                    </span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-staff', this)" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">Staff List</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-ops', this)" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    <span class="nav-text">Staff Audit</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-breaches', this)" class="nav-link">
                    <i class="fas fa-exclamation-circle"></i>
                    <span class="nav-text">Attendance Breaches</span>
                    <span id="stale-sidebar-badge" style="background: #ef4444; color: white; padding: 0.1rem 0.5rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; margin-left: auto; display: none;"></span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-ongoing-leave', this)" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span class="nav-text">On-Going Leave</span>
                    <span id="ongoing-sidebar-badge" style="<?php echo $active_staff_leave_count > 0 ? 'display: inline-block;' : 'display: none;'; ?> background: #ef4444; color: white; padding: 0.1rem 0.5rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; margin-left: auto;">
                        <?php echo $active_staff_leave_count; ?>
                    </span>
                </a>
            </li>

            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-email', this)" class="nav-link" style="position: relative;">
                    <i class="fas fa-envelope"></i>
                    <span class="nav-text">Email</span>
                    <span class="main-unread-count-badge" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: #ef4444; color: white; font-size: 0.55rem; padding: 0.1rem 0.4rem; border-radius: 100px; display: none; font-weight: 800;">0</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-settings', this)" class="nav-link">
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
        <!-- Authoritative Global Cockpit -->
        <header class="top-header">
            <div class="page-title">
                <h2 id="module-title-broadcast" style="font-size: 1.1rem;">Operational Command</h2>
                <p style="color: var(--text-muted); font-size: 0.55rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; margin-top: 0.1rem;">TEAM LEAD DASHBOARD</p>
            </div>
            <?php 
                $cutoff = get_current_cutoff_dates();
                $my_days_worked = get_days_worked_in_cutoff($user_id, $pdo, $cutoff['start'], $cutoff['end']);
            ?>
            <div style="display: flex; align-items: center; gap: 1.5rem;">
                <!-- Cutoff Attendance Pill -->
                <div style="text-align: left; padding: 0.35rem 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.2); backdrop-filter: blur(10px);">
                    <span id="cutoff-label-broadcast" style="font-size: 0.55rem; color: var(--accent-green); text-transform: uppercase; font-weight: 800; letter-spacing: 0.1em; display: block;">Cutoff Duty (<?php echo $cutoff['label']; ?>)</span>
                    <span id="cutoff-days-display" style="font-size: 0.8rem; font-weight: 800; color: white;"><?php echo $my_days_worked; ?> <small style="font-size: 0.55rem; opacity: 0.6;">DAYS</small></span>
                </div>

                <!-- Total Worked Hours (Operational Ledger) -->
                <div style="text-align: left; padding: 0.35rem 1rem; background: rgba(99, 102, 241, 0.1); border-radius: 12px; border: 1px solid rgba(99, 102, 241, 0.2); backdrop-filter: blur(10px);">
                    <span style="font-size: 0.55rem; color: var(--primary-color); text-transform: uppercase; font-weight: 800; letter-spacing: 0.1em; display: block;">Total Worked Hours</span>
                    <span id="global-total-hours" style="font-size: 0.8rem; font-weight: 800; color: white;"><?php echo number_format($total_hours_worked, 2); ?> <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small></span>
                </div>

                <!-- Earned PTO Credits Pill (Definitive Standard) -->
                <div style="text-align: left; padding: 0.35rem 1rem; background: rgba(16, 185, 129, 0.1); border-radius: 12px; border: 1px solid rgba(16, 185, 129, 0.2); backdrop-filter: blur(10px);">
                    <span style="font-size: 0.55rem; color: var(--accent-green); text-transform: uppercase; font-weight: 800; letter-spacing: 0.1em; display: block;">Earned PTO Credits</span>
                    <span id="global-pto-display" style="font-size: 0.8rem; font-weight: 800; color: white;"><?php echo number_format($pto_credits, 4); ?> <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small></span>
                </div>
                
                <div class="user-pill">
                    <div style="text-align: right;">
                        <div style="font-weight: 800; font-size: 0.7rem; color: white;"><?php echo htmlspecialchars($username); ?></div>
                        <div style="font-size: 0.5rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase;">TEAM LEAD DASHBOARD</div>
                    </div>
                    <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; color: white;">
                        <?php echo strtoupper(substr($username, 0, 1)); ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- MODULE: HOME (DASHBOARD COCKPIT + FEED) -->
        <section id="module-home" class="dashboard-module" style="display: block;">
            <!-- Stats Cockpit (Static within Home) -->
            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon icon-blue"><i class="fas fa-users"></i></div>
                        <span class="stat-label">Total Staff</span>
                    </div>
                    <div class="stat-value" id="total-staff-count"><?php echo $total_staff; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon icon-green"><i class="fas fa-bolt"></i></div>
                        <span class="stat-label">Active Now</span>
                    </div>
                    <div class="stat-value" id="active-attendance-count"><?php echo $active_now; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon icon-gold"><i class="fas fa-check-circle"></i></div>
                        <span class="stat-label">Completed Today</span>
                    </div>
                    <div class="stat-value" id="completed-today-count"><?php echo $completed_today; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon icon-gold"><i class="fas fa-plane-departure"></i></div>
                        <span class="stat-label">Pending PTO</span>
                    </div>
                    <div class="stat-value" id="pto-pending-count" style="color: <?php echo $pending_staff_pto > 0 ? '#f59e0b' : 'white'; ?>;"><?php echo $pending_staff_pto; ?></div>
                    <div style="font-size: 0.55rem; color: var(--text-muted); margin-top: 0.25rem; font-weight: 700; text-transform: uppercase;">Awaiting Review</div>
                </div>
                <div class="stat-card" style="cursor: pointer; transition: 0.3s;" onclick="switchModule('module-pto', document.querySelector('[onclick*=\'module-pto\']'))" onmouseover="this.style.borderColor='var(--primary-color)'" onmouseout="this.style.borderColor='var(--card-border)'">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;"><i class="fas fa-walking"></i></div>
                        <span class="stat-label">On Going Leave</span>
                    </div>
                    <div class="stat-value" id="active-leave-count" style="color: #f59e0b;"><?php echo $active_staff_leave_count; ?></div>
                    <div style="font-size: 0.55rem; color: var(--text-muted); margin-top: 0.25rem; font-weight: 700; text-transform: uppercase;">Active Absences</div>
                </div>
            </section>

            <section class="clock-console">
                <div id="digital-clock"><?php echo date('h:i:s A'); ?></div>
                <div id="current-date"><?php echo date('l, F d, Y'); ?></div>
                <div id="clock-btn-relay" style="margin: 1.5rem 0 1rem 0;">
                    <?php 
                    $stale_session = get_stale_active_session($user_id, $pdo);
                    if ($stale_session): ?>
                        <button class="btn-attendance" onclick="processAttendance('time_out')" style="background: #ef4444; border: none; padding: 0.75rem 2.5rem; border-radius: 100px; color: white; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.75rem; transition: 0.4s; box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em;">
                            <i class="fas fa-exclamation-triangle"></i> RESOLVE STALE SESSION
                        </button>
                    <?php elseif (!$personal_attendance): ?>
                        <button class="btn-attendance" onclick="processAttendance('time_in')" style="background: var(--accent-green); border: none; padding: 0.75rem 2.5rem; border-radius: 100px; color: white; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.75rem; transition: 0.4s; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em;">
                            <i class="fas fa-sign-in-alt"></i> TIME IN
                        </button>
                    <?php elseif (!$personal_attendance['time_out']): ?>
                        <button class="btn-attendance" onclick="processAttendance('time_out')" style="background: #f43f5e; border: none; padding: 0.75rem 2.5rem; border-radius: 100px; color: white; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.75rem; transition: 0.4s; box-shadow: 0 10px 25px rgba(244, 63, 94, 0.4); text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.05em;">
                            <i class="fas fa-external-link-alt"></i> TIME OUT
                        </button>
                    <?php else: ?>
                        <div class="status-badge-green">
                            <i class="fas fa-check-circle"></i> SHIFT COMPLETED
                        </div>
                    <?php endif; ?>
                </div>
                
                <div id="attendance-status-relay" style="font-size: 0.85rem; font-weight: 700; color: #10b981; margin-top: 0.5rem; letter-spacing: 0.02em;">
                    <?php if ($personal_attendance && !$personal_attendance['time_out']): ?>
                        You timed in at <?php echo date('h:i A', strtotime($personal_attendance['time_in'])); ?>
                    <?php endif; ?>
                </div>
                
                <div class="time-metrics">
                    <div class="metric-box">
                        <span class="metric-label">TIME IN</span>
                        <span class="metric-value"><?php echo $personal_attendance ? date('h:i A', strtotime($personal_attendance['time_in'])) : '--:-- --'; ?></span>
                    </div>
                    <div class="metric-box">
                        <span class="metric-label">TIME OUT</span>
                        <span class="metric-value"><?php echo ($personal_attendance && $personal_attendance['time_out']) ? date('h:i A', strtotime($personal_attendance['time_out'])) : '--:-- --'; ?></span>
                    </div>
                    <div class="metric-box">
                        <span class="metric-label">TOTAL HOURS</span>
                        <span class="metric-value" id="p-duration-ticker"><?php echo $p_duration; ?></span>
                    </div>
                </div>
            </section>

            <!-- Operational Notice Banner -->
            <div style="background: linear-gradient(90deg, rgba(99, 102, 241, 0.15), rgba(245, 158, 11, 0.1)); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 1rem; margin: 1.5rem 0 1.25rem 0; display: flex; align-items: center; gap: 1.25rem; animation: fadeInUp 0.8s ease both; backdrop-filter: blur(10px); box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                <div style="width: 38px; height: 38px; background: #6366f1; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1rem; color: white; flex-shrink: 0; box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);">
                    <i class="fas fa-broadcast-tower"></i>
                </div>
                <div style="flex: 1;">
                    <h4 style="font-size: 0.75rem; font-weight: 800; color: white; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.15rem;">Elite Performance Update</h4>
                    <p style="font-size: 0.65rem; color: var(--text-muted); line-height: 1.4;">Standby for floor-wide synchronization. Ensure all staff deployments are monitored in real-time. System health is currently <span style="color: #10b981; font-weight: 800;">OPTIMAL</span>.</p>
                </div>
                <div style="padding: 0.4rem 0.8rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px; border: 1px solid rgba(99, 102, 241, 0.2); text-align: center;">
                    <span id="header-clock" style="font-size: 0.75rem; font-weight: 800; color: white;"><?php echo date('h:i A'); ?></span>
                </div>
            </div>

            <!-- PENDING PTO ACTION ALERT -->
            <?php if ($pending_staff_pto > 0): ?>
            <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 12px; padding: 0.85rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1rem; cursor:pointer;" onclick="switchModule('module-pto', document.querySelector('[onclick*=\'module-pto\']'))">
                <div style="width: 40px; height: 40px; background: rgba(245, 158, 11, 0.2); color: #f59e0b; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div style="flex: 1;">
                    <h4 style="font-size: 0.8rem; font-weight: 800; color: #f59e0b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.15rem;">Action Required: Pending PTO Requests</h4>
                    <p style="font-size: 0.65rem; color: var(--text-muted);">There are <?php echo $pending_staff_pto; ?> staff leave requests awaiting your review.</p>
                </div>
                <button style="background: #f59e0b; color: #0f172a; border: none; padding: 0.4rem 1rem; border-radius: 8px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase;">Review</button>
            </div>
            <?php endif; ?>

            <!-- Team Operations Hub Notice -->
            <div style="background: linear-gradient(90deg, rgba(16, 185, 129, 0.1), rgba(99, 102, 241, 0.15)); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 1rem; margin: 1rem 0 0 0; display: flex; align-items: center; gap: 1.25rem; animation: fadeInDown 0.8s ease both; backdrop-filter: blur(10px); box-shadow: 0 10px 30px rgba(0,0,0,0.15);">
                <div style="width: 44px; height: 44px; background: #6366f1; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: white; flex-shrink: 0; box-shadow: 0 5px 15px rgba(99, 102, 241, 0.3);">
                    <i class="fas fa-microchip"></i>
                </div>
                <div style="flex: 1;">
                    <h4 style="font-size: 0.8rem; font-weight: 800; color: white; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.2rem;">Team Operations Hub</h4>
                    <p style="font-size: 0.65rem; color: var(--text-muted); line-height: 1.4;">All team monitoring nodes are currently active. Broadcast signal is <span style="color: #6366f1; font-weight: 800;">ENCRYPTED & STABLE</span>.</p>
                </div>
                <div style="padding: 0.4rem 0.8rem; background: rgba(99, 102, 241, 0.1); border-radius: 8px; border: 1px solid rgba(99, 102, 241, 0.2); text-align: center;">
                    <span style="display: block; font-size: 0.55rem; color: #6366f1; font-weight: 800; text-transform: uppercase;">Signal</span>
                    <span style="font-size: 0.75rem; font-weight: 800; color: white;">STRONG</span>
                </div>
            </div>
            <!-- Team Activity Feed -->
            <section class="card" style="margin-top: 1.25rem; background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem;">
            <h3 style="font-weight: 800; margin-bottom: 0.85rem; color: white; font-size: 0.8rem;"><i class="fas fa-stream" style="color: var(--primary-color);"></i> Recent Operational Activity</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; color: var(--text-muted); font-size: 0.6rem; text-transform: uppercase;">
                        <th style="padding: 0.65rem 0.85rem;">Member</th>
                        <th style="padding: 0.65rem 0.85rem;">Time In & Date</th>
                        <th style="padding: 0.65rem 0.85rem;">Time Out & Date</th>
                        <th style="padding: 0.65rem 0.85rem; text-align: right;">Status</th>
                    </tr>
                </thead>
                <tbody id="activity-feed-relay">
                    <?php foreach($activity_feed as $feed): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                        <td style="padding: 0.65rem 0.85rem; color: white; display: flex; align-items: center; gap: 0.6rem;">
                            <div style="width: 24px; height: 24px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 800; color: white;"><?php echo strtoupper(substr($feed['username'], 0, 1)); ?></div>
                            <div style="font-weight: 700; font-size: 0.75rem;"><?php echo htmlspecialchars($feed['username']); ?></div>
                        </td>
                        <td style="padding: 0.65rem 0.85rem;">
                            <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($feed['time_in'])); ?></div>
                            <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($feed['time_in'])); ?></div>
                        </td>
                        <td style="padding: 1rem;">
                            <?php if ($feed['time_out']): ?>
                                <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($feed['time_out'])); ?></div>
                                <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($feed['time_out'])); ?></div>
                            <?php else: ?>
                                <div style="color: #f59e0b; font-weight: 800; font-size: 0.85rem;">PENDING</div>
                                <div style="font-size: 0.6rem; color: var(--text-muted);">In Session</div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.65rem 0.85rem; text-align: right;">
                            <span style="background: <?php echo $feed['time_out'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)'; ?>; color: <?php echo $feed['time_out'] ? 'var(--accent-green)' : '#f59e0b'; ?>; padding: 0.25rem 0.75rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800;">
                                <?php echo $feed['time_out'] ? 'COMPLETED' : 'ONLINE'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
        </section> <!-- CLOSE MODULE: HOME -->

        <!-- MODULE: PTO REQUEST (COMFORT-DENSITY COMMAND CENTER) -->
        <section id="module-pto" class="dashboard-module" style="display: none; margin-top: 1rem;">
            
            <!-- STAFF REQUESTS COMMAND CENTER (MEDIUM DENSITY) -->
            <div style="background: rgba(99, 102, 241, 0.04); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 20px; padding: 1rem; margin-bottom: 1.25rem; animation: fadeInUp 0.5s ease both; box-shadow: 0 15px 35px rgba(0,0,0,0.25);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                    <div>
                        <h3 style="font-weight: 800; color: white; font-size: 0.85rem; letter-spacing: -0.02em;"><i class="fas fa-clipboard-check" style="color: var(--primary-color); margin-right: 0.5rem;"></i> Staff Request Queue</h3>
                        <p style="color: var(--text-muted); font-size: 0.65rem; margin-top: 0.2rem;">Authoritative review of staff deployment leave requests.</p>
                    </div>
                    <?php if ($pending_staff_pto > 0): ?>
                        <span style="background: #ef4444; color: white; font-size: 0.65rem; padding: 0.35rem 1rem; border-radius: 100px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo $pending_staff_pto; ?> PENDING</span>
                    <?php endif; ?>
                </div>

                <div class="table-container" style="background: rgba(255,255,255,0.02); border-radius: 14px; border: 1px solid rgba(255,255,255,0.05); max-height: 500px; overflow-y: auto; overflow-x: auto; padding-right: 0.5rem; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent;">
                    <style>
                        /* Custom scrollbar for webkit */
                        #module-pto .table-container::-webkit-scrollbar { width: 8px; }
                        #module-pto .table-container::-webkit-scrollbar-track { background: transparent; margin-block: 0.5rem; }
                        #module-pto .table-container::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
                        #module-pto .table-container::-webkit-scrollbar-thumb:hover { background: #64748b; }
                    </style>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="position: sticky; top: 0; background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(10px); z-index: 10; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                            <tr style="text-align: left; color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid rgba(255,255,255,0.08);">
                                <th style="padding: 0.65rem 0.85rem;">Staff Member</th>
                                <th style="padding: 0.65rem 0.85rem;">Classification</th>
                                <th style="padding: 0.65rem 0.85rem;">Deployment Interval</th>
                                <th style="padding: 0.65rem 0.85rem;">Justification</th>
                                <th style="padding: 0.65rem 0.85rem; text-align: right;">Authorization</th>
                            </tr>
                        </thead>
                        <tbody id="tl-pto-queue-relay">
                            <?php 
                            $pending_found = false;
                            foreach ($staff_pto_requests as $req): 
                                if ($req['status'] !== 'Pending') continue; 
                                $pending_found = true;
                            ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.01)'" onmouseout="this.style.background='transparent'">
                                <td style="padding: 0.65rem 0.85rem;">
                                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                                        <div style="width: 28px; height: 28px; background: rgba(99, 102, 241, 0.2); color: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800; border: 1px solid rgba(99, 102, 241, 0.2);"><?php echo strtoupper(substr($req['staff_username'] ?? '', 0, 1)); ?></div>
                                        <div>
                                            <div style="font-weight: 700; color: white; font-size: 0.75rem;"><?php echo htmlspecialchars($req['full_name'] ?? ''); ?></div>
                                            <div style="font-size: 0.6rem; color: var(--text-muted);">@<?php echo htmlspecialchars($req['staff_username'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding: 0.65rem 0.85rem;">
                                    <span style="font-size: 0.7rem; color: var(--primary-color); font-weight: 700;"><?php echo htmlspecialchars($req['leave_type'] ?? ''); ?></span>
                                </td>
                                <td style="padding: 0.65rem 0.85rem; color: #cbd5e1; font-size: 0.7rem; font-weight: 600;">
                                    <?php echo date('M d', strtotime($req['start_date'] ?? '')); ?> - <?php echo date('M d', strtotime($req['end_date'] ?? '')); ?>
                                </td>
                                <td style="padding: 0.65rem 0.85rem;">
                                    <div style="font-size: 0.65rem; color: var(--text-muted); max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: help;" title="<?php echo htmlspecialchars($req['reason'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($req['reason'] ?? ''); ?>
                                    </div>
                                </td>
                                <td style="padding: 0.65rem 0.85rem; text-align: right;">
                                    <div style="display: flex; gap: 0.4rem; justify-content: flex-end;">
                                        <button onclick="handlePTO(<?php echo $req['id']; ?>, 'Approved')" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;">Authorize</button>
                                        <button onclick="handlePTO(<?php echo $req['id']; ?>, 'Denied')" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;">Reject</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$pending_found): ?>
                                <tr><td colspan="5" style="padding: 3.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem; border-top: 1px solid rgba(255,255,255,0.05);"><i class="fas fa-check-double" style="display: block; font-size: 2rem; margin-bottom: 1rem; opacity: 0.15;"></i> Zero pending deployment requests from your staff.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>

            <!-- STAFF LEAVE HISTORY (AUDIT) -->
            <div style="background: rgba(15, 23, 42, 0.3); border: 1px solid rgba(255, 255, 255, 0.05); border-radius: 20px; padding: 1rem; margin-top: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 20px 50px rgba(0,0,0,0.2); backdrop-filter: blur(10px);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.75rem; flex-wrap: wrap; gap: 1.5rem;">
                    <div>
                        <h3 style="font-weight: 800; color: white; font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; text-transform: uppercase; letter-spacing: 0.08em; margin: 0;">
                            <i class="fas fa-history" style="color: var(--primary-color); font-size: 0.75rem;"></i> Staff Leave Audit Ledger
                        </h3>
                        <p style="font-size: 0.65rem; color: var(--text-muted); margin-top: 0.2rem; font-weight: 500;">Comprehensive log of authorized personnel deployment intervals.</p>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; align-items: center; background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 100px; border: 1px solid rgba(255,255,255,0.05);">
                        <div style="position: relative;">
                            <i class="fas fa-search" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.75rem;"></i>
                            <input type="text" id="audit-search" placeholder="Search staff member..." style="width: 200px; background: transparent; border: none; padding: 0.5rem 1rem 0.5rem 2.5rem; color: white; outline: none; font-size: 0.75rem; font-weight: 500;" onkeyup="filterAuditTable()">
                        </div>
                        <div style="width: 1px; height: 20px; background: rgba(255,255,255,0.1);"></div>
                        <div style="display: flex; gap: 0.25rem;">
                            <button onclick="filterByTime('all', event)" class="audit-filter-btn active" style="background: var(--primary-color); border: none; color: white; padding: 0.4rem 1rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;">All</button>
                            <button onclick="filterByTime('today', event)" class="audit-filter-btn" style="background: transparent; border: 1px solid transparent; color: var(--text-muted); padding: 0.4rem 1rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="if(!this.classList.contains('active'))this.style.background='transparent'">Today</button>
                            <button onclick="filterByTime('weekly', event)" class="audit-filter-btn" style="background: transparent; border: 1px solid transparent; color: var(--text-muted); padding: 0.4rem 1rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="if(!this.classList.contains('active'))this.style.background='transparent'">Weekly</button>
                            <button onclick="filterByTime('monthly', event)" class="audit-filter-btn" style="background: transparent; border: 1px solid transparent; color: var(--text-muted); padding: 0.4rem 1rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="if(!this.classList.contains('active'))this.style.background='transparent'">Monthly</button>
                            <button onclick="filterByTime('yearly', event)" class="audit-filter-btn" style="background: transparent; border: 1px solid transparent; color: var(--text-muted); padding: 0.4rem 1rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;" onmouseover="this.style.background='rgba(255,255,255,0.05)'" onmouseout="if(!this.classList.contains('active'))this.style.background='transparent'">Yearly</button>
                        </div>
                    </div>
                </div>
                <div style="overflow-x: auto; background: rgba(255,255,255,0.01); border-radius: 12px; border: 1px solid rgba(255,255,255,0.03);">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <th style="padding: 0.75rem 1rem;">Account Member</th>
                                <th style="padding: 0.75rem 1rem;">Classification</th>
                                <th style="padding: 0.75rem 1rem;">Interval Period</th>
                                <th style="padding: 0.75rem 1rem;">Authorized By</th>
                                <th style="padding: 0.75rem 1rem; text-align: right;">Operational Status</th>
                            </tr>
                        </thead>
                        <tbody id="tl-staff-audit-relay">
                            <?php 
                            $history_found = false;
                            foreach ($staff_pto_requests as $req): 
                                if ($req['status'] === 'Pending') continue; 
                                $history_found = true;
                                $status_color = $req['status'] === 'Approved' ? '#10b981' : '#ef4444';
                                $status_bg = $req['status'] === 'Approved' ? 'rgba(16, 185, 129, 0.08)' : 'rgba(239, 68, 68, 0.08)';
                            ?>
                            <tr class="audit-row" data-name="<?php echo strtolower($req['full_name']); ?>" data-start="<?php echo $req['start_date']; ?>" style="border-bottom: 1px solid rgba(255,255,255,0.02); transition: 0.2s;">
                                <td style="padding: 0.85rem 1rem;">
                                    <div style="font-weight: 700; color: white; font-size: 0.8rem;"><?php echo htmlspecialchars($req['full_name'] ?? ''); ?></div>
                                    <div style="font-size: 0.6rem; color: var(--text-muted); opacity: 0.7;">@<?php echo htmlspecialchars($req['staff_username'] ?? ''); ?></div>
                                </td>
                                <td style="padding: 0.85rem 1rem; color: #cbd5e1; font-size: 0.75rem; font-weight: 500;"><?php echo htmlspecialchars($req['leave_type'] ?? ''); ?></td>
                                <td style="padding: 0.85rem 1rem; color: #94a3b8; font-size: 0.7rem; font-weight: 600;">
                                    <?php echo date('M d', strtotime($req['start_date'] ?? '')); ?> - <?php echo date('M d', strtotime($req['end_date'] ?? '')); ?>
                                </td>
                                <td style="padding: 0.85rem 1rem;">
                                    <?php if ($req['approver_name']): ?>
                                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                                            <div style="width: 18px; height: 18px; background: rgba(99, 102, 241, 0.1); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.5rem; color: var(--primary-color); font-weight: 800;"><?php echo strtoupper(substr($req['approver_name'] ?? '', 0, 1)); ?></div>
                                            <span style="font-size: 0.7rem; font-weight: 700; color: #10b981;"><?php echo htmlspecialchars($req['approver_name'] ?? ''); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size: 0.6rem; color: var(--text-muted); font-style: italic;">Auto-System</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 0.85rem 1rem; text-align: right;">
                                    <span style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 0.25rem 0.75rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; border: 1px solid <?php echo str_replace('0.08', '0.15', $status_bg); ?>;">
                                        <?php echo htmlspecialchars($req['status'] ?? ''); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (!$history_found): ?>
                                <tr><td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-muted); font-size: 0.75rem; opacity: 0.6;">No operational leaf history recorded.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PERSONAL PTO MODULE (TEAM LEAD SELF-SERVICE) -->
            <div style="display: grid; grid-template-columns: 1.2fr 1.8fr; gap: 1.5rem;">
                <!-- Request Form Card -->
                <div style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem; box-shadow: 0 15px 40px rgba(0,0,0,0.2);">
                    <div style="margin-bottom: 1.25rem;">
                        <h2 style="font-weight: 800; color: white; font-size: 1rem; letter-spacing: -0.04em;">Request Personal PTO</h2>
                        <p style="color: var(--text-muted); font-size: 0.7rem; margin-top: 0.2rem;">Leadership leave protocol (6.66 HRS/Month) applies.</p>
                    </div>

                    <form id="pto-request-form" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.25rem;">
                        <div class="form-group">
                            <label style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.35rem; display: block;">Start Period</label>
                            <input type="date" name="start_date" min="<?php echo date('Y-m-d'); ?>" style="background: rgba(15, 23, 42, 0.5); border: 1px solid var(--card-border); border-radius: 8px; padding: 0.5rem; color: white; font-weight: 600; outline: none; width: 100%; box-sizing: border-box; font-size: 0.75rem; height: 38px; transition: 0.3s;" required onfocus="this.style.border='1px solid var(--primary-color)'">
                        </div>
                        <div class="form-group">
                            <label style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.35rem; display: block;">End Period</label>
                            <input type="date" name="end_date" min="<?php echo date('Y-m-d'); ?>" style="background: rgba(15, 23, 42, 0.5); border: 1px solid var(--card-border); border-radius: 8px; padding: 0.5rem; color: white; font-weight: 600; outline: none; width: 100%; box-sizing: border-box; font-size: 0.75rem; height: 38px; transition: 0.3s;" required onfocus="this.style.border='1px solid var(--primary-color)'">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.35rem; display: block;">Leave Classification</label>
                            <select name="leave_type" style="background: rgba(15, 23, 42, 0.5); border: 1px solid var(--card-border); border-radius: 8px; padding: 0.5rem 0.75rem; color: white; font-weight: 600; outline: none; width: 100%; box-sizing: border-box; font-size: 0.75rem; height: 40px; cursor: pointer; appearance: none; background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%236366f1%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E'); background-repeat: no-repeat; background-position: right 1rem center; background-size: 0.6rem auto;" required>
                                <option value="" disabled selected>Select leaf classification...</option>
                                <option value="Vacation Leave">Vacation Leave (VL)</option>
                                <option value="Sick Leave">Sick Leave (SL)</option>
                                <option value="Emergency Leave">Emergency Leave (EL)</option>
                                <option value="Bereavement Leave">Bereavement Leave (BL)</option>
                                <option value="Maternity/Paternity Leave">Maternity / Paternity Leave</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.35rem; display: block;">Deployment Justification</label>
                            <textarea name="reason" placeholder="Provide a detailed justification for your leave request..." style="background: rgba(15, 23, 42, 0.5); border: 1px solid var(--card-border); border-radius: 8px; padding: 0.75rem; color: white; font-weight: 500; min-height: 80px; outline: none; resize: none; font-size: 0.75rem; width: 100%; transition: 0.3s;" required onfocus="this.style.border='1px solid var(--primary-color)'"></textarea>
                        </div>
                        <div style="grid-column: span 2; display: flex; justify-content: flex-end; margin-top: 0.5rem;">
                            <button type="submit" style="background: var(--primary-color); border: none; padding: 0.5rem 1.5rem; border-radius: 100px; color: white; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.6rem; transition: 0.4s; box-shadow: 0 10px 20px rgba(99, 102, 241, 0.25); text-transform: uppercase; font-size: 0.725rem; letter-spacing: 0.05em;">
                                <i class="fas fa-paper-plane" style="font-size: 0.75rem;"></i> BROADCAST REQUEST
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Personal Request Status Card -->
                <div style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem; box-shadow: 0 15px 40px rgba(0,0,0,0.2); display: flex; flex-direction: column; animation: fadeInUp 0.5s ease both 0.1s;">
                    <div style="margin-bottom: 1.25rem;">
                        <h2 style="font-weight: 800; color: white; font-size: 1.1rem; letter-spacing: -0.02em; display: flex; align-items: center; gap: 0.75rem;"><i class="fas fa-clock-rotate-left" style="color: var(--primary-color);"></i> My Request Status</h2>
                        <p style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.25rem;">Monitor your personal leave authorization status.</p>
                    </div>

                    <div class="my-request-container" style="flex: 1; max-height: 500px; overflow-y: auto; padding-right: 0.5rem; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent;">
                        <style>
                            /* Custom scrollbar for webkit */
                            #module-pto .my-request-container::-webkit-scrollbar { width: 8px; }
                            #module-pto .my-request-container::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.6); border-radius: 10px; margin-block: 0.5rem; }
                            #module-pto .my-request-container::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
                            #module-pto .my-request-container::-webkit-scrollbar-thumb:hover { background: #64748b; }
                        </style>
                        <?php if (empty($user_pto_requests)): ?>
                            <div style="text-align: center; color: var(--text-muted); padding: 3rem 0; background: rgba(255,255,255,0.02); border-radius: 16px; border: 1px dashed rgba(255,255,255,0.1);">
                                <i class="fas fa-inbox" style="font-size: 2rem; opacity: 0.1; display: block; margin-bottom: 1rem;"></i>
                                <p style="font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">No requests filed.</p>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                                <?php foreach($user_pto_requests as $req): 
                                    $status_color = '#f59e0b'; // Pending
                                    if ($req['status'] === 'Approved') $status_color = '#10b981';
                                    elseif ($req['status'] === 'Denied') $status_color = '#ef4444';
                                ?>
                                <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 1.15rem; transition: 0.3s; border-left: 4px solid <?php echo $status_color; ?>;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.65rem;">
                                        <span style="font-weight: 800; font-size: 0.7rem; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.05em;"><?php echo htmlspecialchars($req['leave_type']); ?></span>
                                        <span style="background: <?php echo $status_color; ?>20; color: <?php echo $status_color; ?>; padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; border: 1px solid <?php echo $status_color; ?>30;">
                                            <?php echo htmlspecialchars($req['status']); ?>
                                        </span>
                                    </div>
                                    <div style="font-size: 0.85rem; color: white; font-weight: 700; margin-bottom: 0.35rem;">
                                        <?php echo date('M d, Y', strtotime($req['start_date'])); ?> - <?php echo date('M d, Y', strtotime($req['end_date'])); ?>
                                    </div>
                                    <div style="font-size: 0.7rem; color: var(--text-muted); line-height: 1.5;">
                                        <i class="fas fa-quote-left" style="font-size: 0.6rem; opacity: 0.3; margin-right: 0.4rem;"></i>
                                        <?php echo htmlspecialchars($req['reason']); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- MODULE: ATTENDANCE (PERSONAL AUDIT) -->
        <section id="module-attendance" class="dashboard-module card" style="display: none; margin-top: 1rem; background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h3 style="font-weight: 800; color: white; font-size: 0.95rem; letter-spacing: -0.02em;">Audit Ledger</h3>
                    <p style="color: var(--text-muted); font-size: 0.65rem; margin-top: 0.2rem;">Detailed history of all deployment logs and session intervals.</p>
                </div>
                <!-- Date Filter (Static for personal history) -->
                <form method="GET" action="dashboard.php" style="display: flex; align-items: flex-end; gap: 1rem;">
                    <input type="hidden" name="view" value="module-attendance">
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <label style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Start Date</label>
                        <input type="date" name="start" value="<?php echo $start_date; ?>" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 0.5rem; color: white;">
                    </div>
                    <button type="submit" style="background: var(--primary-color); border: none; padding: 0.4rem 0.85rem; border-radius: 8px; color: white; cursor: pointer; font-size: 0.75rem;"><i class="fas fa-search"></i></button>
                </form>
            </div>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em;">
                        <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.05);">Personnel</th>
                        <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.05);">Time In & Date</th>
                        <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.05);">Time Out & Date</th>
                        <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.05);">Metrics</th>
                        <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.05); text-align: right;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($personal_ledger)): ?>
                        <tr><td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-muted);">No records found.</td></tr>
                    <?php endif; ?>
                    <?php foreach($personal_ledger as $row): 
                        $s_time = new DateTime($row['time_in']);
                        $e_time = $row['time_out'] ? new DateTime($row['time_out']) : null;
                        $dur = '--:--:--';
                        $pto_val = 0.0000;
                        if ($e_time) {
                            $diff = $s_time->diff($e_time);
                            $dur = $diff->format('%H:%I:%S');
                            $diff_sec = $e_time->getTimestamp() - $s_time->getTimestamp();
                            $pto = number_format(($diff_sec / 3600) * (6.66 / 160), 4);
                        } else {
                            $now = new DateTime();
                            $diff = $s_time->diff($now);
                            $dur = $diff->format('%H:%I:%S');
                            $diff_sec = $now->getTimestamp() - $s_time->getTimestamp();
                            $pto = number_format(($diff_sec / 3600) * (6.66 / 160), 4);
                        }
                    ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                        <td style="padding: 0.75rem 0.85rem;">
                            <div style="display: flex; align-items: center; gap: 0.65rem;">
                                <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.7rem; color: white;"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                                <div>
                                    <div style="font-weight: 700; color: white; font-size: 0.75rem;"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                                    <div style="font-size: 0.55rem; color: var(--text-muted);">Personal</div>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 0.75rem 0.85rem;">
                            <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_in'])); ?></div>
                            <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($row['time_in'])); ?></div>
                        </td>
                        <td style="padding: 0.75rem 0.85rem;">
                            <?php if ($row['time_out']): ?>
                                <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($row['time_out'])); ?></div>
                                <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($row['time_out'])); ?></div>
                            <?php else: ?>
                                <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">ACTIVE</div>
                                <div style="font-size: 0.55rem; color: var(--text-muted);">In Session</div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.75rem 0.85rem;">
                            <div style="color: var(--primary-color); font-weight: 800; font-size: 0.75rem;"><?php echo $dur; ?></div>
                            <div style="font-size: 0.55rem; color: #10b981; font-weight: 700;"><?php echo $pto ?? '0.0000'; ?> <small style="opacity:0.6;">HRS</small></div>
                        </td>
                        <td style="padding: 0.75rem 0.85rem; text-align: right;">
                            <span style="background: <?php echo $row['time_out'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)'; ?>; color: <?php echo $row['time_out'] ? 'var(--accent-green)' : '#f59e0b'; ?>; padding: 0.25rem 0.7rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase;">
                                <?php echo $row['time_out'] ? 'Completed' : 'Online'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- MODULE: STAFF LIST (MONITORING) -->
        <section id="module-staff" class="dashboard-module card" style="display: none; margin-top: 1rem; background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem;">
            <h3 style="font-weight: 800; margin-bottom: 1rem; color: white; font-size: 0.95rem;"><i class="fas fa-users-viewfinder" style="color: var(--primary-color);"></i> Staff Monitoring Engine</h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase;">
                        <th style="padding: 1rem;">Staff Member</th>
                        <th style="padding: 1rem;">Entry Interval & Date</th>
                        <th style="padding: 1rem;">Status</th>
                    </tr>
                </thead>
                <tbody id="staff-monitoring-list">
                    <?php foreach($staff_list as $staff): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                        <td style="padding: 0.65rem 0.85rem; color: white; font-size: 0.75rem;"><?php echo htmlspecialchars($staff['username']); ?></td>
                        <td style="padding: 0.65rem 0.85rem;">
                            <?php if ($staff['time_in']): ?>
                                <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($staff['time_in'])); ?></div>
                                <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo $staff['attendance_date'] ? date('M d, Y', strtotime($staff['attendance_date'])) : 'In Session'; ?></div>
                            <?php else: ?>
                                <div style="color: var(--text-muted); font-size: 0.7rem;">None recorded</div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 0.65rem 0.85rem;">
                            <span style="width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: <?php echo ($staff['time_in'] && !$staff['time_out']) ? 'var(--accent-green)' : 'rgba(255,255,255,0.1)'; ?>;"></span>
                            <span style="font-size: 0.725rem; margin-left: 0.5rem; color: var(--text-muted);"><?php echo ($staff['time_in'] && !$staff['time_out']) ? 'Online' : 'Offline'; ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- MODULE: STAFF AUDIT (REPLACED FROM OPERATIONS) -->
        <section id="module-ops" class="dashboard-module card" style="display: none; margin-top: 1.5rem; background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="font-weight: 800; color: white; font-size: 1.1rem; letter-spacing: -0.04em;">Staff Audit</h2>
                    <p style="color: var(--text-muted); font-size: 0.65rem; margin-top: 0.1rem;">Global ledger of staff deployment and professional performance.</p>
                </div>
                <!-- Date Filter for Global Audit -->
                <form method="GET" style="display: flex; gap: 1rem; align-items: flex-end; background: rgba(30, 41, 59, 0.4); padding: 1.25rem; border-radius: 20px; border: 1px solid var(--card-border); backdrop-filter: blur(10px);">
                    <input type="hidden" name="view" value="module-ops">
                    <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                        <label style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">Start Period</label>
                        <input type="date" name="audit_start" value="<?php echo htmlspecialchars($audit_start); ?>" style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--card-border); border-radius: 10px; padding: 0.6rem 0.75rem; color: white; outline: none; font-size: 0.85rem; font-weight: 600;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                        <label style="font-size: 0.6rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">End Period</label>
                        <input type="date" name="audit_end" value="<?php echo htmlspecialchars($audit_end); ?>" style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--card-border); border-radius: 10px; padding: 0.6rem 0.75rem; color: white; outline: none; font-size: 0.85rem; font-weight: 600;">
                    </div>
                    <button type="submit" style="background: var(--primary-color); color: white; border: none; padding: 0.7rem 1.75rem; border-radius: 10px; font-weight: 800; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.75rem;">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                </form>
            </div>

            <div style="background: rgba(255,255,255,0.01); border: 1px solid rgba(255,255,255,0.03); border-radius: 20px; padding: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--card-border);">
                    <h3 style="font-weight: 800; color: white; font-size: 0.9rem;"><i class="fas fa-list-ul" style="color: var(--primary-color); margin-right: 0.6rem;"></i> Deployment Ledger</h3>
                    <span style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;"><?php echo count($staff_audit_logs); ?> total entries found</span>
                </div>

                <div class="table-container" style="max-height: 500px; overflow-y: auto; overflow-x: auto; padding-right: 0.5rem; scrollbar-width: thin; scrollbar-color: rgba(15, 23, 42, 0.6) transparent;">
                    <style>
                        /* Refined Custom Scrollbar for Deployment Ledger */
                        #module-ops .table-container::-webkit-scrollbar { width: 8px; }
                        #module-ops .table-container::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.4); border-radius: 10px; margin-block: 0.5rem; }
                        #module-ops .table-container::-webkit-scrollbar-thumb { background: #475569; border-radius: 10px; }
                        #module-ops .table-container::-webkit-scrollbar-thumb:hover { background: #64748b; }
                    </style>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="position: sticky; top: 0; background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(10px); z-index: 10; box-shadow: 0 4px 10px -2px rgba(0, 0, 0, 0.3);">
                            <tr style="text-align: left; color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em;">
                            <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--card-border);">Account</th>
                            <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--card-border);">Time In & Date</th>
                            <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--card-border);">Time Out & Date</th>
                            <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--card-border);">Metrics</th>
                            <th style="padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--card-border); text-align: right;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="staff-audit-list">
                        <?php if (empty($staff_audit_logs)): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 4rem; color: var(--text-muted); font-size: 0.9rem;">No operational logs broadcasted for this period.</td></tr>
                        <?php else: ?>
                            <?php foreach ($staff_audit_logs as $log): ?>
                                    <?php 
                                        $s_time = new DateTime($log['time_in']);
                                        $e_time = $log['time_out'] ? new DateTime($log['time_out']) : null;
                                        $dur = '--:--:--';
                                        $pto = '--';
                                        if ($e_time) {
                                            $diff = $s_time->diff($e_time);
                                            $dur = $diff->format('%H:%I:%S');
                                            $diff_sec = $e_time->getTimestamp() - $s_time->getTimestamp();
                                            $pto = number_format(($diff_sec / 3600) * (6.66 / 160), 4);
                                        }
                                    ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                                        <td style="padding: 0.75rem 0.85rem;">
                                            <div style="display: flex; align-items: center; gap: 0.65rem;">
                                                <?php 
                                                    $display_name = !empty($log['user_full_name']) ? $log['user_full_name'] : $log['user_handle'];
                                                    $display_handle = $log['user_handle'] ?? '';
                                                ?>
                                                <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.7rem; color: white;"><?php echo strtoupper(substr($display_name ?? '', 0, 1)); ?></div>
                                                <div>
                                                    <div style="font-weight: 700; color: white; font-size: 0.8rem;"><?php echo htmlspecialchars($display_name ?? ''); ?></div>
                                                    <div style="font-size: 0.6rem; color: var(--text-muted);">@<?php echo htmlspecialchars($display_handle ?? ''); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding: 0.75rem 0.85rem;">
                                            <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($log['time_in'])); ?></div>
                                            <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($log['time_in'])); ?></div>
                                        </td>
                                        <td style="padding: 0.75rem 0.85rem;">
                                            <?php if ($log['time_out']): ?>
                                                <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;"><?php echo date('h:i A', strtotime($log['time_out'])); ?></div>
                                                <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($log['time_out'])); ?></div>
                                            <?php else: ?>
                                                <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">PENDING</div>
                                                <div style="font-size: 0.55rem; color: var(--text-muted);">In Session</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 0.75rem 0.85rem;">
                                            <div style="color: var(--primary-color); font-weight: 800; font-size: 0.75rem;"><?php echo $dur; ?></div>
                                            <div style="font-size: 0.55rem; color: #10b981; font-weight: 700;"><?php echo $pto; ?> <small style="opacity:0.6;">HRS</small></div>
                                        </td>
                                        <td style="padding: 0.75rem 0.85rem; text-align: right;">
                                            <span style="background: <?php echo $log['time_out'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)'; ?>; color: <?php echo $log['time_out'] ? 'var(--accent-green)' : '#f59e0b'; ?>; padding: 0.25rem 0.7rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase;">
                                                <?php echo $log['time_out'] ? 'Completed' : 'Online'; ?>
                                            </span>
                                        </td>
                                    </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </section>

        <!-- MODULE: ATTENDANCE BREACHES (STALE SESSIONS MONITORING) -->
        <section id="module-breaches" class="dashboard-module" style="display: none; margin-top: 1.5rem;">
            <div style="background: linear-gradient(90deg, rgba(239, 68, 68, 0.1), rgba(30, 41, 59, 0.4)); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 20px; padding: 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1.25rem; backdrop-filter: blur(10px);">
                <div style="width: 38px; height: 38px; background: rgba(239, 68, 68, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <h2 style="font-weight: 800; color: white; font-size: 1.1rem; letter-spacing: -0.04em;">Attendance Breaches</h2>
                    <p style="color: var(--text-muted); font-size: 0.65rem; margin-top: 0.15rem;">Monitoring staff members who failed to formally conclude their deployments within 24-hour periods.</p>
                </div>
            </div>

            <div class="card" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1rem;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                            <th style="padding: 0.65rem 0.85rem;">Staff Member</th>
                            <th style="padding: 0.65rem 0.85rem;">Role</th>
                            <th style="padding: 0.65rem 0.85rem;">Deployment Date</th>
                            <th style="padding: 0.65rem 0.85rem;">Time In</th>
                            <th style="padding: 0.65rem 0.85rem;">Breach Status</th>
                            <th style="padding: 0.65rem 0.85rem; text-align: right;">Executive Action</th>
                        </tr>
                    </thead>
                    <tbody id="breaches-list-relay">
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 4rem; color: var(--text-muted);">
                                <i class="fas fa-check-circle" style="display: block; font-size: 2.5rem; margin-bottom: 1rem; opacity: 0.15;"></i>
                                Team session integrity is currently 100%. No breaches require intervention.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- MODULE: ON-GOING LEAVE (ACTIVE ABSENCES) -->
        <section id="module-ongoing-leave" class="dashboard-module card" style="display: none; margin-top: 1.5rem; background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 2rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                <div>
                    <h2 style="font-weight: 800; font-size: 1.1rem; letter-spacing: -0.04em; color: white;">Active Leave Deployment</h2>
                    <p style="color: var(--text-muted); font-size: 0.7rem; margin-top: 0.15rem;">Comprehensive ledger of staff currently on authorized leave protocols.</p>
                </div>
            </div>

            <div class="table-container" style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: var(--text-muted); font-size: 0.55rem; text-transform: uppercase; letter-spacing: 0.1em;">
                            <th style="padding: 0.65rem 0.85rem;">Account Member</th>
                            <th style="padding: 0.65rem 0.85rem;">Role</th>
                            <th style="padding: 0.65rem 0.85rem;">Classification</th>
                            <th style="padding: 0.65rem 0.85rem;">Interval Period</th>
                            <th style="padding: 0.65rem 0.85rem;">Justification</th>
                            <th style="padding: 0.65rem 0.85rem;">Authorized By</th>
                            <th style="padding: 0.65rem 0.85rem; text-align: right;">Authorization</th>
                        </tr>
                    </thead>
                    <tbody id="ongoing-leave-relay">
                        <?php echo $ongoing_leave_html; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- MODULE: SETTINGS (ACCOUNT CONFIGURATION) -->


        <section id="module-settings" class="dashboard-module" style="display: none; margin-top: 1.25rem;">
            <div style="max-width: 800px; margin: 0 auto;">
                <div class="card" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 24px; padding: 2.5rem; backdrop-filter: blur(20px);">
                    <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2.5rem; border-bottom: 1px solid var(--card-border); padding-bottom: 2rem;">
                        <div style="width: 56px; height: 56px; background: rgba(99, 102, 241, 0.1); border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--primary-color); border: 1px solid rgba(99, 102, 241, 0.2);">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 1.1rem; font-weight: 800; color: var(--text-main);">Profile Synchronization</h3>
                            <p style="font-size: 0.75rem; color: var(--text-muted);">Manage your operational identity and security credentials.</p>
                        </div>
                    </div>

                    <!-- Read-Only Account Identifiers -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2.5rem; background: rgba(255,255,255,0.02); padding: 1.25rem; border-radius: 16px; border: 1px solid var(--card-border);">
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Staff ID</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white; letter-spacing: 0.05em;">TL-<?php echo str_pad($tl_info['id'], 4, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Username</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;">@<?php echo htmlspecialchars($tl_info['username']); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Date Joined</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;"><?php echo date('M d, Y', strtotime($tl_info['created_at'])); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--accent-green); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Primary Role</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;">Team Lead</span>
                        </div>
                    </div>

                    <form id="profile-sync-form">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($username); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-input" placeholder="Update your contact email" required>
                            </div>
                        </div>

                        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--card-border); border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;">
                            <h4 style="font-size: 0.7rem; color: var(--primary-color); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1rem; font-weight: 800;">Security Protocol Override</h4>
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
        </section>

    <!-- MODULE: EMAIL COMMUNICATIONS -->
    <section id="module-email" class="dashboard-module" style="display: none; margin-top: 1rem;">
        <div style="display: grid; grid-template-columns: 240px 1fr; gap: 1.5rem; height: calc(100vh - 160px); animation: fadeInUp 0.6s ease both;">
            <!-- Sidebar Folders -->
            <aside style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; position: relative;">
                <button onclick="openComposeWithData()" style="width: 100%; padding: 0.75rem; background: var(--primary-color); color: white; border: none; border-radius: 12px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.6rem; box-shadow: 0 8px 16px rgba(99, 102, 241, 0.2);">
                    <i class="fas fa-pen-nib"></i> Compose
                </button>
                
                <div style="flex: 1; display: flex; overflow: hidden;">
                    <nav id="email-folders-nav" style="flex: 1; display: flex; flex-direction: column; gap: 0.25rem; overflow-y: auto; padding-right: 5px;">
                        <a href="javascript:void(0)" onclick="loadEmails('inbox', this)" class="email-nav-link active" style="display: flex; align-items: center; justify-content: space-between; padding: 0.7rem 0.85rem; background: rgba(99, 102, 241, 0.1); color: var(--primary-color); border-radius: 10px; text-decoration: none; font-size: 0.8rem; font-weight: 700;">
                            <span style="display: flex; align-items: center; gap: 0.75rem;"><i class="fas fa-inbox"></i> Inbox</span>
                            <span id="unread-count-badge" style="background: #ef4444; color: white; font-size: 0.6rem; padding: 0.1rem 0.45rem; border-radius: 100px; display: none; font-weight: 800;">0</span>
                        </a>
                        <a href="javascript:void(0)" onclick="loadEmails('sent', this)" class="email-nav-link" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 0.85rem; color: var(--text-muted); border-radius: 10px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: 0.3s;">
                            <i class="fas fa-paper-plane" style="width: 16px;"></i> Sent
                        </a>
                        <a href="javascript:void(0)" onclick="loadEmails('starred', this)" class="email-nav-link" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 0.85rem; color: var(--text-muted); border-radius: 10px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: 0.3s;">
                            <i class="fas fa-star" style="width: 16px;"></i> Starred
                        </a>
                        <a href="javascript:void(0)" onclick="loadEmails('trash', this)" class="email-nav-link" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 0.85rem; color: var(--text-muted); border-radius: 10px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: 0.3s;">
                            <i class="fas fa-trash-alt" style="width: 16px;"></i> Trash
                        </a>
                    </nav>
                </div>

                <div style="margin-top: auto; padding: 1rem; background: rgba(16, 185, 129, 0.05); border: 1px dashed rgba(16, 185, 129, 0.2); border-radius: 12px; text-align: center;">
                    <i class="fas fa-shield-alt" style="color: var(--accent-green); font-size: 1.2rem; margin-bottom: 0.5rem; display: block;"></i>
                    <span style="display: block; font-size: 0.55rem; color: var(--text-main); font-weight: 800; text-transform: uppercase;">Encrypted Relay</span>
                    <span style="font-size: 0.45rem; color: var(--text-muted);">End-to-End Secure</span>
                </div>
            </aside>

            <!-- Triple Pane Assembly -->
            <div style="flex: 1; display: grid; grid-template-columns: 350px 1fr; gap: 1rem; min-height: 0;">
                <!-- Pane 1: Message Stream -->
                <div style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; display: flex; flex-direction: column; overflow: hidden;">
                    <div style="padding: 1rem; border-bottom: 1px solid var(--card-border); background: rgba(255,255,255,0.01);">
                        <div style="position: relative;">
                            <i class="fas fa-search" style="position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.75rem;"></i>
                            <input type="text" id="email-search-input" placeholder="Filter stream..." style="width: 100%; background: #0f172a; border: 1px solid var(--card-border); border-radius: 10px; padding: 0.5rem 0.5rem 0.5rem 2.25rem; color: white; font-size: 0.7rem; outline: none; transition: 0.3s;" oninput="filterEmailStream(this.value)">
                        </div>
                    </div>
                    <div id="email-list-container" style="flex: 1; overflow-y: auto; padding: 0.5rem;">
                        <div style="padding: 4rem; text-align: center; color: var(--text-muted);">
                            <i class="fas fa-spinner fa-spin" style="font-size: 1.5rem; margin-bottom: 1rem; display: block;"></i>
                            Fetching Logs...
                        </div>
                    </div>
                </div>

                <!-- Pane 2: Cinema Preview -->
                <div id="email-preview-pane" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; display: flex; flex-direction: column; overflow: hidden;">
                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-muted); padding: 3rem; text-align: center;">
                        <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.02); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-bottom: 1.5rem; border: 1px solid rgba(255,255,255,0.05);">
                            <i class="fas fa-envelope-open" style="font-size: 2rem; opacity: 0.2;"></i>
                        </div>
                        <h3 style="font-size: 1rem; font-weight: 800; color: white; margin-bottom: 0.5rem;">Select a communication</h3>
                        <p style="font-size: 0.75rem; max-width: 250px; line-height: 1.5;">Choose a transmission from the stream to decrypt and view the full payload.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    </main>

    <script>
        // High-Performance Module Switching Engine
        function switchModule(moduleId, element) {
            document.querySelectorAll('.dashboard-module').forEach(m => m.style.display = 'none');
            const target = document.getElementById(moduleId);
            if(target) target.style.display = 'block';
            
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            if (element) {
                element.classList.add('active');
            } else {
                const link = document.querySelector(`.nav-link[onclick*="${moduleId}"]`);
                if(link) link.classList.add('active');
            }
            
            const titleMap = {
                'module-home': 'Operational Command',
                'module-attendance': 'Personal Audit Ledger',
                'module-pto': 'PTO Request Dashboard',
                'module-staff': 'Staff Monitoring Engine',
                'module-history': 'Audit Ledger',
                'module-settings': 'Account Settings',
                'module-email': 'Communications Hub',
                'module-breaches': 'Attendance Breaches',
                'module-ongoing-leave': 'Active Leave Deployment'
            };
            const titleRelay = document.getElementById('module-title-broadcast');
            if(titleRelay) titleRelay.innerText = titleMap[moduleId] || 'Command Center';

            const url = new URL(window.location);
            url.searchParams.set('view', moduleId);
            window.history.pushState({}, '', url);

            if (moduleId === 'module-email') {
                loadEmails('inbox');
            }
        }

        // Profile Synchronization Handler
        document.getElementById('profile-sync-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update_profile');

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> SYNCHRONIZING...';

            fetch('update_profile_process.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Ledger Synced',
                        text: data.message,
                        background: '#1e293b',
                        color: '#f8fafc',
                        confirmButtonColor: '#6366f1'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Sync Error',
                        text: data.message,
                        background: '#1e293b',
                        color: '#f8fafc',
                        confirmButtonColor: '#6366f1'
                    });
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire({
                    icon: 'error',
                    title: 'System Error',
                    text: 'Communication failure with server.',
                    background: '#ef4444',
                    color: '#ffffff'
                });
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });

        // Visibility Toggles
        function setupVisibilityToggle(toggleId, inputId) {
            const toggle = document.getElementById(toggleId);
            const input = document.getElementById(inputId);
            if (toggle && input) {
                toggle.addEventListener('click', function() {
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    this.classList.toggle('fa-eye');
                    this.classList.toggle('fa-eye-slash');
                });
            }
        }
        setupVisibilityToggle('toggleSyncNew', 'sync_new_password');
        setupVisibilityToggle('toggleSyncConfirm', 'sync_confirm_password');

        window.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const view = params.get('view');
            if(view) {
                switchModule(view, null);
            }
        });

        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            const shortTimeString = `${hours}:${minutes} ${ampm}`;
            
            if (document.getElementById('digital-clock')) document.getElementById('digital-clock').textContent = timeString;
            if (document.getElementById('header-clock')) document.getElementById('header-clock').textContent = shortTimeString;

            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            document.getElementById('current-date').textContent = now.toLocaleDateString('en-US', options);
        }

        // --- High-Resolution Real-Time Sync Engine ---
        let lastSeenLoginId = 0;
        let lastSeenPtoId = 0;
        let lastSeenAttendanceTime = '<?php echo $latest_att; ?>';
        let lastOwnPtoStatus = {}; // {id: status}
        let isInitialLoad = true;
        let sessionStartTime = <?php echo ($personal_attendance && !$personal_attendance['time_out']) ? "new Date('" . str_replace(' ', 'T', $personal_attendance['time_in']) . "').getTime()" : 'null'; ?>;
        let currentDuration = "<?php echo $total_today ?? '00:00:00'; ?>";
        let isClocking = <?php echo ($personal_attendance && !$personal_attendance['time_out']) ? 'true' : 'false'; ?>;

        function updateDurationTicker() {
            const ticker = document.getElementById('p-duration-ticker');
            if (ticker && sessionStartTime) {
                const now = new Date().getTime();
                const diff = now - sessionStartTime;
                
                const h = Math.floor(diff / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                
                ticker.innerText = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
            }
        }

        // Relay Element Definitions
        const breachesRelay = document.getElementById('breaches-list-relay');
        const ongoingLeaveRelay = document.getElementById('ongoing-leave-relay');
        const activityRelay = document.getElementById('activity-feed-relay');
        const auditRelay = document.getElementById('staff-audit-list');
        const monitoringRelay = document.getElementById('staff-monitoring-list');

        function pollUpdates() {
            fetch('fetch_staff_updates.php')
                .then(response => response.json())
                .then(data => {
                    if (data.error) return;

                    // 1. Core Metrics & Telemetry
                    if (document.getElementById('total-staff-count')) document.getElementById('total-staff-count').innerText = data.total_staff;
                    if (document.getElementById('active-attendance-count')) document.getElementById('active-attendance-count').innerText = data.active_now;
                    if (document.getElementById('completed-today-count')) document.getElementById('completed-today-count').innerText = data.completed_today || 0;
                    if (document.getElementById('pto-pending-count')) {
                        const el = document.getElementById('pto-pending-count');
                        el.innerText = data.pending_staff_pto;
                        el.style.color = data.pending_staff_pto > 0 ? '#f59e0b' : 'white';
                    }
                    if (document.getElementById('global-pto-display')) document.getElementById('global-pto-display').innerHTML = `${data.p_pto} <small style="font-size: 0.6rem; opacity: 0.6;">HRS</small>`;
                    if (document.getElementById('global-total-hours')) document.getElementById('global-total-hours').innerHTML = `${data.p_total_hours} <small style="font-size: 0.6rem; opacity: 0.6;">HRS</small>`;
                    if (document.getElementById('active-leave-count')) document.getElementById('active-leave-count').innerText = data.active_leave_count || 0;
                    if (document.getElementById('cutoff-days-display')) {
                        document.getElementById('cutoff-days-display').innerHTML = `${data.p_cutoff_days} <small style="font-size: 0.6rem; opacity: 0.6;">DAYS</small>`;
                        if (data.p_cutoff_label) {
                            document.getElementById('cutoff-label-broadcast').innerText = `Cutoff Duty (${data.p_cutoff_label})`;
                        }
                    }

                    if (activityRelay) activityRelay.innerHTML = data.activity_feed_html;
                    if (monitoringRelay) monitoringRelay.innerHTML = data.staff_monitoring_html;
                    if (auditRelay) auditRelay.innerHTML = data.staff_audit_html;
                    
                    if (document.getElementById('tl-pto-queue-relay')) document.getElementById('tl-pto-queue-relay').innerHTML = data.tl_pto_queue_html;
                    if (document.getElementById('tl-staff-audit-relay')) {
                        document.getElementById('tl-staff-audit-relay').innerHTML = data.tl_pto_ledger_html;
                        // Synchronize DOM state against local user filter selections
                        if (typeof filterAuditTable === 'function') filterAuditTable();
                        if (typeof filterByTime === 'function') {
                            const activeBtn = document.querySelector('.audit-filter-btn.active');
                            if (activeBtn) filterByTime(activeBtn.innerText.toLowerCase(), null);
                        }
                    }
                    
                    // 1.1 Update Attendance Breaches Sidebar Badge & Table Relay
                    const staleBadge = document.getElementById('stale-sidebar-badge');
                    if (staleBadge) {
                        if (data.stale_incidents && data.stale_incidents.length > 0) {
                            staleBadge.innerText = data.stale_incidents.length;
                            staleBadge.style.display = 'inline-block';
                        } else {
                            staleBadge.style.display = 'none';
                        }
                    }

                    if (breachesRelay && data.stale_incidents) {
                        if (data.stale_incidents.length === 0) {
                            breachesRelay.innerHTML = `
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 4rem; color: var(--text-muted);">
                                        <i class="fas fa-check-circle" style="display: block; font-size: 2.5rem; margin-bottom: 1rem; opacity: 0.15;"></i>
                                        Team session integrity is currently 100%. No breaches require intervention.
                                    </td>
                                </tr>
                            `;
                        } else {
                            let breachHtml = '';
                            data.stale_incidents.forEach(inc => {
                                breachHtml += `
                                    <tr style="border-bottom: 1px solid rgba(239, 68, 68, 0.05);">
                                        <td style="padding: 0.75rem 0.85rem;">
                                            <div style="font-weight: 700; color: white; font-size: 0.75rem;">${inc.user}</div>
                                        </td>
                                        <td style="padding: 0.75rem 0.85rem;">
                                            <span style="font-size: 0.6rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase;">${inc.role}</span>
                                        </td>
                                        <td style="padding: 0.75rem 0.85rem; color: #cbd5e1; font-weight: 600; font-size: 0.7rem;">${inc.date}</td>
                                        <td style="padding: 0.75rem 0.85rem; color: #cbd5e1; font-weight: 600; font-size: 0.7rem;">${inc.time_in}</td>
                                        <td style="padding: 0.75rem 0.85rem;">
                                            ${inc.on_leave ? `
                                                <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 0.35rem;">
                                                    <i class="fas fa-calendar-check"></i> AUTHORIZED LEAVE (By ${inc.approved_by || 'System'})
                                                </div>
                                            ` : `
                                                <span style="background: rgba(239, 68, 68, 0.15); color: #ef4444; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; border: 1px solid rgba(239, 68, 68, 0.2);">UNRESOLVED BREACH</span>
                                            `}
                                        </td>
                                        <td style="padding: 0.75rem 0.85rem; text-align: right;">
                                            <button onclick="forceTimeout(${inc.id}, '${inc.user}')" style="background: var(--accent-red); border: none; color: white; padding: 0.35rem 0.85rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: 0.3s;">
                                                <i class="fas fa-power-off"></i> FORCE TIME OUT
                                            </button>
                                        </td>
                                    </tr>
                                `;
                            });
                            breachesRelay.innerHTML = breachHtml;
                        }
                    }

                    // On-Going Leave Sidebar Badge & Relay
                    if (document.getElementById('ongoing-sidebar-badge')) {
                        document.getElementById('ongoing-sidebar-badge').innerText = data.active_leave_count || 0;
                        document.getElementById('ongoing-sidebar-badge').style.display = data.active_leave_count > 0 ? 'inline-block' : 'none';
                    }
                    if (ongoingLeaveRelay) {
                        ongoingLeaveRelay.innerHTML = data.ongoing_leave_html;
                    }

                    // 1.2 Automated Leave Notification (TL Oversight)
                    if (!isInitialLoad && data.latest_pto_id > lastSeenPtoId) {
                        const Toast = Swal.mixin({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 6000,
                            timerProgressBar: true,
                            background: '#1e293b',
                            color: '#f8fafc',
                            didOpen: (toast) => {
                                toast.addEventListener('mouseenter', Swal.stopTimer)
                                toast.addEventListener('mouseleave', Swal.resumeTimer)
                            }
                        });

                        Toast.fire({
                            icon: 'info',
                            title: 'Staff Leave Filing',
                            text: `${data.latest_pto_name} has submitted a new leave request.`,
                            customClass: { popup: 'animated fadeInRight' }
                        });
                        lastSeenPtoId = data.latest_pto_id;
                    }

                    // 1.3 Automated Attendance Notification (Live Alerts)
                    if (!isInitialLoad && data.latest_attendance_time > lastSeenAttendanceTime) {
                        const icon = data.latest_attendance_type === 'TIME-IN' ? 'success' : 'warning';
                        const title = data.latest_attendance_type === 'TIME-IN' ? 'Shift Alert: Start' : 'Shift Alert: End';
                        const actionText = data.latest_attendance_type === 'TIME-IN' ? 'timed in' : 'timed out';
                        
                        const Toast = Swal.mixin({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 5000,
                            timerProgressBar: true,
                            background: '#1e293b',
                            color: '#f8fafc',
                            didOpen: (toast) => {
                                toast.addEventListener('mouseenter', Swal.stopTimer)
                                toast.addEventListener('mouseleave', Swal.resumeTimer)
                            }
                        });

                        Toast.fire({
                            icon: icon,
                            title: title,
                            text: `${data.latest_attendance_name} has officially ${actionText}.`,
                            customClass: { popup: 'animated fadeInRight' }
                        });
                        lastSeenAttendanceTime = data.latest_attendance_time;
                    }

                    isInitialLoad = false;

                    // 2. PTO Badge & Module Logic
                    const ptoBadge = document.getElementById('pto-pending-badge');
                    if (ptoBadge) {
                        ptoBadge.innerText = data.pending_staff_pto;
                        ptoBadge.style.display = data.pending_staff_pto > 0 ? 'inline-block' : 'none';
                    }

                    // 3. Personal Attendance Control Relay
                    const pRelay = document.getElementById('clock-btn-relay');
                    const sRelay = document.getElementById('attendance-status-relay');
                    if (pRelay) {
                        let html = '';
                        let sHtml = '';
                        if (data.p_stale_session) {
                            html = `
                                <button class="btn-attendance" onclick="processAttendance('time_out')" style="background: #ef4444; border: none; padding: 1.1rem 3.5rem; border-radius: 100px; color: white; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.75rem; transition: 0.4s; box-shadow: 0 10px 25px rgba(239, 68, 68, 0.4); text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.05em;">
                                    <i class="fas fa-exclamation-triangle"></i> RESOLVE STALE SESSION
                                </button>
                            `;
                        } else if (data.p_status === 'OFF-SHIFT') {
                            html = `
                                <button class="btn-attendance" onclick="processAttendance('time_in')" style="background: var(--accent-green); border: none; padding: 1.1rem 3.5rem; border-radius: 100px; color: white; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.75rem; transition: 0.4s; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4); text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.05em;">
                                    <i class="fas fa-sign-in-alt"></i> TIME IN
                                </button>
                            `;
                            sessionStartTime = null;
                        } else if (data.p_status === 'ACTIVE') {
                            html = `
                                <button class="btn-attendance" onclick="processAttendance('time_out')" style="background: #f43f5e; border: none; padding: 1.1rem 3.5rem; border-radius: 100px; color: white; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.75rem; transition: 0.4s; box-shadow: 0 10px 25px rgba(244, 63, 94, 0.4); text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.05em;">
                                    <i class="fas fa-external-link-alt"></i> TIME OUT
                                </button>
                            `;
                            if (data.p_time_in) {
                                sessionStartTime = new Date(data.p_time_in.replace(' ', 'T')).getTime();
                                const timeObj = new Date(data.p_time_in.replace(' ', 'T'));
                                sHtml = `You timed in at ${timeObj.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}`;
                            }
                        } else {
                            html = `<div class="status-badge-green"><i class="fas fa-check-circle"></i> SHIFT COMPLETED</div>`;
                            sessionStartTime = null;
                        }
                        if (pRelay.innerHTML !== html) pRelay.innerHTML = html;
                        if (sRelay) sRelay.innerHTML = sHtml;
                    }

                    // Functional Handlers (Direct Relay Control)
                    window.approvePTO = (id) => togglePTOStatus(id, 'Approved');
                    window.denyPTO = (id) => togglePTOStatus(id, 'Denied');

                    function togglePTOStatus(id, status) {
                        Swal.fire({
                            title: `Authorize Leave?`,
                            text: `Mark this request as definitively ${status}?`,
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonColor: status === 'Approved' ? '#10b981' : '#ef4444',
                            background: '#1e293b',
                            color: '#f8fafc'
                        }).then((res) => {
                            if (res.isConfirmed) {
                                const formData = new FormData();
                                formData.append('id', id);
                                formData.append('status', status);
                                
                                fetch('pto_approval_process.php', { method: 'POST', body: formData })
                                .then(r => r.json())
                                .then(data => {
                                    if (data.success) {
                                        Swal.fire({ icon: 'success', title: 'Synchronized!', text: data.message, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                                        pollUpdates();
                                    } else {
                                        Swal.fire('Operation Error', data.message || 'Check logs.', 'error');
                                    }
                                });
                            }
                        });
                    }

                    // 4. Automated Leave Notification
                    if (!isInitialLoad && data.latest_pto_id > lastSeenPtoId) {
                        const Toast = Swal.mixin({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 6000,
                            timerProgressBar: true,
                            background: '#1e293b',
                            color: '#f8fafc',
                            didOpen: (toast) => {
                                toast.addEventListener('mouseenter', Swal.stopTimer)
                                toast.addEventListener('mouseleave', Swal.resumeTimer)
                            }
                        });

                        Toast.fire({
                            icon: 'info',
                            title: 'New Leave Filing',
                            text: `${data.latest_pto_name} has filed a new leave request.`,
                            customClass: {
                                popup: 'animated fadeInRight'
                            }
                        });
                    }

                    // 4. Automated Attendance Notification (Live Feed Alerts)
                    if (!isInitialLoad && data.latest_attendance_time > lastSeenAttendanceTime) {
                        const icon = data.latest_attendance_type === 'TIME-IN' ? 'success' : 'warning';
                        const title = data.latest_attendance_type === 'TIME-IN' ? 'Shift Started' : 'Shift Ended';
                        const actionText = data.latest_attendance_type === 'TIME-IN' ? 'timed in' : 'timed out';
                        
                        triggerToast(title, `${data.latest_attendance_name} has ${actionText} just now.`, icon);
                    }

                    // 5. Sync State
                    if (data.latest_pto_id > 0) lastSeenPtoId = data.latest_pto_id;
                    if (data.latest_attendance_time) lastSeenAttendanceTime = data.latest_attendance_time;
                    
                    // Personal PTO Status Change Detection
                    if (data.latest_pto_own_id > 0) {
                        const curStatus = data.latest_pto_own_status;
                        const ptoId = data.latest_pto_own_id;
                        if (!isInitialLoad && lastOwnPtoStatus[ptoId] && lastOwnPtoStatus[ptoId] !== curStatus) {
                            const icon = curStatus === 'Approved' ? 'success' : (curStatus === 'Denied' ? 'error' : 'info');
                            Swal.fire({
                                title: 'Personal PTO Updated',
                                text: `Your request (#${ptoId}) is now ${curStatus.toUpperCase()}.`,
                                icon: icon,
                                background: '#1e293b',
                                color: '#f8fafc'
                            });
                        }
                        lastOwnPtoStatus[ptoId] = curStatus;
                    }

                    // Dynamic System Health Feedback
                    const healthSpan = document.getElementById('system-health-status');
                    if (healthSpan) {
                        healthSpan.innerText = 'OPTIMAL';
                        healthSpan.style.color = '#10b981';
                    }

                    isInitialLoad = false;
                })
                .catch(err => console.error('Relay Sync Error:', err));
        }

        function triggerToast(title, text, icon) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 5000,
                timerProgressBar: true,
                background: '#1e293b',
                color: '#f8fafc',
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });

            Toast.fire({
                icon: icon,
                title: title,
                text: text
            });
        }

        // Polling and Clock will be initialized at the end of the script tag

        function processAttendance(type) {
            Swal.fire({
                title: 'Confirm Attendance?',
                text: `You are about to ${type.replace('_', ' ')}.`,
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
                            Swal.fire({ icon: 'success', title: 'Synchronized!', text: data.message, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                            pollUpdates();
                        } else {
                            Swal.fire('Operation Error', data.error || 'Sync failed.', 'error');
                        }
                    });
                }
            });
        }

        function updateDurationTicker() {
            const ticker = document.getElementById('p-duration-ticker');
            if (ticker && sessionStartTime) {
                const now = new Date().getTime();
                const diff = now - sessionStartTime;
                
                const h = Math.floor(diff / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                
                ticker.innerText = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
            }
        }

        // Operational engines will be initialized at script termination

        function forceTimeout(id, user) {
            Swal.fire({
                title: 'Authorize Intervention?',
                text: `Conclude deployment protocol for ${user}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                background: '#1e293b',
                color: '#f8fafc'
            }).then((res) => {
                if (res.isConfirmed) {
                    fetch(`../OM/force_timeout_process.php?id=${id}&ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Breach Resolved', text: data.message, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                            pollUpdates();
                        }
                    });
                }
            });
        }

        // --- STAFF PTO APPROVAL SYSTEM ---
        function handlePTO(requestId, decision) {
            const actionText = decision === 'Approved' ? 'approve' : 'deny';
            const color = decision === 'Approved' ? '#10b981' : '#ef4444';

            Swal.fire({
                title: `${decision.toUpperCase()} REQUEST?`,
                text: `Are you sure you want to ${actionText} this staff leave request?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: color,
                cancelButtonColor: '#1e293b',
                confirmButtonText: `Yes, ${actionText}`
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('id', requestId);
                    formData.append('status', decision);

                    fetch('pto_approval_process.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                                if (data.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'ACTION BROADCASTED',
                                        text: data.message,
                                        background: '#1e293b',
                                        color: '#f8fafc',
                                        confirmButtonColor: '#6366f1',
                                        timer: 1500,
                                        showConfirmButton: false
                                    });
                                    pollUpdates();
                                } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'ACTION FAILED',
                                text: data.message,
                                background: '#1e293b',
                                color: '#f8fafc'
                            });
                        }
                    })
                    .catch(err => {
                        console.error('System error:', err);
                        Swal.fire('ERROR', 'Unable to reach the approval server.', 'error');
                    });
                }
            });
        }

        // Team Lead's own PTO request handler
        const ptoSelfForm = document.getElementById('pto-request-form');
        if (ptoSelfForm) {
            ptoSelfForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> TRANSMITTING...';

                fetch('pto_request_process.php', { // Use local TL process script
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'REQUEST SENT',
                                text: data.message,
                                background: '#1e293b',
                                color: '#f8fafc',
                                confirmButtonColor: '#6366f1',
                                timer: 1500,
                                showConfirmButton: false
                            });
                            ptoSelfForm.reset();
                            pollUpdates();
                        } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'FAILED',
                            text: data.message,
                            background: '#1e293b',
                            color: '#f8fafc'
                        });
                    }
                })
                .finally(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                });
            });
        }

        // --- Audit Filtering Logic ---
        function filterAuditTable() {
            const searchTerm = document.getElementById('audit-search').value.toLowerCase();
            const rows = document.querySelectorAll('.audit-row');
            
            rows.forEach(row => {
                const name = row.getAttribute('data-name');
                if (name.includes(searchTerm)) {
                    row.classList.remove('search-hidden');
                } else {
                    row.classList.add('search-hidden');
                }
                updateRowVisibility(row);
            });
        }

        function filterByTime(period, event) {
            // Update UI buttons
            if (event && event.currentTarget) {
                document.querySelectorAll('.audit-filter-btn').forEach(btn => {
                    btn.classList.remove('active');
                    btn.style.background = 'transparent';
                    btn.style.color = 'var(--text-muted)';
                });
                event.currentTarget.classList.add('active');
                event.currentTarget.style.background = 'var(--primary-color)';
                event.currentTarget.style.color = 'white';
            }

            const now = new Date();
            const rows = document.querySelectorAll('.audit-row');
            
            rows.forEach(row => {
                const startDate = new Date(row.getAttribute('data-start'));
                let match = false;

                if (period === 'all') match = true;
                else if (period === 'today') {
                    match = startDate.toDateString() === now.toDateString();
                } else if (period === 'weekly') {
                    const weekAgo = new Date();
                    weekAgo.setDate(now.getDate() - 7);
                    match = startDate >= weekAgo && startDate <= now;
                } else if (period === 'monthly') {
                    match = startDate.getMonth() === now.getMonth() && startDate.getFullYear() === now.getFullYear();
                } else if (period === 'yearly') {
                    match = startDate.getFullYear() === now.getFullYear();
                }

                if (match) row.classList.remove('time-hidden');
                else row.classList.add('time-hidden');
                
                updateRowVisibility(row);
            });
        }

        function updateRowVisibility(row) {
            if (row.classList.contains('search-hidden') || row.classList.contains('time-hidden')) {
                row.style.display = 'none';
            } else {
                row.style.display = 'table-row';
            }
        }

        // --- EMAIL HUB FUNCTIONALITY ---
        function linkify(text) {
            const div = document.createElement('div');
            div.textContent = text;
            let safeText = div.innerHTML;
            const urlPattern = /(\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|!:,.;]*[-A-Z0-9+&@#\/%=~_|])/ig;
            return safeText.replace(urlPattern, '<a href="$1" target="_blank" style="color: #6366f1; text-decoration: underline; font-weight: 600; cursor: pointer;">$1</a>');
        }

        let currentEmailFolder = 'inbox';

        function loadEmails(folder = 'inbox', element = null) {
            currentEmailFolder = folder;
            const container = document.getElementById('email-list-container');
            if (!container) return;

            // Update Sidebar UI
            if (element) {
                document.querySelectorAll('.email-nav-link').forEach(l => {
                    l.classList.remove('active');
                    l.style.background = 'transparent';
                    l.style.color = 'var(--text-muted)';
                    l.style.fontWeight = '600';
                });
                element.classList.add('active');
                element.style.background = 'rgba(99, 102, 241, 0.1)';
                element.style.color = 'var(--primary-color)';
                element.style.fontWeight = '700';
            }

            fetch(`../email_process.php?action=fetch&folder=${folder}`)
                .then(r => {
                    if (!r.ok) throw new Error(`HTTP Error: ${r.status}`);
                    return r.json();
                })
                .then(data => {
                    if (data.success) {
                        renderEmailList(data.emails || []);
                    } else {
                        container.innerHTML = `<div style="padding: 2rem; color: #ef4444; text-align: center;">${data.message || 'Transmission relay failure'}</div>`;
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    container.innerHTML = `<div style="padding: 2rem; color: #ef4444; text-align: center;">Operational relay offline.</div>`;
                });
        }

        function renderEmailList(emails) {
            const container = document.getElementById('email-list-container');
            if (!container || !emails) return;
            
            container.innerHTML = emails.map(email => `
                <div onclick="viewEmail(${email.id}, this)" class="email-stream-item" style="padding: 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.02); cursor: pointer; transition: 0.2s; background: ${email.is_read == 0 && email.receiver_id == '<?php echo $user_id; ?>' ? 'rgba(99, 102, 241, 0.02)' : 'transparent'};">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span style="font-weight: 800; font-size: 0.75rem; color: ${email.is_read == 0 ? 'white' : 'rgba(255,255,255,0.6)'}; display: flex; align-items: center; gap: 0.5rem;">
                            ${email.is_read == 0 ? '<div style="width: 6px; height: 6px; background: var(--primary-color); border-radius: 50%;"></div>' : ''}
                            <span style="font-size: 0.6rem; color: var(--primary-color); opacity: 0.8; font-weight: 900;">${email.sender_id == '<?php echo $user_id; ?>' ? 'TO:' : 'FROM:'}</span>
                            ${email.participant_name}
                            <span style="font-size: 0.5rem; padding: 0.1rem 0.4rem; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 4px; color: var(--primary-color); text-transform: uppercase; font-weight: 900; letter-spacing: 0.05em;">${email.participant_role}</span>
                        </span>
                        <span style="font-size: 0.6rem; color: var(--text-muted); font-weight: 600;">${email.display_time}</span>
                    </div>
                    <div style="font-weight: 700; font-size: 0.75rem; color: ${email.is_read == 0 ? 'white' : 'rgba(255,255,255,0.4)'}; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${email.subject}</div>
                    <div style="font-size: 0.65rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; opacity: 0.6;">${email.message}</div>
                </div>
            `).join('');
        }

        function viewEmail(id, element = null) {
            // Update UI State in stream
            document.querySelectorAll('.email-stream-item').forEach(i => i.classList.remove('selected'));
            if (element) {
                element.classList.add('selected');
            }

            const previewPane = document.getElementById('email-preview-pane');
            previewPane.innerHTML = `
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                </div>
            `;

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

                                    ${email.attachment_path ? `
                                        <div style="margin-top: 2rem; border-top: 1px solid var(--card-border); padding-top: 1.5rem;">
                                            ${email.attachment_type.startsWith('image/') ? `
                                                <div style="border-radius: 15px; overflow: hidden; border: 1px solid var(--card-border);">
                                                    <img src="../${email.attachment_path}" style="width: 100%; display: block; cursor: zoom-in;" onclick="window.open(this.src)">
                                                    <div style="padding: 0.75rem; background: rgba(0,0,0,0.3); display: flex; justify-content: space-between; align-items: center;">
                                                        <span style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700;"><i class="fas fa-image" style="color: var(--primary-color); margin-right: 0.5rem;"></i> PICTURE RELAY: ${email.attachment_name}</span>
                                                        <a href="../${email.attachment_path}" download="${email.attachment_name}" style="color: var(--primary-color); font-size: 0.75rem; font-weight: 800; text-decoration: none;"><i class="fas fa-save"></i> SAVE PICTURE</a>
                                                    </div>
                                                </div>
                                            ` : email.attachment_type.startsWith('video/') ? `
                                                <div style="border-radius: 15px; overflow: hidden; border: 1px solid var(--card-border);">
                                                    <video controls style="width: 100%; display: block;">
                                                        <source src="../${email.attachment_path}" type="${email.attachment_type}">
                                                        Incompatible codec relay.
                                                    </video>
                                                    <div style="padding: 0.75rem; background: rgba(0,0,0,0.3); display: flex; justify-content: space-between; align-items: center;">
                                                        <span style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700;"><i class="fas fa-video" style="color: var(--primary-color); margin-right: 0.5rem;"></i> VIDEO TRANSMISSION: ${email.attachment_name}</span>
                                                        <a href="../${email.attachment_path}" download="${email.attachment_name}" style="color: var(--primary-color); font-size: 0.75rem; font-weight: 800; text-decoration: none;"><i class="fas fa-save"></i> SAVE VIDEO</a>
                                                    </div>
                                                </div>
                                            ` : `
                                                <div style="padding: 1.5rem; background: rgba(99, 102, 241, 0.03); border: 1px dashed rgba(99, 102, 241, 0.2); border-radius: 15px; display: flex; align-items: center; gap: 1rem;">
                                                    <div style="width: 50px; height: 50px; background: var(--primary-color); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.5rem;">
                                                        <i class="fas fa-file-invoice"></i>
                                                    </div>
                                                    <div style="flex: 1;">
                                                        <div style="font-weight: 800; color: white; font-size: 0.85rem; margin-bottom: 0.15rem; word-break: break-all;">${email.attachment_name}</div>
                                                        <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700;">FILE ANALYTICS • ${Math.round(email.attachment_size/1024)} KB</div>
                                                    </div>
                                                    <a href="../${email.attachment_path}" download="${email.attachment_name}" style="padding: 0.6rem 1rem; background: var(--primary-color); color: white; border-radius: 8px; font-weight: 800; font-size: 0.65rem; text-decoration: none; display: flex; align-items: center; gap: 0.5rem;">
                                                        <i class="fas fa-save"></i> SAVE FILE
                                                    </a>
                                                </div>
                                            `}
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                            <div style="padding: 1.25rem; border-top: 1px solid var(--card-border); background: rgba(0,0,0,0.1);">
                                <div style="display: flex; gap: 0.75rem; align-items: flex-end; background: rgba(255,255,255,0.03); padding: 0.5rem 0.5rem 0.5rem 1.25rem; border-radius: 24px; border: 1px solid var(--card-border); transition: 0.3s;" onfocusin="this.style.borderColor='var(--primary-color)'; this.style.background='rgba(255,255,255,0.05)';" onfocusout="this.style.borderColor='var(--card-border)'; this.style.background='rgba(255,255,255,0.03)';">
                                    <textarea id="quick-reply-message" placeholder="Type your secure response..." style="flex: 1; background: transparent; border: none; padding: 0.75rem 0; color: white; font-size: 0.95rem; outline: none; height: 45px; max-height: 200px; resize: none; font-family: 'Inter', sans-serif; line-height: 1.5; overflow-y: auto; scrollbar-width: none;" oninput="this.style.height = '45px'; this.style.height = (this.scrollHeight) + 'px';"></textarea>
                                    <button onclick="sendQuickReply(${email.participant_id}, \`${email.subject.replace(/`/g, '\\`')}\`)" style="width: 40px; height: 40px; background: var(--primary-color); color: white; border: none; border-radius: 50%; cursor: pointer; transition: 0.3s; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);" onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 6px 15px rgba(99, 102, 241, 0.4)';" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 4px 12px rgba(99, 102, 241, 0.3)';">
                                        <i class="fas fa-paper-plane" style="font-size: 0.9rem; transform: translateX(1px) translateY(-1px);"></i>
                                    </button>
                                </div>
                            </div>
                        `;

                        if (email.is_read == 0 && email.receiver_id == '<?php echo $user_id; ?>') {
                            fetch('../email_process.php', {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: `action=mark_read&id=${id}`
                            }).then(() => {
                                pollEmailCounts();
                                // We don't reload the list here to avoid losing scroll position
                            });
                        }
                    }
                });
        }

        function replyToEmail(receiverId, subject) {
            const cleanSubject = subject.startsWith('RE:') ? subject : 'RE: ' + subject;
            openComposeWithData(receiverId, cleanSubject);
        }

        function sendQuickReply(receiverId, subject) {
            const message = document.getElementById('quick-reply-message').value;
            if (!message.trim()) {
                Swal.fire({ icon: 'warning', title: 'Empty Transmission', text: 'Please enter a message before sending.', background: '#1e293b', color: '#f8fafc' });
                return;
            }

            const cleanSubject = subject.startsWith('RE:') ? subject : 'RE: ' + subject;
            const formData = new FormData();
            formData.append('action', 'send');
            formData.append('receiver_ids', receiverId);
            formData.append('subject', cleanSubject);
            formData.append('message', message);

            fetch('../email_process.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'RELAY SENT', text: 'Your communication has been successfully transmitted.', timer: 1500, showConfirmButton: false, background: '#1e293b', color: '#f8fafc' });
                    document.getElementById('quick-reply-message').value = '';
                    document.getElementById('quick-reply-message').style.height = '45px';
                    if (typeof currentEmailFolder !== 'undefined' && currentEmailFolder === 'sent') {
                        loadEmails('sent');
                    }
                } else {
                    Swal.fire({ icon: 'error', title: 'SYNC FAILED', text: data.error || 'Network relay interrupted.', background: '#1e293b', color: '#f8fafc' });
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
                            title: receiverId ? 'DIRECT SECURE REPLY' : 'SECURE PRIVATE COMMUNICATION',
                            html: `
                                <div style="text-align: left;">
                                    ${receiverId ? `
                                        <div style="margin-bottom: 1.5rem; padding: 0.75rem; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: space-between;">
                                            <div>
                                                <span style="font-size: 0.6rem; color: #94a3b8; font-weight: 800; text-transform: uppercase; display: block; margin-bottom: 0.2rem;">RECIPIENT RELAY:</span>
                                                <span style="font-size: 0.85rem; color: var(--primary-color); font-weight: 800;">${targetUser ? targetUser.full_name : 'Unknown Participant'}</span>
                                            </div>
                                            <span style="font-size: 0.55rem; padding: 0.2rem 0.5rem; background: var(--primary-color); color: white; border-radius: 4px; font-weight: 900;">SECURED</span>
                                        </div>
                                        <select id="swal-receiver" style="display: none;" multiple><option value="${receiverId}" selected></option></select>
                                    ` : `
                                        <label style="display: block; font-size: 0.7rem; color: #94a3b8; font-weight: 800; margin-bottom: 0.5rem;">RECIPIENT(S) - <span style="font-size: 0.55rem; color: var(--primary-color);">Multi-Select (Hold Ctrl/Cmd)</span></label>
                                        <input type="text" id="swal-search" placeholder="Filter targets..." style="width: 100%; margin-bottom: 0.5rem; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 0.4rem; color: white; font-size: 0.75rem;">
                                        <select id="swal-receiver" class="swal2-input custom-relay-select" multiple style="width: 100%; margin: 0 0 1rem 0; background: #0f172a; color: white; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; height: 120px; padding: 0.5rem; scrollbar-width: thin; scrollbar-color: var(--primary-color) #0f172a;">
                                            ${userOptions}
                                        </select>
                                    `}
                                    <label style="display: block; font-size: 0.7rem; color: #94a3b8; font-weight: 800; margin-bottom: 0.5rem;">PROTOCOL SUBJECT</label>
                                    <input id="swal-subject" class="swal2-input" value="${subject}" style="width: 100%; margin: 0 0 1rem 0; background: #0f172a; color: white; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px;" placeholder="Identify transmission...">
                                    <label style="display: block; font-size: 0.7rem; color: #94a3b8; font-weight: 800; margin-bottom: 0.5rem;">PRIVATE MESSAGE BODY</label>
                                    <textarea id="swal-message" class="swal2-textarea" style="width: 100%; margin: 0 0 1rem 0; background: #0f172a; color: white; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; height: 150px;" placeholder="Enter secure payload..."></textarea>
                                    
                                    <input type="file" id="swal-attachment" style="display: none;">
                                    <button type="button" onclick="document.getElementById('swal-attachment').click()" style="width: 100%; padding: 0.75rem; background: rgba(99, 102, 241, 0.03); border: 1px dashed rgba(99, 102, 241, 0.2); border-radius: 10px; color: #94a3b8; font-size: 0.7rem; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.75rem; transition: 0.3s;" onmouseover="this.style.background='rgba(99, 102, 241, 0.08)'" onmouseout="this.style.background='rgba(99, 102, 241, 0.03)'">
                                        <i class="fas fa-image" style="color: var(--primary-color);"></i>
                                        <i class="fas fa-video" style="color: var(--primary-color);"></i>
                                        <i class="fas fa-paperclip" style="color: var(--primary-color);"></i> 
                                        <span id="attachment-name">ATTACH PICTURE/VIDEO/FILE</span>
                                    </button>

                                    <div style="margin-top: 1rem; padding: 0.75rem; background: rgba(16, 185, 129, 0.05); border: 1px dashed rgba(16, 185, 129, 0.2); border-radius: 10px; display: flex; align-items: center; gap: 0.75rem;">
                                        <i class="fas fa-user-shield" style="color: #10b981; font-size: 1rem;"></i>
                                        <span style="font-size: 0.6rem; color: #94a3b8; line-height: 1.4;"><strong>SECURE TUNNEL:</strong> This is a private transmission. Only the designated recipient can view this content.</span>
                                    </div>
                                </div>
                            `,
                            showCancelButton: true,
                            confirmButtonText: 'SEND PRIVATE MESSAGE',
                            confirmButtonColor: '#6366f1',
                            background: '#1e293b',
                            color: '#f8fafc',
                            didOpen: () => {
                                const searchInput = document.getElementById('swal-search');
                                const receiverSelect = document.getElementById('swal-receiver');
                                if (searchInput && receiverSelect) {
                                    searchInput.addEventListener('input', (e) => {
                                        const q = e.target.value.toLowerCase();
                                        Array.from(receiverSelect.options).forEach(opt => {
                                            opt.style.display = opt.innerText.toLowerCase().includes(q) ? '' : 'none';
                                        });
                                    });
                                }

                                const fileInput = document.getElementById('swal-attachment');
                                const nameLabel = document.getElementById('attachment-name');
                                fileInput.addEventListener('change', (e) => {
                                    if(e.target.files.length > 0) {
                                        nameLabel.innerText = 'ATTACHED: ' + e.target.files[0].name;
                                        nameLabel.style.color = 'var(--primary-color)';
                                    } else {
                                        nameLabel.innerText = 'ATTACH RESOURCE (PICTURE/VIDEO/FILE)';
                                        nameLabel.style.color = '#94a3b8';
                                    }
                                });
                            },
                            preConfirm: () => {
                                const select = document.getElementById('swal-receiver');
                                const selectedIds = Array.from(select.selectedOptions).map(opt => opt.value);
                                if (selectedIds.length === 0) {
                                    Swal.showValidationMessage('Selection Required: Identify target recipient(s).');
                                    return false;
                                }
                                return {
                                    receiver_ids: selectedIds.join(','),
                                    subject: document.getElementById('swal-subject').value,
                                    message: document.getElementById('swal-message').value,
                                    attachment: document.getElementById('swal-attachment').files[0]
                                }
                            }
                        }).then((result) => {
                            if (result.isConfirmed) {
                                const formData = new FormData();
                                formData.append('action', 'send');
                                formData.append('receiver_ids', result.value.receiver_ids);
                                formData.append('subject', result.value.subject);
                                formData.append('message', result.value.message);
                                if(result.value.attachment) {
                                    formData.append('attachment', result.value.attachment);
                                }

                                fetch('../email_process.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(r => r.json())
                                .then(data => {
                                    if(data.success) {
                                        triggerToast('DISPATCHED', data.message, 'success');
                                        if(currentEmailFolder === 'sent') loadEmails('sent');
                                    }
                                });
                            }
                        });
                    }
                });
        }

        function toggleStar(id) {
            fetch('../email_process.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=toggle_star&id=${id}`
            }).then(() => loadEmails(currentEmailFolder));
        }

        function deleteEmail(id) {
            Swal.fire({
                title: 'DECOMMISSION MESSAGE?',
                text: "This communication will be moved to the organizational trash archive.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                background: '#1e293b',
                color: '#f8fafc'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('../email_process.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=delete&id=${id}`
                    }).then(() => loadEmails(currentEmailFolder));
                }
            });
        }

        // --- COMPOSE INITIATION ---
        document.querySelector('#module-email button[style*="background: var(--primary-color)"]')?.addEventListener('click', function() {
            openComposeWithData();
        });

        // Initialize if perspective is already email
        if(window.location.search.includes('module-email')) loadEmails('inbox');

        let previousUnreadCount = 0;
        let isFirstPoll = true;

        function pollEmailCounts() {
            fetch('../email_process.php?action=get_unread_count')
                .then(r => r.json())
                .then(data => {
                    const currentCount = parseInt(data.count) || 0;
                    
                    // Trigger notification if new email arrives
                    if (!isFirstPoll && currentCount > previousUnreadCount) {
                        triggerToast('NEW TRANSMISSION', `You have ${currentCount} unread communication(s) in your relay.`, 'info');
                        // Optional: Refresh list if currently in inbox
                        if (typeof currentEmailFolder !== 'undefined' && currentEmailFolder === 'inbox') {
                            loadEmails('inbox');
                        }
                    }

                    const badges = document.querySelectorAll('.main-unread-count-badge, #unread-count-badge');
                    badges.forEach(badge => {
                        if (currentCount > 0) {
                            badge.innerText = currentCount;
                            badge.style.display = 'block';
                        } else {
                            badge.style.display = 'none';
                        }
                    });

                    previousUnreadCount = currentCount;
                    isFirstPoll = false;
                });
        }
        function filterEmailStream(query) {
            const items = document.querySelectorAll('.email-stream-item');
            const q = query.toLowerCase();
            items.forEach(item => {
                const text = item.innerText.toLowerCase();
                item.style.display = text.includes(q) ? 'block' : 'none';
            });
        }
        setInterval(pollEmailCounts, 5000);
        pollEmailCounts();
    </script>
</body>
</html>
