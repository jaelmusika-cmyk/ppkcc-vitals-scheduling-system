<?php
header('Content-Type: application/json');
require_once '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$name = trim($_POST['full_name'] ?? '');

if (empty($name)) {
    echo json_encode(['status' => 'error', 'message' => 'Name cannot be empty.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO schedules (patient_id, status, updated_at) VALUES (NULL, 'Pending', NOW())");
    $stmt->execute();
    $schedule_id = $pdo->lastInsertId();

    if (!$schedule_id) {
        throw new Exception("Error: Schedule ID was not generated correctly.");
    }

    $stmt = $pdo->prepare("INSERT INTO unregistered_patients (full_name, schedule_id) VALUES (?, ?)");
    $stmt->execute([$name, $schedule_id]);

    $pdo->commit();

    include_once 'log_action.php';
    $who = $_SESSION['full_name'] ?? 'Unknown Admin';
    logAction('Add Unregistered Patient', "Name: $name\nSchedule ID: $schedule_id", $who);

    echo json_encode(['status' => 'success', 'message' => 'Unregistered patient added successfully!']);
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Transaction failed: ' . $e->getMessage()]);
    exit;
}
