<?php
session_start();
require_once '../config/db.php';
require_once 'log_action.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

$action = $_POST['action'] ?? null;
$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['full_name'];

try {
    if ($action === 'create_announcement') {
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';

        if (empty($title) || empty($message)) {
            throw new Exception('Title and message cannot be empty.');
        }

        $stmt = $pdo->prepare("INSERT INTO announcements (title, message, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$title, $message, $admin_id]);
        
        logAction('Create Announcement', "Admin created announcement: '$title'", $admin_name);

        echo json_encode(['success' => true]);

    } elseif ($action === 'delete_announcement') {
        $id = $_POST['announcement_id'] ?? null;
        if (!$id) {
            throw new Exception('Announcement ID is required.');
        }

        $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
        $stmt->execute([$id]);

        logAction('Delete Announcement', "Admin deleted announcement ID: $id", $admin_name);

        echo json_encode(['success' => true]);

    } else {
        throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}