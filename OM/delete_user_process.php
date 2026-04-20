<?php
require_once 'db_config.php';

// Auth Guard: Only Operations Manager can purge organizational records
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized Access Protocol Triggered']);
        exit();
    }
    die("Unauthorized Access Protocol Triggered.");
}

if (!empty($_GET['id'])) {
    $user_id = $_GET['id'];

    try {
        // High-Fidelity Purge: Remove user from the organizational ledger
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);

        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'User record definitively purged from the system.']);
            exit();
        }
        header("Location: dashboard.php?success=user_deleted");
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
