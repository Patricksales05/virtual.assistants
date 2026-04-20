<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Use Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Handles OTP generation, storage, and sending.
 * This function encapsulates the logic provided in the user's snippet.
 * 
 * @param string $email The recipient email address
 * @param string $subject The email subject
 * @param string $messageTemplate Optional custom message template
 * @return array Status and message of the operation
 */
function processOTP($email, $subject = "Your OTP Code", $messageTemplate = null)
{
    // Database configuration
    $host = "localhost";
    $user = "root";
    $password = "";
    $dbname = "btc";

    $conn = new mysqli($host, $user, $password, $dbname);
    if ($conn->connect_error) {
        return ['status' => 'error', 'message' => 'Database connection failed: ' . $conn->connect_error];
    }
    $conn->set_charset("utf8");

    $now = date('Y-m-d H:i:s');
    $cooldownSeconds = 60;

    // Check for a recent unused OTP
    $s = $conn->prepare("SELECT IDdtb, OTPdtb, CREATED_ATdtb FROM otp WHERE EMAILdtb = ? AND USEDdtb = 0 ORDER BY CREATED_ATdtb DESC LIMIT 1");
    $s->bind_param("s", $email);
    $s->execute();
    $s->bind_result($existId, $existOtp, $createdAt);
    $s->fetch();
    $s->close();

    $secsSince = !empty($createdAt) ? (strtotime($now) - strtotime($createdAt)) : 999;

    if (!empty($existOtp) && $secsSince < $cooldownSeconds) {
        $remaining = $cooldownSeconds - $secsSince;
        return [
            'status' => 'cooldown',
            'message' => "Please wait {$remaining} seconds before requesting a new OTP.",
            'remaining' => $remaining
        ];
    }

    // Generate new OTP
    $otp = rand(100000, 999999);
    $expires_at = date('Y-m-d H:i:s', strtotime('+5 minutes'));

    if (!empty($existId)) {
        $u = $conn->prepare("UPDATE otp SET OTPdtb = ?, CREATED_ATdtb = ?, EXPIRES_ATdtb = ?, USEDdtb = 0 WHERE IDdtb = ?");
        $u->bind_param("sssi", $otp, $now, $expires_at, $existId);
        $u->execute();
        $u->close();
    } else {
        $i = $conn->prepare("INSERT INTO otp (EMAILdtb, OTPdtb, CREATED_ATdtb, USEDdtb, EXPIRES_ATdtb) VALUES (?, ?, ?, 0, ?)");
        $i->bind_param("ssss", $email, $otp, $now, $expires_at);
        $i->execute();
        $i->close();
    }

    // PHPMailer settings
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'vmvirtual.assistant26@gmail.com';
        $mail->Password = 'ajyj hcmn zvfe cwvq';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->setFrom('vmvirtual.assistant26@gmail.com', 'virtual.assistant');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = $subject;

        $body = $messageTemplate ? str_replace('{{otp}}', $otp, $messageTemplate) : "Your OTP code is: <strong>$otp</strong>. It will expire in 5 minutes.";

        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        $conn->close();
        return ['status' => 'success', 'message' => 'OTP sent successfully.'];
    } catch (Exception $e) {
        $conn->close();
        return ['status' => 'error', 'message' => "Email failed: {$mail->ErrorInfo}"];
    }
}

/**
 * Verifies the OTP provided by the user.
 * 
 * @param string $email The email address to verify
 * @param string $otp The OTP code to verify
 * @return array Status of the verification
 */
function verifyOTP($email, $otp)
{
    $host = "localhost";
    $user = "root";
    $password = "";
    $dbname = "btc";

    $conn = new mysqli($host, $user, $password, $dbname);
    if ($conn->connect_error) {
        return ['status' => 'error', 'message' => 'Database connection failed.'];
    }

    $now = date('Y-m-d H:i:s');
    // Check if OTP matches and is not used and not expired
    $s = $conn->prepare("SELECT IDdtb FROM otp WHERE EMAILdtb = ? AND OTPdtb = ? AND USEDdtb = 0 AND EXPIRES_ATdtb > ? LIMIT 1");
    $s->bind_param("sss", $email, $otp, $now);
    $s->execute();
    $s->bind_result($id);
    $s->fetch();
    $s->close();

    if ($id) {
        // Mark as used immediately upon successful verification
        $u = $conn->prepare("UPDATE otp SET USEDdtb = 1 WHERE IDdtb = ?");
        $u->bind_param("i", $id);
        $u->execute();
        $u->close();
        $conn->close();
        return ['status' => 'success'];
    } else {
        $conn->close();
        return ['status' => 'error', 'message' => 'Invalid or expired OTP. Please try again.'];
    }
}
?>