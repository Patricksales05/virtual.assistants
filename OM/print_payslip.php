<?php
require_once 'db_config.php';

$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    die("Unauthorized Access");
}

$target_user_id = $_GET['user_id'] ?? 0;
$start_date = $_GET['start'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date = $_GET['end'] ?? date('Y-m-d');
$rate = floatval($_GET['rate'] ?? 100);
$currency = $_GET['currency'] ?? 'PHP';
$conversion = floatval($_GET['conversion'] ?? 1);

try {
    // Get target user details
    $stmt = $pdo->prepare("SELECT full_name, username, role FROM users WHERE id = ?");
    $stmt->execute([$target_user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        die("User not found.");
    }
    
    // Get attendance records - Use COALESCE for legacy records missing attendance_date
    $att_stmt = $pdo->prepare("
        SELECT id, attendance_date, time_in, time_out, 
               COALESCE(attendance_date, DATE(time_in)) as active_date,
               TIMESTAMPDIFF(SECOND, time_in, time_out) as duration_sec
        FROM attendance 
        WHERE user_id = ? AND (attendance_date BETWEEN ? AND ? OR DATE(time_in) BETWEEN ? AND ?)
        ORDER BY active_date ASC, time_in ASC
    ");
    $att_stmt->execute([$target_user_id, $start_date, $end_date, $start_date, $end_date]);
    $attendance_logs = $att_stmt->fetchAll();

    // Get Leave records
    $leave_stmt = $pdo->prepare("
        SELECT leave_type, start_date, end_date, reason
        FROM pto_requests
        WHERE user_id = ? AND status = 'Approved'
        AND start_date <= ? AND end_date >= ?
        ORDER BY start_date ASC
    ");
    $leave_stmt->execute([$target_user_id, $end_date, $start_date]);
    $leave_logs = $leave_stmt->fetchAll();

} catch (PDOException $e) {
    die("Database Error");
}

$currencyIcons = [ 'PHP' => '₱', 'USD' => '$', 'EUR' => '€' ];
$icon = $currencyIcons[$currency] ?? '';

$total_seconds = 0;
$days_worked = 0;
$unique_days = [];
foreach ($attendance_logs as $log) {
    if ($log['time_out']) {
        $total_seconds += (int)$log['duration_sec'];
        $d_key = $log['active_date'] ?? date('Y-m-d', strtotime($log['time_in']));
        $unique_days[$d_key] = true;
    }
}
$days_worked = count($unique_days);
$base_pay = $days_worked * $rate * $conversion;

$leave_days_in_period = 0;
foreach ($leave_logs as $leave) {
    $lap_s = max(strtotime($leave['start_date']), strtotime($start_date));
    $lap_e = min(strtotime($leave['end_date']), strtotime($end_date));
    if ($lap_s <= $lap_e) {
        $leave_days_in_period += round(($lap_e - $lap_s) / 86400) + 1;
    }
}
$leave_deduction = $leave_days_in_period * $rate * $conversion;
$net_payout = max(0, $base_pay - $leave_deduction);
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == 1;

if ($is_ajax):
?>
<div class="payslip-modal-content" style="text-align: left; color: #0f172a; padding: 1rem; border-radius: 12px;">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 1.5rem;">
        <div>
            <div style="font-size: 1.4rem; font-weight: 800; color: #0f172a; margin-bottom: 0.2rem;">Seamless Assist</div>
            <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Official Payslip & Attendance Log</div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Period</div>
            <div style="font-size: 0.85rem; font-weight: 800; color: #0f172a;"><?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?></div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
        <div>
            <div style="margin-bottom: 0.75rem;">
                <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Account Name</div>
                <div style="font-size: 0.9rem; font-weight: 800; color: #0f172a;"><?php echo htmlspecialchars($user['full_name']); ?></div>
            </div>
            <div style="margin-bottom: 0.75rem;">
                <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Username</div>
                <div style="font-size: 0.9rem; font-weight: 800; color: #6366f1;">@<?php echo htmlspecialchars($user['username']); ?></div>
            </div>
        </div>
        <div style="text-align: right;">
            <div style="margin-bottom: 0.75rem;">
                <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Classification</div>
                <div style="font-size: 0.9rem; font-weight: 800; color: #0f172a;"><?php echo strtoupper(htmlspecialchars($user['role'])); ?></div>
            </div>
            <div style="margin-bottom: 0.75rem;">
                <div style="font-size: 0.65rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Daily Base Rate</div>
                <div style="font-size: 0.9rem; font-weight: 800; color: #0f172a;"><?php echo $icon . number_format($rate * $conversion, 2); ?></div>
            </div>
        </div>
    </div>

    <h3 style="font-size: 0.95rem; font-weight: 800; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; color: #0f172a;">Attendance Ledger</h3>
    <table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid #e2e8f0;">
                <th style="padding: 0.5rem; font-size: 0.65rem; color: #64748b;">Date</th>
                <th style="padding: 0.5rem; font-size: 0.65rem; color: #64748b;">In</th>
                <th style="padding: 0.5rem; font-size: 0.65rem; color: #64748b;">Out</th>
                <th style="padding: 0.5rem; font-size: 0.65rem; color: #64748b; text-align: right;">Dur.</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (array_slice($attendance_logs, 0, 15) as $log): ?>
                <tr>
                    <td style="padding: 0.5rem; font-size: 0.8rem; font-weight: 600; color: #0f172a;"><?php echo date('M d', strtotime($log['active_date'])); ?></td>
                    <td style="padding: 0.5rem; font-size: 0.8rem; color: #64748b;"><?php echo date('h:i A', strtotime($log['time_in'])); ?></td>
                    <td style="padding: 0.5rem; font-size: 0.8rem; color: #64748b;"><?php echo $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : '...'; ?></td>
                    <td style="padding: 0.5rem; font-size: 0.8rem; text-align: right; font-weight: 700; color: #6366f1;"><?php echo $log['duration_sec'] ? sprintf("%02d:%02d", floor($log['duration_sec']/3600), floor(($log['duration_sec']%3600)/60)) : '00:00'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if(count($attendance_logs) > 15): ?>
                <tr><td colspan="4" style="text-align:center; padding: 0.5rem; font-size: 0.65rem; color: #94a3b8;">+ <?php echo count($attendance_logs) - 15; ?> more entries</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="background: #f8fafc; border-radius: 8px; padding: 1.25rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <div style="font-size: 0.6rem; color: #64748b; font-weight: 700; text-transform: uppercase;">Total Active Days</div>
            <div style="font-size: 1.2rem; font-weight: 800; color: #0f172a;"><?php echo $days_worked; ?> Days</div>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 0.6rem; color: #10b981; font-weight: 700; text-transform: uppercase;">Net Payout</div>
            <div style="font-size: 1.6rem; font-weight: 800; color: #10b981;"><?php echo $icon . number_format($net_payout, 2); ?></div>
        </div>
    </div>
</div>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?php echo htmlspecialchars($user['full_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        @media print {
            @page { size: auto; margin: 0; }
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; margin: 0; padding: 10mm; font-size: 0.92rem; }
            .container { box-shadow: none !important; border: none !important; padding: 0; max-width: 100%; margin: 0; }
            table { border-collapse: collapse; width: 100%; border: 1px solid #ddd; margin-bottom: 1.5rem; }
            tr { page-break-inside: avoid; }
            th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
            th { background-color: #f8fafc !important; color: black !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .summary-box { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; page-break-inside: avoid; padding: 1rem !important; }
            .emp-details, .header, h3 { page-break-inside: avoid; }
            .header { margin-bottom: 1.5rem !important; padding-bottom: 1rem !important; }
            .emp-details { margin-bottom: 1.5rem !important; }
        }
        
        * { font-family: 'Outfit', sans-serif; box-sizing: border-box; }
        body { background: #f1f5f9; color: #0f172a; padding: 2rem; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 2.5rem; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 1.5rem; }
        .company-name { font-size: 1.6rem; font-weight: 800; color: #0f172a; margin-bottom: 0.2rem; }
        .doc-title { font-size: 0.9rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
        
        .emp-details { display: flex; justify-content: space-between; margin-bottom: 2rem; }
        .detail-group { margin-bottom: 0.75rem; }
        .detail-label { font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; }
        .detail-value { font-size: 1rem; font-weight: 800; color: #0f172a; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 2rem; }
        th { text-align: left; padding: 1rem; font-size: 0.75rem; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #e2e8f0; }
        td { padding: 1rem; font-size: 0.9rem; border-bottom: 1px solid #f1f5f9; }
        
        .summary-box { background: #f8fafc; border-radius: 8px; padding: 1.5rem; display: flex; justify-content: space-between; align-items: flex-end; }
        
        .btn-print { background: #6366f1; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 700; cursor: pointer; text-transform: uppercase; transition: 0.3s; }
        .btn-print:hover { background: #4f46e5; }
    </style>
</head>
<body>
    <div class="container">
        <div class="no-print" style="text-align: right; margin-bottom: 1rem;">
            <button class="btn-print" onclick="window.print()">Print Document</button>
        </div>

        <div class="header">
            <div>
                <div class="company-name">Seamless Assist</div>
                <div class="doc-title">Official Payslip & Attendance Log</div>
            </div>
            <div style="text-align: right;">
                <div class="detail-label">Period</div>
                <div class="detail-value"><?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?></div>
            </div>
        </div>

        <div class="emp-details">
            <div>
                <div class="detail-group">
                    <div class="detail-label">Account Name</div>
                    <div class="detail-value"><?php echo htmlspecialchars($user['full_name']); ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Username</div>
                    <div class="detail-value" style="color: #6366f1;">@<?php echo htmlspecialchars($user['username']); ?></div>
                </div>
            </div>
            <div style="text-align: right;">
                <div class="detail-group">
                    <div class="detail-label">Classification</div>
                    <div class="detail-value"><?php echo strtoupper(htmlspecialchars($user['role'])); ?></div>
                </div>
                <div class="detail-group">
                    <div class="detail-label">Daily Base Rate</div>
                    <div class="detail-value"><?php echo $icon . number_format($rate * $conversion, 2); ?></div>
                </div>
            </div>
        </div>

        <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0;">Attendance Ledger</h3>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th style="text-align: right;">Duration</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attendance_logs)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #64748b; padding: 2rem;">No attendance records found for this period.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($attendance_logs as $log): ?>
                        <tr>
                            <td style="font-weight: 600;"><?php echo date('M d, Y', strtotime($log['active_date'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($log['time_in'])); ?></td>
                            <td>
                                <?php echo $log['time_out'] ? date('h:i A', strtotime($log['time_out'])) : '<span style="color: #f59e0b; font-weight: 700;">ONGOING</span>'; ?>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #6366f1;">
                                <?php 
                                    if ($log['time_out']) {
                                        $h = floor($log['duration_sec'] / 3600);
                                        $m = floor(($log['duration_sec'] % 3600) / 60);
                                        echo sprintf("%02d:%02d", $h, $m);
                                    } else {
                                        echo '--:--';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if (!empty($leave_logs)): ?>
        <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 1px solid #e2e8f0; margin-top: 2rem;">Authorized Leaves</h3>
        <table style="margin-bottom: 2rem;">
            <thead>
                <tr>
                    <th>Leave Type</th>
                    <th>Date Range</th>
                    <th>Justification</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leave_logs as $leave): ?>
                    <tr>
                        <td style="font-weight: 700; color: #f59e0b;"><?php echo htmlspecialchars($leave['leave_type']); ?></td>
                        <td style="font-weight: 600;"><?php echo date('M d', strtotime($leave['start_date'])); ?> - <?php echo date('M d, Y', strtotime($leave['end_date'])); ?></td>
                        <td style="color: #64748b;"><?php echo htmlspecialchars($leave['reason']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="summary-box">
            <div style="display: flex; gap: 2rem; align-items: flex-end;">
                <div>
                    <div class="detail-label">Total Validated Days</div>
                    <div class="detail-value" style="font-size: 1.5rem;"><?php echo $days_worked; ?> Days</div>
                </div>
                
                <?php if ($leave_days_in_period > 0): ?>
                <div style="padding-left: 2rem; border-left: 2px solid #e2e8f0;">
                    <div class="detail-label" style="color: #ef4444;">Leave Deductions</div>
                    <div class="detail-value" style="font-size: 1.5rem; color: #ef4444;">
                        <?php echo $leave_days_in_period; ?> Days <span style="font-size: 1rem; opacity: 0.8;">(<?php echo $icon . number_format($leave_deduction, 2); ?>)</span>
                    </div>
                </div>
                <?php else: ?>
                <div style="padding-left: 2rem; border-left: 2px solid #e2e8f0; opacity: 0.5;">
                    <div class="detail-label">Leaves Taken</div>
                    <div class="detail-value" style="font-size: 1.2rem;">0 Days</div>
                </div>
                <?php endif; ?>
            </div>

            <div style="text-align: right;">
                <div class="detail-label" style="color: #10b981;">Net Payout</div>
                <div class="detail-value" style="font-size: 2rem; color: #10b981;"><?php echo $icon . number_format($net_payout, 2); ?></div>
            </div>
        </div>

        <div style="margin-top: 3rem; text-align: center; font-size: 0.75rem; color: #94a3b8;">
            <p>This is a system-generated document. Unauthorized reproduction or alteration is strictly prohibited.</p>
        </div>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(() => {
                window.print();
            }, 600);
        }
    </script>
</body>
</html>
