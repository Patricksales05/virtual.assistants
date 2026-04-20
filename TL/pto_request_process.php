<?php
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
    if (!isset($_SESSION['user_id']) || ($current_role !== 'team lead' && $current_role !== 'team-lead')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $leave_type = $_POST['leave_type'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (empty($start_date) || empty($end_date) || empty($leave_type) || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO pto_requests (user_id, start_date, end_date, leave_type, reason) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $start_date, $end_date, $leave_type, $reason]);

        echo json_encode(['success' => true, 'message' => 'Your personal PTO request has been archived.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    }
    exit();
}
?>
