<?php
// Include necessary files
include('../includes/auth_check.php');
include('../includes/header.php');
include('../config/db.php');
include('../admin/admin_sidebar.php');

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $full_name = $_POST['full_name'];
    $birth_date = $_POST['birth_date'];
    $gender = $_POST['gender'];
    $phone_number = $_POST['phone_number'];
    $guardian_number = $_POST['guardian_number'];
    $address = $_POST['address'];
    $medical_history = $_POST['medical_history'];

    // Insert user data into users table
    try {
        // Start transaction
        $pdo->beginTransaction();

        // Insert patient into users table
        $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role, phone_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$full_name, $email, $hashedPassword, 'patient', $phone_number]);
        $user_id = $pdo->lastInsertId();

        // Insert patient details into patients table
        $stmt = $pdo->prepare("INSERT INTO patients (id, full_name, birth_date, gender, phone_number, guardian_number, address, medical_history) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $full_name, $birth_date, $gender, $phone_number, $guardian_number, $address, $medical_history]);

        // Insert default schedule for patient (Pending)
        $stmt = $pdo->prepare("INSERT INTO schedules (patient_id, status) VALUES (?, 'Pending')");
        $stmt->execute([$user_id]);

        // Commit transaction
        $pdo->commit();

        // Redirect or show success message
        header("Location: manage_users.php?success=1");
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Failed: " . $e->getMessage();
    }
}
?>

<div class="container">
    <h1>Add New Patient</h1>

    <form method="POST" action="">
        <div class="form-group">
            <label for="full_name">Full Name</label>
            <input type="text" class="form-control" name="full_name" required>
        </div>
        <div class="form-group">
            <label for="birth_date">Birth Date</label>
            <input type="date" class="form-control" name="birth_date" required>
        </div>
        <div class="form-group">
            <label for="gender">Gender</label>
            <select class="form-control" name="gender" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
            </select>
        </div>
        <div class="form-group">
            <label for="phone_number">Phone Number</label>
            <input type="text" class="form-control" name="phone_number" required>
        </div>
        <div class="form-group">
            <label for="guardian_number">Guardian's Phone Number</label>
            <input type="text" class="form-control" name="guardian_number">
        </div>
        <div class="form-group">
            <label for="address">Address</label>
            <textarea class="form-control" name="address" required></textarea>
        </div>
        <div class="form-group">
            <label for="medical_history">Medical History</label>
            <textarea class="form-control" name="medical_history"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Add Patient</button>
    </form>
</div>

<?php include('../includes/footer.php'); ?>
