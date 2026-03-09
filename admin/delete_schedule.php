<?php
session_start();
date_default_timezone_set('Asia/Manila');
include('../config/db.php');
include_once 'log_action.php';
header('Content-Type: application/json');
ob_clean();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patientId = $_POST['patient_id'] ?? null;
    $unregisteredId = $_POST['unregistered_id'] ?? null;
    $action = $_POST['action'] ?? null;

    $schedule_id = null;
    $name = 'Unknown';

    if (!empty($patientId) && $patientId !== 'null') {
        $stmt = $pdo->prepare("SELECT schedules.id, patients.full_name FROM schedules 
                               JOIN patients ON schedules.patient_id = patients.id 
                               WHERE patient_id = ?");
        $stmt->execute([$patientId]);
        $row = $stmt->fetch();
        $schedule_id = $row['id'] ?? null;
        $name = $row['full_name'] ?? 'Unknown';

    } elseif (!empty($unregisteredId) && $unregisteredId !== 'null') {
        // Get schedule_id safely
        $stmt = $pdo->prepare("SELECT schedule_id, full_name FROM unregistered_patients WHERE id = ?");
        $stmt->execute([$unregisteredId]);
        $row = $stmt->fetch();

        $schedule_id = $row['schedule_id'] ?? null;
        $name = $row['full_name'] ?? 'Unknown';

        // Auto-create schedule if missing
        if (!$schedule_id) {
            $pdo->prepare("INSERT INTO schedules (status, updated_at) VALUES ('Pending', NOW())")->execute();
            $schedule_id = $pdo->lastInsertId();
            $pdo->prepare("UPDATE unregistered_patients SET schedule_id = ? WHERE id = ?")
                ->execute([$schedule_id, $unregisteredId]);
        }
    }

    if (!$schedule_id) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found.']);
        exit;
    }

    if ($action === 'archive' || $action === 'destroy') {
        $newStatus = $action === 'archive' ? 'Inactive' : 'Deleted';

        try {
            $stmt = $pdo->prepare("UPDATE schedules SET status = :status, updated_at = NOW() WHERE id = :id");
            $success = $stmt->execute([
                ':status' => $newStatus,
                ':id' => $schedule_id
            ]);

            $who = $_SESSION['full_name'] ?? 'Unknown Admin';

            if ($success) {
                $logLabel = ($newStatus === 'Inactive') ? 'Archived' : ucfirst($newStatus);
                logAction('Archive/Destroy Schedule', "$logLabel schedule ID: $schedule_id ($name)", $who);
                // START: ADD NOTIFICATION LOGIC
if (!empty($patientId) && $patientId !== 'null') {
    $notif_message = "Your schedule has been set to '$newStatus'. Please contact the clinic for details.";
    $notifStmt = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
    $notifStmt->execute([$patientId, $notif_message]);
}
// END: ADD NOTIFICATION LOGIC
                echo json_encode(['success' => true, 'message' => ucfirst($newStatus) . ' successfully.']);
            } else {
                logAction('Archive/Destroy Schedule Failed', "Failed to update status for schedule ID: $schedule_id ($name)", $who);
                echo json_encode(['success' => false, 'message' => 'Failed to update schedule.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
exit;
