<?php
date_default_timezone_set('Asia/Manila');
include_once __DIR__ . '/../config/db.php';

function logAction($action_type, $action_details, $performed_by, $action_id = null) {
    global $pdo;

    // Generate a unique Action ID if one isn't provided
    if ($action_id === null) {
        $action_id = 'PPKC-' . str_pad(mt_rand(1, 9999999999), 10, '0', STR_PAD_LEFT);
    }
    
    $target_table = 'schedule_logs'; // default table
    if (strpos($action_type, 'Create ') === 0 || in_array($action_type, ['Login', 'Logout', 'Edit User', 'Delete User', 'Archive User', 'Restore User', 'Register Unregistered', 'Edit Unregistered', 'Delete Unregistered', 'Delete Archive'])) {
        $target_table = 'account_logs';
    }

    $sql = "INSERT INTO $target_table (action_id, action_type, action_details, performed_by) VALUES (?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$action_id, $action_type, $action_details, $performed_by]);
    
    return $action_id;
}
?>