<?php
require_once 'db_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

try {
    if ($action === 'time_in') {
        // Strict check: don't allow duplicate Time In for today (Team Led context)
        $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND attendance_date = ?");
        $stmt->execute([$user_id, $today]);
        if ($stmt->fetch()) {
             if (isset($_GET['ajax'])) {
                 header('Content-Type: application/json');
                 echo json_encode(['success' => false, 'error' => 'Already Clocked In Today']);
                 exit();
             }
             header("Location: dashboard.php?msg=Already Clocked In Today");
             exit();
        }

        $stmt = $pdo->prepare("INSERT INTO attendance (user_id, time_in, attendance_date, status) VALUES (?, ?, ?, 'Regular')");
        $stmt->execute([$user_id, $now, $today]);
        
        if (isset($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Time In Synchronized']);
            exit();
        }
        header("Location: dashboard.php?msg=Time In Success");
        exit();

    } elseif ($action === 'time_out') {
        // Fetch latest active session (could be today or a stale session from a previous day)
        $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND time_out IS NULL ORDER BY attendance_date DESC, id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $session = $stmt->fetch();

        if ($session) {
            $stmt = $pdo->prepare("UPDATE attendance SET time_out = ? WHERE id = ?");
            $stmt->execute([$now, $session['id']]);
            
            if (isset($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Time Out Synchronized']);
                exit();
            }
            header("Location: dashboard.php?msg=Time Out Success");
            exit();
        } else {
             if (isset($_GET['ajax'])) {
                 header('Content-Type: application/json');
                 echo json_encode(['success' => false, 'error' => 'No Active Session Found']);
                 exit();
             }
             header("Location: dashboard.php?msg=No Active Session Found");
             exit();
        }
    } else {
        header("Location: dashboard.php?msg=Invalid Action");
        exit();
    }
} catch (PDOException $e) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
    die("Deployment error: " . $e->getMessage());
}
?>
