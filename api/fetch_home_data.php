<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';

if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Email required']);
    exit;
}

try {
    // 1. Get Patient ID
    $userStmt = $pdo->prepare("SELECT p.id FROM users u JOIN patients p ON u.id = p.user_id WHERE u.email = ?");
    $userStmt->execute([$email]);
    $patient_id = $userStmt->fetchColumn();

    if (!$patient_id) {
        echo json_encode(['status' => 'error', 'message' => 'Patient not found']);
        exit;
    }
    
    // 2. Fetch Announcements (get all active ones)
    $announcements = $pdo->query("SELECT title, message, created_at FROM announcements ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Unpaid Payment Reminders
    $remindersStmt = $pdo->prepare("SELECT amount, date_of_absence FROM payment_reminders WHERE patient_id = ? AND status = 'unpaid' ORDER BY date_of_absence ASC");
    $remindersStmt->execute([$patient_id]);
    $reminders = $remindersStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch Unread Notifications
    $notifStmt = $pdo->prepare("SELECT id, message, created_at FROM notifications WHERE patient_id = ? ORDER BY created_at DESC");
    $notifStmt->execute([$patient_id]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Fetch Schedule
    $scheduleStmt = $pdo->prepare("SELECT * FROM schedules WHERE patient_id = ? LIMIT 1");
    $scheduleStmt->execute([$patient_id]);
    $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
    
    $scheduleData = [];
    $schedule_status = 'Pending';
    if ($schedule) {
        $schedule_status = $schedule['status'];
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday'];
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
    }

    echo json_encode([
        'status' => 'success',
        'announcements' => $announcements,
        'reminders' => $reminders,
        'notifications' => $notifications,
        'schedule_info' => [
            'schedule_status' => $schedule_status,
            'schedules' => $scheduleData
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>