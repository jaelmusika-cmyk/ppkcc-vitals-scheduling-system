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

// Fetch patient's full name for logging
$nameStmt = $conn->prepare("SELECT full_name FROM patients WHERE id = ?");
$nameStmt->bind_param("i", $patient_id);
$nameStmt->execute();
$patient_name = $nameStmt->get_result()->fetch_assoc()['full_name'] ?? "ID: $patient_id";
$nameStmt->close();

$batch_id = $request['batch_id'];
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

function format_val($val) {
    return $val ? $val : 'None';
}
function format_time($time) {
    return $time ? date('g:i A', strtotime($time)) : 'None';
}

// Fetch all requests related to this action (for both single and batch)
$all_requests = [];
if ($batch_id) {
    $req_stmt = $conn->prepare("SELECT * FROM schedule_requests WHERE batch_id = ?");
    $req_stmt->bind_param("s", $batch_id);
} else {
    $req_stmt = $conn->prepare("SELECT * FROM schedule_requests WHERE id = ?");
    $req_stmt->bind_param("i", $request_id);
}
$req_stmt->execute();
$result = $req_stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $all_requests[] = $row;
}
$req_stmt->close();

if (empty($all_requests)) {
    echo json_encode(['success' => false, 'message' => 'No requests found to process.']);
    exit;
}

// Fetch current schedule for logging purposes
$sched_stmt = $conn->prepare("SELECT * FROM schedules WHERE patient_id = ?");
$sched_stmt->bind_param("i", $patient_id);
$sched_stmt->execute();
$current_schedule = $sched_stmt->get_result()->fetch_assoc();
$sched_stmt->close();

$logDetails = "";
foreach ($all_requests as $req) {
    $day = strtolower($req['day']);
    $replaces_day = isset($req['replaces_day']) ? strtolower($req['replaces_day']) : null;
    
    $old_shift = $current_schedule["{$day}_shift"] ?? null;
    $old_start = $current_schedule["{$day}_start_time"] ?? null;
    $old_duration = $current_schedule["{$day}_duration"] ?? null;
    
    $new_shift = $req['shift'];
    $new_start = $req['start_time'];
    $new_duration = $req['duration'];
    
    $logDetails .= ucfirst($day) . ":\n";
    $logDetails .= "  Shift: " . format_val($old_shift) . " -> " . format_val($new_shift) . "\n";
    $logDetails .= "  Start Time: " . format_time($old_start) . " -> " . format_time($new_start) . "\n";
    $logDetails .= "  Duration: " . format_val($old_duration) . " -> " . format_val($new_duration) . "\n";
    
    if ($replaces_day) {
        $logDetails .= "  (Replaces " . ucfirst($replaces_day) . ")\n";
    }
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

    $logMessage = ($batch_id ? "Batch request rejected for " : "Single request rejected for ") . "$patient_name\n" . trim($logDetails);
    logAction('Rejected Schedule Request', $logMessage, 'Admin', $admin_action_id);
    
    echo json_encode(['success' => true, 'message' => 'Request rejected successfully.']);
    exit;
}

// --- APPROVAL LOGIC ---
$conn->begin_transaction();
try {
    $conflict = null;
    foreach($all_requests as $req) {
        $day = strtolower($req['day']);
        $shift = $req['shift'];
        if ($shift && !isShiftAvailable($conn, $day, $shift, $patient_id, $valid_days)) {
            $conflict = ucfirst($day) . " $shift";
            break;
        }
    }

    if ($conflict) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => "Cannot approve. $conflict is already full."]);
        exit;
    }

    // If batch, clear entire schedule first
    if ($batch_id) {
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
    }
    
    // Apply changes from all requests
    foreach($all_requests as $req) {
        $day = strtolower($req['day']);
        $shift = $req['shift'];
        $start_time = $req['start_time'];
        $duration = $req['duration'];
        $replaces_day = isset($req['replaces_day']) ? strtolower($req['replaces_day']) : null;
        
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
    }
    
    // Mark requests as Approved
    if ($batch_id) {
        $stmt = $conn->prepare("UPDATE schedule_requests SET status = 'Approved' WHERE batch_id = ?");
        $stmt->bind_param("s", $batch_id);
    } else {
        $stmt = $conn->prepare("UPDATE schedule_requests SET status = 'Approved' WHERE id = ?");
        $stmt->bind_param("i", $request_id);
    }
    $stmt->execute();
    $stmt->close();
    
    $conn->commit();

    $logActionType = $batch_id ? 'Approved Weekly Schedule Request' : 'Approved Schedule Request';
    $logMessage = ($batch_id ? "Batch approval for " : "Single day approval for ") . "$patient_name\n" . trim($logDetails);
    logAction($logActionType, $logMessage, 'Admin', $admin_action_id);
    
    echo json_encode(['success' => true, 'message' => 'Request approved successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Caught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()
    ]);
    exit;
}