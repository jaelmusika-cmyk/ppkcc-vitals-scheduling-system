<?php
// ✅ CRON: Expire old pending requests
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../admin/log_action.php';

date_default_timezone_set('Asia/Manila');

function generateActionId() {
    return 'PPKC-' . mt_rand(1000000000, 9999999999);
}

// Find requests that need to be expired
$stmt = $conn->prepare("
    SELECT r.id, r.day, r.shift, p.full_name 
    FROM schedule_requests r
    JOIN patients p ON r.patient_id = p.id
    WHERE r.status = 'Pending' AND r.expires_at IS NOT NULL AND r.expires_at < NOW()
");
$stmt->execute();
$requests_to_expire = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (count($requests_to_expire) > 0) {
    $ids_to_update = [];
    foreach ($requests_to_expire as $req) {
        $ids_to_update[] = $req['id'];
        $logDetails = "Request for " . $req['full_name'] . " (" . ucfirst($req['day']) . " - " . $req['shift'] . ") has expired.";
        $action_id = generateActionId();
        // Log the expiration action
        logAction('Expired Schedule Request', $logDetails, 'System', $action_id);
    }
    
    // Update all expired requests in one query
    $id_list = implode(',', $ids_to_update);
    $conn->query("UPDATE schedule_requests SET status = 'Expired' WHERE id IN ($id_list)");
}

echo "Expired request check completed. " . count($requests_to_expire) . " requests expired.";
?>