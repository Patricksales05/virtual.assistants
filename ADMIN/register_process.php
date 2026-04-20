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

    // Simple validation
    if ($password !== $confirm_password) {
        header("Location: register.php?error=password_mismatch");
        exit();
    }

    try {
        // Check if username or email already exists
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $checkStmt->execute([$username, $email]);
        if ($checkStmt->fetch()) {
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

        // Success, redirect to login
        header("Location: index.php?success=registered");
        exit();

    } catch (PDOException $e) {
        die("Error: " . $e->getMessage());
    }
} else {
    header("Location: register.php");
    exit();
}
?>
