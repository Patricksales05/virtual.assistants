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
        $stmt = $pdo->prepare("SELECT id, username, password, role, is_approved, full_name FROM users WHERE username = ?");
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
            $_SESSION['full_name'] = $user['full_name'];

            // Logic to redirect based on role (Case-insensitive comparison)
            $user_role = strtolower(trim($user['role']));
            if ($user_role === 'staff' || $user_role === 'staff member') {
                header("Location: dashboard.php");
            } else {
                // If not staff, prevent login to this specific portal
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
