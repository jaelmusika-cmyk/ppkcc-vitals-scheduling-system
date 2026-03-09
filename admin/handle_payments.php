<?php
session_start();
require_once '../config/db.php';
require_once 'log_action.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$action = $_POST['action'] ?? null;
$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['full_name'];

try {
    if ($action === 'mark_paid') {
        $reminder_id = $_POST['reminder_id'] ?? null;
        if (!$reminder_id) {
            throw new Exception('Reminder ID is required.');
        }

        // Update payment status
        $stmt = $pdo->prepare("UPDATE payment_reminders SET status = 'paid', paid_at = NOW(), paid_by_admin_id = ? WHERE id = ?");
        $stmt->execute([$admin_id, $reminder_id]);

        // Fetch info for logging and notification
        $logStmt = $pdo->prepare("SELECT pr.patient_id, p.full_name, pr.amount FROM payment_reminders pr JOIN patients p ON pr.patient_id = p.id WHERE pr.id = ?");
        $logStmt->execute([$reminder_id]);
        $info = $logStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($info) {
            // Log this action
            logAction('Payment Received', "Marked No-Show fee as paid for {$info['full_name']} (Amount: {$info['amount']})", $admin_name);

            // --- START: NEW NOTIFICATION LOGIC ---
            // Construct a clear notification message for the patient
            $amount_paid = number_format($info['amount'], 2);
            $notif_message = "Your payment for the no-show fee of ₱{$amount_paid} has been received. Thank you.";

            // Insert the notification into the database for the specific patient
            $notifStmt = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
            $notifStmt->execute([$info['patient_id'], $notif_message]);
            // --- END: NEW NOTIFICATION LOGIC ---
        }

        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}