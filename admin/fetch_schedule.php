<?php
header('Content-Type: application/json');
require_once '../config/db.php';

$patient_id = $_GET['patient_id'] ?? null;
$unregistered_id = $_GET['unregistered_id'] ?? null;

if (!$patient_id && !$unregistered_id) {
    echo json_encode(['status' => 'error', 'message' => 'Missing patient identifier.']);
    exit;
}

// If unregistered, we first need to find the registered patient_id from the schedule
if ($unregistered_id && !$patient_id) {
    $findPIdStmt = $pdo->prepare("SELECT s.patient_id FROM schedules s JOIN unregistered_patients u ON s.id = u.schedule_id WHERE u.id = ?");
    $findPIdStmt->execute([$unregistered_id]);
    $patient_id = $findPIdStmt->fetchColumn();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM schedules WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$schedule) {
        // For unregistered patients without a linked patient_id yet, we handle them differently
        if ($unregistered_id) {
             $stmt = $pdo->prepare("SELECT s.* FROM schedules s JOIN unregistered_patients u ON s.id = u.schedule_id WHERE u.id = ?");
             $stmt->execute([$unregistered_id]);
             $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
             if (!$schedule) {
                 echo json_encode(['status' => 'error', 'message' => 'Schedule not found for unregistered patient.']);
                 exit;
             }
             // Unregistered patients cannot be swapped, so we send an empty swapped_days array
             echo json_encode(['status' => 'success', 'data' => $schedule, 'swapped_days' => []]);
             exit;
        }
        echo json_encode(['status' => 'error', 'message' => 'Schedule not found.']);
        exit;
    }

    // Fetch active swaps for this patient
    $swapsStmt = $pdo->prepare("
        SELECT patient1_day as day FROM schedule_swaps WHERE patient1_id = :id AND status = 'active'
        UNION
        SELECT patient2_day as day FROM schedule_swaps WHERE patient2_id = :id AND status = 'active'
    ");
    $swapsStmt->execute([':id' => $patient_id]);
    $swapped_results = $swapsStmt->fetchAll(PDO::FETCH_ASSOC);

    $swapped_days = [];
    foreach ($swapped_results as $row) {
        $swapped_days[$row['day']] = true;
    }

    echo json_encode(['status' => 'success', 'data' => $schedule, 'swapped_days' => $swapped_days]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>