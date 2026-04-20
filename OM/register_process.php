<?php
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $address = $_POST['address'] ?? '';
    $region = $_POST['region'] ?? '';
    $city = $_POST['city'] ?? '';
    $barangay = $_POST['barangay'] ?? '';
    $role = $_POST['role'] ?? 'Staff';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $is_ajax = isset($_GET['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');

    // Simple validation
    if ($password !== $confirm_password) {
        if ($is_ajax) {
            echo json_encode(['success' => false, 'error' => 'password_mismatch', 'message' => 'Passwords do not match.']);
            exit();
        }
        header("Location: register.php?error=password_mismatch");
        exit();
    }

    try {
        // Check if username or email already exists
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $checkStmt->execute([$username, $email]);
        if ($checkStmt->fetch()) {
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => 'already_exists', 'message' => 'Username or Email already exists.']);
                exit();
            }
            header("Location: register.php?error=already_exists");
            exit();
        }

        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Prepare insert statement
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone_number, address, region, city, brgy, role, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // All new registrations require OM approval
        $is_approved = 0; 

        $stmt->execute([
            $username, 
            $hashed_password, 
            $full_name, 
            $email, 
            $phone_number, 
            $address, 
            $region, 
            $city, 
            $barangay, 
            $role, 
            $is_approved
        ]);

        if ($is_ajax) {
            echo json_encode(['success' => true, 'message' => 'Account created! Please wait for the OM to approve your access.']);
            exit();
        }

        // Success, redirect back to register page to show notice
        header("Location: register.php?success=registered");
        exit();

    } catch (PDOException $e) {
        if ($is_ajax) {
            echo json_encode(['success' => false, 'error' => 'db_error', 'message' => $e->getMessage()]);
            exit();
        }
        die("Error: " . $e->getMessage());
    }
} else {
    header("Location: register.php");
    exit();
}
?>
