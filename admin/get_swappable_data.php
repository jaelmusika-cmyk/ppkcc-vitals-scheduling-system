<?php
header('Content-Type: application/json');
require_once '../config/db.php';

$sql = "
    SELECT 
        s.patient_id,
        p.full_name,
        'monday' as day, s.monday_shift as shift FROM schedules s JOIN patients p ON s.patient_id = p.id WHERE s.monday_shift IS NOT NULL AND s.status = 'Scheduled'
    UNION ALL
    SELECT 
        s.patient_id, p.full_name, 'tuesday' as day, s.tuesday_shift as shift FROM schedules s JOIN patients p ON s.patient_id = p.id WHERE s.tuesday_shift IS NOT NULL AND s.status = 'Scheduled'
    UNION ALL
    SELECT 
        s.patient_id, p.full_name, 'wednesday' as day, s.wednesday_shift as shift FROM schedules s JOIN patients p ON s.patient_id = p.id WHERE s.wednesday_shift IS NOT NULL AND s.status = 'Scheduled'
    UNION ALL
    SELECT 
        s.patient_id, p.full_name, 'thursday' as day, s.thursday_shift as shift FROM schedules s JOIN patients p ON s.patient_id = p.id WHERE s.thursday_shift IS NOT NULL AND s.status = 'Scheduled'
    UNION ALL
    SELECT 
        s.patient_id, p.full_name, 'friday' as day, s.friday_shift as shift FROM schedules s JOIN patients p ON s.patient_id = p.id WHERE s.friday_shift IS NOT NULL AND s.status = 'Scheduled'
    UNION ALL
    SELECT 
        s.patient_id, p.full_name, 'saturday' as day, s.saturday_shift as shift FROM schedules s JOIN patients p ON s.patient_id = p.id WHERE s.saturday_shift IS NOT NULL AND s.status = 'Scheduled'
";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$patients = [];
foreach ($results as $row) {
    if (!isset($patients[$row['patient_id']])) {
        $patients[$row['patient_id']] = [
            'full_name' => $row['full_name'],
            'scheduled_days' => []
        ];
    }
    $patients[$row['patient_id']]['scheduled_days'][] = $row['day'];
}

echo json_encode(['status' => 'success', 'data' => $patients]);
?>