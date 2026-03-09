<?php
session_start();
require_once '../config/db.php';
require_once 'log_action.php'; // Assuming you have this for logging

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$action = $_POST['action'] ?? null;
$admin_name = $_SESSION['full_name'];

try {
    if ($action === 'update_no_show_fee') {
        $new_fee = $_POST['no_show_fee'] ?? null;

        if ($new_fee === null || !is_numeric($new_fee) || $new_fee < 0) {
            throw new Exception('Invalid fee amount provided.');
        }

        // Use INSERT...ON DUPLICATE KEY UPDATE to create or update the setting
        $stmt = $pdo->prepare(
            "INSERT INTO app_settings (setting_key, setting_value) VALUES ('no_show_fee', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $stmt->execute([$new_fee, $new_fee]);

        logAction('Settings Update', "Updated No-Show Fee to: " . number_format($new_fee, 2), $admin_name);

        echo json_encode(['success' => true, 'message' => 'Fee updated successfully!']);
    } else {
        throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>