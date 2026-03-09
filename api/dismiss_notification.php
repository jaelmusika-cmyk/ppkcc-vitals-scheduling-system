<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';
$notification_id = $data->notification_id ?? null;

if (empty($email) || empty($notification_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Email and Notification ID are required.']);
    exit;
}

try {
    // Get Patient ID to ensure the user owns the notification
    $userStmt = $pdo->prepare("SELECT p.id FROM users u JOIN patients p ON u.id = p.user_id WHERE u.email = ?");
    $userStmt->execute([$email]);
    $patient_id = $userStmt->fetchColumn();

    if (!$patient_id) {
        echo json_encode(['status' => 'error', 'message' => 'Patient not found']);
        exit;
    }

    // Delete the notification only if it belongs to the patient
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND patient_id = ?");
    $stmt->execute([$notification_id, $patient_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Notification dismissed.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Notification not found or you do not have permission to delete it.']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>