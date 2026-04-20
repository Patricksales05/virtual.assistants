<?php
require_once 'db_config.php';
session_start();

header('Content-Type: application/json');

// Auth Guard
$current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || $current_role !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized Access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_capacity'])) {
    $new_capacity = (int)$_POST['max_node_capacity'];
    
    if ($new_capacity < 1) {
        echo json_encode(['success' => false, 'message' => 'Capacity must be at least 1 node.']);
        exit();
    }

    try {
        // Ensure system_settings table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS system_settings (
                setting_key VARCHAR(50) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Prepare UPSERT statement
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES ('max_node_capacity', ?) 
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        
        $stmt->execute([(string)$new_capacity, (string)$new_capacity]);
        
        echo json_encode(['success' => true, 'message' => 'Capacity threshold synchronized.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database Sync Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid Request Protocol']);
}
?>
