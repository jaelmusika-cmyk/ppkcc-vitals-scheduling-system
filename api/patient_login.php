<?php
require_once '../config/db.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"));
$email = $data->email ?? '';
$password = $data->password ?? '';

if (!$email || !$password) {
    echo json_encode(['status' => 'error', 'message' => 'Missing credentials']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT u.id, u.email, u.password, u.role, p.full_name, p.phone_number
    FROM users u
    JOIN patients p ON u.id = p.user_id
    WHERE u.email = ? AND u.role = 'patient'
");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'user' => [
        'id' => $user['id'],
        'email' => $user['email'],
        'full_name' => $user['full_name'],
        'phone_number' => $user['phone_number']
    ]
]);
