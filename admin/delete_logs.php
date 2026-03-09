<?php
// delete_logs.php

header('Content-Type: application/json');

// Start session and include database configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include('../config/db.php');

// Create PDO instance if not defined
if (!isset($pdo)) {
    $pdo = new PDO("mysql:host=localhost;dbname=u301954910_dialiease21", "u30194910_dialiease21", "DialiEase#21");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Check if the user is an admin (or has appropriate permissions)
// Note: You should implement a proper role check based on your session management.
// For this example, we assume an admin role is required.
/*
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
*/

// Get the posted data
$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['logs'])) {
    echo json_encode(['success' => false, 'message' => 'No logs selected for deletion.']);
    exit;
}

$pdo->beginTransaction();

try {
    // Prepare statements for both log tables to be efficient
    $deleteScheduleStmt = $pdo->prepare("DELETE FROM schedule_logs WHERE action_id = ?");
    $deleteAccountStmt = $pdo->prepare("DELETE FROM account_logs WHERE action_id = ?");

    foreach ($data['logs'] as $log) {
        $actionId = $log['action_id'];
        $logSource = $log['log_source'];

        if ($logSource === 'Schedule Log') {
            $deleteScheduleStmt->execute([$actionId]);
        } elseif ($logSource === 'Account Log') {
            $deleteAccountStmt->execute([$actionId]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Selected logs have been deleted.']);

} catch (PDOException $e) {
    $pdo->rollBack();
    // In a real application, you might want to log this error instead of echoing it.
    echo json_encode(['success' => false, 'message' => 'Failed to delete logs: ' . $e->getMessage()]);
}