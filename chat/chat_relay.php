<?php
/**
 * Advanced Multi-Node Communication Relay (v2.0)
 * Logic: Private Direct Messaging (Peer-to-Peer) & Multi-Role Group Channels.
 * Engineered for Unified Organizational Telemetry.
 */
ob_start();

$sessions = ['VA_UNIFIED_SESSION'];
$authenticated = false;
$user_id = null;
$username = null;

foreach ($sessions as $s) {
    if (isset($_COOKIE[$s])) {
        session_name($s);
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (isset($_SESSION['user_id'])) {
            $user_id = $_SESSION['user_id'];
            $username = $_SESSION['username'] ?? 'User';
            $authenticated = true;
            break;
        }
        session_write_close();
    }
}

if (!$authenticated) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Deployment failure: Unauthorized access node.']);
    exit();
}

// Database Connection
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'virtual assistant database');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Advanced Schema Initialization
    // 1. Rooms (Public, Private, Group)
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) DEFAULT NULL,
        type ENUM('public', 'private', 'group') DEFAULT 'public',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Room Participants
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_room_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        room_id INT NOT NULL,
        user_id INT NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (room_id),
        INDEX (user_id),
        FOREIGN KEY (room_id) REFERENCES chat_rooms(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 3. Messages Expansion
    $pdo->exec("CREATE TABLE IF NOT EXISTS chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        room_id INT NOT NULL DEFAULT 1,
        message TEXT,
        attachment_url VARCHAR(255) DEFAULT NULL,
        attachment_type ENUM('image', 'video', 'link', 'file', 'none') DEFAULT 'none',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (room_id),
        INDEX (created_at),
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Idempotent column injection (Migration logic)
    $msgCols = $pdo->query("SHOW COLUMNS FROM chat_messages")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('room_id', $msgCols)) {
        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN room_id INT NOT NULL DEFAULT 1 AFTER sender_id");
        $pdo->exec("CREATE INDEX idx_room ON chat_messages(room_id)");
    }
    
    // Check and update ENUM for attachment_type
    $pdo->exec("ALTER TABLE chat_messages MODIFY COLUMN attachment_type ENUM('image', 'video', 'link', 'file', 'none') DEFAULT 'none'");

    // Initialize Default Public Room (ID 1) if not exists
    $stmt = $pdo->query("SELECT id FROM chat_rooms WHERE id = 1");
    if (!$stmt->fetch()) {
        $pdo->exec("INSERT INTO chat_rooms (id, name, type) VALUES (1, 'Global Channel', 'public')");
    }

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Circuit failure: ' . $e->getMessage()]);
    exit();
}

$action = $_REQUEST['action'] ?? 'fetch';

// ACTION: Start or Get Private Room between two users
if ($action === 'init_private') {
    $target_id = (int)$_POST['target_id'];
    if ($target_id === $user_id) exit(json_encode(['error' => 'Self-messaging redundant.']));

    // Check if room exists
    $stmt = $pdo->prepare("
        SELECT r.id 
        FROM chat_rooms r 
        JOIN chat_room_participants p1 ON r.id = p1.room_id 
        JOIN chat_room_participants p2 ON r.id = p2.room_id 
        WHERE r.type = 'private' AND p1.user_id = ? AND p2.user_id = ?
    ");
    $stmt->execute([$user_id, $target_id]);
    $room = $stmt->fetch();

    if (!$room) {
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO chat_rooms (type) VALUES ('private')");
        $roomId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("INSERT INTO chat_room_participants (room_id, user_id) VALUES (?, ?), (?, ?)");
        $stmt->execute([$roomId, $user_id, $roomId, $target_id]);
        $pdo->commit();
        $room = ['id' => $roomId];
    }
    echo json_encode(['success' => true, 'room_id' => $room['id']]);
    exit();
}

// ACTION: Create Group Room
if ($action === 'create_group') {
    $name = $_POST['name'] ?? 'Team Group';
    $members = $_POST['members'] ?? []; // Array of IDs
    $members[] = $user_id; // Add self

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO chat_rooms (name, type) VALUES (?, 'group')");
    $stmt->execute([$name]);
    $roomId = $pdo->lastInsertId();
    
    $stmt = $pdo->prepare("INSERT INTO chat_room_participants (room_id, user_id) VALUES (?, ?)");
    foreach(array_unique($members) as $mid) {
        $stmt->execute([$roomId, $mid]);
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'room_id' => $roomId]);
    exit();
}

