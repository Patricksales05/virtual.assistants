<?php
require_once 'db_config.php';

// Check if user is logged in
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'operations manager') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_user = $_POST['username'] ?? '';
    $new_pass = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
    $new_full_name = $_POST['full_name'] ?? '';
    $new_email = $_POST['email'] ?? '';
    $new_phone = $_POST['phone_number'] ?? '';
    $new_address = $_POST['address'] ?? '';
    $new_region = $_POST['region'] ?? '';
    $new_city = $_POST['city'] ?? '';
    $new_brgy = $_POST['barangay'] ?? '';
    $new_role = $_POST['role'] ?? 'Staff';

    // Basic validation
    if (empty($new_user) || empty($new_email) || empty($new_full_name)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone_number, address, region, city, brgy, role, is_approved) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$new_user, $new_pass, $new_full_name, $new_email, $new_phone, $new_address, $new_region, $new_city, $new_brgy, $new_role]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => "Successfully created account for $new_full_name."]);
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        if ($e->getCode() == 23000) {
            echo json_encode(['success' => false, 'message' => 'Username or Email already exists in the ledger.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Operational fault: ' . $e->getMessage()]);
        }
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
}
?>
