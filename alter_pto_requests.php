<?php
require_once 'db_config.php';
try {
    $pdo->exec("ALTER TABLE pto_requests ADD COLUMN approved_by INT NULL");
    $pdo->exec("ALTER TABLE pto_requests ADD CONSTRAINT fk_pto_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
    echo "Table pto_requests altered successfully.";
} catch (PDOException $e) {
    echo "Error altering table: " . $e->getMessage();
}
?>
