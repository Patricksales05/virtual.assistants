<?php
require_once 'db_config.php';
// Session is managed by db_config.php

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || ($current_role !== 'team lead' && $current_role !== 'team-lead')) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized Access']);
    exit();
}

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
$user_id = $_SESSION['user_id'];

try {
    // 0. Attendance Breaches Calculation (Reserved for UI Relay)
    $stale_badge_count = $pdo->query("SELECT COUNT(*) FROM attendance WHERE time_out IS NULL AND attendance_date < '$today'")->fetchColumn();
    
    $alerts = [];
    foreach($stale_incidents as $incident) {
        // High-Fidelity Leave Detection
        $checkLeave = $pdo->prepare("SELECT p.*, approver.full_name as approver_name FROM pto_requests p LEFT JOIN users approver ON p.approved_by = approver.id WHERE p.user_id = ? AND p.status = 'Approved' AND ? BETWEEN p.start_date AND p.end_date");
        $checkLeave->execute([$incident['user_id'], $incident['attendance_date']]);
        $leave = $checkLeave->fetch();

        $alerts[] = [
            'id' => $incident['id'],
            'user' => $incident['full_name'] ?: $incident['username'],
            'role' => $incident['role'],
            'date' => date('M d', strtotime($incident['attendance_date'])),
            'on_leave' => $leave ? true : false,
            'approved_by' => $leave ? ($leave['approver_name'] ?: 'System') : ''
        ];
    }
    // 1. Core Metrics for Dashboard
    $total_staff = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('Staff', 'Staff Member', 'STAFF MEMBER')")->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE time_out IS NULL");
    $stmt->execute();
    $active_now = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN users u ON a.user_id = u.id WHERE u.role = 'Staff' AND DATE(a.time_out) = ?");
    $stmt->execute([$today]);
    $completed_today = $stmt->fetchColumn();
    // 2. Personal PTO & Cutoff Telemetry (for Header)
    require_once '../accrual_helper.php';
    $p_pto = calculate_realtime_pto($user_id, $pdo);
    $p_total_hours = get_total_cumulative_hours($user_id, $pdo);
    $cutoff = get_current_cutoff_dates();
    $p_cutoff_days = get_days_worked_in_cutoff($user_id, $pdo, $cutoff['start'], $cutoff['end']);
    $p_stale = get_stale_active_session($user_id, $pdo);

    // 2.1 Personal Attendance Status
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $p_att = $stmt->fetch();

    if (!$p_att) {
        $stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id, $today]);
        $p_att = $stmt->fetch();
    }

    $p_status = 'OFF-SHIFT';
    $p_time_in = '';
    if ($p_att) {
        $p_status = $p_att['time_out'] ? 'COMPLETED' : 'ACTIVE';
        $p_time_in = $p_att['time_in'];
    }

    // 3. Activity Feed (Home Module)
    $stmt = $pdo->prepare("
        SELECT a.*, u.username 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE (u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER')) 
        AND (a.attendance_date = ? OR DATE(a.time_out) = ? OR a.time_out IS NULL) 
        ORDER BY a.id DESC LIMIT 10
    ");
    $stmt->execute([$today, $today]);
    $activity_feed = $stmt->fetchAll();

    $activity_feed_html = '';
    foreach($activity_feed as $feed) {
        $activity_feed_html .= '
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                <td style="padding: 0.65rem 0.85rem; color: white; display: flex; align-items: center; gap: 0.6rem;">
                    <div style="width: 24px; height: 24px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; font-weight: 800; color: white;">'.strtoupper(substr($feed['username'], 0, 1)).'</div>
                    <div style="font-weight: 700; font-size: 0.75rem;">'.htmlspecialchars($feed['username']).'</div>
                </td>
                <td style="padding: 0.65rem 0.85rem;">
                    <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;">'.date('h:i A', strtotime($feed['time_in'])).'</div>
                    <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">'.date('M d, Y', strtotime($feed['time_in'])).'</div>
                </td>
                <td style="padding: 0.65rem 0.85rem;">
                    <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;">'.($feed['time_out'] ? date('h:i A', strtotime($feed['time_out'])) : 'PENDING').'</div>
                    <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">'.($feed['time_out'] ? date('M d, Y', strtotime($feed['time_out'])) : 'Active').'</div>
                </td>
                <td style="padding: 0.65rem 0.85rem;">
                    <span style="background: '.($feed['time_out'] ? 'rgba(16, 185, 129, 0.1)' : 'rgba(245, 158, 11, 0.1)').'; color: '.($feed['time_out'] ? 'var(--accent-green)' : '#f59e0b').'; padding: 0.2rem 0.6rem; border-radius: 100px; font-size: 0.6rem; font-weight: 800;">
                        '.($feed['time_out'] ? 'Completed' : 'Active').'
                    </span>
                </td>
            </tr>';
    }

    // 4. Staff Monitoring Engine (Staff List Module)
    $stmt = $pdo->prepare("
        SELECT u.*, a.time_in, a.time_out 
        FROM users u 
        LEFT JOIN attendance a ON a.id = (
            SELECT id FROM attendance 
            WHERE user_id = u.id 
            AND (attendance_date = ? OR time_out IS NULL)
            ORDER BY id DESC LIMIT 1
        )
        WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER')
        ORDER BY u.username ASC
    ");
    $stmt->execute([$today]);
    $staff_list = $stmt->fetchAll();

    $staff_monitoring_html = '';
    foreach($staff_list as $staff) {
        $staff_monitoring_html .= '
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                <td style="padding: 0.65rem 0.85rem; color: white; font-size: 0.75rem;">'.htmlspecialchars($staff['username']).'</td>
                <td style="padding: 0.65rem 0.85rem; color: #cbd5e1; font-size: 0.7rem;">'.($staff['time_in'] ? date('h:i A', strtotime($staff['time_in'])) : 'None').'</td>
                <td style="padding: 0.65rem 0.85rem;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; display: inline-block; background: '.($staff['time_in'] && !$staff['time_out'] ? 'var(--accent-green)' : 'rgba(255,255,255,0.1)').';"></span>
                    <span style="font-size: 0.725rem; margin-left: 0.5rem; color: var(--text-muted);">'.($staff['time_in'] && !$staff['time_out'] ? 'Online' : 'Offline').'</span>
                </td>
            </tr>';
    }

    // 5. Deployment Ledger (Staff Audit - Attendance Logs)
    $stmt = $pdo->prepare("
        SELECT a.*, u.username as user_handle, u.full_name as user_full_name 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER') 
        ORDER BY a.attendance_date DESC, a.time_in DESC LIMIT 15
    ");
    $stmt->execute();
    $audit_logs = $stmt->fetchAll();

    $staff_audit_html = '';
    foreach($audit_logs as $log) {
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
            // Live Session Logic for Staff Audit
            $now = new DateTime();
            $diff = $s_time->diff($now);
            $dur = $diff->format('%H:%I:%S');
            $diff_sec = $now->getTimestamp() - $s_time->getTimestamp();
            $pto = number_format(($diff_sec / 3600) * (6.66 / 160), 4);
        }

        $display_name = !empty($log['user_full_name']) ? $log['user_full_name'] : $log['user_handle'];
        $display_handle = $log['user_handle'] ?? '';
        $staff_audit_html .= '
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                <td style="padding: 0.75rem 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.7rem; color: white;">'.strtoupper(substr($display_name ?? '', 0, 1)).'</div>
                        <div>
                            <div style="font-weight: 700; color: white; font-size: 0.8rem;">'.htmlspecialchars($display_name ?? '').'</div>
                            <div style="font-size: 0.6rem; color: var(--text-muted);">@'.htmlspecialchars($display_handle ?? '').'</div>
                        </div>
                    </div>
                </td>
                <td style="padding: 0.75rem 0.85rem;">
                    <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;">'.date('h:i A', strtotime($log['time_in'])).'</div>
                    <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">'.date('M d, Y', strtotime($log['time_in'])).'</div>
                </td>
                <td style="padding: 0.75rem 0.85rem;">
                    '.($log['time_out'] ? '
                        <div style="color: #f8fafc; font-weight: 800; font-size: 0.75rem;">'.date('h:i A', strtotime($log['time_out'])).'</div>
                        <div style="font-size: 0.55rem; color: var(--text-muted); font-weight: 600;">'.date('M d, Y', strtotime($log['time_out'])).'</div>
                    ' : '
                        <div style="color: #f59e0b; font-weight: 800; font-size: 0.75rem;">PENDING</div>
                        <div style="font-size: 0.55rem; color: var(--text-muted);">In Session</div>
                    ').'
                </td>
                <td style="padding: 0.75rem 0.85rem;">
                    <div style="color: var(--primary-color); font-weight: 800; font-size: 0.75rem;">'.$dur.'</div>
                    <div style="font-size: 0.55rem; color: #10b981; font-weight: 700;">'.$pto.' <small style="opacity:0.6;">HRS</small></div>
                </td>
                <td style="padding: 0.75rem 0.85rem; text-align: right;">
                    <span style="font-size: 0.55rem; color: var(--primary-color); font-weight: 800; text-transform: uppercase;">STAFF</span>
                </td>
            </tr>';
    }

    // 5.5 TL Staff Request Queue & Audit Ledger (PTO Logs)
    // Queue (Pending)
    $stmt = $pdo->query("SELECT p.*, u.full_name, u.username as acc_name FROM pto_requests p JOIN users u ON p.user_id = u.id WHERE u.role IN ('Staff', 'Staff Member') AND p.status = 'Pending' ORDER BY p.id DESC");
    $p_queue = $stmt->fetchAll();
    $tl_pto_queue_html = '';
    if (empty($p_queue)) {
        $tl_pto_queue_html = '<tr><td colspan="5" style="padding: 3.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;"><i class="fas fa-check-double" style="display: block; font-size: 2rem; margin-bottom: 1rem; opacity: 0.15;"></i> Zero pending deployment requests from your staff.</td></tr>';
    } else {
        foreach($p_queue as $req) {
            $tl_pto_queue_html .= '
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.3s;">
                    <td style="padding: 0.75rem 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <div style="width: 28px; height: 28px; background: rgba(99, 102, 241, 0.2); color: white; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 800; border: 1px solid rgba(99, 102, 241, 0.2);">'.strtoupper(substr($req['acc_name'] ?? '', 0, 1)).'</div>
                            <div>
                                <div style="font-weight: 700; color: white; font-size: 0.8rem;">'.htmlspecialchars($req['full_name'] ?? '').'</div>
                                <div style="font-size: 0.6rem; color: var(--text-muted);">@'.htmlspecialchars($req['acc_name'] ?? '').'</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 0.75rem 0.85rem;"><span style="font-size: 0.7rem; color: var(--primary-color); font-weight: 700;">'.htmlspecialchars($req['leave_type']).'</span></td>
                    <td style="padding: 0.75rem 0.85rem; color: #cbd5e1; font-size: 0.7rem; font-weight: 600;">'.date('M d', strtotime($req['start_date'])).' - '.date('M d', strtotime($req['end_date'])).'</td>
                    <td style="padding: 0.75rem 0.85rem;"><div style="font-size: 0.65rem; color: var(--text-muted); max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">'.htmlspecialchars($req['reason']).'</div></td>
                    <td style="padding: 0.75rem 0.85rem; text-align: right;">
                        <div style="display: flex; gap: 0.4rem; justify-content: flex-end;">
                            <button onclick="handlePTO('.$req['id'].', \'Approved\')" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;">Authorize</button>
                            <button onclick="handlePTO('.$req['id'].', \'Denied\')" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; cursor: pointer; transition: 0.3s; text-transform: uppercase;">Reject</button>
                        </div>
                    </td>
                </tr>';
        }
    }

    // Ledger (History)
    $stmt = $pdo->query("SELECT p.*, u.full_name, u.username as acc_name, approver.full_name as approver_name FROM pto_requests p JOIN users u ON p.user_id = u.id LEFT JOIN users approver ON p.approved_by = approver.id WHERE u.role IN ('Staff', 'Staff Member') AND p.status != 'Pending' ORDER BY p.id DESC LIMIT 15");
    $p_history = $stmt->fetchAll();
    $tl_pto_ledger_html = '';
    if (empty($p_history)) {
        $tl_pto_ledger_html = '<tr><td colspan="5" style="padding: 3rem; text-align: center; color: var(--text-muted); font-size: 0.75rem; opacity: 0.6;">No operational leaf history recorded.</td></tr>';
    } else {
        foreach($p_history as $log) {
            $s_color = ($log['status'] === 'Approved') ? '#10b981' : '#ef4444';
            $tl_pto_ledger_html .= '
                <tr class="audit-row" data-name="'.strtolower($log['full_name'] ?? '').'" data-start="'.$log['start_date'].'" style="border-bottom: 1px solid rgba(255,255,255,0.02); transition: 0.2s;">
                    <td style="padding: 0.65rem 0.85rem;">
                        <div style="font-weight: 700; color: white; font-size: 0.75rem;">'.htmlspecialchars($log['full_name'] ?? '').'</div>
                        <div style="font-size: 0.55rem; color: var(--text-muted); opacity: 0.7;">@'.htmlspecialchars($log['acc_name'] ?? '').'</div>
                    </td>
                    <td style="padding: 0.65rem 0.85rem; color: #cbd5e1; font-size: 0.7rem; font-weight: 500;">'.htmlspecialchars($log['leave_type'] ?? '').'</td>
                    <td style="padding: 0.65rem 0.85rem; color: #94a3b8; font-size: 0.65rem; font-weight: 600;">'.date('M d', strtotime($log['start_date'])).' - '.date('M d', strtotime($log['end_date'])).'</td>
                    <td style="padding: 0.65rem 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.35rem;">
                            <div style="width: 16px; height: 16px; background: rgba(99, 102, 241, 0.1); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.45rem; color: var(--primary-color); font-weight: 800;">'.strtoupper(substr($log['approver_name'] ?? 'S', 0, 1)).'</div>
                            <span style="font-size: 0.65rem; font-weight: 700; color: #10b981;">'.htmlspecialchars($log['approver_name'] ?? 'Auto-System').'</span>
                        </div>
                    </td>
                    <td style="padding: 0.65rem 0.85rem; text-align: right;">
                        <span style="background: '.$s_color.'15; color: '.$s_color.'; padding: 0.2rem 0.65rem; border-radius: 100px; font-size: 0.55rem; font-weight: 800; text-transform: uppercase; border: 1px solid '.$s_color.'30;">'.htmlspecialchars($log['status'] ?? '').'</span>
                    </td>
                </tr>';
        }
    }
    // 6. Latest Activity Tracking (for Real-Time Notifications)
    $latest_attendance = $pdo->query("
        SELECT a.id, u.full_name, a.time_out, a.updated_at 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER')
        ORDER BY a.updated_at DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    // 7. PTO Requests Engine (Pending Count & Latest Detection)
    $pending_pto_count = $pdo->query("SELECT COUNT(*) FROM pto_requests p JOIN users u ON p.user_id = u.id WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER') AND p.status = 'Pending'")->fetchColumn();
    $latest_pto = $pdo->query("SELECT p.id, u.full_name FROM pto_requests p JOIN users u ON p.user_id = u.id WHERE u.role IN ('Staff', 'Staff Member', 'STAFF MEMBER') AND p.status = 'Pending' ORDER BY p.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    // 8. Organizational Alerts (Forgotten Time-Out Detection)
    $stale_incidents = $pdo->query("
        SELECT a.id, a.attendance_date, a.time_in, u.username, u.full_name, u.role
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.time_out IS NULL 
        AND a.attendance_date < '$today'
        ORDER BY a.id DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $alerts = [];
    foreach($stale_incidents as $incident) {
        $alerts[] = [
            'id' => $incident['id'],
            'user' => $incident['full_name'] ?: $incident['username'],
            'role' => $incident['role'],
            'date' => date('M d', strtotime($incident['attendance_date'])),
            'time_in' => date('h:i A', strtotime($incident['time_in']))
        ];
    }
    
    // 9. Active Ongoing Study/Leave Deployment (Consolidated Audit)
    $active_leave_count = $pdo->prepare("
        SELECT COUNT(*) FROM pto_requests p
        JOIN users u ON p.user_id = u.id
        WHERE p.status = 'Approved' 
        AND ? BETWEEN p.start_date AND p.end_date
    ");
    $active_leave_count->execute([$today]);
    $ongoing_leaves = $active_leave_count->fetchColumn();

    // 6. On-Going Leave Deployment (Active Absences Module)
    $stmt = $pdo->prepare("
        SELECT p.*, u.full_name, u.username as acc_name, u.role, approver.full_name as approver_name 
        FROM pto_requests p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN users approver ON p.approved_by = approver.id
        WHERE ? BETWEEN p.start_date AND p.end_date 
        AND p.status = 'Approved'
        ORDER BY p.id DESC
    ");
    $stmt->execute([$today]);
    $active_leaves = $stmt->fetchAll();
    
    $ongoing_leave_html = '';
    if (empty($active_leaves)) {
        $ongoing_leave_html = '<tr><td colspan="7" style="text-align: center; padding: 4rem; color: var(--text-muted);">Zero active leave deployments broadcasted currently.</td></tr>';
    } else {
        foreach($active_leaves as $leave) {
            $ongoing_leave_html .= '
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                    <td style="padding: 0.75rem 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.75rem; color: white;">'.strtoupper(substr($leave['acc_name'], 0, 1)).'</div>
                            <div>
                                <div style="font-weight: 700; color: white; font-size: 0.8rem;">'.htmlspecialchars($leave['full_name']).'</div>
                                <div style="font-size: 0.6rem; color: var(--text-muted);">@'.htmlspecialchars($leave['acc_name']).'</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 0.75rem 0.85rem;"><span style="font-size: 0.65rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase;">'.htmlspecialchars($leave['role']).'</span></td>
                    <td style="padding: 0.75rem 0.85rem; color: var(--primary-color); font-weight: 700; font-size: 0.75rem;">'.htmlspecialchars($leave['leave_type']).'</td>
                    <td style="padding: 0.75rem 0.85rem; color: #cbd5e1; font-weight: 600; font-size: 0.7rem;">'.date('M d', strtotime($leave['start_date'])).' - '.date('M d', strtotime($leave['end_date'])).'</td>
                    <td style="padding: 0.75rem 0.85rem; color: var(--text-muted); font-size: 0.65rem; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">'.htmlspecialchars($leave['reason']).'</td>
                    <td style="padding: 0.75rem 0.85rem; color: #10b981; font-weight: 800; font-size: 0.7rem;">'.htmlspecialchars($leave['approver_name'] ?: 'System').'</td>
                    <td style="padding: 0.75rem 0.85rem; text-align: right;"><span style="background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800;">AUTHORIZED</span></td>
                </tr>';
        }
    }

    // Fetch latest personal PTO status
    $stmt = $pdo->prepare("SELECT id, status FROM pto_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $own_pto = $stmt->fetch();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'total_staff' => $total_staff,
        'active_now' => $active_now,
        'completed_today' => $completed_today,
        'p_pto' => number_format($p_pto, 4),
        'p_total_hours' => number_format($p_total_hours, 2),
        'p_cutoff_days' => $p_cutoff_days,
        'p_cutoff_label' => $cutoff['label'],
        'p_stale_session' => $p_stale ? true : false,
        'p_stale_date' => $p_stale ? date('M d', strtotime($p_stale['attendance_date'])) : '',
        'p_status' => $p_status,
        'p_time_in' => $p_time_in,
        'activity_feed_html' => $activity_feed_html,
        'staff_monitoring_html' => $staff_monitoring_html,
        'staff_audit_html' => $staff_audit_html,
        'tl_pto_queue_html' => $tl_pto_queue_html,
        'tl_pto_ledger_html' => $tl_pto_ledger_html,
        'ongoing_leave_html' => $ongoing_leave_html,
        'pending_staff_pto' => $pending_pto_count,
        'active_leave_count' => $ongoing_leaves,
        'latest_pto_id' => $latest_pto ? $latest_pto['id'] : 0,
        'latest_pto_name' => $latest_pto ? $latest_pto['full_name'] : '',
        'latest_attendance_id' => $latest_attendance ? $latest_attendance['id'] : 0,
        'latest_attendance_name' => $latest_attendance ? $latest_attendance['full_name'] : '',
        'latest_attendance_type' => ($latest_attendance && $latest_attendance['time_out']) ? 'TIME-OUT' : 'TIME-IN',
        'latest_attendance_time' => $latest_attendance ? $latest_attendance['updated_at'] : '',
        'latest_pto_own_id' => $own_pto ? $own_pto['id'] : 0,
        'latest_pto_own_status' => $own_pto ? $own_pto['status'] : '',
        'stale_incidents' => $alerts,
        'last_relay' => date('h:i:s A')
    ]);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
