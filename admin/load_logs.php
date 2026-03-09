<?php
session_start();
date_default_timezone_set('Asia/Manila');
include('../config/db.php');

$limit = isset($_GET['limit']) && $_GET['limit'] !== 'all' ? intval($_GET['limit']) : PHP_INT_MAX;

if ($limit === PHP_INT_MAX) {
    $stmt = $pdo->prepare("SELECT * FROM schedule_logs ORDER BY timestamp DESC");
} else {
    $stmt = $pdo->prepare("SELECT * FROM schedule_logs ORDER BY timestamp DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
}

$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($logs as $i => $log) {
    echo "<tr>
        <td>" . ($i + 1) . "</td>
        <td class='log-type'>" . htmlspecialchars($log['action_type']) . "</td>
        <td>" . nl2br(htmlspecialchars($log['action_details'])) . "</td>
        <td>" . htmlspecialchars($log['performed_by']) . "</td>
        <td class='timestamp'>" . date("Y-m-d h:i:s A", strtotime($log['timestamp'])) . "</td>
    </tr>";
}
?>
