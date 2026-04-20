<?php
// email_process.php - Centralized Messaging Engine
require_once 'shared_db.php';

$current_user = null;
$session_names = ['VA_OM_SESSION', 'VA_TL_SESSION', 'VA_STAFF_SESSION', 'VA_ADMIN_SESSION'];

// Identify origin to prioritize correct session
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$priority_order = $session_names;

if (stripos($referer, '/OM/') !== false) {
    $priority_order = ['VA_OM_SESSION', 'VA_TL_SESSION', 'VA_STAFF_SESSION', 'VA_ADMIN_SESSION'];
} elseif (stripos($referer, '/TL/') !== false) {
    $priority_order = ['VA_TL_SESSION', 'VA_OM_SESSION', 'VA_STAFF_SESSION', 'VA_ADMIN_SESSION'];
} elseif (stripos($referer, '/STAFF/') !== false) {
    $priority_order = ['VA_STAFF_SESSION', 'VA_OM_SESSION', 'VA_TL_SESSION', 'VA_ADMIN_SESSION'];
}

foreach ($priority_order as $name) {
    if (isset($_COOKIE[$name])) {
        session_name($name);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['user_id'])) {
            $current_user = $_SESSION;
            break;
        }
    }
}

if (!$current_user) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Session identification failure. Please re-authenticate.']);
    exit;
}

$user_id = $current_user['user_id'];

