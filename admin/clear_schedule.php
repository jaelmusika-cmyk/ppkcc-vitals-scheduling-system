<?php
session_start();
include('../config/db.php');
include_once 'log_action.php';
header('Content-Type: application/json');
ob_clean();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schedule_id = null;
    $name = 'Unknown';

    if (!empty($_POST['patient_id']) && $_POST['patient_id'] !== 'null') {
        $stmt = $pdo->prepare("SELECT schedules.id, patients.full_name FROM schedules 
                               JOIN patients ON schedules.patient_id = patients.id 
                               WHERE patient_id = ?");
        $stmt->execute([$_POST['patient_id']]);
        $row = $stmt->fetch();
        $schedule_id = $row['id'] ?? null;
        $name = $row['full_name'] ?? 'Unknown';

    } elseif (!empty($_POST['unregistered_id']) && $_POST['unregistered_id'] !== 'null') {
        // Get schedule_id safely
        $stmt = $pdo->prepare("SELECT schedule_id, full_name FROM unregistered_patients WHERE id = ?");
        $stmt->execute([$_POST['unregistered_id']]);
        $row = $stmt->fetch();

        $schedule_id = $row['schedule_id'] ?? null;
        $name = $row['full_name'] ?? 'Unknown';

        // Create schedule if missing
        if (!$schedule_id) {
            $pdo->prepare("INSERT INTO schedules (status, updated_at) VALUES ('Pending', NOW())")->execute();
            $schedule_id = $pdo->lastInsertId();
            $pdo->prepare("UPDATE unregistered_patients SET schedule_id = ? WHERE id = ?")
                ->execute([$schedule_id, $_POST['unregistered_id']]);
        }
    }

    if (!$schedule_id) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found.']);
        exit;
    }

    $sql = "UPDATE schedules SET 
        monday_shift = NULL, monday_start_time = NULL, monday_duration = NULL,
        tuesday_shift = NULL, tuesday_start_time = NULL, tuesday_duration = NULL,
        wednesday_shift = NULL, wednesday_start_time = NULL, wednesday_duration = NULL,
        thursday_shift = NULL, thursday_start_time = NULL, thursday_duration = NULL,
        friday_shift = NULL, friday_start_time = NULL, friday_duration = NULL,
        saturday_shift = NULL, saturday_start_time = NULL, saturday_duration = NULL,
        updated_at = NOW(), status = 'Pending'
        WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $schedule_id, PDO::PARAM_INT);
    $success = $stmt->execute();

    if ($success) {
        $who = $_SESSION['full_name'] ?? 'Unknown Admin';
        logAction('Clear Schedule', "Cleared all days for Schedule ID: $schedule_id ($name)", $who);
        // START: ADD NOTIFICATION LOGIC
if (!empty($_POST['patient_id']) && $_POST['patient_id'] !== 'null') {
    $notifStmt = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
    $notifStmt->execute([$_POST['patient_id'], "Your entire weekly schedule has been cleared."]);
}
// END: ADD NOTIFICATION LOGIC
        echo json_encode(['success' => true, 'message' => 'Schedule cleared successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to clear schedule.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request.']);
exit;
