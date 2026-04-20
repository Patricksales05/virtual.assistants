<?php
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

try {
    // Fetch latest attendance (prioritize active sessions)
    // First, check if there's an active session from any date
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $attendance = $stmt->fetch();

    // If no active session, get the most recent completed session for today to show the "Completed" state
    if (!$attendance) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id, $today]);
        $attendance = $stmt->fetch();
    }

    $status = 'OFF-SHIFT';
    $duration = '00:00:00';
    $time_in_display = '--:-- --';
    $time_out_display = '--:-- --';
    
    if ($attendance) {
        $status = $attendance['time_out'] ? 'COMPLETED' : 'ACTIVE';
        $time_in_display = date('h:i A', strtotime($attendance['time_in']));
        $time_out_display = $attendance['time_out'] ? date('h:i A', strtotime($attendance['time_out'])) : '--:-- --';
        
        if (!$attendance['time_out']) {
            $start = new DateTime($attendance['time_in']);
            $now = new DateTime();
            $diff = $start->diff($now);
            $duration = $diff->format('%H:%I:%S');
        } else {
            $start = new DateTime($attendance['time_in']);
            $end = new DateTime($attendance['time_out']);
            $diff = $start->diff($end);
            $duration = $diff->format('%H:%I:%S');
        }
    }

    // 3. Current User's PTO Requests (History Fragment)
    $stmt = $pdo->prepare("SELECT p.*, approver.full_name as approver_name FROM pto_requests p LEFT JOIN users approver ON p.approved_by = approver.id WHERE p.user_id = ? ORDER BY p.id DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $user_ptos = $stmt->fetchAll();
    
    $pto_history_html = '';
    if (empty($user_ptos)) {
        $pto_history_html = '<tr><td colspan="4" style="text-align: center; padding: 2rem; color: var(--text-muted);">No deployment history found.</td></tr>';
    } else {
        foreach($user_ptos as $req) {
            $status_color = '#f59e0b';
            if ($req['status'] === 'Approved') $status_color = '#10b981';
            elseif ($req['status'] === 'Denied') $status_color = '#ef4444';
            
            $pto_history_html .= '
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                    <td style="padding: 0.75rem 0.8rem;">
                        <div style="font-weight: 700; color: white; font-size: 0.75rem;">'.htmlspecialchars($req['leave_type']).'</div>
                        <div style="font-size: 0.6rem; color: var(--text-muted); opacity: 0.6;">'.date('M d, Y', strtotime($req['created_at'])).'</div>
                    </td>
                    <td style="padding: 0.75rem 0.8rem; color: #cbd5e1; font-size: 0.65rem; font-weight: 600;">'.date('M d', strtotime($req['start_date'])).' - '.date('M d', strtotime($req['end_date'])).'</td>
                    <td style="padding: 0.75rem 0.8rem; color: #10b981; font-weight: 800; font-size: 0.65rem;">'.htmlspecialchars($req['approver_name'] ?: ($req['status'] === 'Pending' ? '--' : 'System')).'</td>
                    <td style="padding: 0.75rem 0.8rem; text-align: right;">
                        <span style="background: '.$status_color.'10; color: '.$status_color.'; padding: 0.2rem 0.6rem; border-radius: 100px; font-size: 0.55rem; font-weight: 800; text-transform: uppercase;">'.htmlspecialchars($req['status']).'</span>
                    </td>
                </tr>';
        }
    }

    // Personal History Relay (Recent Activity)
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY attendance_date DESC, time_in DESC LIMIT 8");
    $stmt->execute([$user_id]);
    $logs = $stmt->fetchAll();
    $personal_history_html = '';
    foreach($logs as $row) {
        $s = new DateTime($row['time_in']);
        $e = $row['time_out'] ? new DateTime($row['time_out']) : null;
        if ($e) {
            $df = $s->diff($e);
            $d = $df->format('%H:%I:%S');
            $ds = $e->getTimestamp() - $s->getTimestamp();
            $p = number_format(($ds / 3600) * (6.66 / 160), 4);
        } else {
            // Live Session Calculation
            $now = new DateTime();
            $df = $s->diff($now);
            $d = $df->format('%H:%I:%S');
            $ds = $now->getTimestamp() - $s->getTimestamp();
            $p = number_format(($ds / 3600) * (6.66 / 160), 4);
        }
        $personal_history_html .= '
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                <td style="padding: 0.85rem 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <div style="width: 24px; height: 24px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; font-weight: 800; color: white;">'.strtoupper(substr($_SESSION['username'], 0, 1)).'</div>
                        <div>
                            <div style="font-weight: 700; font-size: 0.75rem; color: white;">'.htmlspecialchars($_SESSION['username']).'</div>
                            <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">@'.htmlspecialchars($_SESSION['username']).'</div>
                        </div>
                    </div>
                </td>
                <td style="padding: 0.85rem 0.75rem;">
                    <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;">'.date('h:i A', strtotime($row['time_in'])).'</div>
                    <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">'.date('M d, Y', strtotime($row['time_in'])).'</div>
                </td>
                <td style="padding: 0.85rem 0.75rem;">
                    '.($row['time_out'] ? '<div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;">'.date('h:i A', strtotime($row['time_out'])).'</div><div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">'.date('M d, Y', strtotime($row['time_out'])).'</div>' : '<div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">PENDING</div><div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">In Session</div>').'
                </td>
                <td style="padding: 0.85rem 0.75rem;">
                    <div style="color: var(--primary-color); font-weight: 800; font-size: 0.75rem;">'.$d.'</div>
                    <div style="font-size: 0.55rem; color: var(--accent-green); font-weight: 700; text-transform: uppercase;">'.$p.' <small style="opacity:0.6; font-weight: 700;">HRS</small></div>
                </td>
                <td style="padding: 0.85rem 0.75rem; text-align: right;">
                    <span style="background: '.($row['time_out'] ? 'rgba(16, 185, 129, 0.08)' : 'rgba(245, 158, 11, 0.08)').'; color: '.($row['time_out'] ? 'var(--accent-green)' : '#f59e0b').'; padding: 0.2rem 0.6rem; border-radius: 100px; font-size: 0.55rem; font-weight: 800; border: 1px solid '.($row['time_out'] ? 'rgba(16, 185, 129, 0.2)' : 'rgba(245, 158, 11, 0.2)').';">'.($row['time_out'] ? 'COMPLETED' : 'ONLINE').'</span>
                </td>
            </tr>';
    }

    // Fetch user start date for PTO
    require_once '../accrual_helper.php';
    $pto_credits = calculate_realtime_pto($user_id, $pdo);
    $total_hours_full = get_total_cumulative_hours($user_id, $pdo);
    $cutoff = get_current_cutoff_dates();
    $cutoff_days = get_days_worked_in_cutoff($user_id, $pdo, $cutoff['start'], $cutoff['end']);
    $stale = get_stale_active_session($user_id, $pdo);

    // Latest Activity Tracking (for Real-Time Notifications)
    $latest_attendance = $pdo->query("
        SELECT a.id, u.full_name, a.time_out, a.updated_at 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE u.role = 'Staff'
        ORDER BY a.updated_at DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    // Fetch latest PTO request status for real-time notification
    $stmt = $pdo->prepare("SELECT id, status FROM pto_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $latest_pto_own = $stmt->fetch();

    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'duration' => $duration,
        'time_in' => $time_in_display,
        'time_out' => $time_out_display,
        'pto_credits' => number_format($pto_credits, 4),
        'total_hours' => number_format($total_hours_full, 2),
        'cutoff_days' => $cutoff_days,
        'cutoff_label' => $cutoff['label'],
        'stale_session' => $stale ? true : false,
        'stale_date' => $stale ? date('M d', strtotime($stale['attendance_date'])) : '',
        'history_html' => $personal_history_html,
        'pto_history_html' => $pto_history_html,
        'latest_attendance_id' => $latest_attendance ? $latest_attendance['id'] : 0,
        'latest_attendance_name' => $latest_attendance ? $latest_attendance['full_name'] : '',
        'latest_attendance_type' => ($latest_attendance && $latest_attendance['time_out']) ? 'TIME-OUT' : 'TIME-IN',
        'latest_attendance_time' => $latest_attendance ? $latest_attendance['updated_at'] : '',
        'latest_pto_id' => $latest_pto_own ? $latest_pto_own['id'] : 0,
        'latest_pto_status' => $latest_pto_own ? $latest_pto_own['status'] : '',
        'server_time' => date('h:i:s A')
    ]);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
