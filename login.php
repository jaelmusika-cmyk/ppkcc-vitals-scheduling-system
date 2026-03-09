<?php
session_start();
require_once './config/db.php';
require_once './includes/auth_check.php';

if (isLoggedIn()) {
    redirectToDashboard();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['email']) && isset($_POST['password'])) {
        $email = $_POST['email'];
        $password = $_POST['password'];

        $sql = "SELECT * FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (password_verify($password, $user['password'])) {
                if ($user['role'] === 'patient') {
                    $_SESSION['error'] = "Patients cannot log in on the web. Please use the mobile app.";
                    header("Location: login.php");
                    exit;
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];
                    redirectToDashboard();
                }
            } else {
                $_SESSION['error'] = "Invalid password!";
                header("Location: login.php");
                exit;
            }
        } else {
            $_SESSION['error'] = "No user found with this email!";
            header("Location: login.php");
            exit;
        }
        
    } else {
        $_SESSION['error'] = "Please fill in both fields.";
        header("Location: login.php");
        exit;
    }
    
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login - DialiEase</title>
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

    .login-container h2 {
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

    input[type="email"],
    input[type="password"] {
      background-color: var(--input-bg);
      border: 1px solid var(--input-border);
      color: #fff;
      padding: 12px;
      font-size: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      outline: none;
    }

    input[type="email"]::placeholder,
    input[type="password"]::placeholder {
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
      margin-top: 10px;
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

    .bx {
      vertical-align: middle;
      margin-right: 6px;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <h2><i class='bx bx-lock-alt'></i> DialiEase: Admin & Nurse Login</h2>

    <?php if (isset($_SESSION['error'])): ?>
  <div class="error-message"><?= $_SESSION['error']; ?></div>
  <?php unset($_SESSION['error']); ?>
<?php endif; ?>


    <form method="POST" action="login.php">
      <label for="email"><i class='bx bx-envelope'></i> Email</label>
      <input type="email" id="email" name="email" placeholder="Enter your email" required>

      <label for="password"><i class='bx bx-lock'></i> Password</label>
      <input type="password" id="password" name="password" placeholder="Enter your password" required>

      <button type="submit"><i class='bx bx-log-in'></i> Login</button>
    </form>
    <!-- Forgot Password Link -->
    <p><a href="forgot_password.php" style="color: #fff; text-align: center; display: block; margin-top: 15px;">Forgot Password?</a></p>
  </div>
</body>
</html>
