<?php
session_start();
require_once './config/db.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';
require 'phpmailer/Exception.php';

function sendOTP($email, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jaelmusika@gmail.com';
        $mail->Password = 'wkbp txnr bbpz rpmz';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('jaelmusika@gmail.com', 'DialiEase Support');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'DialiEase Password Reset OTP';
        $mail->Body = "Your OTP code is: <strong>$otp</strong><br>This code will expire in 10 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user) {
        $otp = rand(100000, 999999);
        $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

        $stmt = $pdo->prepare("UPDATE users SET reset_otp = :otp, otp_expiry = :expiry WHERE email = :email");
        $stmt->execute([
            'otp' => $otp,
            'expiry' => $expiry,
            'email' => $email
        ]);

        if (sendOTP($email, $otp)) {
            $_SESSION['success'] = "OTP sent to $email. Please check your email.";
            $_SESSION['reset_email'] = $email;
            header("Location: reset_password.php");
            exit;
        } else {
            $_SESSION['error'] = "Failed to send OTP. Please try again.";
        }
    } else {
        $_SESSION['error'] = "No account found with that email.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Forgot Password - DialiEase</title>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
    @import url("https://fonts.googleapis.com/css2?family=Agdasima:wght@400;700&display=swap");

    :root {
      --sidebar-bg: #2f323a;
      --text-color: #fff;
      --input-bg: #3c3f48;
      --input-border: #555;
      --btn-bg: #007bff;
      --btn-hover-bg: #0056b3;
      --error-bg: #ff4c4c;
      --success-bg: #28a745;
    }

    * {
      box-sizing: border-box;
    }

    body {
      background-color: var(--sidebar-bg);
      font-family: 'Agdasima', sans-serif;
      color: var(--text-color);
      display: flex;
      align-items: center;
      justify-content: center;
      height: 100vh;
      margin: 0;
      padding: 20px;
    }

    .login-container {
      background-color: #1f2228;
      padding: 40px 30px;
      border-radius: 10px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 0 15px rgba(0, 0, 0, 0.5);
    }

    h2 {
      text-align: center;
      margin-bottom: 25px;
      font-size: 26px;
      color: #ffffff;
    }

    form {
      display: flex;
      flex-direction: column;
    }

    label {
      margin-bottom: 6px;
      font-weight: 600;
      font-size: 16px;
    }

    input[type="email"] {
      background-color: var(--input-bg);
      border: 1px solid var(--input-border);
      color: #fff;
      padding: 12px;
      font-size: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }

    input::placeholder {
      color: #ccc;
    }

    button {
      background-color: var(--btn-bg);
      color: white;
      padding: 12px;
      font-size: 16px;
      font-weight: bold;
      border: none;
      border-radius: 5px;
      cursor: pointer;
      transition: background 0.3s ease;
    }

    button:hover {
      background-color: var(--btn-hover-bg);
    }

    .error-message {
      background-color: var(--error-bg);
      color: white;
      padding: 12px;
      border-radius: 5px;
      margin-bottom: 20px;
      font-size: 15px;
      text-align: center;
    }

    .success-message {
      background-color: var(--success-bg);
      color: white;
      padding: 12px;
      border-radius: 5px;
      margin-bottom: 20px;
      font-size: 15px;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h2><i class='bx bx-mail-send'></i> Forgot Password</h2>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="error-message"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
      <div class="success-message"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php">
      <label for="email"><i class='bx bx-envelope'></i> Enter your email</label>
      <input type="email" name="email" id="email" placeholder="Email address" required>
      <button type="submit"><i class='bx bx-paper-plane'></i> Send OTP</button>
    </form>
  </div>
</body>
</html>
