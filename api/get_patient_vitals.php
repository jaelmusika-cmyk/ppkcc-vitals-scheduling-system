<?php
// Set the timezone to Asia/Manila
date_default_timezone_set('Asia/Manila');

// Include your database configuration
include('../config/db.php');
// Set the response header to JSON
header('Content-Type: application/json');

// Get patient_id and date from the query parameters
$patient_id = $_GET['patient_id'] ?? null;
$selected_date = $_GET['date'] ?? date('Y-m-d'); // Default to today

// Validate inputs
if (empty($patient_id) || !is_numeric($patient_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing patient ID.']);
    exit;
}

if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $selected_date)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Please use YYYY-MM-DD.']);
    exit;
}

$response_data = [];

try {
    // Prepare the SQL statement to fetch vitals data
    $stmt = $pdo->prepare("
        SELECT 
            f.shift,
            ve.pre_hd_bp, 
            ve.post_hd_bp, 
            ve.pre_hd_wt, 
            ve.post_hd_wt, 
            ve.medication, 
            ve.attendance_status,
            nic.full_name AS nurse_in_charge_name
        FROM flowsheets f
        JOIN vitals_entries ve ON f.id = ve.flowsheet_id
        LEFT JOIN users nic ON ve.nurse_in_charge_id = nic.id
        WHERE f.flowsheet_date = :selected_date AND ve.patient_id = :patient_id
        ORDER BY f.shift
    ");
    // Execute the query
    $stmt->execute(['selected_date' => $selected_date, 'patient_id' => $patient_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If results are found, structure them by shift
    if ($results) {
        foreach ($results as $row) {
            $response_data[$row['shift']] = $row;
        }
    }

    // Return a success response with the data
    echo json_encode(['status' => 'success', 'data' => $response_data]);
    
} catch (PDOException $e) {
    // Handle database errors
    error_log("Database Error: " . $e->getMessage()); // Log error for debugging
    echo json_encode(['status' => 'error', 'message' => 'A database error occurred.']);
} catch (Exception $e) {
    // Handle other general errors
    error_log("General Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An unexpected error occurred.']);
}
?>