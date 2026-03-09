<?php
// Set headers first to ensure they are sent
header('Content-Type: application/json');

// This is a global exception handler to ensure we always output valid JSON,
// even if an unexpected error occurs.
set_exception_handler(function ($exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected server error occurred. See error log for details.',
        // For debugging, you might want to see the error message. Remove in production.
        'error' => $exception->getMessage() 
    ]);
    exit;
});

session_start();

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Include database config and check for the PDO object
require_once '../config/db.php';
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

// Admin authentication check
// FIX: Added !isset($_SESSION['user_role']) to prevent a PHP warning if the key is not set.
// This is the primary fix for the 403 error and invalid JSON response.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$absence_id = filter_var($_POST['absence_id'] ?? 0, FILTER_VALIDATE_INT);

if ($action === 'acknowledge' && $absence_id > 0) {
    try {
        $pdo->beginTransaction();

        // Step 1: Get patient_id before updating
        $stmt = $pdo->prepare("SELECT patient_id FROM informed_absences WHERE id = ? AND status = 'pending'");
        $stmt->execute([$absence_id]);
        $patient_id = $stmt->fetchColumn();

        if (!$patient_id) {
             throw new Exception('Absence record not found or already acknowledged.');
        }

        // Step 2: Update the informed_absences table
        $updateStmt = $pdo->prepare("UPDATE informed_absences SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?");
        $updateStmt->execute([$_SESSION['user_id'], $absence_id]);

        // Step 3: Create a notification for the patient
        $notificationMessage = "Your recently informed absence has been acknowledged by the clinic.";
        $notifStmt = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
        $notifStmt->execute([$patient_id, $notificationMessage]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Absence acknowledged and notification sent.']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        // Return the specific database error message
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action or ID.']);
}
?>