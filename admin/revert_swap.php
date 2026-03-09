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

$swap_id = $_POST['swap_id'] ?? null;

if (!$swap_id) {
    echo json_encode(['status' => 'error', 'message' => 'Swap ID is missing.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get swap details
    $stmt = $pdo->prepare("SELECT * FROM schedule_swaps WHERE id = ? AND status = 'active'");
    $stmt->execute([$swap_id]);
    $swap = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$swap) {
        throw new Exception("Active swap with the given ID not found.");
    }
    
    $p1_id = $swap['patient1_id'];
    $p1_day = $swap['patient1_day'];
    $p2_id = $swap['patient2_id'];
    $p2_day = $swap['patient2_day'];

// Restore Patient 1: Restore collision data to p2_day, and original schedule to p1_day
$restore1 = $pdo->prepare("
    UPDATE schedules 
    SET 
        `{$p2_day}_shift` = ?, 
        `{$p2_day}_start_time` = ?, 
        `{$p2_day}_duration` = ?,
        `{$p1_day}_shift` = ?, 
        `{$p1_day}_start_time` = ?, 
        `{$p1_day}_duration` = ?
    WHERE patient_id = ?
");
$restore1->execute([
    $swap['patient1_collision_shift'], $swap['patient1_collision_start_time'], $swap['patient1_collision_duration'], // Restore what was overwritten
    $swap['patient1_original_shift'], $swap['patient1_original_start_time'], $swap['patient1_original_duration'],   // Restore original day
    $p1_id
]);

// Restore Patient 2: Restore collision data to p1_day, and original schedule to p2_day
$restore2 = $pdo->prepare("
    UPDATE schedules 
    SET 
        `{$p1_day}_shift` = ?, 
        `{$p1_day}_start_time` = ?, 
        `{$p1_day}_duration` = ?,
        `{$p2_day}_shift` = ?, 
        `{$p2_day}_start_time` = ?, 
        `{$p2_day}_duration` = ?
    WHERE patient_id = ?
");
$restore2->execute([
    $swap['patient2_collision_shift'], $swap['patient2_collision_start_time'], $swap['patient2_collision_duration'], // Restore what was overwritten
    $swap['patient2_original_shift'], $swap['patient2_original_start_time'], $swap['patient2_original_duration'],   // Restore original day
    $p2_id
]);

    // Update swap status to 'reverted'
    $updateSwap = $pdo->prepare("UPDATE schedule_swaps SET status = 'reverted', reverted_at = NOW() WHERE id = ?");
    $updateSwap->execute([$swap_id]);
    
    // Log to main audit log
    $nameStmt = $pdo->prepare("SELECT full_name FROM patients WHERE id = ?");
    $nameStmt->execute([$p1_id]);
    $p1_name = $nameStmt->fetchColumn();
    $nameStmt->execute([$p2_id]);
    $p2_name = $nameStmt->fetchColumn();

    $who = $_SESSION['full_name'] ?? 'Admin';
    $action_id = generateActionId();
    $details = "Reverted schedule swap (Swap ID: $swap_id):\n" .
               "- Patient 1: $p1_name (ID: $p1_id) - " . ucfirst($p1_day) . "\n" .
               "- Patient 2: $p2_name (ID: $p2_id) - " . ucfirst($p2_day);
    logAction('Revert Swap', $details, $who, $action_id);
    
    // START: ADD NOTIFICATION LOGIC
$notifStmt = $pdo->prepare("INSERT INTO notifications (patient_id, message) VALUES (?, ?)");
$notif_message = "Your schedule swap involving " . ucfirst($p1_day) . " and " . ucfirst($p2_day) . " has been reverted.";
$notifStmt->execute([$p1_id, $notif_message]);
$notifStmt->execute([$p2_id, $notif_message]);
// END: ADD NOTIFICATION LOGIC

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Swap reverted successfully!']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Revert failed: ' . $e->getMessage()]);
}
?>