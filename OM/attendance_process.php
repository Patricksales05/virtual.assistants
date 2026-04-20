<?php
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    if ($action === 'time_in') {
        // Strict check: don't allow duplicate Time In for today
        $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND attendance_date = ?");
        $stmt->execute([$user_id, $today]);
        
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, time_in, attendance_date, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $now, $today, 'Present']);
        }
    } elseif ($action === 'time_out') {
        // High-Precision Resolution: Target the most recent active session (Today or Legacy)
        $stmt = $pdo->prepare("UPDATE attendance SET time_out = ? WHERE user_id = ? AND time_out IS NULL ORDER BY attendance_date DESC, id DESC LIMIT 1");
        $stmt->execute([$now, $user_id]);
    }
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Attendance synchronicity confirmed.']);
        exit();
    }
    header("Location: dashboard.php?success=recorded");
} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
    header("Location: dashboard.php?error=database_error");
}
exit();
?>
