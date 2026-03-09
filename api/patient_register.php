<?php
require_once '../config/db.php';
// --- 1. Include the logging script ---
require_once __DIR__ . '/../admin/log_action.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"));

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$full_name = trim($data->full_name ?? '');
$email = trim($data->email ?? '');
$password = $data->password ?? '';
$phone_number = trim($data->phone_number ?? '');
$guardian_number = trim($data->guardian_number ?? '');
$reg_code = trim($data->registration_code ?? '');

if (!$full_name || !$email || !$password || !$phone_number || !$reg_code) {
    echo json_encode(['status' => 'error', 'message' => 'Full name, email, password, phone number, and registration code are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
    exit;
}

try {
    // 1. Validate Registration Code
    $stmt = $pdo->prepare("SELECT * FROM unregistered_patients WHERE registration_code = ?");
    $stmt->execute([$reg_code]);
    $unreg_patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$unreg_patient) {
        throw new Exception("Invalid registration code.");
    }
    if (strtolower($unreg_patient['full_name']) !== strtolower($full_name)) {
        throw new Exception("The full name does not match the name associated with this code.");
    }
    if ($unreg_patient['status'] === 'used') {
        throw new Exception("This registration code has already been used.");
    }
    if (new DateTime() > new DateTime($unreg_patient['code_expiry'])) {
        // Optionally update status to 'expired'
        $exp_stmt = $pdo->prepare("UPDATE unregistered_patients SET status = 'expired' WHERE id = ?");
        $exp_stmt->execute([$unreg_patient['id']]);
        throw new Exception("This registration code has expired.");
    }

    // 2. Check if email is already in use
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        throw new Exception("This email address is already registered.");
    }

    // 3. Begin Transaction for Registration
    $pdo->beginTransaction();

    // Create User account
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, phone_number, role, status) VALUES (?, ?, ?, ?, 'patient', 'active')");
    $stmt->execute([$unreg_patient['full_name'], $email, $hashedPassword, $phone_number]);
    $userId = $pdo->lastInsertId();

    // Create Patient record
    $stmt = $pdo->prepare("INSERT INTO patients (user_id, full_name, birth_date, gender, phone_number, guardian_number, address) VALUES (?, ?, '1970-01-01', 'Male', ?, ?, '')");
    $stmt->execute([$userId, $unreg_patient['full_name'], $phone_number, $guardian_number ?: null]);
    $patientId = $pdo->lastInsertId();

    // Link the existing schedule
    if ($unreg_patient['schedule_id']) {
        $stmt = $pdo->prepare("UPDATE schedules SET patient_id = ? WHERE id = ? AND patient_id IS NULL");
        $stmt->execute([$patientId, $unreg_patient['schedule_id']]);
    } else {
        // Fallback: create a new schedule if none was linked
        $stmt = $pdo->prepare("INSERT INTO schedules (patient_id, status) VALUES (?, 'Pending')");
        $stmt->execute([$patientId]);
    }
    
    // Link any existing vitals entries to the new patient record
    $vitalsUpdateStmt = $pdo->prepare("UPDATE vitals_entries SET patient_id = ?, unregistered_patient_id = NULL WHERE unregistered_patient_id = ?");
    $vitalsUpdateStmt->execute([$patientId, $unreg_patient['id']]);
    
    // Delete the unregistered patient record to finalize the process
    $stmt = $pdo->prepare("DELETE FROM unregistered_patients WHERE id = ?");
    $stmt->execute([$unreg_patient['id']]);

    $pdo->commit();

    // --- 2. Add the log entry upon success ---
    $patientName = $unreg_patient['full_name'];
    logAction("Patient Registration", "New patient registered: $patientName ($email)", "System (Mobile App)");


    echo json_encode(['status' => 'success', 'message' => 'Registration successful! You can now log in.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}