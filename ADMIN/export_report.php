<?php
require_once 'db_config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    exit('Unauthorized access');
}

$filter_date = $_GET['filter_date'] ?? '';
$where_clause = "1";
$params = [];

if (!empty($filter_date)) {
    $where_clause = "a.attendance_date = ?";
    $params[] = $filter_date;
}

// Fetch data
try {
    $sql = "
        SELECT a.*, u.full_name, u.role 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE $where_clause
        ORDER BY a.attendance_date DESC, a.time_in DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    exit('Database error');
}

// Set Headers for Excel Download
$filename = "Attendance_Report_" . ($filter_date ? $filter_date : "Full") . "_" . date('Ymd_His') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Set CSV Header
fputcsv($output, ['Full Name', 'Role', 'Attendance Date', 'Time In', 'Time Out', 'Total Hours']);

foreach ($data as $row) {
    // Calculate total hours
    $total_hrs = '--:--';
    if ($row['time_in'] && $row['time_out']) {
        $start = new DateTime($row['time_in']);
        $end = new DateTime($row['time_out']);
        $diff = $start->diff($end);
        $total_hrs = $diff->format('%H:%I:%S');
    }

    fputcsv($output, [
        $row['full_name'],
        $row['role'],
        date('M d, Y', strtotime($row['attendance_date'])),
        $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : 'N/A',
        $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : 'PENDING',
        $total_hrs
    ]);
}

fclose($output);
exit();
?>
