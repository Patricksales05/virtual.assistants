<?php
require_once 'db_config.php';

// Auth Guard: Only Operations Manager can authorize global leave
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized Access Protocol Triggered']);
        exit();
    }
    die("Unauthorized Access Protocol Triggered.");
}

if (!empty($_GET['id']) && !empty($_GET['status'])) {
    $req_id = $_GET['id'];
    $new_status = $_GET['status']; // 'Approved' or 'Denied'

    try {
        $stmt = $pdo->prepare("UPDATE pto_requests SET status = ?, approved_by = ? WHERE id = ?");
        $stmt->execute([$new_status, $_SESSION['user_id'], $req_id]);

        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Organizational leave status updated']);
            exit();
        }
        header("Location: dashboard.php?success=pto_updated");
        exit();
    } catch (PDOException $e) {
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        }
        die("Operational Failure: " . $e->getMessage());
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>
