<?php
header('Content-Type: application/json');
require_once '../config/db.php';
require_once __DIR__ . '/log_action.php'; // MOVED: Include moved to top for consistency
date_default_timezone_set('Asia/Manila');

// NEW: Function to generate a unique action ID
function generateActionId()
{
    return 'PPKC-' . mt_rand(1000000000, 9999999999);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$patient_id = $_POST['patient_id'] ?? null;
$unregistered_id = $_POST['unregistered_id'] ?? null;

$schedule_id = null;
// Determine schedule ID
if (!empty($patient_id) && $patient_id !== 'null') {
    $stmt = $pdo->prepare("SELECT id FROM schedules WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $schedule_id = $stmt->fetchColumn();
} elseif (!empty($unregistered_id)) {
    $stmt = $pdo->prepare("SELECT schedule_id FROM unregistered_patients WHERE id = ?");
    $stmt->execute([$unregistered_id]);
    $schedule_id = $stmt->fetchColumn();
}

if (!$schedule_id) {
    echo json_encode(['status' => 'error', 'message' => "Schedule not found."]);
    exit;
}

$days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
$schedule = [];

// Use isset to avoid undefined index warnings
foreach ($days as $day) {
    $schedule[$day . '_shift'] = isset($_POST[$day . '_shift']) ? $_POST[$day . '_shift'] : null;
    $schedule[$day . '_start_time'] = isset($_POST[$day . '_start_time']) ? $_POST[$day . '_start_time'] : null;
    // force duration into integer minutes (0 if blank)
    $schedule[$day . '_duration'] = isset($_POST[$day . '_duration']) && $_POST[$day . '_duration'] !== ''
        ? (int)$_POST[$day . '_duration']
        : 0;
}

// Normalize empty or cleared fields to actual NULLs
foreach ($days as $day) {
    if (
        empty($schedule[$day . '_shift']) &&
        (empty($schedule[$day . '_start_time']) || $schedule[$day . '_start_time'] === '00:00:00') &&
        $schedule[$day . '_duration'] === 0
    ) {

        $schedule[$day . '_shift'] = null;
        $schedule[$day . '_start_time'] = null;
        $schedule[$day . '_duration'] = null;
    }
}

// Check if schedule has any non-empty data
$hasSchedule = false;
foreach ($days as $day) {
    if ($schedule[$day . '_shift'] || $schedule[$day . '_start_time'] || $schedule[$day . '_duration']) {
        $hasSchedule = true;
        break;
    }
}
$status = $hasSchedule ? 'Scheduled' : 'Pending';

// Enforce duration limits based on shift and start time
$shiftEndLimits = [
    'First Shift' => '10:00',
    'Second Shift' => '15:00'
];

foreach ($days as $day) {
    $shift = $schedule["{$day}_shift"];
    $startTime = $schedule["{$day}_start_time"];
    $duration = $schedule["{$day}_duration"];
    if ($shift && $startTime && $duration && isset($shiftEndLimits[$shift])) {
        $startMinutes = (int)date('H', strtotime($startTime)) * 60 + (int)date('i', strtotime($startTime));
        list($limitH, $limitM) = explode(':', $shiftEndLimits[$shift]);
        $endLimitMinutes = ((int)$limitH) * 60 + ((int)$limitM);
        $maxAllowed = $endLimitMinutes - $startMinutes;

        if ($maxAllowed <= 0) {
            echo json_encode(['status' => 'error', 'message' => ucfirst($day) . " has an invalid start time for $shift."]);
            exit;
        }

        if ($duration > $maxAllowed) {
            echo json_encode([
                'status' => 'error',
                'message' => ucfirst($day) . " duration too long for $shift. Max allowed minutes: $maxAllowed."
            ]);
            exit;
        }
    }
}

// Fetch existing schedule data
$stmt = $pdo->prepare("SELECT * FROM schedules WHERE id = ?");
$stmt->execute([$schedule_id]);
$existingSchedule = $stmt->fetch(PDO::FETCH_ASSOC);
$existingStatus = $existingSchedule['status'] ?? '';

// Compare changes (normalize start_time to H:i format)
$isChanged = false;
foreach ($days as $day) {
    $newShift = $schedule[$day . '_shift'];
    $newStart = $schedule[$day . '_start_time'];
    $newDuration = $schedule[$day . '_duration'];

    $oldShift = $existingSchedule[$day . '_shift'] ?? null;
    $oldStart = $existingSchedule[$day . '_start_time'] ?? null;
    $oldDuration = $existingSchedule[$day . '_duration'] ?? null;

    if ($newStart) $newStart = date('H:i', strtotime($newStart));
    if ($oldStart) $oldStart = date('H:i', strtotime($oldStart));

    if ($newShift !== $oldShift || $newStart !== $oldStart || $newDuration != $oldDuration) {
        $isChanged = true;
        break;
    }
}

// Block update if no changes AND status is Scheduled or Pending
if (!$isChanged && in_array($existingStatus, ['Scheduled', 'Pending'])) {
    echo json_encode(['status' => 'warning', 'message' => "No changes detected. Nothing was updated."]);
    exit;
}

// Validate shift limits
foreach ($days as $day) {
    $shift = $schedule[$day . '_shift'];
    if ($shift) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM schedules WHERE {$day}_shift = :shift AND id != :id");
        $stmt->execute(['shift' => $shift, 'id' => $schedule_id]);
        $count = $stmt->fetchColumn();

        if ($count >= 14) {
            echo json_encode(['status' => 'error', 'message' => "The shift is full for " . ucfirst($day) . "."]);
            exit;
        }
    }
}

// Build and execute update
$sql = "UPDATE schedules SET 
    monday_shift = :monday_shift, monday_start_time = :monday_start_time, monday_duration = :monday_duration,
    tuesday_shift = :tuesday_shift, tuesday_start_time = :tuesday_start_time, tuesday_duration = :tuesday_duration,
    wednesday_shift = :wednesday_shift, wednesday_start_time = :wednesday_start_time, wednesday_duration = :wednesday_duration,
    thursday_shift = :thursday_shift, thursday_start_time = :thursday_start_time, thursday_duration = :thursday_duration,
    friday_shift = :friday_shift, friday_start_time = :friday_start_time, friday_duration = :friday_duration,
    saturday_shift = :saturday_shift, saturday_start_time = :saturday_start_time, saturday_duration = :saturday_duration,
    updated_at = NOW(), status = :status
    WHERE id = :schedule_id";

$stmt = $pdo->prepare($sql);

$params = array_merge(
    [':schedule_id' => $schedule_id],
    array_combine(
        array_map(fn ($k) => ":$k", array_keys($schedule)),
        array_values($schedule)
    )
);
$params[':status'] = $status;

if ($stmt->execute($params)) {
    $affectedRows = $stmt->rowCount();
    $responseMsg = $affectedRows ? "Schedule updated successfully!" : "No changes made.";

    $name = 'Unknown';
    if (!empty($patient_id) && $patient_id !== 'null') {
        $stmt = $pdo->prepare("SELECT full_name FROM patients WHERE id = ?");
        $stmt->execute([$patient_id]);
        $name = $stmt->fetchColumn() ?? 'Unknown';
    } elseif (!empty($unregistered_id)) {
        $stmt = $pdo->prepare("SELECT full_name FROM unregistered_patients WHERE id = ?");
        $stmt->execute([$unregistered_id]);
        $name = $stmt->fetchColumn() ?? 'Unknown';
    }

    $logDetails = "Schedule updated for Schedule ID: $schedule_id ($name)\n";
    $changes = [];

    foreach ($days as $day) {
        $fields = ['shift', 'start_time', 'duration'];
        foreach ($fields as $field) {
            $key = $day . '_' . $field;
            $old_val = $existingSchedule[$key] ?? null;
            $new_val = $schedule[$key] ?? null;

            if ($field === 'start_time') {
                $old_val = $old_val ? date('g:i A', strtotime($old_val)) : 'None';
                $new_val = $new_val ? date('g:i A', strtotime($new_val)) : 'None';
            } else {
                $old_val = $old_val ?: 'None';
                $new_val = $new_val ?: 'None';
            }

            if ($old_val !== $new_val) {
                $changes[] = "$key: $old_val -> $new_val";
            }
        }
    }

    if (!empty($changes)) {
        $logDetails .= implode("\n", $changes);
        $who = $_SESSION['full_name'] ?? 'Admin'; // Default to 'Admin' if session not set

        // MODIFIED: Generate and pass the new action_id to the logger
        $new_action_id = generateActionId();
        logAction('Update Schedule', $logDetails, $who, $new_action_id);
    }

    // START: MODIFIED NOTIFICATION LOGIC
    if ($isChanged && !empty($patient_id) && $patient_id !== 'null') {
        $modified_days = [];
        foreach ($days as $day) {
            // Get new values from the submitted form data
            $newShift = $schedule[$day . '_shift'];
            $newStart = $schedule[$day . '_start_time'];
            $newDuration = $schedule[$day . '_duration'];

            // Get old values from the schedule we fetched earlier
            $oldShift = $existingSchedule[$day . '_shift'] ?? null;
            $oldStart = $existingSchedule[$day . '_start_time'] ?? null;
            $oldDuration = $existingSchedule[$day . '_duration'] ?? null;

            // Normalize values for a reliable comparison
            // Time: format to HH:MM to ignore seconds
            $newStartNormalized = $newStart ? date('H:i', strtotime($newStart)) : null;
            $oldStartNormalized = $oldStart ? date('H:i', strtotime($oldStart)) : null;
            
            // Duration: treat null and 0 as the same (no duration)
            $newDurationNormalized = (int) $newDuration;
            $oldDurationNormalized = (int) $oldDuration;

            // Check if this specific day has any change in its shift, start time, or duration
            if ($newShift !== $oldShift || $newStartNormalized !== $oldStartNormalized || $newDurationNormalized !== $oldDurationNormalized) {
                $modified_days[] = ucfirst($day);
            }
        }

        // If any days were actually modified, build the message and send the notification
        if (!empty($modified_days)) {
            $notif_message = "Your schedule for " . implode(', ', $modified_days) . " has been updated.";
            $notifStmt = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
            $notifStmt->execute([$patient_id, $notif_message]);
        }
    }
    // END: MODIFIED NOTIFICATION LOGIC

    echo json_encode(['status' => 'success', 'message' => $responseMsg]);
    exit;
} else {
    echo json_encode(['status' => 'error', 'message' => "There was an error updating the schedule."]);
    exit;
}
?>