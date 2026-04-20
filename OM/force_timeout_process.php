<?php
require_once 'db_config.php';

// Executive Auth Check: Ensure only Operations Manager or Team Leader can manually resolve breaches
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || ($current_role !== 'operations manager' && $current_role !== 'team lead' && $current_role !== 'team-lead')) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized Access Protocol Triggered']);
        exit();
    }
    header("Location: ../index.php");
    exit();
}

$attendance_id = $_GET['id'] ?? null;
date_default_timezone_set('Asia/Manila');
$now = date('Y-m-d H:i:s');

if ($attendance_id) {
    try {
        // High-Fidelity Organizational Overrule: Manually conclude the legacy deployment
        $stmt = $pdo->prepare("UPDATE attendance SET time_out = ? WHERE id = ? AND time_out IS NULL");
        $stmt->execute([$now, $attendance_id]);

        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Deployment concluded.']);
            exit();
        }
        $redirect = ($current_role === 'team lead' || $current_role === 'team-lead') ? '../TL/dashboard.php' : 'dashboard.php';
        header("Location: $redirect?success=resolved");
        exit();
    } catch (PDOException $e) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        }
        $redirect = ($current_role === 'team lead' || $current_role === 'team-lead') ? '../TL/dashboard.php' : 'dashboard.php';
        header("Location: $redirect?error=resolution_failed");
        exit();
    }
} else {
    $redirect = ($current_role === 'team lead' || $current_role === 'team-lead') ? '../TL/dashboard.php' : 'dashboard.php';
    header("Location: $redirect");
    exit();
}
exit();
?>
