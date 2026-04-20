<?php
require_once 'OM/db_config.php';
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

try {
    echo "Current Date: $today\n";
    
    $query = "
        SELECT a.id, a.attendance_date, a.user_id, u.username, u.full_name, u.role, a.time_in, a.time_out
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE a.time_out IS NULL 
        AND a.attendance_date < '$today'
        ORDER BY a.id DESC LIMIT 10
    ";
    
    $stale = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($stale) . " stale sessions.\n";
    foreach ($stale as $s) {
        echo "ID: {$s['id']} | User: {$s['username']} | Role: {$s['role']} | Date: {$s['attendance_date']} | Time In: {$s['time_in']}\n";
    }
    
    // Check for Raynegaming17 specifically
    $stmt = $pdo->prepare("SELECT * FROM attendance a JOIN users u ON a.user_id = u.id WHERE u.username = 'Raynegaming17' ORDER BY a.id DESC LIMIT 5");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    echo "\nRecent sessions for Raynegaming17:\n";
    foreach ($rows as $r) {
        echo "ID: {$r['id']} | Date: {$r['attendance_date']} | Time In: {$r['time_in']} | Time Out: " . ($r['time_out'] ?? 'NULL') . "\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
