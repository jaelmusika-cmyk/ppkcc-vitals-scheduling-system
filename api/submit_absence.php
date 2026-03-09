<?php
// Add this line to include the logging function
require_once '../admin/log_action.php'; 
require_once '../config/db.php';
header('Content-Type: application/json');

// This script handles the "Inform Absence" submission from the Android app.

$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';
$message = $data->message ?? '';

if (empty($email) || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'Email and message are required.']);
    exit;
}

try {
    // Get Patient ID from email
    $userStmt = $pdo->prepare("SELECT u.id, p.id as patient_id, p.full_name FROM users u JOIN patients p ON u.id = p.user_id WHERE u.email = ?");
    $userStmt->execute([$email]);
    $user_data = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_data) {
        echo json_encode(['status' => 'error', 'message' => 'Patient not found.']);
        exit;
    }
    
    $patient_id = $user_data['patient_id'];
    $patient_name = $user_data['full_name']; // Get patient's name for the log

    // Insert the absence message into the new table
    $stmt = $pdo->prepare("INSERT INTO informed_absences (patient_id, message) VALUES (?, ?)");
    if ($stmt->execute([$patient_id, $message])) {
        // --- LOG THE ACTION ---
        $log_details = "Patient '{$patient_name}' submitted an absence notice.";
        logAction('Patient Informed Absence', $log_details, $patient_name);
        // --- END LOGGING ---

        echo json_encode(['status' => 'success', 'message' => 'Absence information submitted successfully.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to submit absence information.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>