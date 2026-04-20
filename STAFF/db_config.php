<?php
// Database configuration
date_default_timezone_set('Asia/Manila');
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'virtual assistant database');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Create PDO connection
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    
    // Set PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set default fetch mode to associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("ERROR: Could not connect. " . $e->getMessage());
}

// Unique session name for the Staff segment to prevent cross-logout with other roles
$s_name = 'VA_STAFF_SESSION';

if (session_status() === PHP_SESSION_NONE) {
    // 1. Mandatory: Set session name BEFORE starting or setting params
    session_name($s_name);
    
    // 2. Set robust cookie parameters
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 Days persistence
        'path' => '/',
        'domain' => null, // Better for localhost/127.0.0.1 compatibility
        'secure' => false, // Set to true if using HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    // 3. Ensure server-side garbage collection doesn't kill the session early
    ini_set('session.gc_maxlifetime', 86400 * 30);
    ini_set('session.cookie_lifetime', 86400 * 30);
    
    session_start();
}
?>
