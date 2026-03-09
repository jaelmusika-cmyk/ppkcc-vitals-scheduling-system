<?php
require_once '../config/db.php';
// Load the mail configuration
$mail_config = require_once '../config/mail_config.php';

require '../phpmailer/PHPMailer.php';
require '../phpmailer/SMTP.php';
require '../phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents("php://input"));
    $email = $data->email ?? '';

    if (empty($email)) {
        throw new Exception('Email is required');
    }

    $otp = rand(100000, 999999);
    $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Update DB
    $stmt = $pdo->prepare("UPDATE users SET reset_otp = ?, otp_expiry = ? WHERE email = ? AND role = 'patient'");
    $stmt->execute([$otp, $expiry, $email]);

    // Send Email
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $mail_config['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $mail_config['smtp_user'];
    $mail->Password   = $mail_config['smtp_pass'];
    $mail->SMTPSecure = 'tls';
    $mail->Port       = $mail_config['smtp_port'];

    $mail->setFrom($mail_config['from_email'], $mail_config['from_name']);
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'DialiEase Password Reset OTP';
    $mail->Body    = "Your OTP: <b>$otp</b> (expires in 10 mins)";
    
    $mail->send();

    echo json_encode(['status' => 'success', 'message' => 'OTP sent']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
