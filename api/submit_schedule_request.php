<?php 
header("Content-Type: application/json; charset=UTF-8");
date_default_timezone_set('Asia/Manila'); // Set correct timezone
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log');

require_once '../config/db.php';
// Use include_once for log_action to prevent re-declaration issues
include_once __DIR__ . '/../admin/log_action.php'; 

function respond($status, $message) {
    echo json_encode(["status" => $status, "message" => $message]);
    exit;
}

function isShiftAvailable($conn, $day, $shift, $patient_id) {
    $column = "{$day}_shift";
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM schedules WHERE `$column` = ? AND patient_id != ?");
    $stmt->bind_param("si", $shift, $patient_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    return $res['cnt'] < 13;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        respond("error", "Invalid JSON input: " . json_last_error_msg());
    }

    if (!$input || !isset($input['email'])) {
        respond("error", "Missing 'email' field in input");
    }

    $email = $input['email'];
    $batch_id = $input['batch_id'] ?? null;
    $batch_id = ($batch_id === '' || is_null($batch_id)) ? null : $batch_id;
    $note = $input['note'] ?? '';
    $requests = $input['requests'] ?? null;

    $day = isset($input['day']) ? strtolower($input['day']) : null;
    $shift = $input['shift'] ?? null;
    $start_time = $input['start_time'] ?? null;
    $duration = $input['duration'] ?? null;
    $replaces_day = isset($input['replaces_day']) ? strtolower($input['replaces_day']) : null;

    // Get patient_id and full_name for logging
    $stmt = $conn->prepare("SELECT p.id AS patient_id, u.full_name FROM patients p JOIN users u ON u.id = p.user_id WHERE u.email = ? AND u.role = 'patient' LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        respond("error", "Patient not found");
    }
    $patient_data = $res->fetch_assoc();
    $patient_id = $patient_data['patient_id'];
    $patient_name = $patient_data['full_name'];

    function hasPendingRequestForDay($conn, $patient_id, $day) {
        $sql = "SELECT id FROM schedule_requests WHERE patient_id = ? AND status = 'Pending' AND (day = ? OR replaces_day = ?) AND (expires_at IS NULL OR expires_at > NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $patient_id, $day, $day);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
    
    // Generate one Action ID for this entire submission (single or batch)
    $action_id = 'PPKC-' . str_pad(mt_rand(1, 9999999999), 10, '0', STR_PAD_LEFT);

    if ($batch_id) {
        if (!is_array($requests) || count($requests) === 0) {
            respond("error", "Batch requests missing or empty");
        }

        file_put_contents("debug_batch_insert.log", "Batch ID: $batch_id\nRequests: " . json_encode($requests, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        $expires_at = (stripos($note, 'weekly') !== false) ? date('Y-m-d H:i:s', strtotime('+3 days')) : date('Y-m-d H:i:s', strtotime('+1 day'));

        $conflicts = [];
        foreach ($requests as $req) {
            $d = strtolower($req['day'] ?? '');
            $s = $req['shift'] ?? '';
            $r = isset($req['replaces_day']) ? strtolower($req['replaces_day']) : null;
            if (!$d || !$s) {
                respond("error", "Each request must have valid 'day' and 'shift'");
            }
            if (hasPendingRequestForDay($conn, $patient_id, $d)) {
                $conflicts[] = "$d (pending)";
            }
            if ($r && hasPendingRequestForDay($conn, $patient_id, $r)) {
                $conflicts[] = "$r (pending)";
            }
            if (!isShiftAvailable($conn, $d, $s, $patient_id)) {
                $conflicts[] = "$d ($s shift full)";
            }
        }

        if (!empty($conflicts)) {
            respond("error", "Conflicts: " . implode(", ", array_unique($conflicts)));
        }

        $conn->begin_transaction();
        try {
            $del_stmt = $conn->prepare("DELETE FROM schedule_requests WHERE batch_id = ? AND patient_id = ?");
            $del_stmt->bind_param("si", $batch_id, $patient_id);
            $del_stmt->execute();

            $insert_stmt = $conn->prepare("INSERT INTO schedule_requests (action_id, patient_id, day, shift, start_time, duration, note, batch_id, replaces_day, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW(), ?)");

            foreach ($requests as $req) {
                $d = strtolower($req['day']);
                $s = $req['shift'] ?? '';
                $st = $req['start_time'] ?? '';
                $dur = (int)($req['duration'] ?? 0);
                $rep = isset($req['replaces_day']) ? strtolower($req['replaces_day']) : '';

                $insert_stmt->bind_param("sisssissss", $action_id, $patient_id, $d, $s, $st, $dur, $note, $batch_id, $rep, $expires_at);
                if (!$insert_stmt->execute()) {
                    throw new Exception("Insert failed for $d: " . $insert_stmt->error);
                }
            }
            
            $logDetails = "Patient $patient_name submitted a weekly schedule request.\n";
            foreach ($requests as $req) {
                $day_log = ucfirst($req['day']);
                $shift_log = $req['shift'] ?? 'N/A';
                $start_log = $req['start_time'] ? date('g:i A', strtotime($req['start_time'])) : 'N/A';
                $duration_log = $req['duration'] ?? 'N/A';
                $logDetails .= "  - $day_log: $shift_log, $start_log, $duration_log mins\n";
            }
            logAction("Submitted Weekly Request", trim($logDetails), $patient_name, $action_id);

            $conn->commit();
            respond("success", "Weekly batch requests sent.");
        } catch (Exception $e) {
            $conn->rollback();
            respond("error", "Batch insert failed: " . $e->getMessage());
        }
    } else {
        // SINGLE REQUEST HANDLING
        file_put_contents("debug_single_check.log", json_encode([
            "day" => $day, "shift" => $shift, "start_time" => $start_time, "duration" => $duration, "note" => $note
        ], JSON_PRETTY_PRINT));

        if (!$day || (!$shift && !$start_time && !$duration && stripos($note, 'clear') === false)) {
            respond("error", "Missing fields for single request");
        }
        if (hasPendingRequestForDay($conn, $patient_id, $day)) {
            respond("error", "Pending request already exists for $day");
        }
        if ($replaces_day && hasPendingRequestForDay($conn, $patient_id, $replaces_day)) {
            respond("error", "Cannot replace $replaces_day due to existing pending request");
        }
        if ($shift && !isShiftAvailable($conn, $day, $shift, $patient_id)) {
            respond("error", "Slot for $day ($shift) is full");
        }

        $expires_at = (stripos($note, 'weekly') !== false) ? date('Y-m-d H:i:s', strtotime('+3 days')) : date('Y-m-d H:i:s', strtotime('+1 day'));
        
        $stmt = $conn->prepare("INSERT INTO schedule_requests (action_id, patient_id, day, shift, start_time, duration, note, batch_id, replaces_day, status, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 'Pending', NOW(), ?)");
        $stmt->bind_param("sisssisss", $action_id, $patient_id, $day, $shift, $start_time, $duration, $note, $replaces_day, $expires_at);

        if ($stmt->execute()) {
            $logDetails = "Patient $patient_name submitted a single request for $day.\n";
            $shift_log = $shift ?: 'None';
            $start_log = $start_time ? date('g:i A', strtotime($start_time)) : 'None';
            $duration_log = $duration ?: 'None';

            if (stripos($note, 'clear') !== false) {
                 $logDetails .= "  - Request to clear schedule for " . ucfirst($day);
            } else {
                 $logDetails .= "  - Details: $shift_log, $start_log, $duration_log mins";
            }

            logAction("Submitted Single Request", $logDetails, $patient_name, $action_id);
            
            // This old logging can now be removed if desired
            $log_stmt = $conn->prepare("INSERT INTO patient_logs (patient_id, action) VALUES (?, ?)");
            $action_desc = "Submitted single schedule request for $day";
            $log_stmt->bind_param("is", $patient_id, $action_desc);
            $log_stmt->execute();
            
            respond("success", "Single request sent.");
        } else {
            respond("error", "Insert failed: " . $stmt->error);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
    exit;
}
?>