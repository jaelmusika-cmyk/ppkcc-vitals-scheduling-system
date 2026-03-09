<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');

include('../config/db.php');
require_once '../includes/auth_check.php';
if (!isLoggedIn() || !checkRole('nurse')) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? null;
$nurse_user_id = $_SESSION['user_id'];
$nurse_full_name = $_SESSION['full_name'];

header('Content-Type: application/json');
try {
    switch ($action) {
        case 'get_scheduled_patients':
            $date = $_GET['date'] ??
date('Y-m-d');
            $shift = $_GET['shift'] ?? null;
            if (!$shift) throw new Exception("Shift is required.");

            $day_of_week = strtolower(date('l', strtotime($date)));
if (!in_array($day_of_week, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])) {
                echo json_encode(['status' => 'success', 'patients' => []]);
exit;
            }

            $day_column = "{$day_of_week}_shift";
// *** FIX: Fetches BOTH registered and unregistered patients. ***
            $stmt = $pdo->prepare("
                (SELECT p.id, p.full_name, 'registered' as patient_type
                 FROM schedules s
                 JOIN patients p ON s.patient_id = p.id
              
   JOIN users u ON p.user_id = u.id
                 WHERE s.deleted = 0 AND s.status = 'Scheduled' AND u.status = 'active' AND s.{$day_column} = :shift)
                UNION ALL
                (SELECT up.id, up.full_name, 'unregistered' as patient_type
                 FROM schedules s
  
               JOIN unregistered_patients up ON s.id = up.schedule_id
                 WHERE s.deleted = 0 AND s.status = 'Scheduled' AND s.{$day_column} = :shift)
                ORDER BY full_name ASC
            ");
$stmt->execute(['shift' => $shift]);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'patients' => $patients]);
            break;
case 'get_flowsheet_details_for_edit':
            $flowsheet_id = $_GET['flowsheet_id'] ?? null;
if (!$flowsheet_id) throw new Exception("Flowsheet ID is required.");

            $fsStmt = $pdo->prepare("SELECT flowsheet_date, shift FROM flowsheets WHERE id = :id");
$fsStmt->execute(['id' => $flowsheet_id]);
            $fsInfo = $fsStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fsInfo) throw new Exception("Flowsheet not found.");

            $date = $fsInfo['flowsheet_date'];
            $shift = $fsInfo['shift'];
