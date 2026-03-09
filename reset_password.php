<?php
session_start();
require_once './config/db.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit;
}

$email = $_SESSION['reset_email'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enteredOtp = trim($_POST['otp']);
    $newPassword = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];

    if ($newPassword !== $confirmPassword) {
        $_SESSION['error'] = "Passwords do not match.";
    } else {
        $stmt = $pdo->prepare("SELECT reset_otp, otp_expiry FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $now = date('Y-m-d H:i:s');
            if ($user['reset_otp'] === $enteredOtp && $user['otp_expiry'] >= $now) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = :password, reset_otp = NULL, otp_expiry = NULL WHERE email = :email");
                $stmt->execute([
                    'password' => $hashedPassword,
                    'email' => $email
                ]);

                unset($_SESSION['reset_email']);
                $_SESSION['success'] = "Password successfully updated. You can now log in.";
                header("Location: login.php");
                exit;
            } else {
                $_SESSION['error'] = "Invalid or expired OTP.";
            }
        } else {
            $_SESSION['error'] = "Unexpected error. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reset Password - DialiEase</title>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet" />
  <style>
    body, html { margin: 0; padding: 0; font-family: 'Agdasima', sans-serif; background: #2f323a; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; }
    .login-container {
      background-color: #1f2228;
      padding: 40px 30px;
      border-radius: 10px;
      width: 100%;
      max-width: 400px;
      box-shadow: 0 0 15px rgba(0,0,0,0.5);
    }
    h2 { text-align: center; margin-bottom: 20px; }
    form { display: flex; flex-direction: column; }
    label { margin-bottom: 5px; font-weight: bold; }
    input {
      background: #3c3f48;
      color: #fff;
      border: 1px solid #555;
      padding: 12px;
      border-radius: 5px;
      margin-bottom: 15px;
      font-size: 15px;
    }
    button {
      background: #007bff;
      border: none;
      padding: 12px;
      font-weight: bold;
      font-size: 16px;
      border-radius: 5px;
      color: #fff;
      cursor: pointer;
      transition: 0.3s;
    }
    button:hover { background: #0056b3; }
    .error-message {
      background-color: #ff4c4c;
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
    <h2><i class='bx bx-refresh'></i> Reset Your Password</h2>

    <?php if (isset($_SESSION['error'])): ?>
      <div class="error-message"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <form method="POST" action="reset_password.php">
      <label for="otp"><i class='bx bx-key'></i> Enter OTP</label>
      <input type="text" name="otp" id="otp" required>

      <label for="password"><i class='bx bx-lock'></i> New Password</label>
      <input type="password" name="password" id="password" required>

      <label for="confirm_password"><i class='bx bx-lock-alt'></i> Confirm New Password</label>
      <input type="password" name="confirm_password" id="confirm_password" required>

      <button type="submit"><i class='bx bx-check'></i> Reset Password</button>
    </form>
  </div>
</body>
</html>
