<?php
require_once 'shared_db.php';
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN otp_code VARCHAR(10) DEFAULT NULL, ADD COLUMN otp_expiry DATETIME DEFAULT NULL");
    echo "Success: Database updated.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
