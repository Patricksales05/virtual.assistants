<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

try {
    // 1. System Metrics
    try { $total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(); } catch (PDOException $e) { $total_users = 0; }
    try { $total_staff = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('Staff', 'Staff Member', 'STAFF MEMBER')")->fetchColumn(); } catch (PDOException $e) { $total_staff = 0; }
    try { $total_om = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Operations Manager'")->fetchColumn(); } catch (PDOException $e) { $total_om = 0; }
    try { $total_tl = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Team Lead'")->fetchColumn(); } catch (PDOException $e) { $total_tl = 0; }

    // Fetch Capacity Settings
    try {
        $max_capacity_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_node_capacity'");
        $max_raw = $max_capacity_stmt->fetchColumn();
        $max_capacity = $max_raw ? (int)$max_raw : 200;
    } catch (PDOException $e) {
        $max_capacity = 200;
    }
    $capacity_percentage = $max_capacity > 0 ? min(100, round(($total_users / $max_capacity) * 100)) : 0;

    // 2. Attendance Stats
    $active_now = $pdo->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = '$today' AND time_out IS NULL")->fetchColumn();
    $completed_today = $pdo->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = '$today' AND time_out IS NOT NULL")->fetchColumn();

    // 3. System Health Feed (Last 5 user registrations with full details)
    $stmt = $pdo->query("SELECT username, email, role, created_at, is_approved FROM users ORDER BY id DESC LIMIT 5");
    $recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $users_html = '';
    if (empty($recent_users)) {
        $users_html = '<tr><td colspan="4" style="text-align: center;">No registered users yet.</td></tr>';
    } else {
        foreach($recent_users as $row) {
            $status_badge = $row['is_approved'] ? 
                '<span class="badge" style="background: rgba(16, 185, 129, 0.1); color: #10b981; font-size: 0.45rem; padding: 0.1rem 0.4rem; border: 1px solid rgba(16, 185, 129, 0.15);">APPROVED</span>' : 
                '<span class="badge" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b; font-size: 0.45rem; padding: 0.1rem 0.4rem; border: 1px solid rgba(245, 158, 11, 0.15);">PENDING</span>';
            
            $users_html .= '
                <tr style="transition: background 0.2s;" onmouseover="this.style.background=\'rgba(255,255,255,0.02)\'" onmouseout="this.style.background=\'transparent\'">
                    <td style="padding: 0.5rem 0.65rem;">
                        <div style="display: flex; align-items: center; gap: 0.6rem;">
                            <div class="avatar" style="width: 20px; height: 20px; font-size: 0.55rem; background: var(--primary-color); border: none;">'.strtoupper(substr($row['username'], 0, 1)).'</div>
                            <div>
                                <div style="font-weight:700; color: white; line-height: 1; font-size: 0.65rem;">'.htmlspecialchars($row['username']).'</div>
                                <div style="font-size:0.55rem; color:var(--text-muted); margin-top: 2px;">'.htmlspecialchars($row['email']).'</div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 0.5rem 0.65rem; font-weight: 600; color: var(--text-muted); font-size: 0.65rem;">'.htmlspecialchars($row['role']).'</td>
                    <td style="padding: 0.5rem 0.65rem; font-weight: 600; color: var(--text-muted); font-size: 0.65rem;">'.date('M d, Y', strtotime($row['created_at'])).'</td>
                    <td style="padding: 0.5rem 0.65rem;">'.$status_badge.'</td>
                </tr>';
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'total_users' => $total_users,
        'total_staff' => $total_staff,
        'total_om' => $total_om,
        'total_tl' => $total_tl,
        'active_now' => $active_now,
        'completed_today' => $completed_today,
        'recent_users_html' => $users_html,
        'max_capacity' => $max_capacity,
        'capacity_percentage' => $capacity_percentage,
        'server_time' => date('h:i:s A')
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
