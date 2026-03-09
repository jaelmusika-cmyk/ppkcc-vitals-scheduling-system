<?php
// Include necessary files
include('../config/db.php');
include('../includes/auth_check.php');

// Initialize variables
$full_name = $email = $password = $confirm_password = $phone_number = $guardian_number = $address = $birth_date = $gender = $medical_history = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Sanitize inputs
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone_number = trim($_POST['phone_number']);
    $guardian_number = trim($_POST['guardian_number']);
    $address = trim($_POST['address']);
    $birth_date = $_POST['birth_date'];
    $gender = $_POST['gender'];
    $medical_history = trim($_POST['medical_history']);

    // Validate form
    if (empty($full_name)) $errors[] = 'Full Name is required.';
    if (empty($email)) $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
    if (empty($password)) $errors[] = 'Password is required.';
    elseif (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
    if (empty($phone_number)) $errors[] = 'Phone number is required.';
    if (empty($guardian_number)) $errors[] = 'Guardian phone number is required.';
    if (empty($address)) $errors[] = 'Address is required.';
    if (empty($birth_date)) $errors[] = 'Birth date is required.';
    if (empty($gender)) $errors[] = 'Gender is required.';

    if (count($errors) === 0) {
        try {
            $pdo->beginTransaction();

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Insert into users table
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role, phone_number, created_at) VALUES (?, ?, ?, 'patient', ?, NOW())");
            $stmt->execute([$full_name, $email, $hashed_password, $phone_number]);
            $user_id = $pdo->lastInsertId();

            // Insert into patients table
            $stmt = $pdo->prepare("INSERT INTO patients (user_id, full_name, birth_date, gender, phone_number, guardian_number, address, medical_history, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$user_id, $full_name, $birth_date, $gender, $phone_number, $guardian_number, $address, $medical_history]);
            $patient_id = $pdo->lastInsertId();

            // Insert blank schedule row for patient
            $stmt = $pdo->prepare("INSERT INTO schedules (patient_id, status) VALUES (?, 'Pending')");
            $stmt->execute([$patient_id]);

            $pdo->commit();
            $_SESSION['success'] = "Patient successfully registered.";
            header("Location: ../admin/manage_schedules.php"); // Redirect to manage_schedules.php to avoid duplicate submissions
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!-- HTML Form with styling -->
<?php include('../admin/admin_sidebar.php'); ?>
<div class="content">
<h2>Register New Patient</h2>

<?php if (!empty($errors)): ?>
    <div class="error-messages">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="registration-form">
    <input type="text" name="full_name" placeholder="Full Name" value="<?= htmlspecialchars($full_name) ?>" required>
    <input type="email" name="email" placeholder="Email" value="<?= htmlspecialchars($email) ?>" required>
    <input type="password" name="password" placeholder="Password" required>
    <input type="password" name="confirm_password" placeholder="Confirm Password" required>
    <input type="text" name="phone_number" placeholder="Phone Number" value="<?= htmlspecialchars($phone_number) ?>" required>
    <input type="text" name="guardian_number" placeholder="Guardian Phone Number" value="<?= htmlspecialchars($guardian_number) ?>" required>
    <input type="date" name="birth_date" value="<?= htmlspecialchars($birth_date) ?>" required>
    <select name="gender" required>
        <option value="">Select Gender</option>
        <option value="Male" <?= $gender == 'Male' ? 'selected' : '' ?>>Male</option>
        <option value="Female" <?= $gender == 'Female' ? 'selected' : '' ?>>Female</option>
    </select>
    <textarea name="address" placeholder="Address" required><?= htmlspecialchars($address) ?></textarea>
    <textarea name="medical_history" placeholder="Medical History (optional)"><?= htmlspecialchars($medical_history) ?></textarea>
    <button type="submit" class="submit-button">Register Patient</button>
</form>

</div>

<!-- Styling -->
<style>
    body {
        font-family: Arial, sans-serif;
        background-color: #f4f7fc;
        margin: 0;
        padding: 0;
    }

    h2 {
        text-align: center;
        color: #333;
        margin-top: 20px;
    }

    .registration-form {
        max-width: 500px;
        margin: 20px auto;
        background-color: #fff;
        padding: 10px;
        border-radius: 8px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }

    .registration-form input,
    .registration-form select,
    .registration-form textarea,
    .registration-form button {
        width: 100%;
        padding: 12px;
        margin: 8px 0;
        border: 1px solid #ddd;
        border-radius: 2px;
        box-sizing: border-box;
    }

    .registration-form input[type="date"] {
        padding: 10px;
    }

    .registration-form button {
        background-color: #007bff;
        color: white;
        border: none;
        cursor: pointer;
        font-size: 16px;
    }

    .registration-form button:hover {
        background-color: #0056b3;
    }

    .error-messages {
        color: #d9534f;
        margin-bottom: 20px;
    }

    .error-messages ul {
        list-style: none;
        padding-left: 0;
    }

    .error-messages li {
        padding: 5px;
        background-color: #f8d7da;
        border-radius: 4px;
        margin-bottom: 5px;
    }

    .error-messages li:last-child {
        margin-bottom: 0;
    }

    .registration-form select,
    .registration-form textarea {
        resize: vertical;
    }

    .content {
        flex-grow: 1;
        margin-left: 0px;
        transition: margin-left 1s ease;
        padding: 0px; /* Space for content */
        margin-right: 500px;
      }
 
</style>
