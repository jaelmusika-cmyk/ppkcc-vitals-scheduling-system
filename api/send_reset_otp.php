<?php
require_once '../config/db.php';
require '../phpmailer/PHPMailer.php';
require '../phpmailer/SMTP.php';
require '../phpmailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';

$otp = rand(100000, 999999);
$expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

$stmt = $pdo->prepare("UPDATE users SET reset_otp = ?, otp_expiry = ? WHERE email = ? AND role = 'patient'");
$stmt->execute([$otp, $expiry, $email]);

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = 'jaelmusika@gmail.com';
$mail->Password = 'pmnf kloh wgah cwqk';
$mail->SMTPSecure = 'tls';
$mail->Port = 587;
$mail->setFrom('jaelmusika@gmail.com', 'DialiEase Support');
$mail->addAddress($email);
$mail->isHTML(true);
$mail->Subject = 'DialiEase Password Reset OTP';
$mail->Body = "Your OTP: <b>$otp</b> (expires in 10 mins)";
$mail->send();

echo json_encode(['status' => 'success', 'message' => 'OTP sent']);
