<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));

$email = $data->email ?? '';
$message = $data->message ?? '';

if (empty($email) || empty($message)) {
    echo json_encode(['status' => 'error', 'message' => 'Email and message are required.']);
    exit;
}

// Sanitize message
$message = htmlspecialchars(strip_tags($message));

try {
    // 1. Get Patient ID from email
    $userStmt = $pdo->prepare("SELECT p.id FROM users u JOIN patients p ON u.id = p.user_id WHERE u.email = ?");
    $userStmt->execute([$email]);
    $patient_id = $userStmt->fetchColumn();

    if (!$patient_id) {
        echo json_encode(['status' => 'error', 'message' => 'Patient not found.']);
        exit;
    }

    // 2. Insert into the informed_absences table
    // NOTE: You must create this table in your database.
    // See the database structure suggestion at the end of this response.
    $stmt = $pdo->prepare("INSERT INTO informed_absences (patient_id, message) VALUES (?, ?)");
    $stmt->execute([$patient_id, $message]);

    echo json_encode(['status' => 'success', 'message' => 'Absence information submitted successfully.']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>