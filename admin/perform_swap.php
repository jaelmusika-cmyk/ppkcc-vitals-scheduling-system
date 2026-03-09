<?php
header('Content-Type: application/json');
session_start();
require_once '../config/db.php';
require_once __DIR__ . '/log_action.php';
date_default_timezone_set('Asia/Manila');

function generateActionId() {
    return 'PPKC-' . mt_rand(1000000000, 9999999999);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit;
}

$p1_id = $_POST['patient1_id'] ?? null;
$p1_day = $_POST['patient1_day'] ?? null;
$p2_id = $_POST['patient2_id'] ?? null;
$p2_day = $_POST['patient2_day'] ?? null;

if (!$p1_id || !$p1_day || !$p2_id || !$p2_day) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required swap information.']);
    exit;
}
if ($p1_id == $p2_id && $p1_day == $p2_day) {
    echo json_encode(['status' => 'error', 'message' => 'Cannot swap a schedule with itself.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get schedule 1 info
    $stmt1 = $pdo->prepare("SELECT `{$p1_day}_shift` as shift, `{$p1_day}_start_time` as start, `{$p1_day}_duration` as duration FROM schedules WHERE patient_id = ?");
    $stmt1->execute([$p1_id]);
    $s1 = $stmt1->fetch(PDO::FETCH_ASSOC);

    // Get schedule 2 info
    $stmt2 = $pdo->prepare("SELECT `{$p2_day}_shift` as shift, `{$p2_day}_start_time` as start, `{$p2_day}_duration` as duration FROM schedules WHERE patient_id = ?");
    $stmt2->execute([$p2_id]);
    $s2 = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    // --- NEW: Get Collision Info (Data that will be overwritten) ---
    // Check what is currently on P1's schedule for the day P2 is moving to (p2_day)
    $stmtCol1 = $pdo->prepare("SELECT `{$p2_day}_shift` as shift, `{$p2_day}_start_time` as start, `{$p2_day}_duration` as duration FROM schedules WHERE patient_id = ?");
    $stmtCol1->execute([$p1_id]);
    $c1 = $stmtCol1->fetch(PDO::FETCH_ASSOC);

    // Check what is currently on P2's schedule for the day P1 is moving to (p1_day)
    $stmtCol2 = $pdo->prepare("SELECT `{$p1_day}_shift` as shift, `{$p1_day}_start_time` as start, `{$p1_day}_duration` as duration FROM schedules WHERE patient_id = ?");
    $stmtCol2->execute([$p2_id]);
    $c2 = $stmtCol2->fetch(PDO::FETCH_ASSOC);
    // ---------------------------------------------------------------
    
    // Get patient names for logging
    $nameStmt = $pdo->prepare("SELECT full_name FROM patients WHERE id = ?");
    $nameStmt->execute([$p1_id]);
    $p1_name = $nameStmt->fetchColumn();
    $nameStmt->execute([$p2_id]);
    $p2_name = $nameStmt->fetchColumn();

    if (!$s1 || !$s2) {
        throw new Exception("Could not retrieve schedule data for one or both patients.");
    }

    // Update Patient 1: Clear their old day and give them Patient 2's schedule on Patient 2's day
$update1 = $pdo->prepare("
    UPDATE schedules 
    SET 
        `{$p1_day}_shift` = NULL, 
        `{$p1_day}_start_time` = NULL, 
        `{$p1_day}_duration` = NULL,
        `{$p2_day}_shift` = ?, 
        `{$p2_day}_start_time` = ?, 
        `{$p2_day}_duration` = ?
    WHERE patient_id = ?
");
$update1->execute([$s2['shift'], $s2['start'], $s2['duration'], $p1_id]);

// Update Patient 2: Clear their old day and give them Patient 1's schedule on Patient 1's day
$update2 = $pdo->prepare("
    UPDATE schedules 
    SET 
        `{$p2_day}_shift` = NULL, 
        `{$p2_day}_start_time` = NULL, 
        `{$p2_day}_duration` = NULL,
        `{$p1_day}_shift` = ?, 
        `{$p1_day}_start_time` = ?, 
        `{$p1_day}_duration` = ?
    WHERE patient_id = ?
");
$update2->execute([$s1['shift'], $s1['start'], $s1['duration'], $p2_id]);

    // Log the swap (Updated to include collision data)
    $logSwap = $pdo->prepare("
        INSERT INTO schedule_swaps (
            patient1_id, patient1_day, patient1_original_shift, patient1_original_start_time, patient1_original_duration, 
            patient2_id, patient2_day, patient2_original_shift, patient2_original_start_time, patient2_original_duration,
            patient1_collision_shift, patient1_collision_start_time, patient1_collision_duration,
            patient2_collision_shift, patient2_collision_start_time, patient2_collision_duration
        ) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $logSwap->execute([
        $p1_id, $p1_day, $s1['shift'], $s1['start'], $s1['duration'], 
        $p2_id, $p2_day, $s2['shift'], $s2['start'], $s2['duration'],
        $c1['shift'], $c1['start'], $c1['duration'], // P1 Collision Data
        $c2['shift'], $c2['start'], $c2['duration']  // P2 Collision Data
    ]);

    // Log to main audit log
    $who = $_SESSION['full_name'] ?? 'Admin';
    $action_id = generateActionId();
    $details = "Schedule swapped:\n" .
               "- Patient 1: $p1_name (ID: $p1_id) - " . ucfirst($p1_day) . " (" . ($s1['shift'] ?? 'N/A') . ")\n" .
               "  swapped with\n" .
               "- Patient 2: $p2_name (ID: $p2_id) - " . ucfirst($p2_day) . " (" . ($s2['shift'] ?? 'N/A') . ")";
    logAction('Swap Schedule', $details, $who, $action_id);
    
        // START: ADD NOTIFICATION LOGIC
$notifStmt = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
// Notif for Patient 1
$msg1 = "Your " . ucfirst($p1_day) . " schedule has been swapped with " . $p2_name . "'s " . ucfirst($p2_day) . " schedule.";
$notifStmt->execute([$p1_id, $msg1]);
// Notif for Patient 2
$msg2 = "Your " . ucfirst($p2_day) . " schedule has been swapped with " . $p1_name . "'s " . ucfirst($p1_day) . " schedule.";
$notifStmt->execute([$p2_id, $msg2]);
// END: ADD NOTIFICATION LOGIC

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Schedules swapped successfully!']);
    

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Swap failed: ' . $e->getMessage()]);
}
?>