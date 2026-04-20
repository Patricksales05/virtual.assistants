<?php
require_once 'db_config.php';

// Check if OM is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    header("Location: index.php");
    exit();
}

if (isset($_GET['id'])) {
    $user_id = $_GET['id'];
    $is_ajax = isset($_GET['ajax']);

    try {
        // Update user status
        $stmt = $pdo->prepare("UPDATE users SET is_approved = 1 WHERE id = ?");
        $stmt->execute([$user_id]);

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'User authorized successfully.']);
            exit();
        }

        // Success, redirect back to dashboard with success flag
        header("Location: dashboard.php?view=module-users&success=approved");
        exit();

    } catch (PDOException $e) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
            exit();
        }
        die("Error: " . $e->getMessage());
    }
} else {
    header("Location: dashboard.php?view=module-users");
    exit();
}
?>
