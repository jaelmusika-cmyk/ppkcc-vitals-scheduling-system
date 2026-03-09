<?php
// handle_schedule_request.php

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/log_action.php';
date_default_timezone_set('Asia/Manila');

// NEW: Function to generate a unique action ID
function generateActionId() {
    return 'PPKC-' . mt_rand(1000000000, 9999999999);
}

set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => "Error: $errstr at $errfile:$errline"]);
    exit;
});

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$request_id = $data['request_id'] ?? null;
// NEW: Generate a unique ID for this specific admin action
$admin_action_id = generateActionId();

if (!$request_id || !in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request or action.']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM schedule_requests WHERE id = ?");
$stmt->bind_param("i", $request_id);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    echo json_encode(['success' => false, 'message' => 'Schedule request not found.']);
    exit;
}

$patient_id = $request['patient_id'];
$day = strtolower($request['day']);

// Fetch patient's full name for logging
$nameStmt = $conn->prepare("SELECT full_name FROM patients WHERE id = ?");
$nameStmt->bind_param("i", $patient_id);
$nameStmt->execute();
$patient_name = $nameStmt->get_result()->fetch_assoc()['full_name'] ?? "ID: $patient_id";
$nameStmt->close();

$shift = $request['shift'];
$start_time = $request['start_time'];
$duration = $request['duration'];
$batch_id = $request['batch_id'];
$replaces_day = isset($request['replaces_day']) ? strtolower($request['replaces_day']) : null;

$valid_days = ['monday','tuesday','wednesday','thursday','friday','saturday'];

function isShiftAvailable($conn, $day, $shift, $patient_id, $valid_days) {
    if (!in_array($day, $valid_days)) throw new Exception("Invalid day: $day");
    $column = "{$day}_shift";
    $sql = "SELECT COUNT(*) AS count FROM schedules WHERE `$column` = ? AND patient_id != ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $shift, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['count'] < 13;
}

if ($action === 'reject') {
    if ($batch_id) {
        $update = $conn->prepare("UPDATE schedule_requests SET status = 'Rejected' WHERE batch_id = ?");
        $update->bind_param("s", $batch_id);
    } else {
        $update = $conn->prepare("UPDATE schedule_requests SET status = 'Rejected' WHERE id = ?");
        $update->bind_param("i", $request_id);
    }
    $update->execute();
    $update->close();

    $logMessage = $batch_id ? "Batch request rejected for $patient_name" : "Single request rejected for $patient_name";
    logAction('Rejected Schedule Request', $logMessage, 'Admin', $admin_action_id);

    // --- START: ADD NOTIFICATION FOR REJECTION ---
    $day_formatted = ucfirst($request['day']);
    $notif_message = $batch_id 
        ? "Your weekly schedule request has been rejected."
        : "Your schedule request for {$day_formatted} has been rejected.";
    
    $notifStmt = $conn->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
    $notifStmt->bind_param("is", $patient_id, $notif_message);
    $notifStmt->execute();
    $notifStmt->close();
    // --- END: ADD NOTIFICATION FOR REJECTION ---

    echo json_encode(['success' => true, 'message' => 'Request rejected successfully.']);
    exit;
}

// Handles single (non-batch) requests
if (!$batch_id) {
    if ($shift && !isShiftAvailable($conn, $day, $shift, $patient_id, $valid_days)) {
        echo json_encode(['success' => false, 'message' => ucfirst($day) . " $shift is already full."]);
        exit;
    }

    $sql = "UPDATE schedules SET {$day}_shift = ?, {$day}_start_time = ?, {$day}_duration = ?, status = 'Scheduled', updated_at = NOW() WHERE patient_id = ?";
    $update = $conn->prepare($sql);
    $update->bind_param("sssi", $shift, $start_time, $duration, $patient_id);
    $update->execute();
    $update->close();

    if ($replaces_day && in_array($replaces_day, $valid_days)) {
        $clearSQL = "UPDATE schedules SET {$replaces_day}_shift = NULL, {$replaces_day}_start_time = NULL, {$replaces_day}_duration = NULL WHERE patient_id = ?";
        $clear = $conn->prepare($clearSQL);
        $clear->bind_param("i", $patient_id);
        $clear->execute();
        $clear->close();
    }

    $stmt = $conn->prepare("UPDATE schedule_requests SET status = 'Approved' WHERE id = ?");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $stmt->close();

    logAction('Approved Schedule Request', "Single day approval for patient ID $patient_id", 'Admin', $admin_action_id);

    // --- START: ADD NOTIFICATION FOR SINGLE APPROVAL ---
    $day_formatted = ucfirst($request['day']);
    $notif_message = "Your schedule request for {$day_formatted} has been approved.";

    $notifStmt = $conn->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
    $notifStmt->bind_param("is", $patient_id, $notif_message);
    $notifStmt->execute();
    $notifStmt->close();
    // --- END: ADD NOTIFICATION FOR SINGLE APPROVAL ---

    echo json_encode(['success' => true, 'message' => 'Request approved successfully.']);
    exit;
}

