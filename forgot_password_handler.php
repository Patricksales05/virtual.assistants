<?php
require_once 'shared_db.php';

// Include PHPMailer classes manually
require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =========================================================================
// ⚠️ ACTION REQUIRED: GMAIL SMTP CONFIGURATION
// You MUST enter your Gmail and your 16-digit App Password below!
// Detailed instructions on how to get an App Password:
// 1. Go to "Manage your Google Account" -> "Security"
// 2. Enable "2-Step Verification"
// 3. Search for "App passwords" -> Create one called "XAMPP"
// =========================================================================
define('SMTP_USER', 'vmvirtual.assistant26@gmail.com');
define('SMTP_PASS', 'ajyj hcmn zvfe cwvq');

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'send_otp':
            $account = trim($_POST['account'] ?? '');
            if (empty($account))
                throw new Exception("Please enter your username or email address.");

            $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$account, $account]);
            $user = $stmt->fetch();

            if (!$user)
                throw new Exception("We couldn't find an account associated with that information.");
            if (empty($user['email']))
                throw new Exception("This account does not have a recovery email set.");

            $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            // Store in dedicated OTP table
            $ins = $pdo->prepare("INSERT INTO otp_verifications (user_id, otp_code, expiry) VALUES (?, ?, ?)");
            $ins->execute([$user['id'], $otp, $expiry]);

            $to = $user['email'];
            $subject = "Security Verification - Seamless Assist";

            // Premium HTML Email Template
            $message = "
            <html>
            <body style='font-family: \"Outfit\", sans-serif; background-color: #0f172a; color: #f8fafc; padding: 40px;'>
                <div style='max-width: 600px; margin: auto; background: #1e293b; padding: 40px; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);'>
                    <div style='text-align: center; margin-bottom: 30px;'>
                        <h1 style='color: #6366f1; margin: 0; font-size: 24px; font-weight: 800; letter-spacing: -0.025em;'>SEAMLESS ASSIST</h1>
                        <p style='color: #94a3b8; font-size: 12px; margin-top: 5px;'>EXECUTIVE COMMAND CENTER</p>
                    </div>
                    <div style='border-top: 1px solid rgba(255,255,255,0.1); padding-top: 30px;'>
                        <h2 style='font-size: 20px; font-weight: 600; margin-bottom: 20px; text-align: center;'>Password Recovery Protocol</h2>
                        <p style='color: #94a3b8; line-height: 1.6; text-align: center;'>A request was initiated for secure access recovery. Please enter the following 6-digit One-Time Password (OTP) to proceed with your credential reset.</p>
                        
                        <div style='background: rgba(99, 102, 241, 0.1); border: 2px dashed rgba(99, 102, 241, 0.3); border-radius: 16px; padding: 25px; text-align: center; margin: 30px 0;'>
                            <span style='font-size: 36px; font-weight: 800; letter-spacing: 12px; color: #6366f1;'>" . $otp . "</span>
                        </div>
                        
                        <p style='color: #ef4444; font-size: 11px; text-align: center; margin-bottom: 0;'>This code expires in 15 minutes. If you did not request this, please secure your account immediately.</p>
                    </div>
                </div>
                <div style='text-align: center; margin-top: 30px; color: #475569; font-size: 10px;'>
                    &copy; " . date('Y') . " Seamless Assist Virtual Assistants. All rights reserved.
                </div>
            </body>
            </html>
            ";


            // Initialize PHPMailer
            $mail = new PHPMailer(true);

            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;

                // Load credentials from the top of the file
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;

                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                // XAMPP Fix: Disable SSL certificate verification for localhost
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                // Recipients
                $mail->setFrom(SMTP_USER, 'Seamless Assist Command Center');
                $mail->addAddress($to, $user['full_name']);

                // Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $message;

                $mail->send();
                echo json_encode(['success' => true, 'message' => 'OTP dispatched securely to your Gmail account.', 'email' => maskEmail($to)]);
            } catch (Exception $e) {
                // If it fails, fallback to local relay mode so they aren't completely blocked
                echo json_encode([
                    'success' => true,
                    'message' => 'Email failed to send. Using local relay mode: ' . $mail->ErrorInfo,
                    'email' => maskEmail($to),
                    'debug_otp' => $otp
                ]);
            }
            break;

        case 'verify_otp':
            $account = trim($_POST['account'] ?? '');
            $otp = trim($_POST['otp'] ?? '');

            $stmt = $pdo->prepare("
                SELECT v.id 
                FROM otp_verifications v 
                JOIN users u ON v.user_id = u.id 
                WHERE (u.username = ? OR u.email = ?) 
                AND v.otp_code = ? 
                AND v.expiry > NOW() 
                AND v.is_used = 0 
                ORDER BY v.created_at DESC LIMIT 1
            ");
            $stmt->execute([$account, $account, $otp]);
            $record = $stmt->fetch();

            if (!$record)
                throw new Exception("Invalid or expired verification code.");
            echo json_encode(['success' => true]);
            break;

        case 'reset_password':
            $account = trim($_POST['account'] ?? '');
            $otp = trim($_POST['otp'] ?? '');
            $new_pass = $_POST['new_password'] ?? '';

            $stmt = $pdo->prepare("
                SELECT v.id, v.user_id 
                FROM otp_verifications v 
                JOIN users u ON v.user_id = u.id 
                WHERE (u.username = ? OR u.email = ?) 
                AND v.otp_code = ? 
                AND v.expiry > NOW() 
                AND v.is_used = 0 
                ORDER BY v.created_at DESC LIMIT 1
            ");
            $stmt->execute([$account, $account, $otp]);
            $record = $stmt->fetch();

            if (!$record)
                throw new Exception("Verification failed. Please restart.");

            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->execute([$hashed, $record['user_id']]);

            // Mark OTP as used
            $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?")->execute([$record['id']]);

            echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
            break;

        default:
            throw new Exception("Unknown action.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function maskEmail($email)
{
    $parts = explode("@", $email);
    $name = $parts[0];
    $len = strlen($name);
    if ($len <= 2)
        return $email;
    return substr($name, 0, 2) . str_repeat('*', $len - 2) . "@" . $parts[1];
}
?>