$day_of_week = strtolower(date('l', strtotime($date)));
            $day_column = "{$day_of_week}_shift";
            
            $scheduledStmt = $pdo->prepare("
                (SELECT p.id as patient_id, p.full_name as patient_name, 'registered' as patient_type FROM schedules s JOIN patients p ON s.patient_id = p.id JOIN users u ON p.user_id = u.id WHERE s.deleted = 0 AND s.status = 'Scheduled' AND u.status = 'active' AND s.{$day_column} = :shift)
                UNION ALL
                
(SELECT up.id as patient_id, up.full_name as patient_name, 'unregistered' as patient_type FROM schedules s JOIN unregistered_patients up ON s.id = up.schedule_id WHERE s.deleted = 0 AND s.status = 'Scheduled' AND s.{$day_column} = :shift)
                ORDER BY patient_name ASC
            ");
$scheduledStmt->execute(['shift' => $shift]);
            $scheduled_patients = $scheduledStmt->fetchAll(PDO::FETCH_ASSOC);

            $entriesStmt = $pdo->prepare("SELECT ve.*, ve.id as entry_id, nic.id AS nurse_in_charge_id FROM vitals_entries ve LEFT JOIN users nic ON ve.nurse_in_charge_id = nic.id WHERE ve.flowsheet_id = :flowsheet_id");
$entriesStmt->execute(['flowsheet_id' => $flowsheet_id]);
            $existing_entries_raw = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $existing_entries = [];
            foreach ($existing_entries_raw as $entry) {
                if (!empty($entry['patient_id'])) {
                    $existing_entries['registered_' .
$entry['patient_id']] = $entry;
                } elseif (!empty($entry['unregistered_patient_id'])) {
                    $existing_entries['unregistered_' .
$entry['unregistered_patient_id']] = $entry;
                }
            }

            $combined_data = [];
foreach ($scheduled_patients as $patient) {
                $key = $patient['patient_type'] .
'_' . $patient['patient_id'];
                if (isset($existing_entries[$key])) {
                    $combined_data[] = $patient + $existing_entries[$key];
} else {
                    $combined_data[] = $patient;
}
            }
            echo json_encode(['status' => 'success', 'patients' => $combined_data]);
break;

        case 'save_flowsheet':
            $pdo->beginTransaction();
$feeStmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'no_show_fee' LIMIT 1");
            $no_show_fee = $feeStmt ? $feeStmt->fetchColumn() : 1000.00;
$flowsheet_date = $_POST['flowsheet_date'];
            $shift = $_POST['shift'];

            $checkStmt = $pdo->prepare("SELECT id FROM flowsheets WHERE flowsheet_date = :date AND shift = :shift");
$checkStmt->execute(['date' => $flowsheet_date, 'shift' => $shift]);
            if ($checkStmt->fetch()) {
                throw new Exception("A flowsheet for {$shift} on {$flowsheet_date} already exists.");
}

            $flowsheetStmt = $pdo->prepare("INSERT INTO flowsheets (flowsheet_date, shift, created_by_nurse_id) VALUES (:date, :shift, :nurse_id)");
$flowsheetStmt->execute(['date' => $flowsheet_date, 'shift' => $shift, 'nurse_id' => $nurse_user_id]);
            $flowsheet_id = $pdo->lastInsertId();
$vitalsStmt = $pdo->prepare("INSERT INTO vitals_entries (flowsheet_id, patient_id, unregistered_patient_id, pre_hd_bp, post_hd_bp, pre_hd_wt, post_hd_wt, medication, nurse_in_charge_id, attendance_status) VALUES (:flowsheet_id, :patient_id, :unregistered_patient_id, :pre_hd_bp, :post_hd_bp, :pre_hd_wt, :post_hd_wt, :medication, :nurse_in_charge_id, :attendance_status)");
            
            $patientLogEntries = [];
            $nursesStmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'nurse'");
            $nurses = $nursesStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($_POST['patients'] as $patient) {
                $vitalsStmt->execute([
                    'flowsheet_id' => $flowsheet_id,
                    'patient_id' => ($patient['patient_type'] === 'registered') ? ($patient['patient_id'] ?? null) : null,
                    'unregistered_patient_id' => ($patient['patient_type'] === 'unregistered') ? ($patient['unregistered_patient_id'] ?? null) : null,
                    'pre_hd_bp' => $patient['pre_hd_bp'] ?? null,
                    'post_hd_bp' => $patient['post_hd_bp'] ?? null,
                    'pre_hd_wt' => !empty($patient['pre_hd_wt']) ? $patient['pre_hd_wt'] : null,
                    'post_hd_wt' => !empty($patient['post_hd_wt']) ? $patient['post_hd_wt'] : null,
                    'medication' => $patient['medication'] ?? null,
                    'nurse_in_charge_id' => !empty($patient['nurse_in_charge_id']) ? $patient['nurse_in_charge_id'] : null,
                    'attendance_status' => $patient['attendance_status'] ?? 'unmarked'
                ]);
$vitals_entry_id = $pdo->lastInsertId();

                if (($patient['patient_type'] ?? '') === 'registered' && ($patient['attendance_status'] ?? '') === 'absent') {
                    $remStmt = $pdo->prepare("INSERT INTO payment_reminders (patient_id, vitals_entry_id, amount, date_of_absence) VALUES (?, ?, ?, ?)");
$remStmt->execute([$patient['patient_id'], $vitals_entry_id, $no_show_fee, $flowsheet_date]);
                }

                // Detailed logging for creation
                $patientDetails = [];
                if (!empty($patient['attendance_status']) && $patient['attendance_status'] !== 'unmarked') {
                    $patientDetails[] = "Attendance: '" . htmlspecialchars($patient['attendance_status']) . "'";
                }
                if (!empty($patient['pre_hd_bp'])) { $patientDetails[] = "Pre-HD BP: '" . htmlspecialchars($patient['pre_hd_bp']) . "'"; }
                if (!empty($patient['post_hd_bp'])) { $patientDetails[] = "Post-HD BP: '" . htmlspecialchars($patient['post_hd_bp']) . "'"; }
                if (!empty($patient['pre_hd_wt'])) { $patientDetails[] = "Pre-HD WT (kg): '" . htmlspecialchars($patient['pre_hd_wt']) . "'"; }
                if (!empty($patient['post_hd_wt'])) { $patientDetails[] = "Post-HD WT (kg): '" . htmlspecialchars($patient['post_hd_wt']) . "'"; }
                if (!empty($patient['medication'])) { $patientDetails[] = "Medication: '" . htmlspecialchars($patient['medication']) . "'"; }
                if (!empty($patient['nurse_in_charge_id'])) {
                    $nurse_name = $nurses[$patient['nurse_in_charge_id']] ?? 'Unknown';
                    $patientDetails[] = "Nurse in Charge: '" . htmlspecialchars($nurse_name) . "'";
                }

                if (!empty($patientDetails)) {
                    $patientLogEntries[] = "Patient " . htmlspecialchars($patient['patient_name']) . ":\n- " . implode("\n- ", $patientDetails);
                }
            }
            
            $logDetails = "Created Vitals Flowsheet for {$shift} on {$flowsheet_date} (ID: {$flowsheet_id}).";
            if (!empty($patientLogEntries)) {
                $logDetails .= "\n\n" . implode("\n\n", $patientLogEntries);
            }

$logStmt = $pdo->prepare("INSERT INTO schedule_logs (action_id, action_type, action_details, performed_by) VALUES (?, ?, ?, ?)");
$logStmt->execute(['PPKC-' . substr(str_shuffle("0123456789"), 0, 10), 'Create Vitals Flowsheet', $logDetails, $nurse_full_name]);
            
            $pdo->commit();
echo json_encode(['status' => 'success', 'message' => 'Vitals flowsheet created successfully.']);
            break;
case 'update_flowsheet':
            $pdo->beginTransaction();
            $flowsheet_id = $_POST['flowsheet_id'];
if (!$flowsheet_id) throw new Exception("Flowsheet ID is missing.");
            
            $fsInfoStmt = $pdo->prepare("SELECT flowsheet_date, shift FROM flowsheets WHERE id = :id");
$fsInfoStmt->execute(['id' => $flowsheet_id]);
            $fsInfo = $fsInfoStmt->fetch(PDO::FETCH_ASSOC);
            $flowsheet_date = $fsInfo['flowsheet_date'];

            $oldDataStmt = $pdo->prepare("SELECT * FROM vitals_entries WHERE flowsheet_id = :id");
$oldDataStmt->execute(['id' => $flowsheet_id]);
            $oldEntriesRaw = $oldDataStmt->fetchAll(PDO::FETCH_ASSOC);
            $oldEntries = [];
            foreach ($oldEntriesRaw as $entry) {
                 if (!empty($entry['patient_id'])) {
                    $oldEntries['registered_' .
$entry['patient_id']] = $entry;
                } elseif (!empty($entry['unregistered_patient_id'])) {
                    $oldEntries['unregistered_' .
$entry['unregistered_patient_id']] = $entry;
                }
            }

            $feeStmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'no_show_fee' LIMIT 1");
$no_show_fee = $feeStmt ? $feeStmt->fetchColumn() : 1000.00;
            $nursesStmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'nurse'");
            $nurses = $nursesStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $patientLogEntries = [];

            $updateStmt = $pdo->prepare("UPDATE vitals_entries SET pre_hd_bp = :pre_hd_bp, post_hd_bp = :post_hd_bp, pre_hd_wt = :pre_hd_wt, post_hd_wt = :post_hd_wt, medication = :medication, nurse_in_charge_id = :nurse_in_charge_id, attendance_status = :attendance_status WHERE id = :entry_id");
$insertStmt = $pdo->prepare("INSERT INTO vitals_entries (flowsheet_id, patient_id, unregistered_patient_id, pre_hd_bp, post_hd_bp, pre_hd_wt, post_hd_wt, medication, nurse_in_charge_id, attendance_status) VALUES (:flowsheet_id, :patient_id, :unregistered_patient_id, :pre_hd_bp, :post_hd_bp, :pre_hd_wt, :post_hd_wt, :medication, :nurse_in_charge_id, :attendance_status)");
            
            foreach ($_POST['patients'] as $patient) {
                $patient_type = $patient['patient_type'];
                // *** FIX: Get the correct ID based on patient type ***
                if ($patient_type === 'registered') {
                    $patient_id = $patient['patient_id'] ?? null;
                } else {
                    $patient_id = $patient['unregistered_patient_id'] ?? null;
                }
                
                $entry_id = $patient['entry_id'];
                // ...
                $entry_id = $patient['entry_id'];
                $vitals_entry_id_for_reminder = $entry_id;

                $lookup_key = $patient_type . '_' . $patient_id;
                $oldEntry = $oldEntries[$lookup_key] ?? [];
                
                // Detailed logging for updates
                $patientChanges = [];
                $fields_to_check = ['attendance_status', 'pre_hd_bp', 'post_hd_bp', 'pre_hd_wt', 'post_hd_wt', 'medication', 'nurse_in_charge_id'];
                $field_names = ['Attendance', 'Pre-HD BP', 'Post-HD BP', 'Pre-HD WT (kg)', 'Post-HD WT (kg)', 'Medication', 'Nurse in Charge'];

                for ($i = 0; $i < count($fields_to_check); $i++) {
                    $field = $fields_to_check[$i];
                    $name = $field_names[$i];
                    $old_value = $oldEntry[$field] ?? null;
                    $new_value = $patient[$field] ?? null;

                    if ($field === 'attendance_status' && is_null($old_value)) $old_value = 'unmarked';
                    if ($field === 'nurse_in_charge_id' && empty($new_value)) $new_value = null;

                    if ($old_value != $new_value) {
                        $old_display = !is_null($old_value) && $old_value !== '' ? htmlspecialchars($old_value) : 'N/A';
                        $new_display = !is_null($new_value) && $new_value !== '' ? htmlspecialchars($new_value) : 'N/A';
                        
                        if ($field === 'nurse_in_charge_id') {
                            $old_display = $old_value ? ($nurses[$old_value] ?? 'N/A') : 'N/A';
                            $new_display = $new_value ? ($nurses[$new_value] ?? 'N/A') : 'N/A';
                        }
                        
                        if ($old_display !== 'N/A' || $new_display !== 'N/A') {
                            $patientChanges[] = "- {$name}: '{$old_display}' -> '{$new_display}'";
                        }
                    }
                }

                if (!empty($patientChanges)) {
                    $patientLogEntries[] = "Patient " . htmlspecialchars($patient['patient_name']) . ":\n" . implode("\n", $patientChanges);
                }

                $params = [
'pre_hd_bp' => $patient['pre_hd_bp'] ?? null,
'post_hd_bp' => $patient['post_hd_bp'] ?? null,
'pre_hd_wt' => !empty($patient['pre_hd_wt']) ? $patient['pre_hd_wt'] : null,
'post_hd_wt' => !empty($patient['post_hd_wt']) ? $patient['post_hd_wt'] : null,
'medication' => $patient['medication'] ?? null,
'nurse_in_charge_id' => !empty($patient['nurse_in_charge_id']) ? $patient['nurse_in_charge_id'] : null,
                    'attendance_status' => $patient['attendance_status'] ?? 'unmarked'
                ];

if (!empty($entry_id)) {
                    $params['entry_id'] = $entry_id;
$updateStmt->execute($params);
                } else {
                    $params['flowsheet_id'] = $flowsheet_id;
$params['patient_id'] = ($patient_type === 'registered') ? $patient_id : null;
                    $params['unregistered_patient_id'] = ($patient_type === 'unregistered') ? $patient_id : null;
                    $insertStmt->execute($params);
$vitals_entry_id_for_reminder = $pdo->lastInsertId();
                }

                if ($patient_type === 'registered') {
                    $old_status = $oldEntry['attendance_status'] ?? null;
                    $new_status = $patient['attendance_status'];
                    if ($new_status == 'absent' && $old_status != 'absent') {
                        $remStmt = $pdo->prepare("INSERT INTO payment_reminders (patient_id, vitals_entry_id, amount, date_of_absence) VALUES (?, ?, ?, ?)");
$remStmt->execute([$patient_id, $vitals_entry_id_for_reminder, $no_show_fee, $flowsheet_date]);
                    } elseif ($new_status != 'absent' && $old_status == 'absent') {
                        $remStmt = $pdo->prepare("DELETE FROM payment_reminders WHERE vitals_entry_id = ? AND status = 'unpaid'");
$remStmt->execute([$vitals_entry_id_for_reminder]);
                    }
                }
            }
            
            $logDetails = "Updated Vitals Flowsheet for {$fsInfo['shift']} on {$fsInfo['flowsheet_date']} (ID: {$flowsheet_id}).";
            if (!empty($patientLogEntries)) {
                $logDetails .= "\n\n" . implode("\n\n", $patientLogEntries);
            }
$logStmt = $pdo->prepare("INSERT INTO schedule_logs (action_id, action_type, action_details, performed_by) VALUES (?, ?, ?, ?)");
$logStmt->execute(['PPKC-' . substr(str_shuffle("0123456789"), 0, 10), 'Update Vitals Flowsheet', $logDetails, $nurse_full_name]);
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "Vitals updated successfully."]);
break;

        case 'delete_flowsheet':
    $pdo->beginTransaction();
    $flowsheet_id = $_POST['flowsheet_id'] ?? null;
    if (!$flowsheet_id) throw new Exception("Flowsheet ID is required for deletion.");

    $fsInfoStmt = $pdo->prepare("SELECT flowsheet_date, shift FROM flowsheets WHERE id = :id");
    $fsInfoStmt->execute(['id' => $flowsheet_id]);
    $fsInfo = $fsInfoStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch patient names for logging BEFORE deletion
    $patientNamesStmt = $pdo->prepare("
        (SELECT p.full_name FROM vitals_entries ve JOIN patients p ON ve.patient_id = p.id WHERE ve.flowsheet_id = :id)
        UNION
        (SELECT up.full_name FROM vitals_entries ve JOIN unregistered_patients up ON ve.unregistered_patient_id = up.id WHERE ve.flowsheet_id = :id)
    ");
    $patientNamesStmt->execute(['id' => $flowsheet_id]);
    $patientNames = $patientNamesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // --- MODIFICATION START ---
    // Build the main log message first
    $logDetails = "Deleted Vitals Flowsheet for {$fsInfo['shift']} on {$fsInfo['flowsheet_date']}. Flowsheet ID: {$flowsheet_id}.";

    // If there are patient names, append them as a new-line separated list
    if (!empty($patientNames)) {
        $patientList = "\nEntries for:\n" . implode("\n", $patientNames);
        $logDetails .= $patientList;
    }
    // --- MODIFICATION END ---

    $remDelStmt = $pdo->prepare("DELETE pr FROM payment_reminders pr JOIN vitals_entries ve ON pr.vitals_entry_id = ve.id WHERE ve.flowsheet_id = ?");
    $remDelStmt->execute([$flowsheet_id]);

    $deleteStmt = $pdo->prepare("DELETE FROM flowsheets WHERE id = :id");
    $deleteStmt->execute(['id' => $flowsheet_id]);
    
    $logStmt = $pdo->prepare("INSERT INTO schedule_logs (action_id, action_type, action_details, performed_by) VALUES (?, ?, ?, ?)");
    $logStmt->execute(['PPKC-' . substr(str_shuffle("0123456789"), 0, 10), 'Delete Vitals Flowsheet', $logDetails, $nurse_full_name]);
            
            $pdo->commit();
echo json_encode(['status' => 'success', 'message' => 'Flowsheet deleted successfully.']);
            break;
        case 'get_acknowledged_absences':
            $stmt = $pdo->prepare("
                SELECT ia.id, ia.message, ia.acknowledged_at, p.full_name 
                FROM informed_absences ia
                JOIN patients p ON ia.patient_id = p.id
                WHERE ia.status = 'acknowledged' 
                ORDER BY ia.acknowledged_at ASC
            ");
            $stmt->execute();
            $absences = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'absences' => $absences]);
            break;

        case 'resolve_absence':
            $pdo->beginTransaction();
            $absence_id = $_POST['absence_id'] ?? null;
            if (!$absence_id) {
                throw new Exception("Absence ID is required.");
            }

            // Get details for logging before updating
            $infoStmt = $pdo->prepare("
                SELECT p.full_name 
                FROM informed_absences ia 
                JOIN patients p ON ia.patient_id = p.id 
                WHERE ia.id = ?
            ");
            $infoStmt->execute([$absence_id]);
            $patient_name = $infoStmt->fetchColumn();
            
            if (!$patient_name) {
                throw new Exception("Absence record not found.");
            }

            // Mark the absence as resolved
            $updateStmt = $pdo->prepare("UPDATE informed_absences SET status = 'resolved' WHERE id = ?");
            $updateStmt->execute([$absence_id]);

            // Log the action
            $logDetails = "Resolved absence notice (ID: {$absence_id}) for patient '{$patient_name}'.";
            $logStmt = $pdo->prepare("INSERT INTO schedule_logs (action_id, action_type, action_details, performed_by) VALUES (?, ?, ?, ?)");
            $logStmt->execute([
                'PPKC-' . substr(str_shuffle("0123456789"), 0, 10), 
                'Resolve Informed Absence', 
                $logDetails, 
                $nurse_full_name
            ]);
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'Absence marked as resolved.']);
            break;
default:
            throw new Exception('Invalid action.');
}
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>