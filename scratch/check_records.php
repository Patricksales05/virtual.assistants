<?php
require dirname(__DIR__) . "/shared_db.php";
$stmt = $pdo->query("SELECT id, username, full_name, role FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Users: " . json_encode($users) . PHP_EOL;

$stmt = $pdo->query("SELECT * FROM attendance WHERE (time_in LIKE '2026-04-18%' OR time_out LIKE '2026-04-20%') ORDER BY id DESC");
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Relevant Records: " . json_encode($records) . PHP_EOL;
?>
