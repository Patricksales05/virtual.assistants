<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';

if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    if ($current_role !== '') {
        if ($current_role === 'admin') {
            header("Location: ../ADMIN/dashboard.php");
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

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$u_data = $stmt->fetch();
$user_name = $u_data['full_name'] ?? $_SESSION['username'];
$role = $_SESSION['role'];

// Handle Form Submission (Add New Member)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
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
        header("Location: dashboard.php?view=users&success=created");
        exit();
    } catch (PDOException $e) {
        header("Location: dashboard.php?view=create-user&error=exists");
        exit();
    }
}

require_once '../accrual_helper.php';
// Robust schema validation (Ensure column existence without non-standard IF NOT EXISTS)
try { $pdo->exec("ALTER TABLE pto_requests ADD approved_by INT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE attendance ADD attendance_date DATE NULL"); } catch (Exception $e) {}

$pto_credits = calculate_realtime_pto($user_id, $pdo);
$total_hours_worked = get_total_cumulative_hours($user_id, $pdo);
$cutoff = get_current_cutoff_dates();
$my_days_worked = get_days_worked_in_cutoff($user_id, $pdo, $cutoff['start'], $cutoff['end']);

// Fetch Operational Stats
try {
    $today = date('Y-m-d');
    
    // Silently perform schema expansion (Authoritative Migration)
    try { $pdo->exec("ALTER TABLE pto_requests ADD approved_by INT NULL"); } catch (Exception $e) {}
    
    $total_employees = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_approved = 1 AND id != ?");
    $total_employees->execute([$_SESSION['user_id']]);
    $total_staff = $total_employees->fetchColumn();
    
    // People currently timed in (active)
    $active_now = $pdo->query("SELECT COUNT(*) FROM attendance WHERE time_out IS NULL")->fetchColumn();
    
    // Completed shifts today
    $completed_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(time_out) = '$today'")->fetchColumn();
    
    // Live feed
    $live_feed = $pdo->query("
        SELECT a.*, u.full_name, u.role, u.username as acc_name 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        ORDER BY a.id DESC LIMIT 6
    ")->fetchAll();

    $latest_att = $pdo->query("SELECT MAX(updated_at) FROM attendance")->fetchColumn() ?: '';

    // Fetch all members for OM oversight (excludes self for operational clarity)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id != ? ORDER BY is_approved ASC, role ASC, full_name ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $staff_members = $stmt->fetchAll();

    // Fetch Global Audit Logs (Reports logic)
    $audit_start = $_GET['audit_start'] ?? date('Y-m-d', strtotime('-7 days'));
    $audit_end = $_GET['audit_end'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name, u.role, u.username as acc_name 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE (COALESCE(a.attendance_date, DATE(a.time_in)) BETWEEN ? AND ? OR DATE(a.time_out) BETWEEN ? AND ? OR a.time_out IS NULL)
        ORDER BY a.id DESC
    ");
    $stmt->execute([$audit_start, $audit_end, $audit_start, $audit_end]);
    $attendance_audit_logs = $stmt->fetchAll();

    // Fetch Pending Approvals count (Accounts)
    $pending_approvals = $pdo->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetchColumn();
    $latest_unapproved_id = $pdo->query("SELECT MAX(id) FROM users WHERE is_approved = 0")->fetchColumn() ?: 0;

    // Fetch Global Pending PTO count
    $pending_pto_count = $pdo->query("SELECT COUNT(*) FROM pto_requests WHERE status = 'Pending'")->fetchColumn();

    // 2. Fetch all organizational PTO requests (Always fetch for SPA module usage)
    $stmt = $pdo->query("
        SELECT p.*, u.full_name, u.username as acc_name, u.role
        FROM pto_requests p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.id DESC
    ");
    $all_pto_requests = $stmt->fetchAll();

    // 3. Fetch Active Ongoing PTO (All Personnel: Staff & TL)
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name, u.username as acc_name, u.role,
               approver.full_name as approver_name
        FROM pto_requests p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN users approver ON p.approved_by = approver.id
        WHERE p.status = 'Approved' 
        AND ? BETWEEN p.start_date AND p.end_date
    ");
    $stmt->execute([$today]);
    $active_leaves = $stmt->fetchAll();
    $active_leave_count = count($active_leaves);

    // Fetch OM's own attendance (Prioritize active sessions)
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $attendance = $stmt->fetch();
    
    if (!$attendance) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND COALESCE(attendance_date, DATE(time_in)) = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id, $today]);
        $attendance = $stmt->fetch();
    }

    // Fetch OM's Personal Attendance History (Ledger)
    $my_history_stmt = $pdo->prepare("SELECT *, COALESCE(attendance_date, DATE(time_in)) as active_date FROM attendance WHERE user_id = ? ORDER BY active_date DESC, id DESC LIMIT 15");
    $my_history_stmt->execute([$user_id]);
    $my_attendance_history = $my_history_stmt->fetchAll();

    // Fetch Payroll Data (All Approved Employees and their hours in current cutoff)
    $payroll_start = $_GET['payroll_start'] ?? $cutoff['start'];
    $payroll_end = $_GET['payroll_end'] ?? $cutoff['end'];

    $payroll_stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, u.username,
               COALESCE((
                   SELECT SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)) 
                   FROM attendance 
                   WHERE user_id = u.id 
                   AND COALESCE(attendance_date, DATE(time_in)) BETWEEN ? AND ? 
                   AND time_out IS NOT NULL
               ), 0) as total_seconds
        FROM users u
        WHERE u.is_approved = 1 AND u.id != ?
        ORDER BY u.full_name ASC
    ");
    $payroll_stmt->execute([$payroll_start, $payroll_end, $user_id]);
    $raw_payroll = $payroll_stmt->fetchAll();

    $payroll_data = [];
    foreach ($raw_payroll as $row) {
        $row['accrued_credits'] = calculate_realtime_pto($row['id'], $pdo);
        $row['leave_count'] = count_approved_leaves($row['id'], $pdo, $payroll_start, $payroll_end);
        $row['days_worked'] = get_days_worked_in_cutoff($row['id'], $pdo, $payroll_start, $payroll_end);
        $payroll_data[] = $row;
    }

    // 4. Organization Alerts (Attendance Breaches Detection)
    $stale_incidents = $pdo->query("
        SELECT a.id, COALESCE(a.attendance_date, DATE(a.time_in)) as attendance_date, a.time_in, a.user_id, u.username, u.full_name, u.role
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.time_out IS NULL 
        AND COALESCE(a.attendance_date, DATE(a.time_in)) < '$today'
        ORDER BY a.id DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $alerts = [];
    foreach($stale_incidents as $incident) {
        $checkLeave = $pdo->prepare("SELECT p.*, approver.full_name as approver_name FROM pto_requests p LEFT JOIN users approver ON p.approved_by = approver.id WHERE p.user_id = ? AND p.status = 'Approved' AND ? BETWEEN p.start_date AND p.end_date");
        $checkLeave->execute([$incident['user_id'], $incident['attendance_date']]);
        $leave = $checkLeave->fetch();

        $alerts[] = [
            'id' => $incident['id'],
            'user' => $incident['full_name'] ?: $incident['username'],
            'role' => $incident['role'],
            'date' => date('M d', strtotime($incident['attendance_date'])),
            'time_in' => date('h:i A', strtotime($incident['time_in'])),
            'on_leave' => $leave ? true : false,
            'approved_by' => $leave ? ($leave['approver_name'] ?: 'System') : ''
        ];
    }

    $active_ids = $pdo->query("SELECT user_id FROM attendance WHERE time_out IS NULL")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $total_staff = 0; $active_now = 0; $completed_today = 0; $live_feed = []; $attendance = null; $pending_approvals = 0; $pending_pto_count = 0; $all_pto_requests = []; $active_leaves = []; $active_leave_count = 0;
}
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
    <!-- jQuery & Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
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

        #quick-reply-message::-webkit-scrollbar { display: none; }
        #quick-reply-message { -ms-overflow-style: none; scrollbar-width: none; }

        /* Unified Deep Midnight Blue Scrollbars */
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

        #email-folders-nav::-webkit-scrollbar, 
        #email-list-container::-webkit-scrollbar,
        #email-detail-content::-webkit-scrollbar {
            width: 4px;
        }

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



        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .sidebar { animation: fadeInUp 0.6s ease-out; }
        .top-header { animation: fadeInUp 0.6s ease-out 0.1s both; }
        .stats-grid { animation: fadeInUp 0.6s ease-out 0.2s both; }
        .attendance-card { animation: fadeInUp 0.6s ease-out 0.3s both; }
        .content-card { animation: fadeInUp 0.6s ease-out 0.4s both; }

        /* Sidebar Styling */
        .sidebar {
            width: 250px;
            background: var(--header-glass);
            border-right: 1px solid var(--card-border);
            padding: 1rem 0.75rem;
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .logo-img {
            max-width: 100%;
            height: auto;
            margin: 0 auto;
            filter: brightness(0) invert(1);
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }

        .logo-container {
            margin-bottom: 2rem;
            display: flex;
            justify-content: center;
            width: 100%;
        }

        .nav-menu {
            list-style: none;
            flex: 1;
            padding: 0 0.5rem;
        }

        .nav-item {
            margin-bottom: 0.35rem;
        }

        .nav-link {
            text-decoration: none;
            color: var(--text-muted);
            padding: 0.55rem 0.85rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 0.8rem;
            white-space: nowrap;
            width: 100%;
            box-sizing: border-box;
        }

        .nav-text {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.03);
            color: white;
            transform: translateX(3px);
        }

        .nav-link.active {
            background: #6366f1;
            color: white;
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
            font-weight: 700;
        }

        .dashboard-module {
            display: none;
            animation: fadeIn 0.4s ease both;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logout-section {
            padding-top: 2rem;
            border-top: 1px solid var(--card-border);
        }

        /* Main Content Styling */
        .main-content {
            flex: 1;
            padding: 1.1rem;
            background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.1) 0%, transparent 50%);
        }

        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .user-pill {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid var(--card-border);
            border-radius: 100px;
            padding: 0.35rem 0.5rem 0.35rem 1.1rem;
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

        @keyframes pulseRed {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); border-color: rgba(239, 68, 68, 0.6); }
            70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); border-color: rgba(239, 68, 68, 0.2); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); border-color: rgba(239, 68, 68, 0.6); }
        }

        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.1); }
            100% { opacity: 1; transform: scale(1); }
        }

        .welcome-msg h2 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.15rem;
        }

        .welcome-msg p {
            color: var(--text-muted);
            font-size: 0.7rem;
        }

        /* Attendance Module */
        .attendance-card { background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem; text-align: center; margin-bottom: 1.25rem; position: relative; overflow: hidden; }
        .digital-clock { font-size: 2.2rem; font-weight: 800; color: white; letter-spacing: -1px; margin-bottom: 0.1rem; }
        .date-display { color: #f8fafc; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1.5rem; }
        .btn-attendance { padding: 0.5rem 1.5rem; border-radius: 100px; border: none; font-size: 0.75rem; font-weight: 800; cursor: pointer; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.6rem; text-transform: uppercase; }
        .btn-time-in { background: #10b981; color: white; border: 1px solid rgba(16, 185, 129, 0.3); }
        .btn-time-out { background: #ef4444; color: white; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-attendance:hover { transform: translateY(-2px); filter: brightness(1.1); }

        .time-box-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            background: rgba(15, 23, 42, 0.4); 
            border: 1px solid var(--card-border); 
            border-radius: 12px; 
            margin-top: 2rem;
            overflow: hidden;
        }
        .time-box { padding: 0.75rem; text-align: center; border-right: 1px solid var(--card-border); }
        .time-box:last-child { border-right: none; }
        .time-label { font-size: 0.5rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; display: block; margin-bottom: 0.25rem; }
        .time-value { font-size: 0.8rem; font-weight: 800; color: white; }

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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-glass);
            backdrop-filter: blur(10px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 0.85rem;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary-color);
        }

        .currency-btn { padding: 0.35rem 0.75rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: transparent; color: var(--text-muted); font-size: 0.65rem; font-weight: 800; cursor: pointer; transition: 0.3s; }
        .currency-btn.active { background: #10b981; color: white; border-color: #10b981; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2); }
        .currency-btn:hover:not(.active) { background: rgba(255,255,255,0.05); color: white; }

        .payout-display { font-family: 'JetBrains Mono', monospace; }

        @media print {
            .sidebar, .top-nav, .currency-btn, #base-rate-input, .fa-sync-alt, button, form, .nav-link, #module-title-broadcast, .user-profile { display: none !important; }
            .dashboard-main { margin-left: 0 !important; padding: 0 !important; width: 100% !important; background: white !important; color: black !important; }
            .card { background: white !important; border: 1px solid #ddd !important; box-shadow: none !important; color: black !important; padding: 0 !important; }
            .dashboard-module { display: none !important; }
            #module-home, #module-users, #module-pto, #module-audit, #module-breaches, #module-ongoing-leave { display: none !important; }
            #module-payroll { display: block !important; margin: 0 !important; width: 100% !important; }
            table { width: 100% !important; border: 1px solid #ddd !important; border-collapse: collapse !important; }
            th, td { border: 1px solid #ddd !important; color: black !important; padding: 8px !important; font-size: 8pt !important; background: transparent !important; }
            .payout-display, .raw-hours, .deduction-display { color: black !important; font-weight: bold !important; }
            h2, p { color: black !important; }
            * { transition: none !important; backdrop-filter: none !important; box-shadow: none !important; }
            body { background: white !important; color: black !important; }
            .hide-on-print { display: none !important; }
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }

        .icon-blue { background: rgba(99, 102, 241, 0.15); color: #6366f1; }
        .icon-green { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .icon-gold { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .icon-red { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        .stat-label {
            color: var(--text-muted);
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 800;
        }

        /* Activity Table */
        .content-card {
            background: var(--card-glass);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 1rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
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
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            padding: 0.65rem 0.85rem;
            border-bottom: 1px solid var(--card-border);
        }

        td {
            padding: 0.65rem 0.85rem;
            border-bottom: 1px solid var(--card-border);
            font-size: 0.75rem;
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

        /* Create User Form Styles */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem; font-weight: 600; text-transform: uppercase; }
        .form-input, .form-select { width: 100%; background: rgba(30, 41, 59, 0.5); border: 1px solid var(--card-border); border-radius: 12px; padding: 0.85rem 1rem; color: white; font-size: 0.95rem; transition: all 0.3s; }
        .form-input:focus { outline: none; border-color: var(--primary-color); background: rgba(30, 41, 59, 0.8); }
        .password-wrapper { position: relative; width: 100%; }
        .password-toggle { position: absolute; right: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); cursor: pointer; transition: color 0.3s; }
        .password-toggle:hover { color: white; }
        .btn-submit { background: var(--primary-color); color: white; border: none; border-radius: 12px; padding: 1rem 2rem; font-size: 1rem; font-weight: 700; cursor: pointer; transition: all 0.3s; width: 100%; margin-top: 2rem; }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.3); }

        @media (max-width: 1024px) {
            .sidebar { width: 80px; padding: 2rem 1rem; }
            .logo-text, .nav-text, .logout-text, .user-details { display: none; }
            .nav-link { justify-content: center; padding: 1rem; }
        }

        /* Settings Specialized UI Components */
        .theme-card { background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 2rem; transition: 0.3s; }
    </style>
</head>
<body style="background-color: #020617;">
    <aside class="sidebar">
        <div class="logo-container">
            <img src="image/ec47d188-a364-4547-8f57-7af0da0fb00a-removebg-preview.png" alt="Logo" class="logo-img">
        </div>

        <ul class="nav-menu">
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-home', this)" class="nav-link active">
                    <i class="fas fa-home"></i>
                    <span class="nav-text">Home</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-users', this)" class="nav-link">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">CREATE ACCOUNT</span>
                    <span id="users-sidebar-badge" style="background: #ef4444; color: white; padding: 0.1rem 0.5rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; display: <?php echo $pending_approvals > 0 ? 'inline-block' : 'none'; ?>;">
                        <?php echo $pending_approvals; ?>
                    </span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-pto', this)" class="nav-link">
                    <i class="fas fa-calendar-check"></i>
                    <span class="nav-text">PTO Requests</span>
                    <span id="pto-sidebar-badge" style="background: #ef4444; color: white; padding: 0.1rem 0.5rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; display: <?php echo $pending_pto_count > 0 ? 'inline-block' : 'none'; ?>;">
                        <?php echo $pending_pto_count; ?>
                    </span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-audit', this)" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    <span class="nav-text">Staff Audit</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-breaches', this)" class="nav-link">
                    <i class="fas fa-exclamation-circle"></i>
                    <span class="nav-text">Attendance Breaches</span>
                    <span id="stale-sidebar-badge" style="background: #ef4444; color: white; padding: 0.1rem 0.5rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; display: <?php echo !empty($alerts) ? 'inline-block' : 'none'; ?>;">
                        <?php echo count($alerts); ?>
                    </span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-ongoing-leave', this)" class="nav-link">
                    <i class="fas fa-calendar-times"></i>
                    <span class="nav-text">On-Going Leave</span>
                    <span style="background: #ef4444; color: white; padding: 0.1rem 0.52rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800;">
                        <?php echo $active_leave_count; ?>
                    </span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-my-attendance', this)" class="nav-link">
                    <i class="fas fa-history"></i>
                    <span class="nav-text">My Attendance</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="javascript:void(0)" onclick="switchModule('module-payroll', this)" class="nav-link">
                    <i class="fas fa-money-check-alt"></i>
                    <span class="nav-text">Payroll Center</span>
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

        <div class="logout-section" style="margin-top:auto; padding: 1.5rem 1rem; border-top: 1px solid rgba(255,255,255,0.05);">
            <a href="logout.php" class="nav-link" style="color: #ef4444; font-weight: 800; display: flex; align-items: center; gap: 1rem; text-decoration: none;">
                <i class="fas fa-sign-out-alt"></i>
                <span class="nav-text">Logout</span>
            </a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div class="page-title">
                <h2 id="module-title-broadcast" style="font-size: 1.15rem; font-weight: 800; letter-spacing: -0.02em;">Operational Overview</h2>
                <p style="color: var(--text-muted); font-size: 0.6rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">Executive Command Center</p>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <!-- Total Worked Hours (Operational Ledger) -->
                <div style="text-align: left; padding: 0.35rem 1.1rem; background: rgba(99, 102, 241, 0.08); border-radius: 100px; border: 1px solid rgba(99, 102, 241, 0.15); backdrop-filter: blur(10px);">
                    <span style="font-size: 0.5rem; color: var(--primary-color); text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; display: block;">Total Worked Hours</span>
                    <span id="global-total-hours" style="font-size: 0.8rem; font-weight: 800; color: white;"><?php echo number_format($total_hours_worked, 2); ?> <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small></span>
                </div>

                <!-- Earned PTO Credits Pill (Definitive Standard) -->
                <div style="text-align: left; padding: 0.35rem 1.1rem; background: rgba(16, 185, 129, 0.08); border-radius: 100px; border: 1px solid rgba(16, 185, 129, 0.15); backdrop-filter: blur(10px);">
                    <span style="font-size: 0.5rem; color: var(--accent-green); text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; display: block;">Accrued Credits</span>
                    <span id="global-pto-display" style="font-size: 0.8rem; font-weight: 800; color: white;"><?php echo number_format($pto_credits, 4); ?> <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small></span>
                </div>

                <div class="user-pill">
                    <div style="text-align: right;">
                        <div id="dynamic-user-name" style="font-weight: 800; font-size: 0.75rem; color: white; line-height: 1.2;"><?php echo htmlspecialchars($user_name); ?></div>
                        <div style="font-size: 0.55rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">OM EXECUTIVE</div>
                    </div>
                    <div id="dynamic-user-initial" class="avatar-small">
                        <?php echo strtoupper(substr($user_name, 0, 1)); ?>
                    </div>
                </div>
            </div>
        </header>

        <section id="module-home" class="dashboard-module" style="display: block;">
            <!-- Stats Grid -->
            <section class="stats-grid" style="margin-top: 1.5rem;">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon icon-blue"><i class="fas fa-address-book"></i></div>
                        <span class="stat-label">Approved Employees</span>
                    </div>
                    <div class="stat-value" id="total-staff-count"><?php echo $total_staff; ?></div>
                </div>
                <!-- ... other stats ... -->
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon icon-green"><i class="fas fa-bolt"></i></div>
                        <span class="stat-label">Active Now</span>
                    </div>
                    <div class="stat-value" id="active-staff-count"><?php echo $active_now; ?></div>
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
                    <div class="stat-value" id="pending-pto-count"><?php echo $pending_pto_count; ?></div>
                    <div style="font-size: 0.6rem; color: var(--text-muted); margin-top: 0.25rem;">REQUIRES OVERSIGHT</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;"><i class="fas fa-walking"></i></div>
                        <span class="stat-label">On Going Leave</span>
                    </div>
                    <div class="stat-value" id="ongoing-leave-count" style="color: #f59e0b;"><?php echo $active_leave_count; ?></div>
                    <div style="font-size: 0.6rem; color: var(--text-muted); margin-top: 0.25rem;">ACTIVE ABSENCES</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1); color: #10b981;"><i class="fas fa-shield-alt"></i></div>
                        <span class="stat-label">System Health</span>
                    </div>
                    <div class="stat-value" style="color: #10b981; font-size: 0.95rem;">ONLINE</div>
                    <div style="font-size: 0.55rem; color: var(--text-muted); margin-top: 0.25rem;">ALL NODES STABLE</div>
                </div>
            </section>

            <!-- PERSONAL ATTENDANCE COMMAND CENTER -->
            <section class="attendance-card" style="padding: 2.5rem 2rem; border-radius: 24px; background: radial-gradient(circle at 50% 0%, rgba(99, 102, 241, 0.1), transparent 70%), var(--card-glass); margin-top: 1.5rem;">
                <div class="digital-clock" id="digital-clock-standard">00:00:00 PM</div>
                <div class="date-display"><?php echo date('l, F j, Y'); ?></div>

                <div id="attendance-status-relay" style="margin-bottom: 1rem;">
                    <?php if (!$attendance || empty($attendance['time_in'])): ?>
                        <button onclick="confirmAttendance('time_in')" class="btn-attendance btn-time-in">
                            <i class="fas fa-sign-in-alt"></i> START DEPLOYMENT
                        </button>
                    <?php elseif (empty($attendance['time_out'])): ?>
                        <button onclick="confirmAttendance('time_out')" class="btn-attendance btn-time-out">
                            <i class="fas fa-sign-out-alt"></i> END DEPLOYMENT
                        </button>
                    <?php else: ?>
                        <div style="padding: 0.6rem 1.75rem; background: rgba(16, 185, 129, 0.1); border-radius: 100px; color: var(--accent-green); font-weight: 800; font-size: 0.75rem; border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 0.6rem;">
                            <i class="fas fa-check-circle"></i> SHIFT COMPLETED
                        </div>
                    <?php endif; ?>
                </div>

                <div class="time-box-grid">
                    <div class="time-box">
                        <span class="time-label">Time In</span>
                        <span class="time-value" id="time-in-display"><?php echo ($attendance && $attendance['time_in']) ? date('h:i A', strtotime($attendance['time_in'])) : '--:--'; ?></span>
                    </div>
                    <div class="time-box">
                        <span class="time-label">Time Out</span>
                        <span class="time-value" id="time-out-display"><?php echo ($attendance && $attendance['time_out']) ? date('h:i A', strtotime($attendance['time_out'])) : '--:--'; ?></span>
                    </div>
                    <div class="time-box">
                        <span class="time-label">Duration</span>
                        <span class="time-value" id="duration-ticker" style="color: #6366f1;">
                            <?php 
                            if ($attendance && $attendance['time_out']) {
                                $sec = (int)$attendance['duration_sec'];
                                $h = (int)($sec / 3600);
                                $m = (int)($sec / 60) % 60;
                                $s = $sec % 60;
                                echo sprintf('%02d:%02d:%02d', $h, $m, $s);
                            } else {
                                echo '00:00:00';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </section>



            <div id="pending-approvals-banner" style="<?php echo $pending_approvals > 0 ? 'display: flex;' : 'display: none;'; ?> background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 12px; padding: 1rem; margin-bottom: 2rem; align-items: center; gap: 1rem; animation: fadeInUp 0.6s ease both;">
                <div style="width: 40px; height: 40px; background: rgba(239, 68, 68, 0.2); color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div style="flex: 1;">
                    <h4 style="font-size: 0.9rem; font-weight: 700; color: #ef4444; margin-bottom: 0.15rem;">Action Required: Pending Approvals</h4>
                    <p id="pending-approvals-text" style="font-size: 0.75rem; color: var(--text-muted);">There are <?php echo $pending_approvals; ?> new account(s) waiting for your activation.</p>
                </div>
                <a href="javascript:void(0)" onclick="switchModule('module-users', null)" style="background: #ef4444; color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; transition: transform 0.3s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">Review Now</a>
            </div>
            <section class="content-card">
                <div class="card-header">
                    <h3 style="font-size: 0.95rem; font-weight: 800;">Live Attendance Feed</h3>
                    <p style="font-size: 0.6rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Real-time floor activity</p>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Role</th>
                                <th>Time In</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="live-attendance-relay">
                            <?php if (empty($live_feed)): ?>
                                <tr><td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-muted);">No recent activity.</td></tr>
                            <?php else: ?>
                                <?php foreach ($live_feed as $activity): ?>
                                    <tr style="transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='transparent'">
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <div class="avatar" style="width: 24px; height: 24px; font-size: 0.6rem;"><?php echo strtoupper(substr($activity['acc_name'], 0, 1)); ?></div>
                                                <div>
                                                    <div style="font-weight: 600; font-size: 0.8rem;"><?php echo htmlspecialchars($activity['full_name']); ?></div>
                                                    <div style="font-size: 0.65rem; color: var(--text-muted);">@<?php echo htmlspecialchars($activity['acc_name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #6366f1;"><?php echo htmlspecialchars($activity['role']); ?></td>
                                        <td style="color: var(--accent-green); font-weight: 600; font-size: 0.8rem;"><?php echo date('h:i A', strtotime($activity['time_in'])); ?></td>
                                        <td>
                                            <?php if ($activity['time_out']): ?>
                                                <span class="badge badge-success" style="font-size: 0.6rem;">Completed</span>
                                            <?php else: ?>
                                                <span class="badge badge-warning" style="background: rgba(99, 102, 241, 0.15); color: #6366f1; font-size: 0.6rem;">Active</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section> <!-- This properly closes module-home -->

        <!-- MODULE: PTO OVERSIGHT (REQUEST QUEUE & AUDIT LEDGER) -->
        <section id="module-pto" class="dashboard-module" style="display: none;">
            <!-- 1. STAFF REQUEST QUEUE (DYNAMIC ACTION CENTER) -->
            <div style="background: rgba(30, 41, 59, 0.4); border: 1px solid var(--card-border); border-radius: 16px; padding: 1rem; margin-bottom: 1.25rem; backdrop-filter: blur(10px);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <div style="width: 30px; height: 30px; background: #6366f1; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; color: white;">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 1rem; font-weight: 800; letter-spacing: -0.02em;">Staff Request Queue</h3>
                            <p style="font-size: 0.6rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Authoritative review of staff deployment leave requests.</p>
                        </div>
                    </div>
                    <div id="pto-pending-badge-relay">
                        <?php if ($pending_pto_count > 0): ?>
                            <div style="background: var(--accent-red); color: white; padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo $pending_pto_count; ?> PENDING</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-container">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="text-align: left; color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                                <th style="padding: 1rem;">Staff Member</th>
                                <th style="padding: 1rem;">Classification</th>
                                <th style="padding: 1rem;">Deployment Interval</th>
                                <th style="padding: 1rem;">Justification</th>
                                <th style="padding: 1rem; text-align: right;">Authorization</th>
                            </tr>
                        </thead>
                        <tbody id="pto-queue-relay">
                            <?php 
                            $pending_requests = array_filter($all_pto_requests, function($r) { return $r['status'] === 'Pending'; });
                            if (empty($pending_requests)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted); opacity: 0.5;">No pending leave deployments found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($pending_requests as $pto): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='transparent'">
                                        <td style="padding: 1.25rem 1rem;">
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <div style="width: 32px; height: 32px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; color: white;">
                                                    <?php echo strtoupper(substr($pto['acc_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 700; color: white;"><?php echo htmlspecialchars($pto['full_name']); ?></div>
                                                    <div style="font-size: 0.65rem; color: var(--text-muted);">@<?php echo htmlspecialchars($pto['acc_name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding: 1rem; color: #6366f1; font-weight: 800; text-transform: uppercase; font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($pto['role']); ?>
                                        </td>
                                        <td style="padding: 1rem; color: white; font-weight: 600; font-size: 0.8rem;">
                                            <?php echo date('M d', strtotime($pto['start_date'])); ?> - <?php echo date('M d', strtotime($pto['end_date'])); ?>
                                        </td>
                                        <td style="padding: 1rem; color: var(--text-muted); font-size: 0.75rem; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($pto['reason']); ?>">
                                            <?php echo htmlspecialchars($pto['reason']); ?>
                                        </td>
                                        <td style="padding: 1rem; text-align: right;">
                                            <div style="display:flex; justify-content: flex-end; gap: 0.75rem;">
                                                <button onclick="handlePTO(<?php echo $pto['id']; ?>, 'Approved')" style="background:#10b981; color:white; border:none; padding:0.4rem 1rem; border-radius:8px; font-weight:800; cursor:pointer; font-size: 0.65rem;">APPROVE</button>
                                                <button onclick="handlePTO(<?php echo $pto['id']; ?>, 'Rejected')" style="background:#ef4444; color:white; border:none; padding:0.4rem 1rem; border-radius:8px; font-weight:800; cursor:pointer; font-size: 0.65rem;">REJECT</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 2. STAFF LEAVE AUDIT LEDGER (CENTRALIZED HISTORY) -->
            <div class="content-card" style="border-radius: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <div style="width: 30px; height: 30px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; color: #6366f1;">
                            <i class="fas fa-history"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 1rem; font-weight: 800; letter-spacing: -0.02em;">Staff Leave Audit Ledger</h3>
                            <p style="font-size: 0.6rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">Log of authorized personnel deployment intervals.</p>
                        </div>
                    </div>

                    <div style="display: flex; gap: 0.75rem; align-items: center;">
                        <div style="position: relative;">
                            <i class="fas fa-search" style="position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.7rem;"></i>
                            <input type="text" id="ledger-search" placeholder="Search..." style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--card-border); border-radius: 100px; padding: 0.45rem 1rem 0.45rem 2.2rem; color: white; font-size: 0.7rem; width: 180px;">
                        </div>
                        <div style="display: flex; background: rgba(15, 23, 42, 0.6); padding: 0.2rem; border-radius: 100px; border: 1px solid var(--card-border);">
                            <button class="ledger-filter-btn active-filter" data-period="all" style="background: #6366f1; color: white; border: none; padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; cursor: pointer;">All</button>
                            <button class="ledger-filter-btn" data-period="today" style="background: transparent; color: var(--text-muted); border: none; padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; cursor: pointer;">Today</button>
                            <button class="ledger-filter-btn" data-period="weekly" style="background: transparent; color: var(--text-muted); border: none; padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; cursor: pointer;">Weekly</button>
                            <button class="ledger-filter-btn" data-period="monthly" style="background: transparent; color: var(--text-muted); border: none; padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; cursor: pointer;">Monthly</button>
                        </div>
                    </div>
                </div>

                <div class="table-container" style="max-height: 500px; overflow-y: auto; padding-right: 0.5rem; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent;">
                    <style>
                        /* Custom scrollbar for webkit */
                        #module-pto .table-container::-webkit-scrollbar { width: 6px; }
                        #module-pto .table-container::-webkit-scrollbar-track { background: transparent; }
                        #module-pto .table-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
                        #module-pto .table-container::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
                    </style>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="position: sticky; top: 0; background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(10px); z-index: 10; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                            <tr style="text-align: left; color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                                <th style="padding: 1rem;">Account Member</th>
                                <th style="padding: 1rem;">Classification</th>
                                <th style="padding: 1rem;">Interval Period</th>
                                <th style="padding: 1rem;">Authorized By</th>
                                <th style="padding: 1rem; text-align: right;">Operational Status</th>
                            </tr>
                        </thead>
                        <tbody id="pto-ledger-relay">
                            <?php 
                            $approved_requests = array_filter($all_pto_requests, function($r) { return $r['status'] !== 'Pending'; });
                            if (empty($approved_requests)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted); opacity: 0.5;">No historical leave logs found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($approved_requests as $pto): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                                        <td style="padding: 1.25rem 1rem;">
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <div style="width: 28px; height: 28px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.65rem; color: white;">
                                                    <?php echo strtoupper(substr($pto['acc_name'], 0, 1)); ?>
                                                </div>
                                                <div style="font-weight: 700; color: white; font-size: 0.8rem;"><?php echo htmlspecialchars($pto['full_name']); ?></div>
                                            </div>
                                        </td>
                                        <td style="padding: 1rem; color: var(--primary-color); font-weight: 700; font-size: 0.75rem;"><?php echo htmlspecialchars($pto['leave_type']); ?></td>
                                        <td style="padding: 1rem; color: #cbd5e1; font-weight: 600; font-size: 0.75rem;">
                                            <?php echo date('M d', strtotime($pto['start_date'])); ?> - <?php echo date('M d', strtotime($pto['end_date'])); ?>
                                        </td>
                                        <td style="padding: 1rem; color: #10b981; font-weight: 800; font-size: 0.75rem;">SYSTEM AUTO</td>
                                        <td style="padding: 1rem; text-align: right;">
                                            <span style="background: <?php echo $pto['status'] === 'Approved' ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; color: <?php echo $pto['status'] === 'Approved' ? '#10b981' : '#ef4444'; ?>; padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase;">
                                                <?php echo strtoupper($pto['status']); ?>
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

        <!-- MODULE: STAFF DIRECTORY & ACCOUNT CREATION -->
        <section id="module-users" class="dashboard-module" style="display: none;">
            <section class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem;">
                    <div>
                        <h2 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 0.15rem;">Personnel Dashboard</h2>
                        <p style="color: var(--text-muted); font-size: 0.7rem;">Manage and monitor your assigned staff tier.</p>
                    </div>
                    <div style="display: flex; gap: 0.85rem; align-items: center;">
                        <div style="display: flex; background: rgba(15, 23, 42, 0.6); padding: 0.2rem; border-radius: 100px; border: 1px solid var(--card-border);">
                            <button id="btn-staff-all" onclick="document.getElementById('staff-directory-relay').className=''; document.getElementById('btn-staff-all').style.background='#6366f1'; document.getElementById('btn-staff-all').style.color='white'; document.getElementById('btn-staff-pending').style.background='transparent'; document.getElementById('btn-staff-pending').style.color='var(--text-muted)';" style="background: #6366f1; color: white; border: none; padding: 0.35rem 1rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; cursor: pointer; transition: 0.3s; margin-right: 0.15rem;">All Accounts</button>
                            <button id="btn-staff-pending" onclick="document.getElementById('staff-directory-relay').className='show-pending'; document.getElementById('btn-staff-pending').style.background='#ef4444'; document.getElementById('btn-staff-pending').style.color='white'; document.getElementById('btn-staff-all').style.background='transparent'; document.getElementById('btn-staff-all').style.color='var(--text-muted)';" style="background: transparent; color: var(--text-muted); border: none; padding: 0.35rem 1rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; cursor: pointer; transition: 0.3s;">Pending Activation <span id="pending-activation-badge" style="display:<?php echo $pending_approvals>0?'inline-block':'none';?>; background:rgba(255,255,255,0.2); padding:0.1rem 0.35rem; border-radius:8px; margin-left:0.25rem;"><?php echo $pending_approvals; ?></span></button>
                        </div>
                        <a href="javascript:void(0)" onclick="switchModule('module-create-user')" style="background: var(--primary-color); color: white; border: none; border-radius: 10px; padding: 0.55rem 1.15rem; font-size: 0.75rem; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.5rem; transition: 0.3s;"><i class="fas fa-user-plus"></i> Add New User</a>
                    </div>
                </div>
                <div class="table-container" style="max-height: 500px; overflow-y: auto; padding-right: 0.5rem; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent;">
                    <style>
                        /* Custom scrollbar for webkit */
                        #module-users .table-container::-webkit-scrollbar { width: 6px; }
                        #module-users .table-container::-webkit-scrollbar-track { background: transparent; }
                        #module-users .table-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
                        #module-users .table-container::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
                        tbody.show-pending tr:not(.is-pending) { display: none !important; }
                    </style>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="position: sticky; top: 0; background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(10px); z-index: 10; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                            <tr style="text-align: left; color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                                <th style="padding: 1rem;">Account</th>
                                <th style="padding: 1rem;">Role</th>
                                <th style="padding: 1rem;">Status</th>
                                <th style="padding: 1rem; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="staff-directory-relay">
                            <?php if (empty($staff_members)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted);">No staff found in XAMPP database.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staff_members as $member): ?>
                                    <?php 
                                        $row_class = !$member['is_approved'] ? 'is-pending' : ''; 
                                        $row_style = !$member['is_approved'] ? 'border-bottom: 1px solid rgba(255,255,255,0.02); background: rgba(239, 68, 68, 0.04); border-left: 3px solid #ef4444;' : 'border-bottom: 1px solid rgba(255,255,255,0.02);'; 
                                    ?>
                                    <tr class="<?php echo $row_class; ?>" style="<?php echo $row_style; ?>">
                                        <td style="padding: 1rem;">
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <div class="avatar" style="width: 32px; height: 32px; font-weight: 700; font-size: 0.8rem; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white;"><?php echo strtoupper(substr($member['username'], 0, 1)); ?></div>
                                                <div>
                                                    <div style="font-weight: 700; color: white; font-size: 0.85rem;"><?php echo htmlspecialchars($member['full_name']); ?></div>
                                                    <div style="font-size: 0.7rem; color: var(--text-muted);">@<?php echo htmlspecialchars($member['username']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.65rem; font-weight: 800; color: #6366f1; text-transform: uppercase;"><?php echo htmlspecialchars($member['role']); ?></span>
                                        </td>
                                         <td>
                                            <?php if ($member['is_approved']): ?>
                                                <span style="color:var(--accent-green); font-weight:800; font-size:0.65rem; text-transform:uppercase;">Approved</span>
                                            <?php else: ?>
                                                <span style="color:#f59e0b; font-weight:800; font-size:0.65rem; text-transform:uppercase;">Pending</span>
                                            <?php endif; ?>
                                            
                                            <?php if (in_array($member['id'], $active_ids)): ?>
                                                <div style="margin-top: 0.2rem; color: #10b981; font-weight: 800; font-size: 0.55rem; text-transform: uppercase; display: flex; align-items: center; gap: 0.3rem;">
                                                    <span style="width: 5px; height: 5px; background: #10b981; border-radius: 50%; animation: pulse 2s infinite;"></span> LIVE SHIFT
                                                </div>
                                            <?php else: ?>
                                                <div style="margin-top: 0.2rem; color: var(--text-muted); font-weight: 700; font-size: 0.55rem; text-transform: uppercase;">OFFLINE</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; justify-content: flex-end; align-items: center; gap: 1rem;">
                                                <?php if (!$member['is_approved']): ?>
                                                    <button onclick="approveUser(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['username']); ?>')" style="background:var(--accent-green); color:white; border:none; padding:0.4rem 0.8rem; border-radius:8px; cursor:pointer; font-size:0.65rem; font-weight:800; text-transform: uppercase;">Approve</button>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted); font-size:0.65rem;">AUTHORIZED</span>
                                                <?php endif; ?>
                                                
                                                <div style="display: flex; gap: 0.4rem;">
                                                    <a href="edit_user.php?id=<?php echo $member['id']; ?>" style="background: rgba(255,255,255,0.05); color: white; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: 0.3s; border: 1px solid rgba(255,255,255,0.1); text-decoration: none;">
                                                        <i class="fas fa-edit" style="font-size: 0.7rem;"></i>
                                                    </a>
                                                    <button onclick="deleteUser(<?php echo $member['id']; ?>)" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: 0.3s; border: 1px solid rgba(239, 68, 68, 0.2); cursor: pointer;">
                                                        <i class="fas fa-trash-alt" style="font-size: 0.7rem;"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- MODULE: ACCOUNT CREATION FORM -->
        <section id="module-create-user" class="dashboard-module" style="display: none;">
            <section class="content-card">
                <div style="margin-bottom: 2rem; display: flex; align-items: center; gap: 1.25rem;">
                    <a href="javascript:void(0)" onclick="switchModule('module-users')" style="color: var(--text-muted); font-size: 1.5rem;"><i class="fas fa-arrow-left"></i></a>
                    <h2 style="font-size: 1.75rem; font-weight: 800;">Add New Member</h2>
                </div>
                <div class="form-card" style="width: 100%; max-width: 850px; margin: 0 auto; background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 24px; padding: 2.5rem;">
                    <form id="create-user-form">
                        <input type="hidden" name="action" value="create_user">
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
            </section>
        </section>

        <!-- MODULE: STAFF AUDIT (REPORTS) -->
        <section id="module-audit" class="dashboard-module" style="display: none;">
            <section class="content-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                    <div>
                        <h2 style="font-size: 1.15rem; font-weight: 800; letter-spacing: -0.04em;">Staff Audit</h2>
                        <p style="color: var(--text-muted); font-size: 0.7rem;">Global ledger of staff deployment and professional performance.</p>
                    </div>
                    
                    <form id="audit-filter-form" style="display: flex; gap: 0.6rem; align-items: flex-end; background: rgba(30, 41, 59, 0.4); padding: 0.5rem 1rem; border-radius: 12px; border: 1px solid var(--card-border); backdrop-filter: blur(10px);">
                        <div style="display: flex; flex-direction: column; gap: 0.15rem;">
                            <label style="font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase;">Start</label>
                            <input type="date" id="audit_start" value="<?php echo htmlspecialchars($audit_start); ?>" style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.4rem; color: white; font-size: 0.7rem;">
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.15rem;">
                            <label style="font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase;">End</label>
                            <input type="date" id="audit_end" value="<?php echo htmlspecialchars($audit_end); ?>" style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--card-border); border-radius: 6px; padding: 0.4rem; color: white; font-size: 0.7rem;">
                        </div>
                        <button type="submit" style="background: var(--primary-color); color: white; border: none; padding: 0.45rem 0.85rem; border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 0.65rem; text-transform: uppercase;"><i class="fas fa-filter"></i></button>
                    </form>
                </div>

                <div class="table-container" style="max-height: 500px; overflow-y: auto; padding-right: 0.5rem; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent;">
                    <style>
                        /* Custom scrollbar for webkit */
                        #module-audit .table-container::-webkit-scrollbar { width: 6px; }
                        #module-audit .table-container::-webkit-scrollbar-track { background: transparent; }
                        #module-audit .table-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }
                        #module-audit .table-container::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
                    </style>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead style="position: sticky; top: 0; background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(10px); z-index: 10; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                            <tr style="color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                                <th style="padding: 1rem; text-align: left;">Account</th>
                                <th style="padding: 1rem; text-align: left;">Time In & Date</th>
                                <th style="padding: 1rem; text-align: left;">Time Out & Date</th>
                                <th style="padding: 1rem; text-align: left;">Metrics</th>
                                <th style="padding: 1rem; text-align: right;">Role</th>
                            </tr>
                        </thead>
                        <tbody id="staff-audit-relay">
                            <?php if (empty($attendance_audit_logs)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 4rem; color: var(--text-muted);">No operational logs found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($attendance_audit_logs as $log): ?>
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
                                        } else {
                                            $now = new DateTime();
                                            $diff = $s_time->diff($now);
                                            $dur = $diff->format('%H:%I:%S');
                                            $diff_sec = $now->getTimestamp() - $s_time->getTimestamp();
                                            $pto = number_format(($diff_sec / 3600) * (6.66 / 160), 4);
                                        }
                                    ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                                        <td style="padding: 1.25rem 1rem;">
                                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                                <div style="width: 32px; height: 32px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; color: white;">
                                                    <?php echo strtoupper(substr($log['acc_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 700; color: white; font-size: 0.85rem;"><?php echo htmlspecialchars($log['full_name']); ?></div>
                                                    <div style="font-size: 0.65rem; color: var(--text-muted);">@<?php echo htmlspecialchars($log['acc_name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="padding: 1.25rem 1rem;">
                                            <div style="color: #f8fafc; font-weight: 800; font-size: 0.85rem;"><?php echo date('h:i A', strtotime($log['time_in'])); ?></div>
                                            <div style="font-size: 0.6rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($log['time_in'])); ?></div>
                                        </td>
                                        <td style="padding: 1.25rem 1rem;">
                                            <?php if ($log['time_out']): ?>
                                                <div style="color: #f8fafc; font-weight: 800; font-size: 0.85rem;"><?php echo date('h:i A', strtotime($log['time_out'])); ?></div>
                                                <div style="font-size: 0.6rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($log['time_out'])); ?></div>
                                            <?php else: ?>
                                                <div style="color: #f59e0b; font-weight: 800; font-size: 0.85rem;">PENDING</div>
                                                <div style="font-size: 0.6rem; color: var(--text-muted);">In Session</div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding: 1.25rem 1rem;">
                                            <div style="color: var(--primary-color); font-weight: 800; font-size: 0.85rem;"><?php echo $dur; ?></div>
                                            <div style="font-size: 0.6rem; color: #10b981; font-weight: 700;"><?php echo $pto; ?> <small style="opacity:0.6;">HRS</small></div>
                                        </td>
                                        <td style="padding: 1.25rem 1rem; text-align: right;">
                                            <span style="background: rgba(99, 102, 241, 0.1); color: var(--primary-color); padding: 0.3rem 0.85rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;"><?php echo $log['role']; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
        <!-- MODULE: ONGOING LEAVE (ACTIVE ABSENCES) -->
        <section id="module-ongoing-leave" class="dashboard-module content-card" style="display: none; margin-top: 1.25rem; background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 16px; padding: 1.1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <div>
                    <h2 style="font-weight: 800; font-size: 1.15rem; letter-spacing: -0.04em;">Active Leave Deployment</h2>
                    <p style="color: var(--text-muted); font-size: 0.7rem; margin-top: 0.15rem;">Comprehensive ledger of staff currently on authorized leave protocols.</p>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Account Member</th>
                            <th>Role</th>
                            <th>Classification</th>
                            <th>Interval Period</th>
                            <th>Justification</th>
                            <th>Authorized By</th>
                            <th style="text-align: right;">Authorization</th>
                        </tr>
                    </thead>
                    <tbody id="ongoing-leave-relay">
                        <?php if (empty($active_leaves)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 4rem; color: var(--text-muted);">
                                    <i class="fas fa-calendar-check" style="display: block; font-size: 2.5rem; margin-bottom: 1rem; opacity: 0.15;"></i>
                                    Zero active leave deployments broadcasted currently.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_leaves as $leave): ?>
                                <tr>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                                            <div style="width: 32px; height: 32px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem; color: white;">
                                                <?php echo strtoupper(substr($leave['acc_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 700; color: white;"><?php echo htmlspecialchars($leave['full_name']); ?></div>
                                                <div style="font-size: 0.7rem; color: var(--text-muted);">@<?php echo htmlspecialchars($leave['acc_name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.75rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase;"><?php echo htmlspecialchars($leave['role']); ?></span>
                                    </td>
                                    <td style="color: var(--primary-color); font-weight: 700;"><?php echo htmlspecialchars($leave['leave_type']); ?></td>
                                    <td style="color: #cbd5e1; font-weight: 600;">
                                        <div style="font-size: 0.8rem;"><?php echo date('M d', strtotime($leave['start_date'])); ?> - <?php echo date('M d', strtotime($leave['end_date'])); ?></div>
                                        <div style="font-size: 0.6rem; color: var(--text-muted);"><?php echo date('Y', strtotime($leave['start_date'])); ?> Ledger</div>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($leave['reason']); ?>">
                                            <?php echo htmlspecialchars($leave['reason']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($leave['approver_name']): ?>
                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                <div style="width: 20px; height: 20px; background: rgba(16, 185, 129, 0.1); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: #10b981; font-weight: 800;"><?php echo strtoupper(substr($leave['approver_name'], 0, 1)); ?></div>
                                                <span style="font-size: 0.75rem; font-weight: 600; color: #10b981;"><?php echo htmlspecialchars($leave['approver_name']); ?></span>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.7rem; color: var(--text-muted); font-style: italic;">Auto-System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <span class="badge badge-success">Approved</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- MODULE: ATTENDANCE BREACHES (STALE SESSIONS MONITORING) -->
        <section id="module-breaches" class="dashboard-module" style="display: none; margin-top: 1.25rem;">
            <div style="background: linear-gradient(90deg, rgba(239, 68, 68, 0.1), rgba(30, 41, 59, 0.4)); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 16px; padding: 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1rem; backdrop-filter: blur(10px);">
                <div style="width: 40px; height: 40px; background: rgba(239, 68, 68, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div>
                    <h2 style="font-weight: 800; color: white; font-size: 1.15rem; letter-spacing: -0.04em;">Attendance Breaches</h2>
                    <p style="color: var(--text-muted); font-size: 0.7rem; margin-top: 0.15rem;">Monitoring personnel who failed to formally conclude their deployments within 24-hour operational periods.</p>
                </div>
            </div>

            <div class="card" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                            <th style="padding: 1rem;">Staff Member</th>
                            <th style="padding: 1rem;">Role</th>
                            <th style="padding: 1rem;">Deployment Date</th>
                            <th style="padding: 1rem;">Time In</th>
                            <th style="padding: 1rem;">Breach Status</th>
                            <th style="padding: 1rem; text-align: right;">Executive Action</th>
                        </tr>
                    </thead>
                    <tbody id="breaches-list-relay">
                        <?php if (empty($alerts)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 4rem; color: var(--text-muted);">
                                    <i class="fas fa-check-circle" style="display: block; font-size: 2.5rem; margin-bottom: 1rem; opacity: 0.15;"></i>
                                    Organizational session integrity is currently 100%. No breaches require executive intervention.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($alerts as $inc): ?>
                                <tr style="border-bottom: 1px solid rgba(239, 68, 68, 0.05);">
                                    <td style="padding: 1rem;">
                                        <div style="font-weight: 700; color: white;"><?php echo htmlspecialchars($inc['user']); ?></div>
                                    </td>
                                    <td style="padding: 1rem;">
                                        <span style="font-size: 0.7rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase;"><?php echo htmlspecialchars($inc['role']); ?></span>
                                    </td>
                                    <td style="padding: 1rem; color: #cbd5e1; font-weight: 600;"><?php echo $inc['date']; ?></td>
                                    <td style="padding: 1rem; color: #cbd5e1; font-weight: 600;"><?php echo $inc['time_in']; ?></td>
                                    <td style="padding: 1rem;">
                                        <?php if ($inc['on_leave']): ?>
                                            <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.65rem; font-weight: 800; border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 0.4rem;">
                                                <i class="fas fa-calendar-check"></i> AUTHORIZED LEAVE (By <?php echo htmlspecialchars($inc['approved_by']); ?>)
                                            </div>
                                        <?php else: ?>
                                            <span style="background: rgba(239, 68, 68, 0.15); color: #ef4444; padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.65rem; font-weight: 800; border: 1px solid rgba(239, 68, 68, 0.2);">UNRESOLVED BREACH</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1rem; text-align: right;">
                                        <button onclick="forceTimeout(<?php echo $inc['id']; ?>, '<?php echo addslashes($inc['user']); ?>')" style="background: var(--accent-red); border: none; color: white; padding: 0.4rem 1.25rem; border-radius: 8px; font-size: 0.65rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: 0.3s; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2);">
                                            <i class="fas fa-power-off"></i> FORCE TIME OUT
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- MY ATTENDANCE MODULE -->
        <section id="module-my-attendance" class="dashboard-module" style="display: none; margin-top: 1.25rem;">
            <div style="background: linear-gradient(90deg, rgba(99, 102, 241, 0.1), rgba(30, 41, 59, 0.4)); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 16px; padding: 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1rem; backdrop-filter: blur(10px);">
                <div style="width: 40px; height: 40px; background: rgba(99, 102, 241, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #6366f1; border: 1px solid rgba(99, 102, 241, 0.3);">
                    <i class="fas fa-history"></i>
                </div>
                <div>
                    <h2 style="font-weight: 800; color: white; font-size: 1.15rem; letter-spacing: -0.04em;">Personal Attendance Ledger</h2>
                    <p style="color: var(--text-muted); font-size: 0.7rem; margin-top: 0.15rem;">Validated session history and deployment telemetry for your executive account.</p>
                </div>
            </div>

            <div class="card" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                            <th style="padding: 1rem;">TIME IN</th>
                            <th style="padding: 1rem;">TIME OUT</th>
                            <th style="padding: 1rem;">Total Duration</th>
                            <th style="padding: 1rem; text-align: right;">Authorization Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_attendance_history as $row): ?>
                            <tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.02);">
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 800; color: white;"><?php echo date('h:i A', strtotime($row['time_in'])); ?></div>
                                    <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($row['time_in'])); ?></div>
                                </td>
                                <td style="padding: 1rem;">
                                    <?php if ($row['time_out']): ?>
                                        <div style="font-weight: 800; color: white;"><?php echo date('h:i A', strtotime($row['time_out'])); ?></div>
                                        <div style="font-size: 0.65rem; color: var(--text-muted); font-weight: 600;"><?php echo date('M d, Y', strtotime($row['time_out'])); ?></div>
                                    <?php else: ?>
                                        <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">PENDING</div>
                                        <div style="font-size: 0.65rem; color: var(--text-muted);">In Session</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem; color: var(--primary-color); font-weight: 700;">
                                    <?php 
                                        if ($row['time_out']) {
                                            $t1 = strtotime($row['time_in']);
                                            $t2 = strtotime($row['time_out']);
                                            $diff = $t2 - $t1;
                                            echo gmdate("H:i:s", $diff);
                                        } else {
                                            echo '<span style="color: #10b981; animation: pulse 2s infinite;">ACTIVE NOW</span>';
                                        }
                                    ?>
                                </td>
                                <td style="padding: 1rem; text-align: right;">
                                    <?php if ($row['time_out']): ?>
                                        <span class="badge badge-success" style="font-size: 0.6rem;">VALIDATED</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="background: rgba(99, 102, 241, 0.15); color: #6366f1; font-size: 0.6rem;">LIVE SESSION</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($my_attendance_history)): ?>
                            <tr><td colspan="5" style="text-align:center; padding: 4rem; color: var(--text-muted);">No attendance records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>


        <!-- MODULE: SETTINGS (ACCOUNT CONFIGURATION) -->


        <section id="module-settings" class="dashboard-module" style="display: none; margin-top: 1.25rem;">
            <div style="max-width: 800px; margin: 0 auto;">
                <!-- Profile Identity Section -->
                <div class="theme-card">
                    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--card-border);">
                        <div style="width: 48px; height: 48px; background: rgba(99, 102, 241, 0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--primary-color); font-size: 1.2rem;">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <div>
                            <h3 style="font-size: 1.1rem; font-weight: 800; color: var(--text-main);">Profile Synchronization</h3>
                            <p style="font-size: 0.75rem; color: var(--text-muted);">Manage your operational identity and security credentials.</p>
                        </div>
                    </div>

                    <!-- Read-Only Account Identifiers -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; background: rgba(255,255,255,0.02); padding: 1.25rem; border-radius: 16px; border: 1px solid var(--card-border);">
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Staff ID</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white; letter-spacing: 0.05em;">OM-<?php echo str_pad($u_data['id'], 4, '0', STR_PAD_LEFT); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Username</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;">@<?php echo htmlspecialchars($u_data['username']); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--text-muted); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Date Joined</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;"><?php echo date('M d, Y', strtotime($u_data['created_at'])); ?></span>
                        </div>
                        <div style="text-align: left;">
                            <span style="display: block; font-size: 0.5rem; color: var(--accent-green); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.25rem;">Primary Role</span>
                            <span style="font-size: 0.85rem; font-weight: 700; color: white;">Operations Manager</span>
                        </div>
                    </div>

                    <form id="profile-sync-form">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                            <div class="form-group">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-input" value="<?php echo htmlspecialchars($user_name); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($u_data['email'] ?? ''); ?>" required>
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

                        <button type="submit" class="btn-submit" style="width: auto; padding: 0.8rem 2.5rem; background: linear-gradient(135deg, var(--primary-color), #818cf8);">Synchronize Profile</button>
                    </form>
                </div>
            </div>
        </section>

        <!-- PAYROLL CENTER MODULE -->
        <section id="module-payroll" class="dashboard-module" style="display: none; margin-top: 1.25rem;">
            <div style="background: linear-gradient(90deg, rgba(16, 185, 129, 0.1), rgba(30, 41, 59, 0.4)); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 16px; padding: 1rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1rem; backdrop-filter: blur(10px);">
                <div style="width: 40px; height: 40px; background: rgba(16, 185, 129, 0.2); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3);">
                    <i class="fas fa-calculator"></i>
                </div>
                <div style="flex: 1;">
                    <h2 style="font-weight: 800; color: white; font-size: 1.15rem; letter-spacing: -0.04em;">Executive Payroll Hub</h2>
                    <p style="color: var(--text-muted); font-size: 0.7rem; margin-top: 0.15rem;">Selected auditing range: <span style="color: #10b981; font-weight: 800;"><?php echo date('M d', strtotime($payroll_start)); ?> - <?php echo date('M d, Y', strtotime($payroll_end)); ?></span></p>
                </div>

                <!-- Calendar Filters (AJAXified) -->
                <div id="payroll-filter-form" style="display: flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.03); padding: 0.35rem 0.75rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                    <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 0.5rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800; margin-bottom: 2px;">Range Start</span>
                        <input type="date" id="p_start_input" value="<?php echo $payroll_start; ?>" style="background: transparent; border: none; color: white; font-size: 0.7rem; font-weight: 700; outline: none;">
                    </div>
                    <div style="width: 1px; height: 20px; background: rgba(255,255,255,0.1); margin: 0 5px;"></div>
                    <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 0.5rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800; margin-bottom: 2px;">Range End</span>
                        <input type="date" id="p_end_input" value="<?php echo $payroll_end; ?>" style="background: transparent; border: none; color: white; font-size: 0.7rem; font-weight: 700; outline: none;">
                    </div>
                    <button type="button" onclick="pollOMUpdates()" style="background: var(--primary-color); border: none; color: white; padding: 0.4rem 0.6rem; border-radius: 8px; cursor: pointer; margin-left: 5px;">
                        <i class="fas fa-sync-alt" style="font-size: 0.7rem;"></i>
                    </button>
                </div>

                <button onclick="window.print()" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: white; padding: 0.5rem 1rem; border-radius: 12px; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='rgba(255,255,255,0.05)'">
                    <i class="fas fa-print"></i>
                    <span style="font-size: 0.7rem; font-weight: 800; text-transform: uppercase;">Print Report</span>
                </button>
                
                <div style="display: flex; align-items: center; gap: 1rem; background: rgba(255,255,255,0.03); padding: 0.5rem 1rem; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05);">
                    <div style="text-align: right;">
                        <span style="display:block; font-size: 0.5rem; color: var(--text-muted); text-transform: uppercase; font-weight: 800;">Daily Rate</span>
                        <input type="number" id="base-rate-input" value="100.00" step="10" style="background: transparent; border: none; color: white; font-weight: 800; font-size: 0.85rem; width: 80px; outline: none; text-align: right;">
                    </div>
                    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.1);"></div>
                    <div style="display: flex; gap: 0.35rem;">
                        <button onclick="setCurrency('PHP', 1)" class="currency-btn active" id="btn-php">PHP</button>
                        <button onclick="setCurrency('USD', 0.018)" class="currency-btn" id="btn-usd">USD</button>
                        <button onclick="setCurrency('EUR', 0.016)" class="currency-btn" id="btn-eur">EUR</button>
                    </div>
                </div>
            </div>

            <div class="card" style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.5rem; position: relative;">
                <!-- Tactical Scroll Engine Arrows -->
                <div style="position: absolute; right: 10px; top: 120px; bottom: 30px; width: 6px; display: flex; flex-direction: column; align-items: center; gap: 0.75rem; z-index: 100;">
                    <button onclick="scrollPayroll('up')" style="width: 32px; height: 32px; border-radius: 10px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); color: var(--primary-color); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: 0.4s; margin-left: -13px; box-shadow: 0 4px 15px rgba(0,0,0,0.4);" onmouseover="this.style.background='var(--primary-color)'; this.style.color='white'; this.style.transform='scale(1.1)'" onmouseout="this.style.background='rgba(99, 102, 241, 0.1)'; this.style.color='var(--primary-color)'; this.style.transform='scale(1)'">
                        <i class="fas fa-caret-up"></i>
                    </button>
                    <div style="flex: 1; width: 2px; background: linear-gradient(to bottom, transparent, rgba(99,102,241,0.2), transparent); margin-left: -13px;"></div>
                    <button onclick="scrollPayroll('down')" style="width: 32px; height: 32px; border-radius: 10px; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); color: var(--primary-color); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; transition: 0.4s; margin-left: -13px; box-shadow: 0 4px 15px rgba(0,0,0,0.4);" onmouseover="this.style.background='var(--primary-color)'; this.style.color='white'; this.style.transform='scale(1.1)'" onmouseout="this.style.background='rgba(99, 102, 241, 0.1)'; this.style.color='var(--primary-color)'; this.style.transform='scale(1)'">
                        <i class="fas fa-caret-down"></i>
                    </button>
                </div>
                <!-- Table Search Engine -->
                <div style="margin-bottom: 1.25rem; position: relative; max-width: 320px;">
                    <i class="fas fa-search" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.75rem;"></i>
                    <input type="text" id="payroll-search" oninput="filterPayrollTable()" placeholder="Search employee name or username..." style="width: 100%; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 0.65rem 1rem 0.65rem 2.5rem; color: white; font-size: 0.75rem; outline: none; transition: 0.3s;" onfocus="this.style.borderColor='var(--primary-color)'; this.style.background='rgba(255,255,255,0.05)';" onblur="this.style.borderColor='rgba(255,255,255,0.08)'; this.style.background='rgba(255,255,255,0.03)';">
                </div>

                <div id="payroll-scroll-container" style="max-height: 550px; overflow-y: auto; overflow-x: hidden; scroll-behavior: smooth; padding-right: 0.5rem;">
                    <style>
                        #payroll-scroll-container::-webkit-scrollbar { width: 6px; }
                        #payroll-scroll-container::-webkit-scrollbar-track { background: transparent; }
                        #payroll-scroll-container::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.05); border-radius: 10px; }
                        #payroll-scroll-container::-webkit-scrollbar-thumb:hover { background: var(--primary-color); }
                    </style>
                    <table id="payroll-main-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="text-align: left; color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.1em; border-bottom: 1px solid var(--card-border);">
                            <th style="padding: 1rem;">Employee</th>
                            <th style="padding: 1rem;">Role</th>
                            <th style="padding: 1rem;">Total Time</th>
                            <th style="padding: 1rem;">Days Worked</th>
                            <th style="padding: 1rem;">Leaves Taken</th>
                            <th style="padding: 1rem;">Remaining Credits</th>
                            <th style="padding: 1rem;">Deductions</th>
                            <th style="padding: 1rem;">Net Payout</th>
                            <th style="padding: 1rem; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="payroll-relay">
                        <?php foreach ($payroll_data as $row): ?>
                            <tr class="payroll-row" style="border-bottom: 1px solid rgba(255, 255, 255, 0.02);">
                                <td style="padding: 1rem;">
                                    <div class="searchable-name" style="font-weight: 700; color: white;"><?php echo htmlspecialchars($row['full_name']); ?></div>
                                    <span class="searchable-user" style="font-size: 0.6rem; color: var(--text-muted);">@<?php echo htmlspecialchars($row['username']); ?></span>
                                </td>
                                <td style="padding: 1rem;">
                                    <span style="font-size: 0.65rem; color: #cbd5e1; font-weight: 700; text-transform: uppercase;"><?php echo htmlspecialchars($row['role']); ?></span>
                                </td>
                                <td style="padding: 1rem; color: white; font-weight: 800;">
                                    <span class="raw-hours" data-seconds="<?php echo $row['total_seconds']; ?>" data-leaves="<?php echo $row['leave_count']; ?>" data-days="<?php echo $row['days_worked']; ?>">
                                        <?php 
                                            $h = floor($row['total_seconds'] / 3600);
                                            $m = floor(($row['total_seconds'] % 3600) / 60);
                                            echo sprintf("%02d:%02d", $h, $m);
                                        ?>
                                    </span>
                                </td>
                                <td style="padding: 1rem; color: var(--primary-color); font-weight: 800;">
                                    <?php echo $row['days_worked']; ?> <small style="font-size: 0.55rem; opacity: 0.6;">DAYS</small>
                                </td>
                                <td style="padding: 1rem; color: #ef4444; font-weight: 800;">
                                    <?php echo $row['leave_count']; ?> <small style="font-size: 0.55rem; opacity: 0.6;">DAYS</small>
                                </td>
                                <td style="padding: 1rem; color: #f59e0b; font-weight: 800;">
                                    <?php echo number_format($row['accrued_credits'], 4); ?> <small style="font-size: 0.55rem; opacity: 0.6;">CREDITS</small>
                                </td>
                                <td style="padding: 1rem; color: #ef4444; font-weight: 800;">
                                    <span class="deduction-display">--</span>
                                </td>
                                <td style="padding: 1rem;">
                                    <span class="payout-display" style="font-weight: 800; color: #10b981; font-size: 0.95rem;">--</span>
                                </td>
                                <td style="padding: 1rem; text-align: right;">
                                    <button onclick="openPayslip(<?php echo $row['id']; ?>)" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05)); border: 1px solid rgba(16, 185, 129, 0.2); color: #10b981; padding: 0.4rem 0.8rem; border-radius: 8px; cursor: pointer; transition: 0.4s; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.6rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);" onmouseover="this.style.background='var(--accent-green)'; this.style.color='white'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 15px -3px rgba(16, 185, 129, 0.3)'" onmouseout="this.style.background='linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05))'; this.style.color='#10b981'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0, 0, 0, 0.1)'">
                                        <i class="fas fa-print" style="font-size: 0.75rem; opacity: 0.8;"></i>
                                        <span>Print Payslip</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payroll_data)): ?>
                            <tr><td colspan="5" style="text-align:center; padding: 4rem; color: var(--text-muted);">No personnel telemetry available for this cutoff.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        </section>

        <!-- MODULE: EMAIL COMMUNICATIONS -->
        <section id="module-email" class="dashboard-module" style="display: none; margin-top: 1rem;">
            <div style="display: grid; grid-template-columns: 240px 1fr; gap: 1.5rem; height: calc(100vh - 160px); animation: fadeInUp 0.6s ease both;">
                <!-- Sidebar Folders -->
                <!-- Sidebar Folders -->
                <aside style="background: var(--card-glass); border: 1px solid var(--card-border); border-radius: 20px; padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem; position: relative;">
                    <button onclick="openComposeWithData()" style="width: 100%; padding: 0.75rem; background: var(--primary-color); color: white; border: none; border-radius: 12px; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 0.6rem; box-shadow: 0 8px 16px rgba(99, 102, 241, 0.2);">
                        <i class="fas fa-pen-nib"></i> Compose
                    </button>
                    
                    <div style="flex: 1; display: flex; overflow: hidden;">
                        <nav id="email-folders-nav" style="flex: 1; display: flex; flex-direction: column; gap: 0.5rem; overflow-y: auto; padding-right: 5px;">
                            <a href="javascript:void(0)" onclick="loadEmails('inbox', this)" class="email-nav-link active" style="display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1rem; background: rgba(99, 102, 241, 0.1); color: var(--primary-color); border-radius: 12px; text-decoration: none; font-size: 0.8rem; font-weight: 700; transition: 0.3s;">
                                <span style="display: flex; align-items: center; gap: 0.75rem;"><i class="fas fa-inbox"></i> Inbox</span>
                                <span id="unread-count-badge" style="background: #ef4444; color: white; font-size: 0.6rem; padding: 0.1gram 0.45rem; border-radius: 100px; display: none; font-weight: 800;">0</span>
                            </a>
                            <a href="javascript:void(0)" onclick="loadEmails('sent', this)" class="email-nav-link" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: var(--text-muted); border-radius: 12px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: 0.3s; border: 1px solid transparent;">
                                <i class="fas fa-paper-plane" style="width: 16px;"></i> Sent
                            </a>
                            <a href="javascript:void(0)" onclick="loadEmails('starred', this)" class="email-nav-link" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: var(--text-muted); border-radius: 12px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: 0.3s; border: 1px solid transparent;">
                                <i class="fas fa-star" style="width: 16px;"></i> Starred
                            </a>
                            <a href="javascript:void(0)" onclick="loadEmails('trash', this)" class="email-nav-link" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: var(--text-muted); border-radius: 12px; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: 0.3s; border: 1px solid transparent;">
                                <i class="fas fa-trash-alt" style="width: 16px;"></i> Trash
                            </a>
                        </nav>
                    </div>

                    <div style="margin-top: auto; padding: 1rem; background: rgba(99, 102, 241, 0.03); border: 1px dashed rgba(99, 102, 241, 0.1); border-radius: 15px; text-align: center;">
                        <i class="fas fa-fingerprint" style="color: var(--primary-color); font-size: 1.5rem; margin-bottom: 0.5rem; display: block; opacity: 0.5;"></i>
                        <span style="display: block; font-size: 0.5rem; color: white; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">Secure Protocol</span>
                        <span style="font-size: 0.45rem; color: var(--text-muted);">Cloud Relay Active</span>
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


    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>

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

            // Persistence: Update URL without reload
            const viewName = moduleId.replace('module-', '');
            const url = new URL(window.location);
            url.searchParams.set('view', viewName);
            window.history.replaceState({}, '', url);

            // Update Header Title
            const titleRelay = document.getElementById('module-title-broadcast');
            if (titleRelay) {
                const titles = {
                    'module-home': 'Operational Overview',
                    'module-users': 'Personnel Dashboard',
                    'module-create-user': 'Add New Member',
                    'module-pto': 'PTO Oversight',
                    'module-audit': 'Staff Audit Logs',
                    'module-breaches': 'Attendance Breach Report',
                    'module-ongoing-leave': 'On-Going Leaves',
                    'module-my-attendance': 'Personal Ledger',
                    'module-payroll': 'Payroll Center',
                    'module-email': 'Communications Hub',
                    'module-settings': 'Account Settings'
                };
                titleRelay.innerText = titles[moduleId] || 'Operations Dashboard';
            }

            // Action-Specific Initialization
            if (moduleId === 'module-email') {
                loadEmails('inbox');
            }
        }

        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            
            // Priority 1: Direct module param or payroll filters
            if (urlParams.get('module') === 'payroll' || urlParams.has('payroll_start')) {
                switchModule('module-payroll');
            } 
            else if (urlParams.has('audit_start')) {
                switchModule('module-audit');
            }
            // Priority 2: Standard view param
            else if (urlParams.get('view')) {
                const v = urlParams.get('view');
                switchModule(`module-${v}`);
            }
            // Default
            else {
                switchModule('module-home');
            }
            updatePayouts();
        };

        function filterPayrollTable() {
            const query = document.getElementById('payroll-search').value.toLowerCase();
            document.querySelectorAll('.payroll-row').forEach(row => {
                const name = row.querySelector('.searchable-name').innerText.toLowerCase();
                const user = row.querySelector('.searchable-user').innerText.toLowerCase();
                if (name.includes(query) || user.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        let liveTimeIn = '<?php echo $attendance ? $attendance['time_in'] : ''; ?>';
        let liveTimeOut = '<?php echo $attendance ? $attendance['time_out'] : ''; ?>';
        
        function updateClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
            const clockEl = document.getElementById('digital-clock-standard');
            if (clockEl) clockEl.textContent = timeStr.toUpperCase();
            
            // Ticker Logic
            if (liveTimeIn && !liveTimeOut) {
                const start = new Date(liveTimeIn);
                const diff = now - start;
                const hh = String(Math.floor(diff / 3600000)).padStart(2, '0');
                const mm = String(Math.floor((diff % 3600000) / 60000)).padStart(2, '0');
                const ss = String(Math.floor((diff % 60000) / 1000)).padStart(2, '0');
                const tickerEl = document.getElementById('duration-ticker');
                if (tickerEl) tickerEl.innerText = `${hh}:${mm}:${ss}`;
            }
        }

        function confirmAttendance(action) {
            Swal.fire({
                title: action === 'time_in' ? 'Start Deployment?' : 'End Deployment?',
                text: "Synchronizing session telemetry with organization ledger.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: action === 'time_in' ? '#10b981' : '#ef4444',
                background: '#0f172a',
                color: '#f8fafc'
            }).then((res) => {
                if (res.isConfirmed) {
                    fetch(`attendance_process.php?action=${action}&ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Synchronized!', text: data.message, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                            pollOMUpdates();
                        }
                    });
                }
            });
        }

        let currentCurrency = 'PHP';
        let currentRate = 1;

        function setCurrency(symbol, rate) {
            currentCurrency = symbol;
            currentRate = rate;
            
            document.querySelectorAll('.currency-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(`btn-${symbol.toLowerCase()}`).classList.add('active');
            
            updatePayouts();
        }

        function openPayslip(userId) {
            const start = document.getElementById('p_start_input').value;
            const end = document.getElementById('p_end_input').value;
            const rate = document.getElementById('base-rate-input').value;
            window.open(`print_payslip.php?user_id=${userId}&start=${start}&end=${end}&rate=${rate}&currency=${currentCurrency}&conversion=${currentRate}`, '_blank');
        }

        function updatePayouts() {
            const dailyRate = parseFloat(document.getElementById('base-rate-input').value) || 0;
            const currencyIcons = { 'PHP': '₱', 'USD': '$', 'EUR': '€' };
            const icon = currencyIcons[currentCurrency];

            document.querySelectorAll('.payroll-row').forEach(row => {
                const hourEl = row.querySelector('.raw-hours');
                const deductionEl = row.querySelector('.deduction-display');
                const payoutEl = row.querySelector('.payout-display');
                
                if (hourEl && payoutEl) {
                    const days = parseInt(hourEl.dataset.days) || 0;
                    const leaves = parseInt(hourEl.dataset.leaves) || 0;
                    
                    // Fiscal Logic: Base pay minus leave penalty
                    const basePay = days * dailyRate * currentRate;
                    const creditDeductionValue = leaves * dailyRate * currentRate;
                    const netPayout = Math.max(0, basePay - creditDeductionValue);

                    if (deductionEl) {
                        deductionEl.innerText = leaves > 0 ? `-${icon}${creditDeductionValue.toLocaleString(undefined, {minimumFractionDigits: 2})}` : '--';
                        deductionEl.style.color = leaves > 0 ? '#ef4444' : 'var(--text-muted)';
                    }

                    payoutEl.innerText = `${icon}${netPayout.toLocaleString(undefined, {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    })}`;
                }
            });
        }

        document.getElementById('base-rate-input')?.addEventListener('input', updatePayouts);


        // Profile Synchronization Handler
        document.getElementById('profile-sync-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update_profile');

            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerText;
            submitBtn.disabled = true;
            submitBtn.innerText = 'SYNCING LEDGER...';

            fetch('update_profile_process.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                const swalBg = '#1e293b';
                const swalColor = '#f8fafc';

                if (data.success) {
                        Swal.fire({ 
                        icon: 'success', 
                        title: 'Protocol Synced', 
                        text: data.message, 
                        background: swalBg, 
                        color: swalColor,
                        confirmButtonColor: '#6366f1'
                    }).then(() => pollOMUpdates());
                } else {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Sync Failed', 
                        text: data.message, 
                        background: swalBg, 
                        color: swalColor 
                    });
                }
            })
            .catch(err => {
                console.error(err);
                Swal.fire({ icon: 'error', title: 'System Fail', text: 'Communication fault with organizational ledger.' });
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerText = originalText;
            });
        });

        // Settings Password Visibility Toggles
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

        let lastSeenPtoId = <?php echo !empty($all_pto_requests) ? $all_pto_requests[0]['id'] : 0; ?>;
        let lastSeenAttendanceTime = '<?php echo $latest_att; ?>';
        let lastSeenUserId = <?php echo $latest_unapproved_id; ?>;
        let isInitialLoad = true;
        let seenStaleIncidents = new Set();
        let ledgerSearch = '';
        let ledgerPeriod = 'all';
        let searchTimeout;

        function pollOMUpdates() {
            const s = document.getElementById('audit_start')?.value || '';
            const e = document.getElementById('audit_end')?.value || '';
            const ps = document.getElementById('p_start_input')?.value || '';
            const pe = document.getElementById('p_end_input')?.value || '';
            
            fetch(`fetch_om_updates.php?audit_start=${s}&audit_end=${e}&ledger_search=${encodeURIComponent(ledgerSearch)}&ledger_period=${ledgerPeriod}&payroll_start=${ps}&payroll_end=${pe}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) return;

                    // 0. Stale Incident Organizational Alerts
                    if (data.stale_incidents && data.stale_incidents.length > 0) {
                        data.stale_incidents.forEach(incident => {
                            if (!seenStaleIncidents.has(incident.id)) {
                                const Toast = Swal.mixin({
                                    toast: true,
                                    position: 'top-end',
                                    showConfirmButton: false,
                                    timer: 10000,
                                    timerProgressBar: true,
                                    background: '#ef4444',
                                    color: '#ffffff',
                                    iconColor: '#ffffff'
                                });
                                Toast.fire({
                                    icon: incident.on_leave ? 'info' : 'error',
                                    title: incident.on_leave ? 'AUTHORIZED LEAVE' : 'ATTENDANCE BREACH',
                                    text: `${incident.user} had an ${incident.on_leave ? 'authorized' : 'unresolved'} deployment on ${incident.date}.`
                                });
                                seenStaleIncidents.add(incident.id);
                            }
                        });
                    }

                    // 0.1 Update Attendance Breaches Sidebar Badge & Table Relay
                    const staleBadge = document.getElementById('stale-sidebar-badge');
                    if (staleBadge) {
                        if (data.stale_incidents && data.stale_incidents.length > 0) {
                            staleBadge.innerText = data.stale_incidents.length;
                            staleBadge.style.display = 'inline-block';
                        } else {
                            staleBadge.style.display = 'none';
                        }
                    }

                    const breachesRelay = document.getElementById('breaches-list-relay');
                    if (breachesRelay && data.stale_incidents) {
                        if (data.stale_incidents.length === 0) {
                            breachesRelay.innerHTML = `
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 4rem; color: var(--text-muted);">
                                        <i class="fas fa-check-circle" style="display: block; font-size: 2.5rem; margin-bottom: 1rem; opacity: 0.15;"></i>
                                        Organizational session integrity is currently 100%. No breaches detected.
                                    </td>
                                </tr>
                            `;
                        } else {
                            let breachHtml = '';
                            data.stale_incidents.forEach(inc => {
                                breachHtml += `
                                    <tr style="border-bottom: 1px solid rgba(239, 68, 68, 0.05);">
                                        <td style="padding: 1rem;">
                                            <div style="font-weight: 700; color: white;">${inc.user}</div>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <span style="font-size: 0.7rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase;">${inc.role}</span>
                                        </td>
                                        <td style="padding: 1rem; color: #cbd5e1; font-weight: 600;">${inc.date}</td>
                                        <td style="padding: 1rem; color: #cbd5e1; font-weight: 600;">${inc.time_in}</td>
                                        <td style="padding: 1rem;">
                                            ${inc.on_leave ? `
                                                <div style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.65rem; font-weight: 800; border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 0.4rem;">
                                                    <i class="fas fa-calendar-check"></i> AUTHORIZED LEAVE (By ${inc.approved_by || 'System'})
                                                </div>
                                            ` : `
                                                <span style="background: rgba(239, 68, 68, 0.15); color: #ef4444; padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.65rem; font-weight: 800; border: 1px solid rgba(239, 68, 68, 0.2);">UNRESOLVED BREACH</span>
                                            `}
                                        </td>
                                        <td style="padding: 1rem; text-align: right;">
                                            <button onclick="forceTimeout(${inc.id}, '${inc.user}')" style="background: var(--accent-red); border: none; color: white; padding: 0.4rem 1.25rem; border-radius: 8px; font-size: 0.65rem; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: 0.3s; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.2);">
                                                <i class="fas fa-power-off"></i> FORCE TIME OUT
                                            </button>
                                        </td>
                                    </tr>
                                `;
                            });
                            breachesRelay.innerHTML = breachHtml;
                        }
                    }

                    // 1. Core Metrics & Telemetry High-Speed Injection
                    if (document.getElementById('total-staff-count')) document.getElementById('total-staff-count').innerText = (data.total_staff || 0).toLocaleString();
                    if (document.getElementById('active-staff-count')) document.getElementById('active-staff-count').innerText = (data.active_now || 0).toLocaleString();
                    if (document.getElementById('completed-today-count')) document.getElementById('completed-today-count').innerText = (data.completed_today || 0).toLocaleString();
                    if (document.getElementById('pending-pto-count')) document.getElementById('pending-pto-count').innerText = (data.pending_pto || 0).toLocaleString();
                    if (document.getElementById('ongoing-leave-count')) document.getElementById('ongoing-leave-count').innerText = (data.active_leave_count || 0).toLocaleString();
                    
                    // 2. Fragment Injections (No-Refresh Table Updates)
                    if (document.getElementById('live-attendance-relay')) document.getElementById('live-attendance-relay').innerHTML = data.feed_html;
                    if (document.getElementById('pto-queue-relay')) document.getElementById('pto-queue-relay').innerHTML = data.pto_queue_html;
                    if (document.getElementById('pto-ledger-relay')) document.getElementById('pto-ledger-relay').innerHTML = data.pto_ledger_html;
                    if (document.getElementById('staff-audit-relay')) document.getElementById('staff-audit-relay').innerHTML = data.audit_relay_html;
                    if (document.getElementById('ongoing-leave-relay')) document.getElementById('ongoing-leave-relay').innerHTML = data.ongoing_leave_html;
                    if (document.getElementById('staff-directory-relay')) document.getElementById('staff-directory-relay').innerHTML = data.staff_directory_html;
                    if (document.getElementById('payroll-relay')) {
                        document.getElementById('payroll-relay').innerHTML = data.payroll_html;
                        updatePayouts(); // Recalculate fiscal logic after relay sync
                    }

                    // 2.5 Personal Identity Sync (Zero-Refresh Name/Avatar)
                    if (document.getElementById('dynamic-user-name')) document.getElementById('dynamic-user-name').innerText = data.u_name;
                    if (document.getElementById('dynamic-user-initial')) document.getElementById('dynamic-user-initial').innerText = data.u_initial;

                    // 3. Personal Metrics (Attendance Relay Sync)
                    const pRelay = document.getElementById('attendance-status-relay');
                    if (pRelay) {
                        let html = '';
                        if (data.p_status === 'OFF-SHIFT') {
                            html = '<button onclick="confirmAttendance(\'time_in\')" class="btn-attendance btn-time-in"><i class="fas fa-sign-in-alt"></i> START DEPLOYMENT</button>';
                        } else if (data.p_status === 'ACTIVE') {
                            html = '<button onclick="confirmAttendance(\'time_out\')" class="btn-attendance btn-time-out"><i class="fas fa-sign-out-alt"></i> END DEPLOYMENT</button>';
                        } else {
                            html = '<div style="padding: 0.6rem 1.75rem; background: rgba(16, 185, 129, 0.1); border-radius: 100px; color: var(--accent-green); font-weight: 800; font-size: 0.75rem; border: 1px solid rgba(16, 185, 129, 0.2); display: inline-flex; align-items: center; gap: 0.6rem;"><i class="fas fa-check-circle"></i> SHIFT COMPLETED</div>';
                        }
                        if (pRelay.innerHTML !== html) pRelay.innerHTML = html;
                        if (document.getElementById('time-in-display')) document.getElementById('time-in-display').innerText = data.p_time_in || '--:--';
                        
                        // Update reactive ticker state
                        liveTimeIn = data.p_raw_time_in || '';
                        liveTimeOut = data.p_raw_time_out || '';
                    }

                    if (document.getElementById('global-pto-display') && data.p_pto) {
                        document.getElementById('global-pto-display').innerHTML = `${data.p_pto} <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small>`;
                    }
                    if (document.getElementById('global-total-hours') && data.p_total_hours) {
                        document.getElementById('global-total-hours').innerHTML = `${data.p_total_hours} <small style="font-size: 0.55rem; opacity: 0.6;">HRS</small>`;
                    }

                    // 4. Sidebar Badges
                    const ptoBadge = document.getElementById('pto-sidebar-badge');
                    if (ptoBadge) {
                        ptoBadge.innerText = data.pending_pto;
                        ptoBadge.style.display = data.pending_pto > 0 ? 'inline-block' : 'none';
                    }

                    const userBadge = document.getElementById('users-sidebar-badge');
                    if (userBadge) {
                        userBadge.innerText = data.pending_approvals;
                        userBadge.style.display = data.pending_approvals > 0 ? 'inline-block' : 'none';
                    }

                    const ptoBadgeRelay = document.getElementById('pto-pending-badge-relay');
                    if (ptoBadgeRelay) {
                        ptoBadgeRelay.innerHTML = data.pending_pto > 0 ? `<div style="background: var(--accent-red); color: white; padding: 0.35rem 0.85rem; border-radius: 100px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">${data.pending_pto} PENDING</div>` : '';
                    }

                    const approvalBanner = document.getElementById('pending-approvals-banner');
                    const approvalText = document.getElementById('pending-approvals-text');
                    if (approvalBanner && approvalText) {
                        approvalBanner.style.display = data.pending_approvals > 0 ? 'flex' : 'none';
                        approvalText.innerText = `There are ${data.pending_approvals} new account(s) waiting for your activation.`;
                    }

                    // 5. Organizational Success Notifications
                    if (!isInitialLoad) {
                        // New PTO Filing
                        if (data.latest_pto_id > lastSeenPtoId) {
                            triggerToast('New Leave Filing', `${data.latest_pto_name} has filed a new request.`, 'info', 'module-pto');
                        }
                        
                        // New User Registration
                        if (data.latest_user_id > lastSeenUserId) {
                            triggerToast('Activation Required', `${data.latest_user_name} is awaiting authorized access.`, 'warning', 'module-users');
                        }

                        // Attendance Pulse
                        if (data.latest_attendance_time > lastSeenAttendanceTime) {
                            const icon = data.latest_attendance_type === 'TIME-IN' ? 'success' : 'warning';
                            const title = data.latest_attendance_type === 'TIME-IN' ? 'Shift Started' : 'Shift Ended';
                            triggerToast(title, `${data.latest_attendance_name} updated deployment status.`, icon, 'module-home');
                        }
                    }

                    // Sync State
                    if (data.latest_pto_id > 0) lastSeenPtoId = data.latest_pto_id;
                    if (data.latest_user_id > 0) lastSeenUserId = data.latest_user_id;
                    if (data.latest_attendance_time) lastSeenAttendanceTime = data.latest_attendance_time;

                    isInitialLoad = false;
                })
                .catch(err => console.error('Relay Sync Error:', err));
        }

        function triggerToast(title, text, icon, targetModule) {
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
            }).then(() => {
                if (targetModule) {
                    // switchModule(targetModule, null);
                }
            });
        }

        // Functional Real-Time Handlers
        function handlePTO(id, status) {
            Swal.fire({
                title: status === 'Approved' ? 'Authorize Leave?' : 'Reject Leave?',
                text: "This will definitively synchronize the organizational leave protocol.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: status === 'Approved' ? '#10b981' : '#ef4444',
                background: '#1e293b',
                color: '#f8fafc'
            }).then((res) => {
                if (res.isConfirmed) {
                    fetch(`pto_approval_process.php?id=${id}&status=${status}&ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Status Synchronized', text: data.message, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                            pollOMUpdates();
                        } else {
                            Swal.fire('Operation Failed', data.error || 'Check server logs.', 'error');
                        }
                    });
                }
            });
        }

        function approveUser(id, name) {
            Swal.fire({
                title: 'Activate Personnel?',
                text: `Grant @${name} authorized access to the organizational portal?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                background: '#1e293b',
                color: '#f8fafc'
            }).then((res) => {
                if (res.isConfirmed) {
                    fetch(`approve_user_process.php?id=${id}&ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Access Authorized', text: `${name} has been activated.`, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                            pollOMUpdates();
                        } else {
                            Swal.fire('Activation Failed', data.error || 'Server rejected request.', 'error');
                        }
                    });
                }
            });
        }

        function forceTimeout(id, name) {
            Swal.fire({
                title: 'Resolve Breach?',
                text: `Manually conclude legacy deployment protocol for @${name}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                background: '#1e293b',
                color: '#f8fafc'
            }).then((res) => {
                if (res.isConfirmed) {
                    fetch(`force_timeout_process.php?id=${id}&ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Breach Resolved', text: data.message, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                            pollOMUpdates();
                        }
                    });
                }
            });
        }

        function deleteUser(id) {
            Swal.fire({
                title: 'Terminate Account?',
                text: "This will definitively purge the user record from the organizational ledger.",
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                background: '#1e293b',
                color: '#f8fafc'
            }).then((res) => {
                if (res.isConfirmed) {
                    fetch(`delete_user_process.php?id=${id}&ajax=1`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({ icon: 'success', title: 'Record Purged', text: data.message, background: '#1e293b', color: '#f8fafc', timer: 1500, showConfirmButton: false });
                            pollOMUpdates();
                        } else {
                            Swal.fire('Operation Restricted', data.error || 'Purge failed.', 'error');
                        }
                    });
                }
            });
        }

        document.getElementById('audit-filter-form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            pollOMUpdates();
        });

        // Staff Leave Audit Ledger Interactive Controls
        document.getElementById('ledger-search')?.addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            ledgerSearch = e.target.value;
            searchTimeout = setTimeout(() => {
                pollOMUpdates();
            }, 300);
        });

        document.querySelectorAll('.ledger-filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                // Update UI active state
                document.querySelectorAll('.ledger-filter-btn').forEach(b => {
                    b.classList.remove('active-filter');
                    b.style.background = 'transparent';
                    b.style.color = 'var(--text-muted)';
                });
                
                this.classList.add('active-filter');
                this.style.background = '#6366f1';
                        this.style.color = 'white';
                
                // Update state and refresh
                ledgerPeriod = this.getAttribute('data-period');
                pollOMUpdates();
            });
        });

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
                        renderEmailList(data.emails);
                    } else {
                        container.innerHTML = `<div style="padding: 4rem; text-align: center; color: #ef4444;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 1.5rem; margin-bottom: 1rem; display: block;"></i>
                            <div style="font-size: 0.8rem; font-weight: 800; text-transform: uppercase;">Relay Error</div>
                            <div style="font-size: 0.65rem; opacity: 0.8; margin-top: 0.5rem;">${data.message}</div>
                        </div>`;
                    }
                })
                .catch(err => {
                    console.error('Email Relay Failure:', err);
                    container.innerHTML = `<div style="padding: 4rem; text-align: center; color: #ef4444;">
                        <i class="fas fa-signal" style="font-size: 1.5rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                        <div style="font-size: 0.8rem; font-weight: 800; text-transform: uppercase;">Connection Dropped</div>
                        <div style="font-size: 0.65rem; opacity: 0.8; margin-top: 0.5rem;">The satellite relay returned an invalid payload. Check console for specifics.</div>
                    </div>`;
                });
        }

        function renderEmailList(emails) {
            const container = document.getElementById('email-list-container');
            if (!container) return;

            if (!emails || emails.length === 0) {
                container.innerHTML = `
                    <div style="padding: 4rem; text-align: center; color: var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size: 1.5rem; margin-bottom: 1rem; display: block; opacity: 0.2;"></i>
                        <div style="font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">No Transmissions</div>
                        <div style="font-size: 0.65rem; opacity: 0.6; margin-top: 0.5rem;">This folder is currently clear of internal communications.</div>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = emails.map(email => `
                <div onclick="viewEmail(${email.id}, this)" class="email-stream-item" style="padding: 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.02); cursor: pointer; transition: 0.2s; background: ${email.is_read == 0 && email.receiver_id == '<?php echo $user_id; ?>' ? 'rgba(99, 102, 241, 0.02)' : 'transparent'};">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span style="font-weight: 800; font-size: 0.75rem; color: ${email.is_read == 0 && email.receiver_id == '<?php echo $user_id; ?>' ? 'white' : 'rgba(255,255,255,0.6)'}; display: flex; align-items: center; gap: 0.5rem;">
                            ${email.is_read == 0 && email.receiver_id == '<?php echo $user_id; ?>' ? '<div style="width: 6px; height: 6px; background: var(--primary-color); border-radius: 50%;"></div>' : ''}
                            <span style="font-size: 0.6rem; color: var(--primary-color); opacity: 0.8; font-weight: 900;">${email.sender_id == '<?php echo $user_id; ?>' ? 'TO:' : 'FROM:'}</span>
                            ${email.participant_name}
                            <span style="font-size: 0.5rem; padding: 0.1rem 0.4rem; background: rgba(99, 102, 241, 0.1); border: 1px solid rgba(99, 102, 241, 0.2); border-radius: 4px; color: var(--primary-color); text-transform: uppercase; font-weight: 900; letter-spacing: 0.05em;">${email.participant_role}</span>
                        </span>
                        <span style="font-size: 0.6rem; color: var(--text-muted); font-weight: 600;">${email.display_time}</span>
                    </div>
                    <div style="font-weight: 700; font-size: 0.75rem; color: ${email.is_read == 0 && email.receiver_id == '<?php echo $user_id; ?>' ? 'white' : 'rgba(255,255,255,0.4)'}; margin-bottom: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${email.subject}</div>
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
                                    <style>
                                        .custom-relay-select::-webkit-scrollbar { width: 6px; }
                                        .custom-relay-select::-webkit-scrollbar-track { background: #0f172a; border-radius: 10px; }
                                        .custom-relay-select::-webkit-scrollbar-thumb { background: rgba(99, 102, 241, 0.3); border-radius: 10px; }
                                        .custom-relay-select::-webkit-scrollbar-thumb:hover { background: var(--primary-color); }
                                    </style>
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
        if(window.location.search.includes('view=email')) loadEmails('inbox');

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
        

        pollOMUpdates();
        setInterval(pollOMUpdates, 5000);

        function scrollEmails(dir) {
            const container = document.getElementById('email-list-container');
            if(!container) return;
            const scrollAmount = 250;
            if(dir === 'up') container.scrollBy({ top: -scrollAmount, behavior: 'smooth' });
            else container.scrollBy({ top: scrollAmount, behavior: 'smooth' });
        }

        function scrollPayroll(dir) {
            const container = document.getElementById('payroll-scroll-container');
            if(!container) return;
            const scrollAmount = 200;
            if(dir === 'up') container.scrollTop -= scrollAmount;
            else container.scrollTop += scrollAmount;
        }
        setInterval(updateClock, 1000);
        updateClock();








        <?php if (isset($_GET['success'])): ?>
            Swal.fire({ icon: 'success', title: 'Recorded!', text: 'Your attendance has been updated successfully.', background: '#1e293b', color: '#f8fafc', timer: 2000, showConfirmButton: false });
        <?php endif; ?>
        const urlParams = new URLSearchParams(window.location.search);
        
        // 4. Persistence: Restore view from URL parameter
        const currentView = urlParams.get('view');
        if (currentView) {
            const viewToModuleMap = {
                'home': 'module-home',
                'users': 'module-users',
                'create-user': 'module-create-user',
                'pto': 'module-pto',
                'audit': 'module-audit',
                'breaches': 'module-breaches',
                'ongoing-leave': 'module-ongoing-leave',
                'settings': 'module-settings'
            };
            const moduleId = viewToModuleMap[currentView];
            if (moduleId) {
                // Find matching sidebar link
                const targetLink = Array.from(document.querySelectorAll('.nav-link')).find(l => l.getAttribute('onclick') && l.getAttribute('onclick').includes(moduleId));
                switchModule(moduleId, targetLink);
            }
        }

        if (urlParams.get('success') === 'pto_updated') {
            Swal.fire({
                icon: 'success',
                title: 'Status Synchronized',
                text: 'The organizational leave status has been definitively updated.',
                background: 'rgba(15, 23, 42, 0.95)',
                color: '#f8fafc',
                confirmButtonColor: '#10b981'
            });
        }

        <?php if (isset($_GET['success']) && strpos($_GET['success'], 'created') !== false): ?>
            Swal.fire({ icon: 'success', title: 'Account Created!', text: 'The new member account has been created.', background: '#1e293b', color: '#f8fafc' });
        <?php endif; ?>
        <?php if (isset($_GET['error']) && $_GET['error'] === 'exists'): ?>
            Swal.fire({ icon: 'error', title: 'Oops...', text: 'Username or Email already exists.', background: '#1e293b', color: '#f8fafc' });
        <?php endif; ?>

        // Password toggle for create user form
        const togglePassword = document.querySelector('#togglePassword');
        const passwordInput = document.querySelector('#password');
        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.classList.toggle('fa-eye-slash');
            });
        }

        // Phone mask for create user form
        const phoneInput = document.getElementById('phone_number');
        if (phoneInput) {
            phoneInput.addEventListener('input', function (e) {
                let x = e.target.value.replace(/\D/g, '').match(/(\d{0,4})(\d{0,3})(\d{0,4})/);
                e.target.value = !x[2] ? x[1] : x[1] + '-' + x[2] + (x[3] ? '-' + x[3] : '');
            });
        }

        // AJAXified User Creation
        document.getElementById('create-user-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerText;

            submitBtn.disabled = true;
            submitBtn.innerText = 'AUTHORIZING...';

            fetch('add_user_process.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Access Authorized', 
                        text: data.message, 
                        background: '#1e293b', 
                        color: '#f8fafc' 
                    }).then(() => {
                        this.reset();
                        switchModule('module-users');
                        pollOMUpdates();
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Operational Fault', text: data.message, background: '#1e293b', color: '#f8fafc' });
                }
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerText = originalText;
            });
        });

    </script>
    <script src="../address_handler.js"></script>
</body>
</html>
