<?php
session_start();
// file_put_contents('debug_post.txt', print_r($_POST, true)); // Keep for debugging if needed

include_once '../config/db.php';
require_once __DIR__ . '/log_action.php';

date_default_timezone_set('Asia/Manila');
$pdo->exec("SET time_zone = '+08:00'");

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['status' => 'error', 'message' => 'Invalid action.'];
$performed_by = $_SESSION['full_name'] ?? 'System';

try {
    switch ($action) {
        case 'create_user':
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $phone = trim($_POST['phone_number'] ?? '');
            $role = $_POST['role'] ?? '';

            if (!$full_name || !$email || !$password || !$confirm || !$phone || !$role) {
                throw new Exception("All fields are required.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format.");
            }
            if ($password !== $confirm) {
                throw new Exception("Passwords do not match.");
            }
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters.");
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception("Email already exists.");
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, role, phone_number, created_at, status) VALUES (?, ?, ?, ?, ?, NOW(), 'active')");
            $stmt->execute([$full_name, $email, $hash, $role, $phone]);
            $userId = $pdo->lastInsertId();

            if ($role === 'patient') {
                $stmt = $pdo->prepare("INSERT INTO patients (user_id, full_name, birth_date, gender, phone_number, created_at) VALUES (?, ?, '1970-01-01', 'Male', ?, NOW())");
                $stmt->execute([$userId, $full_name, $phone]);
                $patientId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO schedules (patient_id, status, updated_at) VALUES (?, 'Pending', NOW())");
                $stmt->execute([$patientId]);
            }

            logAction("Create $role", "Created $role account: $full_name ($email)", $performed_by);
            $response = ['status' => 'success', 'message' => ucfirst($role) . " account created successfully."];
            break;

        case 'get_user':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) throw new Exception("User ID is required.");
            $stmt = $pdo->prepare("SELECT id, full_name, email, phone_number, role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new Exception("User not found.");
            $response = ['status' => 'success', 'data' => $user];
            break;

        case 'edit_user':
            $id = intval($_POST['user_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if (!$id || !$full_name || !$email) {
                throw new Exception("User ID, full name, and email are required.");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format.");
            }
            if ($password && $password !== $confirm) {
                throw new Exception("Passwords do not match.");
            }
            if ($password && strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters.");
            }

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetch()) {
                throw new Exception("Email already exists.");
            }

            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone_number=?, password=? WHERE id=?");
                $stmt->execute([$full_name, $email, $phone, $hash, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, phone_number=? WHERE id=?");
                $stmt->execute([$full_name, $email, $phone, $id]);
            }

            logAction("Edit User", "Edited user ID $id: $full_name ($email)", $performed_by);
            $response = ['status' => 'success', 'message' => "User updated successfully."];
            break;

        case 'delete_user':
            $id = intval($_POST['user_id'] ?? 0);
            if (!$id) throw new Exception("User ID required.");
            if ($id === 1) throw new Exception("Cannot delete Super Admin.");
            
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$userToDelete) throw new Exception("User not found.");

            if ($userToDelete['role'] === 'patient') {
                $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ?");
                $stmt->execute([$id]);
                $patientId = $stmt->fetchColumn();
                if ($patientId) {
                    $stmt = $pdo->prepare("DELETE FROM schedules WHERE patient_id = ?");
                    $stmt->execute([$patientId]);
                    $stmt = $pdo->prepare("DELETE FROM patients WHERE id = ?");
                    $stmt->execute([$patientId]);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            logAction("Delete User", "Permanently deleted user ID $id", $performed_by);
            $response = ['status' => 'success', 'message' => "User permanently deleted."];
            break;
            
        case 'delete_archived_user':
            // This action is identical to delete_user, just for a different button
            $id = intval($_POST['user_id'] ?? 0);
            if (!$id) throw new Exception("User ID required.");
            if ($id === 1) throw new Exception("Cannot delete Super Admin.");
            
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $userToDelete = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$userToDelete) throw new Exception("User not found.");

            if ($userToDelete['role'] === 'patient') {
                $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ?");
                $stmt->execute([$id]);
                $patientId = $stmt->fetchColumn();
                if ($patientId) {
                    $stmt = $pdo->prepare("DELETE FROM schedules WHERE patient_id = ?");
                    $stmt->execute([$patientId]);
                    $stmt = $pdo->prepare("DELETE FROM patients WHERE id = ?");
                    $stmt->execute([$patientId]);
                }
            }

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            logAction("Delete User", "Permanently deleted user ID $id", $performed_by);
            $response = ['status' => 'success', 'message' => "User permanently deleted."];
            break;

        case 'archive_user':
            $id = intval($_POST['user_id'] ?? 0);
            if (!$id) throw new Exception("User ID required.");
            if ($id === 1) throw new Exception("Cannot archive Super Admin.");
            $stmt = $pdo->prepare("UPDATE users SET status = 'archived' WHERE id = ?");
            $stmt->execute([$id]);
            logAction("Archive User", "Archived user ID $id", $performed_by);
            $response = ['status' => 'success', 'message' => "User archived."];
            break;

        case 'restore_user':
            $id = intval($_POST['user_id'] ?? 0);
            if (!$id) throw new Exception("User ID required.");
            $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            logAction("Restore User", "Restored user ID $id", $performed_by);
            $response = ['status' => 'success', 'message' => "User restored."];
            break;

        case 'add_unregistered':
            $full_name = trim($_POST['full_name'] ?? '');
            if (!$full_name) throw new Exception("Full name is required.");

            $stmt = $pdo->prepare("INSERT INTO schedules (patient_id, status, updated_at) VALUES (NULL, 'Pending', NOW())");
            $stmt->execute();
            $scheduleId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO unregistered_patients (full_name, schedule_id, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$full_name, $scheduleId]);
            logAction("Add Unregistered", "Added unregistered patient: $full_name", $performed_by);
            $response = ['status' => 'success', 'message' => "Unregistered patient added."];
            break;

        case 'get_unregistered':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) throw new Exception("Unregistered patient ID required.");
            $stmt = $pdo->prepare("SELECT * FROM unregistered_patients WHERE id = ?");
            $stmt->execute([$id]);
            $unreg = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$unreg) throw new Exception("Unregistered patient not found.");
            $response = ['status' => 'success', 'data' => $unreg];
            break;

        case 'edit_unregistered':
            $id = intval($_POST['unregistered_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            if (!$id || !$full_name) throw new Exception("ID and full name are required.");
            $stmt = $pdo->prepare("UPDATE unregistered_patients SET full_name = ? WHERE id = ?");
            $stmt->execute([$full_name, $id]);
            logAction("Edit Unregistered", "Edited unregistered patient ID $id: $full_name", $performed_by);
            $response = ['status' => 'success', 'message' => "Unregistered patient updated."];
            break;

        case 'delete_unregistered':
            $id = intval($_POST['unregistered_id'] ?? 0);
            if (!$id) throw new Exception("Unregistered patient ID required.");
            $stmt = $pdo->prepare("SELECT schedule_id FROM unregistered_patients WHERE id = ?");
            $stmt->execute([$id]);
            $schedId = $stmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM unregistered_patients WHERE id = ?");
            $stmt->execute([$id]);
            if ($schedId) {
                $stmt = $pdo->prepare("DELETE FROM schedules WHERE id = ? AND patient_id IS NULL");
                $stmt->execute([$schedId]);
            }
            logAction("Delete Unregistered", "Deleted unregistered patient ID $id", $performed_by);
            $response = ['status' => 'success', 'message' => "Unregistered patient deleted."];
            break;

        case 'generate_reg_code':
            $id = intval($_POST['unregistered_id'] ?? 0);
            if (!$id) throw new Exception("Unregistered patient ID required.");
            
            // Generate a unique, readable code
            $code = '';
            do {
                $bytes = random_bytes(3); // 3 bytes = 6 hex chars
                $raw_code = strtoupper(bin2hex($bytes));
                $code = substr($raw_code, 0, 3) . '-' . substr($raw_code, 3, 3);
                $stmt = $pdo->prepare("SELECT id FROM unregistered_patients WHERE registration_code = ?");
                $stmt->execute([$code]);
            } while ($stmt->fetch());

            $expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmt = $pdo->prepare("UPDATE unregistered_patients SET registration_code = ?, code_expiry = ?, status = 'available' WHERE id = ?");
            $stmt->execute([$code, $expiry, $id]);

            logAction("Generate Code", "Generated registration code for unregistered patient ID $id", $performed_by);
            $response = ['status' => 'success', 'message' => "Code generated successfully.", 'code' => $code, 'expiry' => $expiry];
            break;

        default:
            throw new Exception("Unknown action: " . htmlspecialchars($action));
    }
} catch (Exception $e) {
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

echo json_encode($response);
exit;