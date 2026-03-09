<?php
require_once '../config/db.php';
header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"));

$email = $data->email ?? '';
$otp = $data->otp ?? '';
$password = $data->password ?? '';

$stmt = $pdo->prepare("SELECT reset_otp, otp_expiry FROM users WHERE email = ? AND role = 'patient'");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && $user['reset_otp'] === $otp && $user['otp_expiry'] >= date('Y-m-d H:i:s')) {
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ?, reset_otp = NULL, otp_expiry = NULL WHERE email = ?");
    $stmt->execute([$hashedPassword, $email]);
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP']);
}