// Handle JSON Input
if (strpos($_SERVER["CONTENT_TYPE"] ?? '', 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if ($data) {
        $_POST = array_merge($_POST, $data);
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'fetch':
            $folder = $_GET['folder'] ?? 'inbox';
            $sql = "";
            $params = [$user_id];

            if ($folder === 'inbox') {
                $sql = "SELECT e.*, 
                               COALESCE(u.full_name, e.sender_name) as participant_name, 
                               COALESCE(u.role, 'External/System') as participant_role, 
                               e.sender_id as participant_id 
                        FROM emails e 
                        LEFT JOIN users u ON e.sender_id = u.id 
                        WHERE e.receiver_id = ? AND e.is_deleted_by_receiver = 0 
                        ORDER BY e.created_at DESC";
            } elseif ($folder === 'sent') {
                $sql = "SELECT e.*, 
                               COALESCE(u.full_name, e.receiver_name) as participant_name, 
                               COALESCE(u.role, 'External/System') as participant_role, 
                               e.receiver_id as participant_id 
                        FROM emails e 
                        LEFT JOIN users u ON e.receiver_id = u.id 
                        WHERE e.sender_id = ? AND e.is_deleted_by_sender = 0 
                        ORDER BY e.created_at DESC";
            } elseif ($folder === 'starred') {
                $sql = "SELECT e.*, 
                               COALESCE(u.full_name, CASE WHEN e.sender_id = ? THEN e.receiver_name ELSE e.sender_name END) as participant_name, 
                               COALESCE(u.role, 'External/System') as participant_role, 
                               (CASE WHEN e.sender_id = ? THEN e.receiver_id ELSE e.sender_id END) as participant_id 
                        FROM emails e 
                        LEFT JOIN users u ON (CASE WHEN e.sender_id = ? THEN e.receiver_id ELSE e.sender_id END) = u.id 
                        WHERE (e.sender_id = ? AND e.is_deleted_by_sender = 0 AND e.is_starred = 1)
                           OR (e.receiver_id = ? AND e.is_deleted_by_receiver = 0 AND e.is_starred = 1)
                        ORDER BY e.created_at DESC";
                $params = [$user_id, $user_id, $user_id, $user_id, $user_id];
            } elseif ($folder === 'trash') {
                $sql = "SELECT e.*, 
                               COALESCE(u.full_name, CASE WHEN e.sender_id = ? THEN e.receiver_name ELSE e.sender_name END) as participant_name, 
                               COALESCE(u.role, 'External/System') as participant_role, 
                               (CASE WHEN e.sender_id = ? THEN e.receiver_id ELSE e.sender_id END) as participant_id 
                        FROM emails e 
                        LEFT JOIN users u ON (CASE WHEN e.sender_id = ? THEN e.receiver_id ELSE e.sender_id END) = u.id 
                        WHERE (e.sender_id = ? AND e.is_deleted_by_sender = 1)
                           OR (e.receiver_id = ? AND e.is_deleted_by_receiver = 1)
                        ORDER BY e.created_at DESC";
                $params = [$user_id, $user_id, $user_id, $user_id, $user_id];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $emails = $stmt->fetchAll();

            // Format time for UI
            foreach ($emails as &$email) {
                $ts = strtotime($email['created_at']);
                if (date('Y-m-d', $ts) === date('Y-m-d')) {
                    $email['display_time'] = date('h:i A', $ts);
                } else if (date('Y', $ts) === date('Y')) {
                    $email['display_time'] = date('M d', $ts);
                } else {
                    $email['display_time'] = date('m/d/y', $ts);
                }
            }

            echo json_encode(['success' => true, 'emails' => $emails]);
            break;

        case 'send':
            $receiver_ids = $_POST['receiver_ids'] ?? [];
            $subject = $_POST['subject'] ?? '(No Subject)';
            $message = $_POST['message'] ?? '';
            
            // Asset Relay Protocol: Handle multimedia attachments
            $att_path = null;
            $att_type = null;
            $att_name = null;
            $att_size = 0;
            
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['attachment'];
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $newName = 'SECURE_RELAY_' . time() . '_' . uniqid() . '.' . $ext;
                $targetDir = 'uploads/communications/';
                $targetPath = $targetDir . $newName;
                
                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $att_path = $targetPath;
                    $att_type = $file['type'];
                    $att_name = $file['name'];
                    $att_size = $file['size'];
                }
            }

            if (empty($receiver_ids) || (!$message && !$att_path)) {
                throw new Exception("Incomplete courier payload. No content detected.");
            }

            // Ensure receiver_ids is an array
            if (!is_array($receiver_ids)) {
                $receiver_ids = explode(',', $receiver_ids);
            }

            // Fetch sender name
            $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $sender_name = $stmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO emails (sender_id, sender_name, receiver_id, receiver_name, subject, message, attachment_path, attachment_type, attachment_name, attachment_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $sent_to = [];
            foreach ($receiver_ids as $rid) {
                $rid = trim($rid);
                if (!empty($rid)) {
                    // Fetch receiver name
                    $rstmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
                    $rstmt->execute([$rid]);
                    $receiver_name = $rstmt->fetchColumn();
                    $sent_to[] = $receiver_name;
                    
                    $stmt->execute([$user_id, $sender_name, $rid, $receiver_name, $subject, $message, $att_path, $att_type, $att_name, $att_size]);
                }
            }
            $names_list = implode(', ', $sent_to);
            echo json_encode(['success' => true, 'message' => "Secure communication successfully synched to: $names_list."]);
            break;

        case 'get_recipients':
            $stmt = $pdo->prepare("SELECT id, full_name, role, username FROM users WHERE id != ? ORDER BY full_name ASC");
            $stmt->execute([$user_id]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as &$u) {
                // Ensure professional capitalization
                $u['full_name'] = ucwords(strtolower($u['full_name']));
                $u['role'] = ucwords(strtolower($u['role']));
            }
            echo json_encode(['success' => true, 'users' => $users]);
            break;

        case 'mark_read':
            $email_id = $_POST['id'] ?? null;
            if ($email_id) {
                $stmt = $pdo->prepare("UPDATE emails SET is_read = 1 WHERE id = ? AND receiver_id = ?");
                $stmt->execute([$email_id, $user_id]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'toggle_star':
            $email_id = $_POST['id'] ?? null;
            if ($email_id) {
                $stmt = $pdo->prepare("UPDATE emails SET is_starred = NOT is_starred WHERE id = ? AND (sender_id = ? OR receiver_id = ?)");
                $stmt->execute([$email_id, $user_id, $user_id]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete':
            $email_id = $_POST['id'] ?? null;
            if ($email_id) {
                // Determine if sender or receiver
                $stmt = $pdo->prepare("UPDATE emails 
                                       SET is_deleted_by_sender = CASE WHEN sender_id = ? THEN 1 ELSE is_deleted_by_sender END,
                                           is_deleted_by_receiver = CASE WHEN receiver_id = ? THEN 1 ELSE is_deleted_by_receiver END
                                       WHERE id = ?");
                $stmt->execute([$user_id, $user_id, $email_id]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'fetch_single':
            $email_id = $_GET['id'] ?? null;
            if (!$email_id) throw new Exception("Target communication identifier missing.");

            $stmt = $pdo->prepare("SELECT e.*, 
                                   COALESCE(u.full_name, CASE WHEN e.sender_id = ? THEN e.receiver_name ELSE e.sender_name END) as participant_name, 
                                   COALESCE(u.role, 'External/System') as participant_role, 
                                   (CASE WHEN e.sender_id = ? THEN e.receiver_id ELSE e.sender_id END) as participant_id 
                                   FROM emails e 
                                   LEFT JOIN users u ON (CASE WHEN e.sender_id = ? THEN e.receiver_id ELSE e.sender_id END) = u.id 
                                   WHERE e.id = ? AND (e.sender_id = ? OR e.receiver_id = ?)");
            $stmt->execute([$user_id, $user_id, $user_id, $email_id, $user_id, $user_id]);
            $email = $stmt->fetch();

            if (!$email) throw new Exception("Communication not found or access denied.");

            // Formatting time
            $ts = strtotime($email['created_at']);
            $email['display_time_full'] = date('M d, Y - h:i A', $ts);

            echo json_encode(['success' => true, 'email' => $email]);
            break;

        case 'get_unread_count':
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM emails WHERE receiver_id = ? AND is_read = 0 AND is_deleted_by_receiver = 0");
            $stmt->execute([$user_id]);
            $res = $stmt->fetch();
            echo json_encode(['success' => true, 'count' => $res['count']]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Undefined operative action.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
