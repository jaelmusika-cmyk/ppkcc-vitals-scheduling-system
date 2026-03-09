<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';

if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Email required']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT s.*, p.id AS patient_id
    FROM users u
    JOIN patients p ON u.id = p.user_id
    JOIN schedules s ON s.patient_id = p.id
    WHERE u.email = ?
    LIMIT 1
");
$stmt->execute([$email]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    echo json_encode(['status' => 'error', 'message' => 'Schedule not found']);
    exit;
}

// Transform schedule
$days = ['monday','tuesday','wednesday','thursday','friday','saturday'];
$scheduleData = [];
foreach ($days as $day) {
    $shift = $schedule[$day.'_shift'];
    $start = $schedule[$day.'_start_time'];
    $duration = $schedule[$day.'_duration'];

    if ($shift || $start || $duration) {
        $scheduleData[] = [
            'day' => ucfirst($day),
            'shift' => $shift,
            'start_time' => $start,
            'duration' => $duration
        ];
    }
}

echo json_encode([
    'status' => 'success',
    'schedule_status' => $schedule['status'],
    'schedules' => $scheduleData
]);
?>
