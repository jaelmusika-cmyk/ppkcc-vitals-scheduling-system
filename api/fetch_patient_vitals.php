<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';
$date = $data->date ?? '';

if (empty($email) || empty($date)) {
    echo json_encode(['status' => 'error', 'message' => 'Email and date are required.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT
            ve.attendance_status,
            ve.pre_hd_bp,
            ve.post_hd_bp,
            ve.pre_hd_wt,
            ve.post_hd_wt,
            ve.medication,
            nurse.full_name AS nurse_in_charge_name,
            f.shift
        FROM vitals_entries ve
        JOIN flowsheets f ON ve.flowsheet_id = f.id
        JOIN patients p ON ve.patient_id = p.id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN users nurse ON ve.nurse_in_charge_id = nurse.id
        WHERE u.email = :email AND f.flowsheet_date = :date
        ORDER BY f.shift ASC
    ");

    $stmt->execute(['email' => $email, 'date' => $date]);
    $vitals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($vitals) {
        echo json_encode(['status' => 'success', 'data' => $vitals]);
    } else {
        echo json_encode(['status' => 'success', 'data' => []]); // Send success with empty array if no records
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
?>