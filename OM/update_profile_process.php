<?php
require_once 'db_config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($full_name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Identity markers cannot be empty.']);
        exit();
    }

    try {
        // Update core identity parameters
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, $user_id]);

        // Security key update logic
        if (!empty($new_password)) {
            if ($new_password !== $confirm_password) {
                echo json_encode(['success' => false, 'message' => 'Security keys do not match. Integrity check failed.']);
                exit();
            }
            if (strlen($new_password) < 6) {
                echo json_encode(['success' => false, 'message' => 'Encryption key too short. Minimum 6 bits required.']);
                exit();
            }
            
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $user_id]);
        }

        // Post-sync state update
        $_SESSION['username'] = $full_name;

        echo json_encode(['success' => true, 'message' => 'Organizational identity synchronized successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database exception detected: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction broadcast.']);
}
?>
