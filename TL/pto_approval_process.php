<?php
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
    if (!isset($_SESSION['user_id']) || ($current_role !== 'team lead' && $current_role !== 'team-lead')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
        exit();
    }

    $request_id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? ''; // 'Approved' or 'Denied'

    if (!$request_id || !in_array($status, ['Approved', 'Denied'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE pto_requests SET status = ?, approved_by = ? WHERE id = ?");
        $stmt->execute([$status, $_SESSION['user_id'], $request_id]);

        echo json_encode(['success' => true, 'message' => "PTO request has been " . strtolower($status) . " successfully."]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}
?>
