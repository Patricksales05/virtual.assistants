<?php
/**
 * CORE COMMAND CENTER: DATABASE SYNCHRONIZATION ENGINE
 * This script provisions the 'pto_requests' table within the 'virtual assistant database'.
 */

// 1. Establish Secure Connection Relay
require_once 'STAFF/db_config.php'; 

// 2. Define Deployment Target
$target_db = 'virtual assistant database';

try {
    // 3. Authoritative Table Provisioning
    $pdo->exec("USE `$target_db`;");
    
    $sql = "CREATE TABLE IF NOT EXISTS `pto_requests` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `start_date` date NOT NULL,
      `end_date` date NOT NULL,
      `leave_type` varchar(50) NOT NULL,
      `reason` text NOT NULL,
      `status` enum('Pending','Approved','Denied') DEFAULT 'Pending',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `pto_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

    $pdo->exec($sql);
    
    // 4. Integrity Validation
    $stmt = $pdo->query("SHOW TABLES LIKE 'pto_requests'");
    if ($stmt->fetch()) {
        // Redirection to Command Center on success
        header("Location: TL/dashboard.php?sync=success");
        exit();
    } else {
        echo "<h2 style='color:red;'>Deploy Error: Table 'pto_requests' could not be provisioned.</h2>";
    }

} catch (PDOException $e) {
    echo "<h1 style='font-family: sans-serif;'>System Relay Error</h1>";
    echo "<p style='color: gray;'>Environment: XAMPP / virtual assistant database</p>";
    echo "<p><strong>Details:</strong> " . $e->getMessage() . "</p>";
}
?>
