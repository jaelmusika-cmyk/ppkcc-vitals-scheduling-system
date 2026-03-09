<?php
require_once '../config/db.php';
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

$data = json_decode(file_get_contents("php://input"), true);
$email = $data['email'] ?? null;

if (!$email) {
    echo json_encode(["status" => "error", "message" => "Missing email."]);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND role = 'patient'");
$stmt->bind_param("s", $email);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "Patient not found."]);
    exit;
}

$patient = $res->fetch_assoc();
$patient_id = $patient['id'];

$logs = $conn->prepare("SELECT action, timestamp FROM patient_logs WHERE patient_id = ? ORDER BY timestamp DESC");
$logs->bind_param("i", $patient_id);
$logs->execute();
$result = $logs->get_result();

$response = [];
while ($row = $result->fetch_assoc()) {
    $response[] = $row;
}

echo json_encode(["status" => "success", "logs" => $response]);
?>
