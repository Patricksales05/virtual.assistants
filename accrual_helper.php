<?php
/**
 * Automated PTO Accrual Engine (Work-Hour Transformation)
 * High-Precision Multi-Role Benefit Tracker
 * 
 * Formula: 1 Month (160 Standard Work Hours) = 6.66 PTO Credits
 * Accrual Rate: 0.041625 PTO Credits per actual hour worked.
 */

date_default_timezone_set('Asia/Manila');

/**
 * Calculates High-Precision PTO credits based on actual duration logged in 'attendance'.
 * Sums all completed shifts + current active duration (if any).
 */
function calculate_staff_pto($user_id, $pdo, $start_date = null, $end_date = null) {
    if (!$user_id) return 0.0000;

    try {
        // 1. Calculate Accrued Credits from completed work hours
        $query = "SELECT SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)) FROM attendance WHERE user_id = ? AND time_out IS NOT NULL";
        $params = [$user_id];
        if ($start_date && $end_date) {
            $query .= " AND attendance_date BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $completed_seconds = (int)$stmt->fetchColumn();

        // 1.1 Calculate current active session seconds (Live Accrual)
        $query = "SELECT TIMESTAMPDIFF(SECOND, time_in, NOW()) FROM attendance WHERE user_id = ? AND time_out IS NULL";
        $params = [$user_id];
        if ($start_date && $end_date) {
            $query .= " AND attendance_date BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $active_seconds = (int)$stmt->fetchColumn();

        $total_seconds = $completed_seconds + $active_seconds;
        $total_hours = $total_seconds / 3600;
        
        // Accrual Rate: 0.041625 PTO Credits per actual hour worked
        $total_accrued = $total_hours * 0.041625;

        // 2. Deduct consumed PTO (Approved Leaves)
        $total_leave_days = count_approved_leaves($user_id, $pdo, $start_date, $end_date);
        $deduction = $total_leave_days * 8.0; 

        $final_credits = $total_accrued - $deduction;

        return round(max(0, $final_credits), 4);

    } catch (PDOException $e) {
        return 0.0000;
    }
}

/**
 * Totals all hours worked by a user across the entire operational history.
 * Used for the 'Total Hours' global metric.
 */
function get_total_cumulative_hours($user_id, $pdo) {
    if (!$user_id) return 0.00;
    try {
        $stmt = $pdo->prepare("SELECT SUM(TIMESTAMPDIFF(SECOND, time_in, IFNULL(time_out, NOW()))) FROM attendance WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $seconds = (int)$stmt->fetchColumn();
        return round($seconds / 3600, 2);
    } catch (PDOException $e) {
        return 0.00;
    }
}

/**
 * Returns the start and end dates for the current operational cutoff period.
 * Cycle 1: 1st to 15th | Cycle 2: 16th to End of Month.
 */
function get_current_cutoff_dates() {
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $day = (int)$now->format('d');
    $last_day = $now->format('t');

    if ($day <= 15) {
        return [
            'start' => $now->format('Y-m-01'),
            'end' => $now->format('Y-m-15'),
            'label' => '1st - 15th'
        ];
    } else {
        return [
            'start' => $now->format('Y-m-16'),
            'end' => $now->format('Y-m-' . $last_day),
            'label' => '16th - End'
        ];
    }
}

/**
 * Checks for any active sessions (time_out IS NULL) from previous days.
 * returns the record if found, or null.
 */
function get_stale_active_session($user_id, $pdo) {
    if (!$user_id) return null;
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $today = $now->format('Y-m-d');
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM attendance 
            WHERE user_id = ? 
            AND attendance_date < ? 
            AND time_out IS NULL 
            ORDER BY attendance_date DESC, time_in DESC LIMIT 1
        ");
        $stmt->execute([$user_id, $today]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Counts unique work days (attendance entries) within a specific date range.
 */
function get_days_worked_in_cutoff($user_id, $pdo, $start, $end) {
    if (!$user_id) return 0;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT attendance_date) 
            FROM attendance 
            WHERE user_id = ? 
            AND attendance_date BETWEEN ? AND ?
        ");
        $stmt->execute([$user_id, $start, $end]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Alias for backward compatibility or high-precision display (legacy support).
 */
function calculate_realtime_pto($user_id, $pdo) {
    return calculate_staff_pto($user_id, $pdo);
}

/**
 * Returns the count of approved leave days within a specific date range.
 * Calculates exact overlap with the period for accurate fiscal auditing.
 */
function count_approved_leaves($user_id, $pdo, $start_date = null, $end_date = null) {
    if (!$user_id) return 0;
    try {
        if ($start_date && $end_date) {
            $stmt = $pdo->prepare("
                SELECT start_date, end_date 
                FROM pto_requests 
                WHERE user_id = ? AND status = 'Approved'
                AND start_date <= ? AND end_date >= ?
            ");
            $stmt->execute([$user_id, $end_date, $start_date]);
            $leaves = $stmt->fetchAll();
            
            $total_days = 0;
            foreach ($leaves as $leave) {
                $overlap_start = max(strtotime($leave['start_date']), strtotime($start_date));
                $overlap_end = min(strtotime($leave['end_date']), strtotime($end_date));
                if ($overlap_start <= $overlap_end) {
                    $total_days += round(($overlap_end - $overlap_start) / 86400) + 1;
                }
            }
            return (int)$total_days;
        } else {
            // Legacy/Global fallback
            $stmt = $pdo->prepare("SELECT SUM(DATEDIFF(end_date, start_date) + 1) FROM pto_requests WHERE user_id = ? AND status = 'Approved'");
            $stmt->execute([$user_id]);
            return (int)$stmt->fetchColumn() ?: 0;
        }
    } catch (PDOException $e) {
        return 0;
    }
}
?>
