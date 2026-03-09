<?php
session_start();
header('Content-Type: application/json');
require_once '../config/db.php';
// Add this line to include the logging function
require_once '../admin/log_action.php';

// This script handles the 'Acknowledge' action from the admin panel.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request or not logged in.']);
    exit;
}

$action = $_POST['action'] ?? '';
$absence_id = $_POST['absence_id'] ?? null;
$admin_id = $_SESSION['user_id'];

if ($action === 'acknowledge' && $absence_id) {
    try {
        $pdo->beginTransaction();

        // Step 1: Get patient_id and name from the absence record.
        $stmt_get = $pdo->prepare("SELECT ia.patient_id, p.full_name FROM informed_absences ia JOIN patients p ON ia.patient_id = p.id WHERE ia.id = ?");
        $stmt_get->execute([$absence_id]);
        $absence_data = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if (!$absence_data) {
            throw new Exception("Absence record not found.");
        }
        $patient_id = $absence_data['patient_id'];
        $patient_name = $absence_data['full_name'];

        // Get admin's name for logging
        $adminStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
        $adminStmt->execute([$admin_id]);
        $admin_name = $adminStmt->fetchColumn();


        // Step 2: Update the absence record to 'acknowledged' status.
        $stmt_update = $pdo->prepare("
            UPDATE informed_absences
            SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_by = ?
            WHERE id = ? AND status = 'sent'
        ");
        $stmt_update->execute([$admin_id, $absence_id]);

        // Proceed only if the update was successful (row count > 0)
        if ($stmt_update->rowCount() > 0) {
            // Step 3: Insert a notification for the patient.
            $notif_message = "Your informed absence has been acknowledged by the clinic.";
            $stmt_notif = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
            $stmt_notif->execute([$patient_id, $notif_message]);

            $pdo->commit();

            // --- LOG THE ACTION ---
            $log_details = "Acknowledged absence notice (ID: {$absence_id}) for patient '{$patient_name}'.";
            logAction('Acknowledge Informed Absence', $log_details, $admin_name ?? 'Admin');
            // --- END LOGGING ---

            echo json_encode(['success' => true]);
        } else {
            // This prevents errors if the button is clicked twice.
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Could not acknowledge. The absence may have already been processed.']);
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters.']);
}
?>