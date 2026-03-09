<?php
// Include necessary files
include('../includes/auth_check.php');
include('../includes/header.php');
include('../config/db.php');

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $message = $_POST['message'];
    
    // Send notification to all patients (or specific patients)
    // Assuming Twilio for SMS or Firebase for in-app notifications
    // For now, it's just a placeholder for sending logic

    // Example: Send via SMS (Twilio or alternative)
    // sendSMS($patients, $message);

    // Example: Send via Firebase (in-app)
    // sendInAppNotification($patients, $message);

    echo "<div class='alert alert-success'>Notification sent successfully!</div>";
}
?>

<div class="container">
    <h1>Send Notifications</h1>
    
    <form method="POST" action="">
        <div class="form-group">
            <label for="message">Message</label>
            <textarea class="form-control" name="message" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Send Notification</button>
    </form>
</div>

<?php include('../includes/footer.php'); ?>
