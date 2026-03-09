<?php
session_start();
date_default_timezone_set('Asia/Manila');
include('../config/db.php');
include_once 'log_action.php';
header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'Invalid request.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $patientId = $input['patient_id'] ?? null;
    $daysToArchive = $input['days_to_archive'] ?? [];
    $daysToUnarchive = $input['days_to_unarchive'] ?? [];
    $who = $_SESSION['full_name'] ?? 'Unknown Admin';

    if (empty($patientId)) {
        $response['message'] = 'Patient ID is missing.';
        echo json_encode($response);
        exit;
    }
    
    $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    $setClauses = [];
    $params = [];

    foreach ($daysToArchive as $day) {
        if (in_array($day, $validDays)) {
            $setClauses[] = "`{$day}_archived` = 1";
        }
    }
    foreach ($daysToUnarchive as $day) {
        if (in_array($day, $validDays)) {
            $setClauses[] = "`{$day}_archived` = 0";
        }
    }

    if (empty($setClauses)) {
        $response['message'] = 'No valid days selected for action.';
        echo json_encode($response);
        exit;
    }

    try {
        $sql = "UPDATE schedules SET " . implode(', ', $setClauses) . " WHERE patient_id = ?";
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([$patientId]);

        if ($success) {
            logAction('Manage Archived Days', "Updated archived status for patient ID: $patientId", $who);
            $response = ['success' => true, 'message' => 'Archived days updated successfully.'];
        } else {
            $response['message'] = 'Failed to update schedule in the database.';
        }
    } catch (Exception $e) {
        $response['message'] = 'Database error: ' . $e->getMessage();
    }
}

echo json_encode($response);
exit;