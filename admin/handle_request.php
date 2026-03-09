<?php
session_start();
require_once '../config/db.php';
require_once 'log_action.php';

$id = $_POST['id'] ?? null;
$action = $_POST['action'] ?? '';
$note = $_POST['note'] ?? '';

if (!$id || !in_array($action, ['approve', 'reject', 'open_request_approve'])) {
    die('Invalid action');
}

$stmt = $pdo->prepare("SELECT * FROM schedule_requests WHERE id = ?");
$stmt->execute([$id]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request || $request['status'] !== 'Pending') {
    die('Request not found or already handled.');
}

$patient_id = $request['patient_id'];
$admin_name = $_SESSION['full_name'] ?? 'Unknown Admin';

if ($action === 'approve') {
    // Only logs approval — edit is handled in edit_schedule.php
    $pdo->prepare("UPDATE schedule_requests SET status = 'Approved', handled_at = NOW(), handled_by = ?, response_note = ? WHERE id = ?")
        ->execute([$admin_name, $note, $id]);

    logAction('Approved Schedule Request', "Request ID: $id\nPatient ID: $patient_id\nNote: $note", $admin_name);

} elseif ($action === 'reject') {
    $pdo->prepare("UPDATE schedule_requests SET status = 'Rejected', handled_at = NOW(), handled_by = ?, response_note = ? WHERE id = ?")
        ->execute([$admin_name, $note, $id]);

    logAction('Rejected Schedule Request', "Request ID: $id\nPatient ID: $patient_id\nReason: $note", $admin_name);

} elseif ($action === 'open_request_approve') {
    // Create a blank pending schedule
    $stmt = $pdo->prepare("INSERT INTO schedules (patient_id, status, created_at, updated_at) VALUES (?, 'Pending', NOW(), NOW())");
    $stmt->execute([$patient_id]);

    // Mark the request as handled
    $pdo->prepare("UPDATE schedule_requests SET status = 'Approved', handled_at = NOW(), handled_by = ?, response_note = ? WHERE id = ?")
        ->execute([$admin_name, $note, $id]);

    logAction('Approved Open Schedule Request', "Blank schedule created for Patient ID: $patient_id from Request ID: $id\nNote: $note", $admin_name);

    // Redirect to edit the newly created schedule
    header("Location: edit_schedule.php?patient_id=$patient_id&request_id=$id");
    exit;
}

header('Location: view_requests.php');
exit;
