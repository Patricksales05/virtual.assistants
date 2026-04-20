<?php
require_once 'db_config.php';

// Authoritative PTO Submission Protocol
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // 1. Session Integrity Validation
    $current_role = isset($_SESSION['role']) ? strtolower(trim($_SESSION['role'])) : '';
    if (!isset($_SESSION['user_id']) || ($current_role !== 'staff' && $current_role !== 'staff member')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Relay denied.']);
        exit();
    }

    $user_id = $_SESSION['user_id'];
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $leave_type = $_POST['leave_type'] ?? '';
    $reason = $_POST['reason'] ?? '';

    // 2. Data Integrity Scrubbing
    if (empty($start_date) || empty($end_date) || empty($leave_type) || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'All parameters required in dataset.']);
        exit();
    }

    // 3. Logic Validation (Interval Logic)
    if (strtotime($end_date) < strtotime($start_date)) {
        echo json_encode(['success' => false, 'message' => 'Interval integrity error: End date preceded start date.']);
        exit();
    }

    try {
        // 4. Persistence Entry
        $stmt = $pdo->prepare("INSERT INTO pto_requests (user_id, start_date, end_date, leave_type, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([$user_id, $start_date, $end_date, $leave_type, $reason]);

        echo json_encode([
            'success' => true, 
            'message' => 'Your operational leave request has been successfully synchronized to the organizational ledger.'
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Relay error: ' . $e->getMessage()]);
    }
    exit();
}
?>
