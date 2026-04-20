<?php
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        header("Location: index.php?error=empty");
        exit();
    }

    try {
        // Prepare query to find user
        $stmt = $pdo->prepare("SELECT id, username, password, role, is_approved FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Check if user is approved (optional but good practice)
            if ($user['is_approved'] == 0) {
                header("Location: index.php?error=not_approved");
                exit();
            }

            // Authentication successful, set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Logic to redirect based on role (Case-insensitive)
            $user_role = strtolower(trim($user['role']));
            if ($user_role === 'operations manager') {
                header("Location: dashboard.php");
            } else {
                // If not OM, prevent login to this specific executive portal
                header("Location: index.php?error=unauthorized");
            }
            exit();
        } else {
            // Invalid credentials
            header("Location: index.php?error=invalid");
            exit();
        }
    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
} else {
    // If accessed directly without POST
    header("Location: index.php");
    exit();
}
?>
