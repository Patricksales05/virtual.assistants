<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

try {
    // 1. Core Metrics
    $stmt_tot = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_approved = 1 AND id != ?");
    $stmt_tot->execute([$_SESSION['user_id']]);
    $total_staff = $stmt_tot->fetchColumn();
    $active_now = $pdo->query("SELECT COUNT(*) FROM attendance WHERE time_out IS NULL")->fetchColumn();
    $completed_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(time_out) = '$today'")->fetchColumn();
    
    // 1.1 Personal Telemetry (for Header & Attendance Card Synchronization)
    $user_id = $_SESSION['user_id'];
    require_once '../accrual_helper.php';
    $p_pto = calculate_realtime_pto($user_id, $pdo);
    $p_total_hours = get_total_cumulative_hours($user_id, $pdo);
    $cutoff = get_current_cutoff_dates();
    $p_cutoff_days = get_days_worked_in_cutoff($user_id, $pdo, $cutoff['start'], $cutoff['end']);
    $p_stale = get_stale_active_session($user_id, $pdo);

    // 1.2 Personal Attendance Status
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
        $p_time_in = $p_att['time_in'] ? date('h:i A', strtotime($p_att['time_in'])) : '--:--';
    }

    // 2. Organization Alerts (Forgotten Time-Out Detection)
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
        // High-Fidelity Leave Detection: Check if this breach coincides with an authorized protocol
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

    // 3. PTO Requests Engine (Pending Count & Latest Detection)
    $pending_pto_count = $pdo->query("SELECT COUNT(*) FROM pto_requests WHERE status = 'Pending'")->fetchColumn();
    $latest_pto = $pdo->query("
        SELECT p.id, u.full_name 
        FROM pto_requests p 
        JOIN users u ON p.user_id = u.id 
        WHERE p.status = 'Pending' 
        ORDER BY p.id DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    // 4. Active Ongoing Leave Count (Absence Oversight)
    $active_leave_count_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM pto_requests 
        WHERE status = 'Approved' 
        AND ? BETWEEN start_date AND end_date
    ");
    $active_leave_count_stmt->execute([$today]);
    $ongoing_leaves = $active_leave_count_stmt->fetchColumn();

    // 5. MODULE RELAYS (HTML Fragments for Real-Time UI Sync)
    
    // 5.1 Live Activity Feed (Unified Attendance & PTO Action Items)
    // Fetch Recent Attendance
    $stmt = $pdo->query("SELECT a.id, a.time_in, a.time_out, u.full_name, u.role, u.username as acc_name, 'ATTENDANCE' as type FROM attendance a JOIN users u ON a.user_id = u.id ORDER BY a.id DESC LIMIT 6");
    $att_records = $stmt->fetchAll();
    
    // Fetch Pending PTO
    $stmt = $pdo->query("SELECT p.id, p.created_at as time_in, NULL as time_out, u.full_name, u.role, u.username as acc_name, 'PTO' as type FROM pto_requests p JOIN users u ON p.user_id = u.id WHERE p.status = 'Pending' ORDER BY p.id DESC LIMIT 3");
    $pto_records = $stmt->fetchAll();
    
    // Combine and Sort
    $combined_feed = array_merge($att_records, $pto_records);
    usort($combined_feed, function($a, $b) { return strtotime($b['time_in']) - strtotime($a['time_in']); });
    
    $live_feed_html = '';
    foreach($combined_feed as $item) {
        if ($item['type'] === 'PTO') {
            $l_status = '<span style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; padding: 0.2rem 0.6rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; border: 1px solid rgba(245, 158, 11, 0.2); cursor: pointer;" onclick="switchModule(\'module-pto\', null)">ACTION REQUIRED</span>';
            $time_label = '<i class="fas fa-clock" style="margin-right:0.3rem;"></i>' . date('h:i A', strtotime($item['time_in']));
            $role_display = '<span style="color: #f59e0b; font-weight: 800;">LEAVE FILING</span>';
        } else {
            $l_status = $item['time_out'] ? '<span class="badge badge-success" style="font-size: 0.6rem;">Completed</span>' : '<span class="badge badge-warning" style="background: rgba(99, 102, 241, 0.15); color: #6366f1; font-size: 0.6rem;">Active</span>';
            $time_label = date('h:i A', strtotime($item['time_in']));
            $role_display = htmlspecialchars($item['role']);
        }
        
        $live_feed_html .= '
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.015); '.($item['type'] === 'PTO' ? 'background: rgba(245, 158, 11, 0.02);' : '').'">
                <td>
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <div class="avatar" style="width: 24px; height: 24px; font-size: 0.6rem; background: '.($item['type'] === 'PTO' ? '#f59e0b' : 'var(--primary-color)').';">'.strtoupper(substr($item['acc_name'], 0, 1)).'</div>
                        <div>
                            <div style="font-weight: 600; font-size: 0.8rem;">'.htmlspecialchars($item['full_name']).'</div>
                            <div style="font-size: 0.65rem; color: var(--text-muted);">@'.htmlspecialchars($item['acc_name']).'</div>
                        </div>
                    </div>
                </td>
                <td style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #6366f1;">'.$role_display.'</td>
                <td style="color: var(--accent-green); font-weight: 600; font-size: 0.8rem;">'.$time_label.'</td>
                <td>'.$l_status.'</td>
            </tr>';
    }

    // 3. Attendance Activity: Latest Entry Tracking
    $latest_attendance = $pdo->query("
        SELECT a.id, u.full_name, a.time_out, a.updated_at 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        ORDER BY a.updated_at DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    // 5.2 Staff Request Queue (Pending Only)
    $stmt = $pdo->query("SELECT p.*, u.full_name, u.username as acc_name, u.role FROM pto_requests p JOIN users u ON p.user_id = u.id WHERE p.status = 'Pending' ORDER BY p.id DESC");
    $p_queue = $stmt->fetchAll();
    $pto_queue_html = '';
    if (empty($p_queue)) {
        $pto_queue_html = '<tr><td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-muted); opacity: 0.5;"><i class="fas fa-check-circle" style="display: block; font-size: 2rem; margin-bottom: 1rem;"></i> No pending requests in queue.</td></tr>';
    } else {
        foreach($p_queue as $req) {
            $pto_queue_html .= '
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                    <td style="padding: 0.75rem 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.65rem;">
                            <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.7rem; color: white;">'.strtoupper(substr($req['acc_name'], 0, 1)).'</div>
                            <div>
                                <div style="font-weight: 700; color: white; font-size: 0.8rem;">'.htmlspecialchars($req['full_name']).'</div>
                                <div style="font-size: 0.65rem; color: var(--text-muted);">@'.htmlspecialchars($req['acc_name']).'</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 0.75rem 0.85rem;"><span style="color: #6366f1; font-weight: 800; font-size: 0.7rem;">'.htmlspecialchars($req['leave_type']).'</span></td>
                    <td style="padding: 0.75rem 0.85rem; color: #cbd5e1; font-weight: 600; font-size: 0.75rem;">'.date('M d', strtotime($req['start_date'])).' - '.date('M d', strtotime($req['end_date'])).'</td>
                    <td style="padding: 0.75rem 0.85rem; color: var(--text-muted); font-size: 0.7rem; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">'.htmlspecialchars($req['reason']).'</td>
                    <td style="padding: 0.75rem 0.85rem; text-align: right;">
                        <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
                            <button onclick="handlePTO('.$req['id'].', \'Approved\')" style="background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.2); padding: 0.35rem 0.85rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; cursor: pointer; text-transform: uppercase;">Authorize</button>
                            <button onclick="handlePTO('.$req['id'].', \'Denied\')" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.2); padding: 0.35rem 0.85rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800; cursor: pointer; text-transform: uppercase;">Reject</button>
                        </div>
                    </td>
                </tr>';
        }
    }

    // 5.3 Staff Leave Audit Ledger (History with Filtering Capability)
    $ledger_search = $_GET['ledger_search'] ?? '';
    $ledger_period = $_GET['ledger_period'] ?? 'all';
    
    $ledger_params = [];
    $ledger_where = "WHERE p.status != 'Pending'";
    
    if (!empty($ledger_search)) {
        $ledger_where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR p.leave_type LIKE ?)";
        $ledger_params[] = "%$ledger_search%";
        $ledger_params[] = "%$ledger_search%";
        $ledger_params[] = "%$ledger_search%";
    }
    
    if ($ledger_period !== 'all') {
        switch ($ledger_period) {
            case 'today':
                $ledger_where .= " AND DATE(p.created_at) = CURDATE()";
                break;
            case 'weekly':
                $ledger_where .= " AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'monthly':
                $ledger_where .= " AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                break;
            case 'yearly':
                $ledger_where .= " AND p.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
                break;
        }
    }

    $stmt = $pdo->prepare("SELECT p.*, u.full_name, u.username as acc_name, approver.full_name as approver_name FROM pto_requests p JOIN users u ON p.user_id = u.id LEFT JOIN users approver ON p.approved_by = approver.id $ledger_where ORDER BY p.id DESC LIMIT 30");
    $stmt->execute($ledger_params);
    $p_ledger = $stmt->fetchAll();
    
    $pto_ledger_html = '';
    if (empty($p_ledger)) {
        $pto_ledger_html = '<tr><td colspan="5" style="text-align: center; padding: 4rem; color: var(--text-muted); opacity: 0.5;">No records found in the organizational ledger for this classification.</td></tr>';
    } else {
        foreach($p_ledger as $log) {
            $s_color = ($log['status'] === 'Approved') ? '#10b981' : '#ef4444';
            $pto_ledger_html .= '
                <tr style="border-bottom: 1px solid rgba(255,255,255,0.02);">
                    <td style="padding: 0.75rem 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.6rem;">
                            <div style="width: 24px; height: 24px; background: rgba(255,255,255,0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.65rem; color: #94a3b8;">'.strtoupper(substr($log['acc_name'], 0, 1)).'</div>
                            <div>
                                <div style="font-weight: 700; color: white; font-size: 0.75rem;">'.htmlspecialchars($log['full_name']).'</div>
                                <div style="font-size: 0.6rem; color: var(--text-muted);">@'.htmlspecialchars($log['acc_name']).'</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 0.75rem 0.85rem;"><span style="color: #94a3b8; font-weight: 700; font-size: 0.65rem;">'.htmlspecialchars($log['leave_type']).'</span></td>
                    <td style="padding: 0.75rem 0.85rem; color: #cbd5e1; font-size: 0.7rem;">'.date('M d', strtotime($log['start_date'])).' - '.date('M d', strtotime($log['end_date'])).'</td>
                    <td style="padding: 0.75rem 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.4rem;">
                            <div style="width: 16px; height: 16px; background: rgba(99, 102, 241, 0.1); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.5rem; color: #6366f1; font-weight: 800;">'.strtoupper(substr($log['approver_name'] ?: 'S', 0, 1)).'</div>
                            <span style="font-size: 0.7rem; font-weight: 600; color: '.($log['approver_name'] ? '#6366f1' : 'var(--text-muted)').';">'.htmlspecialchars($log['approver_name'] ?: 'Auto-System').'</span>
                        </div>
                    </td>
                    <td style="padding: 0.75rem 0.85rem; text-align: right;">
                        <span style="background: '.$s_color.'15; color: '.$s_color.'; border: 1px solid '.$s_color.'30; padding: 0.25rem 0.65rem; border-radius: 100px; font-size: 0.55rem; font-weight: 800; text-transform: uppercase;">'.htmlspecialchars($log['status']).'</span>
                    </td>
                </tr>';
        }
    }

    // 4. PTO Requests Engine (Pending Count & Latest Detection)
    $audit_start = $_GET['audit_start'] ?? date('Y-m-d', strtotime('-7 days'));
    $audit_end = $_GET['audit_end'] ?? $today;
    
    $stmt = $pdo->prepare("SELECT a.*, u.full_name, u.role, u.username as acc_name FROM attendance a JOIN users u ON a.user_id = u.id WHERE (a.attendance_date BETWEEN ? AND ? OR DATE(a.time_out) BETWEEN ? AND ? OR a.time_out IS NULL) ORDER BY a.id DESC LIMIT 50");
    $stmt->execute([$audit_start, $audit_end, $audit_start, $audit_end]);
    $audit_logs = $stmt->fetchAll();
    
    $audit_relay_html = '';
    foreach($audit_logs as $log) {
        $s_t = new DateTime($log['time_in']);
        $e_t = $log['time_out'] ? new DateTime($log['time_out']) : null;
        $dur = '--:--:--';
        $pto = '--';
        if ($e_t) {
            $diff = $s_t->diff($e_t);
            $dur = $diff->format('%H:%I:%S');
            $ds = $e_t->getTimestamp() - $s_t->getTimestamp();
            $pto = number_format(($ds / 3600) * (6.66 / 160), 4);
        } else {
            // Live Session Logic for Staff Audit (OM)
            $now = new DateTime();
            $diff = $s_t->diff($now);
            $dur = $diff->format('%H:%I:%S');
            $ds = $now->getTimestamp() - $s_t->getTimestamp();
            $pto = number_format(($ds / 3600) * (6.66 / 160), 4);
        }
        $audit_relay_html .= '
            <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                <td style="padding: 0.75rem 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.7rem; color: white;">'.strtoupper(substr($log['acc_name'], 0, 1)).'</div>
                        <div>
                            <div style="font-weight: 700; color: white; font-size: 0.8rem;">'.htmlspecialchars($log['full_name']).'</div>
                            <div style="font-size: 0.6rem; color: var(--text-muted);">@'.htmlspecialchars($log['acc_name']).'</div>
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
                        <div style="font-size: 0.55rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem;"><span style="width: 5px; height: 5px; background: #f59e0b; border-radius: 50%; display: inline-block; animation: pulse 2s infinite;"></span> In Session</div>
                    ').'
                </td>
                <td style="padding: 0.75rem 0.85rem;">
                    <div style="color: var(--primary-color); font-weight: 800; font-size: 0.75rem;">'.$dur.'</div>
                    <div style="font-size: 0.55rem; color: #10b981; font-weight: 700;">'.$pto.' <small style="opacity:0.6;">HRS</small></div>
                </td>
                <td style="padding: 0.75rem 0.85rem; text-align: right;">
                    <span class="badge" style="background: rgba(99, 102, 241, 0.1); color: var(--primary-color); border: 1px solid rgba(99, 102, 241, 0.2); font-size: 0.55rem; padding: 0.2rem 0.6rem;">'.htmlspecialchars($log['role']).'</span>
                </td>
            </tr>';
    }

    // 5.4 Active Ongoing Leave (Ongoing Leave Module)
    $stmt = $pdo->prepare("SELECT p.*, u.full_name, u.username as acc_name, u.role, approver.full_name as approver_name FROM pto_requests p JOIN users u ON p.user_id = u.id LEFT JOIN users approver ON p.approved_by = approver.id WHERE p.status = 'Approved' AND ? BETWEEN p.start_date AND p.end_date");
    $stmt->execute([$today]);
    $active_leaves = $stmt->fetchAll();
    $ongoing_leave_html = '';
    foreach($active_leaves as $leave) {
        $ongoing_leave_html .= '
            <tr>
                <td style="padding: 0.75rem 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <div style="width: 28px; height: 28px; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.7rem; color: white;">'.strtoupper(substr($leave['acc_name'], 0, 1)).'</div>
                        <div>
                            <div style="font-weight: 700; color: white; font-size: 0.8rem;">'.htmlspecialchars($leave['full_name']).'</div>
                            <div style="font-size: 0.6rem; color: var(--text-muted);">@'.htmlspecialchars($leave['acc_name']).'</div>
                        </div>
                    </div>
                </td>
                <td style="padding: 0.75rem 0.85rem;"><span style="font-size: 0.7rem; color: #cbd5e1; font-weight: 600; text-transform: uppercase;">'.htmlspecialchars($leave['role']).'</span></td>
                <td style="padding: 0.75rem 0.85rem;"><span style="background: rgba(99, 102, 241, 0.1); color: var(--primary-color); padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.6rem; font-weight: 800;">'.htmlspecialchars($leave['leave_type']).'</span></td>
                <td style="padding: 0.75rem 0.85rem; color: #cbd5e1; font-weight: 600; font-size: 0.7rem;">'.date('M d', strtotime($leave['start_date'])).' - '.date('M d', strtotime($leave['end_date'])).'</td>
                <td style="padding: 0.75rem 0.85rem; color: var(--text-muted); font-size: 0.65rem; max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">'.htmlspecialchars($leave['reason']).'</td>
                <td style="padding: 0.75rem 0.85rem; color: #10b981; font-weight: 700; font-size: 0.75rem;">'.htmlspecialchars($leave['approver_name'] ?: 'System').'</td>
                <td style="padding: 0.75rem 0.85rem; text-align: right;"><span style="color: #10b981; font-weight: 800; text-transform: uppercase; font-size: 0.6rem;">Authorized</span></td>
            </tr>';
    }
    // 5.5 Staff Directory (Users Module) - Now with Real-Time Session Telemetry
    $active_ids = $pdo->query("SELECT user_id FROM attendance WHERE time_out IS NULL")->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id != ? ORDER BY is_approved ASC, role ASC, full_name ASC");
    $stmt->execute([$_SESSION['user_id']]);
    $all_members = $stmt->fetchAll();
    $staff_directory_html = '';
    foreach($all_members as $member) {
        $status_badge = $member['is_approved'] ? 
            '<span style="color:var(--accent-green); font-weight:800; font-size:0.65rem; text-transform:uppercase;">Approved</span>' : 
            '<span style="color:#f59e0b; font-weight:800; font-size:0.65rem; text-transform:uppercase;">Pending</span>';
        
        $action_html = '';
        if (!$member['is_approved']) {
            $action_html = '<button onclick="approveUser('.$member['id'].', \''.addslashes($member['username']).'\')" style="background:var(--accent-green); color:white; border:none; padding:0.4rem 0.8rem; border-radius:8px; cursor:pointer; font-size:0.65rem; font-weight:800; text-transform: uppercase;">Approve</button>';
        } else {
            $action_html = '<span style="color:var(--text-muted); font-size:0.65rem;">AUTHORIZED</span>';
        }

        $row_class = !$member['is_approved'] ? 'is-pending' : ''; 
        $row_style = !$member['is_approved'] ? 'border-bottom: 1px solid rgba(255,255,255,0.02); background: rgba(239, 68, 68, 0.04); border-left: 3px solid #ef4444;' : 'border-bottom: 1px solid rgba(255,255,255,0.02);'; 

        $staff_directory_html .= '
            <tr class="'.$row_class.'" style="'.$row_style.'">
                <td style="padding: 0.75rem 0.85rem;">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <div class="avatar" style="width: 28px; height: 28px; font-weight: 700; font-size: 0.7rem; background: var(--primary-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white;">'.strtoupper(substr($member['username'], 0, 1)).'</div>
                        <div>
                            <div style="font-weight: 700; color: white; font-size: 0.8rem;">'.htmlspecialchars($member['full_name']).'</div>
                            <div style="font-size: 0.65rem; color: var(--text-muted);">@'.htmlspecialchars($member['username']).'</div>
                        </div>
                    </div>
                </td>
                <td style="padding: 0.75rem 0.85rem;"><span style="font-size: 0.65rem; font-weight: 800; color: #6366f1; text-transform: uppercase;">'.htmlspecialchars($member['role']).'</span></td>
                <td style="padding: 0.75rem 0.85rem;">
                    '.$status_badge.'
                    '.(in_array($member['id'], $active_ids) ? '
                        <div style="margin-top: 0.2rem; color: #10b981; font-weight: 800; font-size: 0.55rem; text-transform: uppercase; display: flex; align-items: center; gap: 0.3rem;">
                            <span style="width: 5px; height: 5px; background: #10b981; border-radius: 50%; animation: pulse 2s infinite;"></span> LIVE SHIFT
                        </div>
                    ' : '
                        <div style="margin-top: 0.2rem; color: var(--text-muted); font-weight: 700; font-size: 0.55rem; text-transform: uppercase;">OFFLINE</div>
                    ').'
                </td>
                <td style="text-align: right; padding: 0.75rem 0.85rem;">
                    <div style="display: flex; justify-content: flex-end; align-items: center; gap: 0.75rem;">
                        '.$action_html.'
                        <div style="display: flex; gap: 0.35rem;">
                            <a href="edit_user.php?id='.$member['id'].'" style="background: rgba(255,255,255,0.05); color: white; width: 26px; height: 26px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: 0.3s; border: 1px solid rgba(255,255,255,0.1);"><i class="fas fa-edit" style="font-size: 0.65rem;"></i></a>
                            <button onclick="deleteUser('.$member['id'].')" style="background: rgba(239, 68, 68, 0.1); color: #ef4444; width: 26px; height: 26px; border-radius: 6px; display: flex; align-items: center; justify-content: center; transition: 0.3s; border: 1px solid rgba(239, 68, 68, 0.2); cursor: pointer;"><i class="fas fa-trash-alt" style="font-size: 0.65rem;"></i></button>
                        </div>
                    </div>
                </td>
            </tr>';
    }

    // 6. Latest Account Request Detection
    $latest_user = $pdo->query("SELECT id, full_name, username FROM users WHERE is_approved = 0 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    // 5.6 Payroll Relay (High-Density Fiscal Data)
    $p_start = $_GET['payroll_start'] ?? date('Y-m-d', strtotime('-15 days'));
    $p_end = $_GET['payroll_end'] ?? $today;
    
    $payroll_stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.role, u.username,
               COALESCE((
                   SELECT SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)) 
                   FROM attendance 
                   WHERE user_id = u.id 
                   AND attendance_date BETWEEN ? AND ? 
                   AND time_out IS NOT NULL
               ), 0) as total_seconds
        FROM users u
        WHERE u.is_approved = 1 AND u.id != ?
        ORDER BY u.full_name ASC
    ");
    $payroll_stmt->execute([$p_start, $p_end, $_SESSION['user_id']]);
    $raw_pay = $payroll_stmt->fetchAll();
    
    $payroll_html = '';
    foreach($raw_pay as $row) {
        $l_count = count_approved_leaves($row['id'], $pdo, $p_start, $p_end);
        $d_worked = get_days_worked_in_cutoff($row['id'], $pdo, $p_start, $p_end);
        $h = floor($row['total_seconds'] / 3600);
        $m = floor(($row['total_seconds'] % 3600) / 60);
        $time_str = sprintf("%02d:%02d", $h, $m);
        
        $payroll_html .= '
            <tr class="payroll-row" style="border-bottom: 1px solid rgba(255, 255, 255, 0.02);">
                <td style="padding: 1rem;">
                    <div class="searchable-name" style="font-weight: 700; color: white;">'.htmlspecialchars($row['full_name']).'</div>
                    <span class="searchable-user" style="font-size: 0.6rem; color: var(--text-muted);">@'.htmlspecialchars($row['username']).'</span>
                </td>
                <td style="padding: 1rem;">
                    <span style="font-size: 0.65rem; color: #cbd5e1; font-weight: 700; text-transform: uppercase;">'.htmlspecialchars($row['role']).'</span>
                </td>
                <td style="padding: 1rem; color: white; font-weight: 800;">
                    <span class="raw-hours" data-seconds="'.$row['total_seconds'].'" data-leaves="'.$l_count.'" data-days="'.$d_worked.'">'.$time_str.'</span>
                </td>
                <td style="padding: 1rem; color: var(--primary-color); font-weight: 800;">'.$d_worked.' <small style="font-size: 0.55rem; opacity: 0.6;">DAYS</small></td>
                <td style="padding: 1rem; color: #ef4444; font-weight: 800;">'.$l_count.' <small style="font-size: 0.55rem; opacity: 0.6;">LEAVES</small></td>
                <td style="padding: 1rem; color: #f59e0b; font-weight: 800; font-size: 0.8rem;">'.number_format(calculate_realtime_pto($row['id'], $pdo), 4).'</td>
                <td style="padding: 1rem; font-weight: 800; font-size: 0.85rem;" class="deduction-display">--</td>
                <td style="padding: 1rem; color: #10b981; font-weight: 800; font-size: 0.85rem;" class="payout-display">--</td>
                <td style="padding: 1rem; text-align: right;">
                    <button onclick="openPayslip('.$row['id'].')" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.05)); border: 1px solid rgba(16, 185, 129, 0.2); color: #10b981; padding: 0.4rem 0.8rem; border-radius: 8px; cursor: pointer; transition: 0.4s; display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.6rem; font-weight: 800; text-transform: uppercase;">
                        <i class="fas fa-print" style="font-size: 0.75rem; opacity: 0.8;"></i>
                        <span>Print Payslip</span>
                    </button>
                </td>
            </tr>';
    }

    $curr_u = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $curr_u->execute([$_SESSION['user_id']]);
    $u_info = $curr_u->fetch();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'u_name' => $u_info['full_name'] ?? 'Executive',
        'u_initial' => strtoupper(substr($u_info['full_name'] ?? 'E', 0, 1)),
        'latest_user_id' => $latest_user ? $latest_user['id'] : 0,
        'latest_user_name' => $latest_user ? ($latest_user['full_name'] ?: $latest_user['username']) : '',
        'pending_approvals' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_approved = 0")->fetchColumn(),
        'stale_incidents' => $alerts,
        'p_pto' => number_format($p_pto, 4),
        'p_total_hours' => number_format($p_total_hours, 2),
        'p_cutoff_days' => $p_cutoff_days,
        'p_status' => $p_status,
        'p_time_in' => $p_time_in,
        'p_raw_time_in' => $p_att ? $p_att['time_in'] : '',
        'p_raw_time_out' => $p_att ? $p_att['time_out'] : '',
        'feed_html' => $live_feed_html,
        'pto_queue_html' => $pto_queue_html,
        'pto_ledger_html' => $pto_ledger_html,
        'audit_relay_html' => $audit_relay_html,
        'ongoing_leave_html' => $ongoing_leave_html,
        'staff_directory_html' => $staff_directory_html,
        'payroll_html' => $payroll_html,
        'pending_pto' => $pending_pto_count,
        'active_now' => $active_now,
        'completed_today' => $completed_today,
        'total_staff' => $total_staff,
        'active_leave_count' => $ongoing_leaves,
        'latest_pto_id' => $latest_pto ? $latest_pto['id'] : 0,
        'latest_pto_name' => $latest_pto ? $latest_pto['full_name'] : '',
        'latest_attendance_id' => $latest_attendance ? $latest_attendance['id'] : 0,
        'latest_attendance_name' => $latest_attendance ? $latest_attendance['full_name'] : '',
        'latest_attendance_type' => ($latest_attendance && $latest_attendance['time_out']) ? 'TIME-OUT' : 'TIME-IN',
        'latest_attendance_time' => $latest_attendance ? $latest_attendance['updated_at'] : '',
        'server_time' => date('h:i:s A')
    ]);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
