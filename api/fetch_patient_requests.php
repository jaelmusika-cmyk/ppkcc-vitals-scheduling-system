<?php
header('Content-Type: application/json');
require_once '../config/db.php';

date_default_timezone_set('Asia/Manila');

$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';

if (!$email) {
    echo json_encode(['status' => 'error', 'message' => 'Email is required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT sr.action_id, sr.day, sr.shift, sr.start_time, sr.duration, sr.note, sr.status, sr.created_at, sr.batch_id
        FROM schedule_requests sr
        JOIN patients p ON sr.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE u.email = ?
        ORDER BY sr.created_at DESC
    ");
    $stmt->execute([$email]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group batch requests
    $groupedRequests = [];
    foreach ($requests as $req) {
        $key = $req['batch_id'] ?? 'single_' . count($groupedRequests);
        if (!isset($groupedRequests[$key])) {
            // Create a DateTime object, specifying that the input from the DB is UTC
            $utc_date = new DateTime($req['created_at'], new DateTimeZone('UTC'));
            // Set the timezone to Asia/Manila for correct display
            $utc_date->setTimezone(new DateTimeZone('Asia/Manila'));
            
            $groupedRequests[$key] = [
                'action_id' => $req['action_id'],
                'note' => $req['note'],
                'status' => $req['status'],
                // Format the correctly converted date for the API response
                'created_at' => $utc_date->format('M d, Y, h:i A'),
                'batch_id' => $req['batch_id'],
                'details' => []
            ];
        }
        $groupedRequests[$key]['details'][] = [
            'day' => $req['day'],
            'shift' => $req['shift'],
            'start_time' => $req['start_time'],
            'duration' => $req['duration']
        ];
    }

    echo json_encode(['status' => 'success', 'requests' => array_values($groupedRequests)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>