if ($action === 'send') {
    $message = $_POST['message'] ?? '';
    $room_id = (int)($_POST['room_id'] ?? 1);
    $attachment_url = null;
    $attachment_type = 'none';

    // Verify participation
    $stmt = $pdo->prepare("SELECT id FROM chat_room_participants WHERE room_id = ? AND user_id = ?");
    $stmt->execute([$room_id, $user_id]);
    if ($room_id != 1 && !$stmt->fetch()) {
        echo json_encode(['error' => 'Unauthorized room access.']);
        exit();
    }

    if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "_", $_FILES['media']['name']);
        $target = $upload_dir . $fileName;
        if (move_uploaded_file($_FILES['media']['tmp_name'], $target)) {
            $attachment_url = 'chat/' . $target;
            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $attachment_type = 'image';
            elseif (in_array($ext, ['mp4', 'webm', 'mov'])) $attachment_type = 'video';
            else $attachment_type = 'link';
        }
    }

    if (!empty($message) || $attachment_url) {
        $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_id, room_id, message, attachment_url, attachment_type) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $room_id, $message, $attachment_url, $attachment_type]);
        echo json_encode(['success' => true]);
    }
    exit();
}

if ($action === 'fetch') {
    $room_id = (int)($_GET['room_id'] ?? 1);
    $last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
    
    $stmt = $pdo->prepare("
        SELECT m.*, u.username, u.full_name, u.role 
        FROM chat_messages m 
        JOIN users u ON m.sender_id = u.id 
        WHERE m.room_id = ? AND m.id > ? 
        ORDER BY m.id ASC
    ");
    $stmt->execute([$room_id, $last_id]);
    $msgs = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true, 
        'messages' => $msgs,
        'current_user_id' => $user_id
    ]);
    exit();
}

// ACTION: Fetch Room List for Sidebar
if ($action === 'fetch_rooms') {
    // Get rooms that user is part of (or Public room 1)
    $stmt = $pdo->prepare("
        SELECT r.*, 
               (SELECT message FROM chat_messages WHERE room_id = r.id ORDER BY id DESC LIMIT 1) as last_msg,
               (SELECT created_at FROM chat_messages WHERE room_id = r.id ORDER BY id DESC LIMIT 1) as last_activity
        FROM chat_rooms r
        LEFT JOIN chat_room_participants p ON r.id = p.room_id
        WHERE r.id = 1 OR p.user_id = ?
        GROUP BY r.id
        ORDER BY CASE WHEN r.id = 1 THEN 0 ELSE 1 END, last_activity DESC
    ");
    $stmt->execute([$user_id]);
    $rooms = $stmt->fetchAll();

    // Enhancement: For private rooms, get the "Other person's" name as room name
    foreach ($rooms as &$r) {
        if ($r['type'] === 'private') {
            $stmt = $pdo->prepare("
                SELECT u.full_name, u.username 
                FROM chat_room_participants p 
                JOIN users u ON p.user_id = u.id 
                WHERE p.room_id = ? AND p.user_id != ? 
                LIMIT 1
            ");
            $stmt->execute([$r['id'], $user_id]);
            $other = $stmt->fetch();
            $r['name'] = $other['full_name'] ?? ('@' . ($other['username'] ?? 'User'));
        }
    }

    echo json_encode(['success' => true, 'rooms' => $rooms]);
    exit();
}

// ACTION: Get Staff for Group Chat creation
if ($action === 'fetch_staff') {
    $stmt = $pdo->query("SELECT id, full_name, username, role FROM users WHERE id != $user_id ORDER BY role, full_name");
    echo json_encode(['success' => true, 'staff' => $stmt->fetchAll()]);
    exit();
}

// ACTION: Get Profile Data
if ($action === 'get_profile') {
    $tid = (int)$_GET['user_id'];
    $stmt = $pdo->prepare("SELECT id, username, full_name, email, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$tid]);
    $user = $stmt->fetch();
    echo json_encode(['success' => true, 'profile' => $user]);
    exit();
}
