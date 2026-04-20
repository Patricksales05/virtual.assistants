<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access segment.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($full_name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Critical identity fields cannot be void.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Update basic profile info
        $update_sql = "UPDATE users SET full_name = ?, email = ? WHERE id = ?";
        $stmt = $pdo->prepare($update_sql);
        $stmt->execute([$full_name, $email, $user_id]);

        // 2. Handle Password Policy Override if requested
        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Security key mismatch detected.']);
                exit;
            }
            if (strlen($new_password) < 6) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Key length fails security protocol (Min 6 chars).']);
                exit;
            }
            
            $hashed_pw = password_hash($new_password, PASSWORD_DEFAULT);
            $pw_sql = "UPDATE users SET password = ? WHERE id = ?";
            $pw_stmt = $pdo->prepare($pw_sql);
            $pw_stmt->execute([$hashed_pw, $user_id]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Administrator identity synchronized successfully.']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database synchronization failure: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request vector.']);
}
?>