// Handles batch approval
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT * FROM schedule_requests WHERE batch_id = ?");
    $stmt->bind_param("s", $batch_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $daysToSet = [];
    $conflict = null;

    while ($r = $result->fetch_assoc()) {
        $d = strtolower($r['day']);
        $s = $r['shift'];

        if ($s && !isShiftAvailable($conn, $d, $s, $patient_id, $valid_days)) {
            $conflict = ucfirst($d) . " $s";
            break;
        }

        $daysToSet["{$d}_shift"] = $s;
        $daysToSet["{$d}_start_time"] = $r['start_time'];
        $daysToSet["{$d}_duration"] = $r['duration'];
    }

    $stmt->close();

    if ($conflict) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => "Cannot approve. $conflict is already full."]);
        exit;
    }
    
    $clearAllSQL = "UPDATE schedules SET 
        monday_shift = NULL, monday_start_time = NULL, monday_duration = NULL,
        tuesday_shift = NULL, tuesday_start_time = NULL, tuesday_duration = NULL,
        wednesday_shift = NULL, wednesday_start_time = NULL, wednesday_duration = NULL,
        thursday_shift = NULL, thursday_start_time = NULL, thursday_duration = NULL,
        friday_shift = NULL, friday_start_time = NULL, friday_duration = NULL,
        saturday_shift = NULL, saturday_start_time = NULL, saturday_duration = NULL
        WHERE patient_id = ?";
    $clearAll = $conn->prepare($clearAllSQL);
    $clearAll->bind_param("i", $patient_id);
    $clearAll->execute();
    $clearAll->close();

    $setClauses = [];
    $params = [];
    $types = '';
    foreach ($daysToSet as $col => $val) {
        $setClauses[] = "`$col` = ?";
        $params[] = $val;
        
        if (strpos($col, '_duration') !== false) {
            $types .= 'i';
        } else {
            $types .= 's';
        }
    }
    
    $setClauses[] = "status = 'Scheduled'";
    $setClauses[] = "updated_at = NOW()";
    
    $updateSQL = "UPDATE schedules SET " . implode(', ', $setClauses) . " WHERE patient_id = ?";
    
    $params[] = $patient_id;
    $types .= 'i';

    $update = $conn->prepare($updateSQL);
    if ($update === false) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $update->bind_param($types, ...$params);
    if ($update->execute() === false) {
        throw new Exception('Execute failed: ' . $update->error);
    }
    $update->close();

    $stmt = $conn->prepare("UPDATE schedule_requests SET status = 'Approved' WHERE batch_id = ?");
    $stmt->bind_param("s", $batch_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    logAction('Approved Weekly Schedule Request', "Batch approval for $patient_name", 'Admin', $admin_action_id);

    // --- START: ADD NOTIFICATION FOR BATCH APPROVAL ---
    $notif_message = "Your weekly schedule request has been approved.";
    $notifStmt = $conn->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
    $notifStmt->bind_param("is", $patient_id, $notif_message);
    $notifStmt->execute();
    $notifStmt->close();
    // --- END: ADD NOTIFICATION FOR BATCH APPROVAL ---

    echo json_encode(['success' => true, 'message' => 'Weekly schedule approved successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Caught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()
    ]);
    exit;
}