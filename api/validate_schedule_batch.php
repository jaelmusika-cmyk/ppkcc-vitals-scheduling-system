<?php
require '../config/db.php';
error_reporting(0); // Disable direct error output to ensure clean JSON responses
ini_set('display_errors', 0);

header('Content-Type: application/json');

function respond($success, $message) {
    echo json_encode(["success" => $success, "message" => $message]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$batch = $input["batch"] ?? [];

if (empty($batch)) {
    respond(false, "Batch request missing or empty");
}

$email = $batch[0]["email"] ?? '';
if (!$email) {
    respond(false, "Email missing in batch");
}

// CORRECT: Get patient_id by joining users and patients table
$stmt = $conn->prepare("SELECT p.id FROM patients p JOIN users u ON u.id = p.user_id WHERE u.email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$patientResult = $stmt->get_result();

if ($patientResult->num_rows === 0) {
    respond(false, "Patient not found for email: " . $email);
}
$patientId = $patientResult->fetch_assoc()["id"];
$stmt->close();

$slotLimit = 13; // Use the correct slot limit of 13

foreach ($batch as $request) {
    $day = strtolower($request["day"] ?? '');
    $shift = $request["shift"] ?? '';
    
    if (!$day || !$shift) {
        respond(false, "Invalid schedule data: day and shift are required.");
    }

    $valid_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    if (!in_array($day, $valid_days)) {
        respond(false, "Invalid day provided: " . $day);
    }

    // CORRECT: Check if the specific day's shift is full
    $column = "{$day}_shift";
    $slotCheck = $conn->prepare("SELECT COUNT(*) as total FROM schedules WHERE `$column` = ?");
    $slotCheck->bind_param("s", $shift);
    $slotCheck->execute();
    $slotResult = $slotCheck->get_result()->fetch_assoc();
    $slotCheck->close();

    if ($slotResult["total"] >= $slotLimit) {
        respond(false, "Slot is full for " . ucfirst($day) . " ($shift)");
    }
    
    // CORRECT: Check for pending requests for this patient and day
    $pendingCheck = $conn->prepare("SELECT id FROM schedule_requests WHERE patient_id = ? AND day = ? AND status = 'Pending'");
    $pendingCheck->bind_param("is", $patientId, $day);
    $pendingCheck->execute();
    if ($pendingCheck->get_result()->num_rows > 0) {
        respond(false, "You already have a pending request for " . ucfirst($day));
    }
    $pendingCheck->close();
}

respond(true, "All schedule requests are valid.");