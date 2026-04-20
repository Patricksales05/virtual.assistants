<?php
require_once 'shared_db.php';

try {
    $pdo->exec("ALTER TABLE emails ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL AFTER message");
    $pdo->exec("ALTER TABLE emails ADD COLUMN attachment_type VARCHAR(100) DEFAULT NULL AFTER attachment_path");
    $pdo->exec("ALTER TABLE emails ADD COLUMN attachment_name VARCHAR(255) DEFAULT NULL AFTER attachment_type");
    $pdo->exec("ALTER TABLE emails ADD COLUMN attachment_size INT DEFAULT 0 AFTER attachment_name");
    echo "Emails table upgraded successfully with attachment protocol fields.\n";
} catch (PDOException $e) {
    echo "Database Upgrade Logic: Columns may already exist or error: " . $e->getMessage() . "\n";
}

// Create uploads directory
$dir = 'uploads/communications';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
    echo "Asset repository initialized: $dir\n";
}
?>
