<?php
date_default_timezone_set('Asia/Manila');
include_once __DIR__ . '/../config/db.php';

function logAccountAction($action_type, $action_details, $performed_by) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO account_logs (action_type, action_details, performed_by) VALUES (?, ?, ?)");
    $stmt->execute([$action_type, $action_details, $performed_by]);
}
