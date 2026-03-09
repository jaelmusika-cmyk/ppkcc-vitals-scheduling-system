<?php
session_start();




include('../config/db.php');
include('../admin/admin_sidebar.php');

// Fetch pending requests
$pendingStmt = $pdo->prepare("
    SELECT r.*, COALESCE(p.full_name, 'Unknown') AS full_name 
    FROM schedule_requests r 
    LEFT JOIN patients p ON r.patient_id = p.id 
    WHERE r.status = 'Pending'
    ORDER BY r.created_at DESC
");
$pendingStmt->execute();
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch historical requests (Approved, Rejected, Expired)
$historyStmt = $pdo->prepare("
    SELECT r.*, COALESCE(p.full_name, 'Unknown') AS full_name 
    FROM schedule_requests r 
    LEFT JOIN patients p ON r.patient_id = p.id 
    WHERE r.status IN ('Approved', 'Rejected', 'Expired')
    ORDER BY r.created_at DESC
");
$historyStmt->execute();
$historicalRequests = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active swaps
$swapsStmt = $pdo->prepare("
    SELECT 
        sw.id, sw.patient1_id, sw.patient1_day, sw.patient2_id, sw.patient2_day,
        sw.swapped_at,
        sw.patient1_original_shift, sw.patient1_original_start_time, sw.patient1_original_duration,
        sw.patient2_original_shift, sw.patient2_original_start_time, sw.patient2_original_duration,
        p1.full_name AS p1_name, p2.full_name AS p2_name
    FROM schedule_swaps sw
    JOIN patients p1 ON sw.patient1_id = p1.id
    JOIN patients p2 ON sw.patient2_id = p2.id
    WHERE sw.status = 'active'
    ORDER BY sw.swapped_at DESC
");
$swapsStmt->execute();
$activeSwaps = $swapsStmt->fetchAll(PDO::FETCH_ASSOC);

// Create a lookup array for easy checking in the table
$swappedCells = [];
foreach ($activeSwaps as $swap) {
    // Patient 1 is now on Patient 2's original day. They take Patient 2's schedule details.
    $swappedCells[$swap['patient1_id']][$swap['patient2_day']] = [
        'shift' => $swap['patient2_original_shift'],
        'start_time' => $swap['patient2_original_start_time'],
        'duration' => $swap['patient2_original_duration']
    ];
    
    // Patient 2 is now on Patient 1's original day. They take Patient 1's schedule details.
    $swappedCells[$swap['patient2_id']][$swap['patient1_day']] = [
        'shift' => $swap['patient1_original_shift'],
        'start_time' => $swap['patient1_original_start_time'],
        'duration' => $swap['patient1_original_duration']
    ];
}


// Handle filter selection
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'All';

$sql = "
    SELECT schedules.*, 
           schedules.monday_archived, schedules.tuesday_archived, schedules.wednesday_archived, 
           schedules.thursday_archived, schedules.friday_archived, schedules.saturday_archived,
           COALESCE(patients.full_name, unregistered_patients.full_name) AS full_name,
           patients.full_name AS registered_name,
           unregistered_patients.full_name AS unregistered_name,
           unregistered_patients.id AS unregistered_id
    FROM schedules 
    LEFT JOIN patients ON schedules.patient_id = patients.id 
    LEFT JOIN users ON patients.user_id = users.id
    LEFT JOIN unregistered_patients ON schedules.id = unregistered_patients.schedule_id 

    WHERE schedules.deleted = 0
      AND (
        (schedules.patient_id IS NOT NULL AND users.status = 'active')
        OR unregistered_patients.id IS NOT NULL
      )
";

if (isset($_GET['export']) && $_GET['export'] === 'true') {
    $filter = 'Scheduled';
}



if ($filter === 'Scheduled') {
    $sql .= " AND schedules.status = 'Scheduled'";
} elseif ($filter === 'Pending') {
    $sql .= " AND schedules.status = 'Pending'";
} elseif ($filter === 'Inactive') {
    $sql .= " AND schedules.status = 'Inactive'";
} elseif ($filter === 'Deleted') {
    $sql .= " AND schedules.status = 'Deleted'";
}

$sql .= " ORDER BY 
  COALESCE(patients.full_name, unregistered_patients.full_name) IS NULL, 
  COALESCE(patients.full_name, unregistered_patients.full_name) ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);






$scheduleData = [];

if (in_array($filter, ['Scheduled', 'All'])) {
    foreach ($schedules as $row) {
        if (in_array($row['status'], ['Inactive', 'Deleted'])) continue;
        $scheduleData['Monday'][] = [
            'name' => $row['full_name'],
            'shift' => $row['monday_shift'],
            'updated_at' => $row['updated_at']
        ];
        $scheduleData['Tuesday'][] = [
            'name' => $row['full_name'],
            'shift' => $row['tuesday_shift'],
            'updated_at' => $row['updated_at']
        ];
        $scheduleData['Wednesday'][] = [
            'name' => $row['full_name'],
            'shift' => $row['wednesday_shift'],
            'updated_at' => $row['updated_at']
        ];
        $scheduleData['Thursday'][] = [
            'name' => $row['full_name'],
            'shift' => $row['thursday_shift'],
            'updated_at' => $row['updated_at']
        ];
        $scheduleData['Friday'][] = [
            'name' => $row['full_name'],
            'shift' => $row['friday_shift'],
            'updated_at' => $row['updated_at']
        ];
        $scheduleData['Saturday'][] = [
            'name' => $row['full_name'],
            'shift' => $row['saturday_shift'],
            'updated_at' => $row['updated_at']
        ];
    }
}



$slotCounts = [];

    $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    foreach ($days as $day) {
    $stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS count, {$day}_shift AS shift_type
    FROM schedules 
    LEFT JOIN patients ON schedules.patient_id = patients.id 
    LEFT JOIN users ON patients.user_id = users.id
    LEFT JOIN unregistered_patients ON schedules.id = unregistered_patients.schedule_id 
    WHERE schedules.deleted = 0 
      AND schedules.status = 'Scheduled'
      AND schedules.{$day}_archived = 0
      AND (
          (schedules.patient_id IS NOT NULL AND users.status = 'active') 
          OR unregistered_patients.id IS NOT NULL
      )
      AND {$day}_shift IS NOT NULL
    GROUP BY shift_type
");

    $stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$slotCounts[$day] = ['first' => 0, 'second' => 0];

foreach ($rows as $row) {
    if ($row['shift_type'] === 'First Shift') {
        $slotCounts[$day]['first'] = (int)$row['count'];
    } elseif ($row['shift_type'] === 'Second Shift') {
        $slotCounts[$day]['second'] = (int)$row['count'];
    }
}


}


function formatTimeWithAMPM($time) {
    if (!$time) return '';

    // Try H:i:s format first
    $date = DateTime::createFromFormat('H:i:s', $time);
    if (!$date) {
        // Fallback to H:i format
        $date = DateTime::createFromFormat('H:i', $time);
    }

    return $date ? $date->format('g:i A') : 'Invalid time';
}

function calculateEndTime($startTimeStr, $durationMinutes) {
    if (!$startTimeStr || !is_numeric($durationMinutes)) {
        return 'N/A';
    }
    $startTime = DateTime::createFromFormat('H:i:s', $startTimeStr);
    if (!$startTime) {
        $startTime = DateTime::createFromFormat('H:i', $startTimeStr);
    }
    if (!$startTime) {
        return 'Invalid Start';
    }
    $endTime = (clone $startTime)->modify("+{$durationMinutes} minutes");
    return $endTime->format('g:i A');
}

?>

<title>Manage Schedules and Requests</title>

<head>
<style>

.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    align-items: center;
    font-size: 14px;
    margin: 10px 0 20px 0;
}

.legend-box {
    display: inline-block;
    width: 16px;
    height: 16px;
    border-radius: 3px;  /* rounded square look */
    margin-right: 6px;
    vertical-align: middle;
}

/* Colors */
.legend-box.green  { background-color: green; }
.legend-box.yellow { background-color: #FFD700; } /* bright yellow */
.legend-box.orange { background-color: orange; }
.legend-box.gray   { background-color: gray; }

/* --- ADD THIS NEW CSS --- */
.customize-section {
    margin-bottom: 15px;
}
.customize-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.customize-header h4 {
    margin: 0;
    font-size: 16px;
}
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 110px;
    height: 34px;
    user-select: none;
}
.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.toggle-switch label {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #bdc3c7;
    transition: background-color 0.4s;
    border-radius: 34px;
    color: white;
}
.toggle-switch label::before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: transform 0.4s ease;
    border-radius: 50%;
    z-index: 1;
}
.toggle-switch input:checked + label {
    background-color: #2196F3;
}
.toggle-switch input:checked + label::before {
    transform: translateX(76px);
}
/* This pseudo-element handles the text label */
.toggle-switch label::after {
    position: absolute;
    top: 0;
    line-height: 34px;
    font-size: 12px;
    font-weight: bold;
}
/* UNCHECKED STATE ("Custom" text) */
.toggle-switch input:not(:checked) + label::after {
    content: 'Custom';
    right: 15px;
    left: auto; /* Unset left position */
}
/* CHECKED STATE ("All Days" text) */
.toggle-switch input:checked + label::after {
    content: 'All Days';
    left: 15px;
    right: auto; /* Unset right position */
}
.day-chips-container {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}
.day-chip {
    padding: 8px 16px;
    border: 1px solid #ccc;
    background-color: #f2f2f2;
    border-radius: 20px;
    cursor: pointer;
    font-size: 14px;
    transition: background-color 0.3s, color 0.3s;
}
.day-chip:hover {
    background-color: #e0e0e0;
}
.day-chip.selected {
    background-color: #2196F3;
    color: white;
    border-color: #2196F3;
}
.shift-radio-group {
    display: flex;
    gap: 20px;
    margin-top: 10px;
}
.shift-radio-group label {
    display: flex;
    align-items: center;
    cursor: pointer;
}
.shift-radio-group input {
    margin-right: 6px;
}

/* --- SWAP MODAL STYLES --- */
.swap-modal-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    margin-bottom: 20px;
}

.swap-patient-column h4 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 8px;
    margin-bottom: 15px;
}

.swap-patient-column label {
    font-weight: bold;
    font-size: 14px;
    display: block;
    margin-bottom: 5px;
}

.swap-patient-column select {
    width: 100%;
    padding: 8px;
    margin-bottom: 15px;
    border-radius: 4px;
    border: 1px solid #ccc;
}

.swap-details {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 12px;
    min-height: 80px;
    font-size: 14px;
    color: #333;
}

.swap-details strong {
    color: #000;
}

/* 🎨 ADD/REPLACE FROM HERE DOWN */
#activeSwapsContainer {
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 8px;
    background-color: #f8f9fa;
}

#activeSwapsContainer table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

#activeSwapsContainer th,
#activeSwapsContainer td {
    padding: 12px 15px; /* Increased padding */
    border-bottom: 1px solid #ddd; /* Softer borders */
    text-align: left;
    vertical-align: middle;
}

#activeSwapsContainer th {
    background-color: #e9ecef;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
}

#activeSwapsContainer tr:last-child td {
    border-bottom: none; /* Remove border for the last row */
}

#activeSwapsContainer tr:hover {
    background-color: #f1f3f5; /* Add a hover effect */
}

.btn-revert {
    background-color: #fd7e14; /* A nice orange */
    color: white;
    border: none;
    padding: 6px 12px;
    font-size: 13px;
    font-weight: bold;
    border-radius: 5px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}

.btn-revert:hover {
    background-color: #e8590c; /* Darker orange on hover */
}



.history-container {
    max-height: 500px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 5px;
}

  .status-inactive {
    color: #007bff; /* Blue */
    font-weight: bold;
}
.status-deleted {
    color: red;
    font-weight: bold;
}

    table {
        border-collapse: collapse;
        width: 100%;
    }

    th, td {
        border: 1px solid #ccc;
        padding: 8px;
        text-align: center;
    }

    th {
        background-color: #f2f2f2;
    }
    
    .archive-btn {
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
    }



    .edit-btn {
        background-color: #2196F3;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
    }

    .edit-btn:hover {
        background-color: #0b7dda;
    }

    .toggle-buttons {
        margin-bottom: 20px;
    }

    .toggle-buttons button {
        margin-right: 10px;
        padding: 8px 16px;
        background-color: #2196F3;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }

    .toggle-buttons button:hover {
        background-color: #0b7dda;
    }

    .content {
    margin-left: 65px;
    margin-top: 20px;
    overflow-x: auto;
}

    .shift-green {
        background-color: green;
        color: white;
        padding: 4px;
        border-radius: 4px;
        margin-bottom: 5px;
        display: inline-block;
        width: 100%;
    }

    .shift-gold {
        background-color: gold;
        color: black;
        padding: 4px;
        border-radius: 4px;
        margin-bottom: 5px;
        display: inline-block;
        width: 100%;
    }

    .manage-container {
        margin-left: 0px;
        margin-right: 10px;
    }

    .view-container {
        margin-left: 0px;
        margin-right: 500px;
    }

    /* Scoped column widths for manage table ONLY */
    .manage-container .name-col {
        width: 8%;
    }

    .manage-container .day-group {
        width: 4.25%;
    }

    .manage-container .status-col {
        width: 7%;
    }

    .manage-container .action-col {
        width: 8.5%;
    }

    .manage-container table tbody td {
        min-height: 60px;
        vertical-align: top;
    }

    .patient {
        margin-bottom: 8px;
    }

    /* View schedule table gets equal width per column (16.66% × 6 = 100%) */
    #viewScheduleView table th,
    #viewScheduleView table td {
        width: 16.66%;
        padding: 10px;
        text-align: center;
    }



/* Oblong group styling */
.oblong-group {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    border-radius: 20px; /* Rounded edges */
    width: 150px;        /* Set width to control the length of the oblong */
    height: 32px;        /* Height to control the vertical size */
    font-size: 14px;
    font-weight: 500;
    text-align: center;
    gap: 6px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 4px 8px;    /* Optional, add padding for better spacing */
}

/* Color variants */
.green {
    background-color: green;
    color: white; /* White text for First Shift */
}

.gold {
    background-color: #FFD700 !important;
    /* Matches your Legend Yellow */
    color: black !important;
    /* REMOVED display: inline-flex !important; to allow JS to hide it */
}

.orange-bg {
    background-color: orange !important;
    color: white !important;
}

.gray {
    background-color: #e0e0e0;
    color: #555; /* Gray color for deleted/inactive states */
}

/* For the table: */
.schedule-cell {
    border: none !important;
    padding: 6px 4px;
    text-align: center;
    vertical-align: middle;
    border-radius: 20px; /* Rounded cell corners for oblong effect */
}

.blank-cell {
    background: transparent !important;
    border: none !important;
    padding: 6px 4px;
}
/* Table Styling */
.table-wrapper table {
    border-collapse: separate; /* Allows for better control of cell borders */
    border-radius: 20px;       /* Rounded corners for the whole table */
    overflow: hidden;          /* Ensures the rounded corners are respected */
    width: 100%;               /* Ensure the table takes full width */
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); /* Optional: Adds shadow for effect */
}

  .table-wrapper th {
    background-color: #f4f4f4;
    padding: 12px 8px;
    text-align: center;
    font-weight: bold;
    box-shadow: 0 -4px 8px rgba(0, 0, 0, 0.1); /* Soft top shadow like the bottom */
}



  /* Body Cell Styling */
  .table-wrapper td {
      border: 1px solid #ddd;   /* Light border for table cells */
      padding: 8px 12px;         /* Cell padding for better spacing */
      text-align: center;        /* Center text in cells */
      border-radius: 10px;       /* Rounded corners for each cell */
      vertical-align: middle;    /* Vertically center cell content */
  }

  /* Specific Cell Styling (to keep rounded effect on cells) */
  .schedule-cell {
      border: none !important;
      border-radius: 10px;       /* Rounded corners for schedule cells */
  }

  /* Adjust for blank cells */
  .blank-cell {
      background: transparent !important;
      border: none !important;
      padding: 6px 4px;
  }

  /* Add some padding to make sure the table is not too tight */
 .table-wrapper {
    display: block; /* Ensures it's a block container */
    width: 100%;
    padding: 10px;
    border-radius: 20px;
    background-color: #fff;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    overflow-x: auto; /* Optional: in case table overflows horizontally */
}


  .table-wrapper th,
.table-wrapper td {
    width: 10%; /* Makes all day columns equal */
}

.table-wrapper th:first-child,
.table-wrapper td:first-child {
    width: 16%; /* Patient name slightly wider */
}

.table-wrapper th:last-child,
.table-wrapper td:last-child {
    width: 12%; /* Action column */
}

.table-wrapper th:nth-last-child(2),
.table-wrapper td:nth-last-child(2) {
    width: 12%; /* Status column */
}







/* Delete button style */
.delete-btn {
    background-color: #dc3545;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    margin-left: 6px;
}

.delete-btn:hover {
    background-color: #a71d2a;
}

/* Wider name & action columns */
td:first-child {
    min-width: 180px;
}

td:last-child {
    min-width: 140px;
}

.option-item {
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.2s;
}

.option-item:hover {
    background-color: #f2f2f2;
}

.badge {
    display: inline-block;
    vertical-align: middle;
}

.dropdown-btn {
    border: 1px solid #ccc;
    padding: 6px 12px;
    border-radius: 6px;
    background-color: white;
    cursor: pointer;
    user-select: none;
}

.dropdown-options {
    display: none;
    position: absolute;
    top: 110%;
    left: 0;
    width: 100%;
    background-color: white;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    z-index: 10;
}

.option-item {
    padding: 8px 12px;
    cursor: pointer;
    font-size: 14px;
}

.option-item:hover {
    background-color: #f2f2f2;
}

.badge {
    display: inline-block;
    vertical-align: middle;
}







@media screen and (max-width: 768px) {
  .modal-content {
    width: 95%;
    margin: 5% auto;
    padding: 15px;
  }

  .day-card {
    padding: 10px;
  }

  .day-card h4 {
    font-size: 14px;
  }

  .day-card label {
    font-size: 13px;
  }

  .day-card input,
  .day-card select {
    font-size: 13px;
    padding: 4px 6px;
  }

  .btn {
    font-size: 14px;
    padding: 8px 16px;
  }
  
  
}



@media screen and (max-width: 1024px) {
  .content {
    margin: 10px;
  }

  .toggle-buttons {
    flex-direction: column;
    align-items: stretch;
  }

  .toggle-buttons button {
    margin-bottom: 10px;
    width: 100%;
  }
}

@media screen and (max-width: 768px) {
  .content {
    margin: 10px;
    padding: 10px;
  }

  .edit-btn {
    font-size: 14px;
    padding: 6px 10px;
  }

  .table-wrapper table {
    font-size: 13px;
    min-width: 1000px;
  }

  th, td {
    padding: 6px;
    white-space: nowrap;
  }

  .modal-content {
    width: 95% !important;
    padding: 10px;
    margin: 20px auto;
  }

  .day-card label,
  .day-card input,
  .day-card select {
    font-size: 13px;
    width: 100%;
  }

  .btn {
    width: 100%;
    padding: 10px;
    font-size: 14px;
  }
}

/* --- RESPONSIVE FIXES --- */

/* Let sidebar and content stack better on small screens */
@media screen and (max-width: 1024px) {
  .content {
    margin: 0;
    padding: 15px;
    width: 100%;
    box-sizing: border-box;
  }
}

/* Prevent sidebar from covering .content on smaller viewports */
@media screen and (max-width: 768px) {
  body {
    overflow-x: hidden;
  }

  .content {
    margin: 0 !important;
    padding: 10px !important;
    width: 100%;
  }

  .manage-container, .view-container {
    margin: 0 !important;
  }

  .filters,
  .toggle-buttons,
  .custom-dropdown,
  .table-wrapper,
  .view-schedule-wrapper {
    width: 100%;
    overflow-x: auto;
    display: block;
  }

  table {
    font-size: 13px;
    min-width: 950px;
  }

  th, td {
    white-space: nowrap;
    padding: 6px;
  }

  .btn, .edit-btn {
    font-size: 14px !important;
    width: 100%;
    margin-bottom: 10px;
  }

  .day-card {
    width: 100% !important;
    padding: 12px !important;
  }

  .unreg-modal-content {
    width: 95% !important;
    padding: 16px;
  }

  input[type="text"],
  input[type="number"],
  select {
    width: 100% !important;
  }

  .custom-dropdown {
    width: 100% !important;
  }

  .dropdown-btn {
    width: 100%;
  }

  #viewScheduleModal .modal-content {
  max-width: 420px;
  width: 90%;
  margin-top: 80px;
  padding: 20px;
}


  #viewScheduleTable {
    min-width: 900px;
  }
}




</style>

<style>
#toast {
  opacity: 0;
  pointer-events: none;
  position: fixed;
  top: 20px;
  left: 50%;
  transform: translateX(-50%);
  width: 100%;
  max-width: 450px;
  background: #f8ffff;
  border: 1px solid #bedfe6;
  border-left: 5px solid #2185d0;
  border-radius: 5px;
  padding: 15px;
  text-align: center;
  font-weight: bold;
  z-index: 999999;
  transition: opacity 0.4s ease;
}

#toast.show {
  opacity: 1;
  pointer-events: auto;
}



@keyframes fadein {
  from {
    opacity: 0;
    top: 0px;
  }
  to {
    opacity: 1;
    top: 20px;
  }
}

@keyframes fadeout {
  from {
    opacity: 1;
    top: 20px;
  }
  to {
    opacity: 0;
    top: 0px;
  }
}

#toast.success {
  background: #fbfff8;
  border: 1px solid #bee6bf;
  border-left: 5px solid #38d021;
}

#toast.error {
  background: #fff8f8;
  border: 1px solid #e6bebe;
  border-left: 5px solid #d02121;
}

#toast.warning {
  background: #fffbf8;
  border: 1px solid #e6d0be;
  border-left: 5px solid #d06421;
}


.actions-container {
            display: flex;
            align-items: center; /* This centers the label and buttons vertically */
            gap: 10px; /* This adds space between each button and the label */
        }
        .btn-custom {
    /* Your existing styles */
    white-space: nowrap;
    padding: 6px 14px;
    background-color: #c2c2c2; 
    color: black;
    border: none;
    display: flex;
    align-items: center;

    /* Add this line to move the buttons higher */
    transform: translateY(-10px); 
}
        .btn-custom i {
            margin-right: 5px; /* Adds space between the icon and the text */
        }
        .actions-label {
            font-weight: bold;
            margin-right: 10px; /* Adds a bit more space after the label */
        }




</style>
<script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>


</head>

<link rel="stylesheet" href="/assets/style.css">

<style>
  html.loading * {
    display: none !important;
  }
</style>
<script>
  // ✅ Reveal content only after filters applied to prevent flickering
document.addEventListener('DOMContentLoaded', function () {
  setTimeout(() => {
    document.getElementById('mainContent').style.display = 'block';
  }, 0); // Allow filters to apply first
});

  document.documentElement.classList.add("loading");
  window.addEventListener("DOMContentLoaded", function () {
    document.documentElement.classList.remove("loading");
  });
</script>

<style>
  body {
    background-color: #f8f9fa; /* or match your actual page bg color */
    margin: 0;
    overflow: hidden;
  }
  #loader {
    position: fixed;
    width: 100%;
    height: 100%;
  
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
  }
  #content {
    display: none;
  }
</style>

<div id="loader">
  <h3>Loading...</h3>
</div>

<div class="content" id="mainContent" style="display: none;">

<!-- TOAST HTML -->
<div id="toast"></div>



<div id="pendingNotif" style="display: none; background: red; color: white; padding: 4px 10px; font-weight: bold; border-radius: 20px; width: fit-content; margin-bottom: 10px;">
    🔔 You have <span id="pendingCountDisplay">0</span> pending schedule(s)
</div>


<!-- Manage Patient Schedules View (Container with custom margin-left) -->
<div id="manageSchedulesView" class="schedule-view manage-container" style="display: none;">
    <h2>Manage Patient Schedules</h2>
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
  <label style="font-weight: bold;">Show:</label>

  <div class="custom-dropdown" style="position: relative; width: 180px;">
    <div id="dropdownBtn" onclick="toggleDropdown()" class="dropdown-btn">Scheduled</div>
    <div id="dropdownList" class="dropdown-options">
      <div class="option-item" onclick="setFilter('All')">All</div>
      <div class="option-item" onclick="setFilter('Scheduled')">Scheduled</div>
      <div class="option-item" onclick="setFilter('Pending')">
        Pending <span id="pendingBadge" class="badge" style="display: none; background: red; color: white; border-radius: 12px; padding: 2px 6px; margin-left: 5px; font-size: 12px;"></span>
      </div>
      <div class="option-item" onclick="setFilter('Inactive')" style="color: blue;">Inactive</div>
<div class="option-item" onclick="setFilter('Deleted')" style="color: red;">Deleted</div>


    </div>
  </div>
  <span id="dropdownPendingCount" style="color: red; font-weight: bold;"></span>

  <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
    <div style="position: relative;
width: 20%;">
      <input type="text" id="searchInput" placeholder="Search patient name..." style="padding: 6px 30px 6px 10px;
width: 95%;">
      <button onclick="clearSearch()" style="position: absolute; right: 0px; top: 50%; transform: translateY(-50%); background: none;
border: none; cursor: pointer;">❌</button>
    </div>

    
    <button id="customizeViewBtn" class="btn" style="white-space: nowrap; padding: 6px 14px; margin-top: 0px; margin-right: 5px; background-color: #c2c2c2; color: black;"><i class="fa">&#xf0b0;</i>
</button>

    <div class="actions-container">
        <label class="actions-label">Actions:</label>
        
        <button class="btn btn-custom btn-warning" onclick="showUnregisteredModal()" style="background-color: #c2c2c2; color: black;">
            <i class="fas fa-plus"></i> Add Unregistered Patient
        </button>
        
        <button class="btn btn-custom" onclick="openSwapModal()" style="background-color: #c2c2c2; color: black;">
            <i class="fas fa-arrows-rotate"></i> Swap Schedule
        </button>
        
        <button id="viewScheduleBtn" class="btn btn-custom" style="background-color: #c2c2c2; color: black;">
            <i class="fas fa-download"></i> Export
        </button>
        
        <button class="btn btn-custom" onclick="printViewSchedule()" style="background-color: #c2c2c2; color: black;">
            <i class="fas fa-print"></i> Print
        </button>
        
        <?php
$pendingCount = count($pendingRequests);
if ($pendingCount > 0) :
?>
    <a href="#pending-requests" class="btn btn-custom btn-danger" style="background-color: #dc3545; color: white; text-decoration: none;">
        <i class="fas fa-eye"></i> View Pending Schedule Requests (
        <span class="badge bg-light text-dark"><?php echo $pendingCount; ?></span>)
    </a>
<?php else : ?>
    <a href="#pending-requests" class="btn btn-custom" style="background-color: #c2c2c2; color: black; text-decoration: none;">
        <i class="fas fa-eye"></i> View Pending Schedule Requests
    </a>
<?php endif; ?>
    </div>

  </div>
</div>

<div class="legend">
  <span><span class="legend-box green"></span> First Shift 6AM - 10AM</span>
  <span><span class="legend-box yellow"></span> Second Shift 11AM - 3PM</span>
  <span><span class="legend-box orange"></span> Swapped Schedule</span>
  <span><span class="legend-box gray"></span> Absent/Archived</span>
</div>









    <?php if ($schedules): ?>
        <div class="table-wrapper">
        <table border="1" cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse; text-align: center;">
            <thead>
                <tr>
    <th>Patient Name</th>
<th data-day-header="monday">Monday</th>
<th data-day-header="tuesday">Tuesday</th>
<th data-day-header="wednesday">Wednesday</th>
<th data-day-header="thursday">Thursday</th>
<th data-day-header="friday">Friday</th>
<th data-day-header="saturday">Saturday</th>
<th>Status</th>
<th>Action</th>
</tr>


                
            </thead>
            <tbody>
<?php foreach ($schedules as $schedule): 
    $schedule['full_name'] = $schedule['registered_name'] 
    ?? ($schedule['unregistered_name'] ? $schedule['unregistered_name'] : 'Unknown');

if (empty($schedule['registered_name']) && empty($schedule['unregistered_name'])) {
    continue; // skip unknown orphan rows
}


    $hasPatient = !empty($schedule['full_name']);
    $rawStatus = $schedule['status'];
    $schedule['resolved_status'] = (
        in_array($rawStatus, ['Scheduled', 'Pending', 'Inactive']) ? $rawStatus
        : (!$hasPatient || $rawStatus === null ? 'Deleted' : $rawStatus)
    );
?>
    <tr>



        <td style="min-width: 180px;">
            <?= htmlspecialchars($schedule['full_name']) ?>
            <?php if (is_null($schedule['patient_id'])): ?>
                <span style="color: gray; font-size: 12px;">(Unregistered)</span>
            <?php endif; ?>
        </td>




        <?php
$hasPatient = !empty($schedule['full_name']);
$rawStatus = $schedule['status'];

$schedule['resolved_status'] = (
    in_array($rawStatus, ['Scheduled', 'Pending', 'Inactive']) ? $rawStatus
    : (!$hasPatient || $rawStatus === null ? 'Deleted' : $rawStatus)
);
?>
<?php
$days = ['monday','tuesday','wednesday','thursday','friday','saturday'];
foreach ($days as $day):
    // 1. Check for Swap Data FIRST
    $isSwapped = isset($swappedCells[$schedule['patient_id']][$day]);
    if ($isSwapped) {
        // Use the SWAPPED schedule details
        $shift = $swappedCells[$schedule['patient_id']][$day]['shift'];
        $start = $swappedCells[$schedule['patient_id']][$day]['start_time'];
        $duration = $swappedCells[$schedule['patient_id']][$day]['duration'];
    } else {
        // Use the ORIGINAL schedule details
        $shift = $schedule["{$day}_shift"];
        $start = $schedule["{$day}_start_time"];
        $duration = $schedule["{$day}_duration"];
    }

    // FIX: Normalize shift string to prevent "Second Shift " (with space) mismatches
    $shift = trim((string)$shift);

    $status = $schedule['resolved_status'];

    // 2. Check if there is data to show
    $hasScheduleData = (!empty($shift) && ($shift === 'First Shift' || $shift === 'Second Shift')) || !empty($start);

    
    
    // 4. Determine Group Color Class
    $groupClass = '';
    $isArchived = !empty($schedule["{$day}_archived"]) && $schedule["{$day}_archived"] == 1;

    if ($isSwapped) {
        $groupClass = 'orange-bg'; // Swaps are ALWAYS Orange
    } elseif ($isArchived || $status === 'Deleted' || ($status === 'Inactive' && $hasScheduleData)) {
        $groupClass = 'gray';
    } elseif ($shift === 'First Shift') {
        $groupClass = 'green';
    } elseif ($shift === 'Second Shift') {
        $groupClass = 'gold';
    }

    // 5. Decide Visibility: Show if it has data OR if it is a swap
    $showCell = $hasScheduleData || $isSwapped || $isArchived;

?>
<td class="<?= $showCell ? 'schedule-cell' : 'blank-cell' ?>" data-day-cell="<?= $day ?>">
    <?php if ($showCell): ?>
        <div class="oblong-group <?= $groupClass ?>" data-shift="<?= htmlspecialchars($shift) ?>">
            <?php
            // Display Shift Label
            if (!empty($displayShift)) {
                echo htmlspecialchars($displayShift);
            }

            // Display Time (Works for both Regular and Swapped now)
            if (!empty($start) && !empty($duration) && is_numeric($duration)) {
                $startTime = DateTime::createFromFormat('H:i:s', $start) ?: DateTime::createFromFormat('H:i', $start);
                if ($startTime) {
                    $endTime = (clone $startTime)->modify("+{$duration} minutes");
                    echo ' ' . formatTimeWithAMPM($startTime->format('H:i')) .
                         ' - ' . formatTimeWithAMPM($endTime->format('H:i'));
                }
            }
            ?>
        </div>
    <?php endif; ?>
</td>
<?php endforeach; ?>



       <?php
$status = htmlspecialchars($schedule['resolved_status']);
$color = match ($status) {
    'Scheduled' => 'green',
    'Pending' => 'orange',
    'Inactive' => 'blue',
    'Deleted' => 'red',
    default => 'black'
};
?>
<td style="color: <?= $color ?>; font-weight: bold;"><?= $status ?></td>


<td style="min-width: 140px;">
  <?php if ($schedule['resolved_status'] === 'Deleted'): ?>
    <?php 
      $checkPatient = $pdo->prepare("SELECT id FROM patients WHERE id = ?");
      $checkPatient->execute([$schedule['patient_id']]);
    ?>
    <?php if ($checkPatient->rowCount() > 0): ?>
      <button class="edit-btn" 
        data-patient-id="<?= $schedule['patient_id'] ?>" 
        data-unregistered-id="null">
        Add Schedule
      </button>
    <?php else: ?>
      <span style="color: gray; font-weight: bold;">No actions possible</span>
    <?php endif; ?>
  <?php else: ?>
    <?php
  $patientId = $schedule['patient_id'] ?? null;
  $unregisteredId = is_null($patientId) ? $schedule['unregistered_id'] ?? 'null' : 'null';
?>
<button class="edit-btn"
  data-patient-id="<?= $patientId ?? 'null' ?>"
  data-unregistered-id="<?= $unregisteredId ?>">
  <i class="fa fa-edit"></i>
</button>

<button class="archive-btn" 
  data-patient-id="<?= $patientId ?? 'null' ?>"
  data-unregistered-id="<?= $unregisteredId ?>"
  style="background-color: #6c757d; margin-left: 6px;">
  <i class="fa fa-archive"></i>
</button>

    <button class="delete-btn"
      data-patient-id="<?= $patientId ?? 'null' ?>"
      data-unregistered-id="<?= $unregisteredId ?? 'null' ?>">
      <i class="fa fa-trash-o"></i>
    </button>
  <?php endif; ?>
</td>







    </tr>
<?php endforeach; ?>
</tbody>



        </table>
        </div>
    <?php else: ?>
        <p>No schedules found for any patient.</p>
    <?php endif; ?>
</div>

<div id="customizeViewModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <span id="closeCustomizeModal" class="close">&times;</span>
        <h3>Customize View</h3>

        <div class="customize-section">
            <div class="customize-header">
                <h4>Filter by Day</h4>
                <div class="toggle-switch">
    <input type="checkbox" id="showAllDaysToggle" checked>
    <label for="showAllDaysToggle"></label>
</div>

            </div>
            <div id="dayChipsContainer" class="day-chips-container" style="display: none;">
                <button class="day-chip" data-day="monday">Mon</button>
                <button class="day-chip" data-day="tuesday">Tue</button>
                <button class="day-chip" data-day="wednesday">Wed</button>
                <button class="day-chip" data-day="thursday">Thu</button>
                <button class="day-chip" data-day="friday">Fri</button>
                <button class="day-chip" data-day="saturday">Sat</button>
            </div>
        </div>

        <hr style="margin: 20px 0;">

        <div class="customize-section">
            <h4>Filter by Shift</h4>
            <div class="shift-radio-group">
                <label>
                    <input type="radio" name="shiftFilter" value="All" checked> All Shifts
                </label>
                <label>
                    <input type="radio" name="shiftFilter" value="First Shift"> First Shift
                </label>
                <label>
                    <input type="radio" name="shiftFilter" value="Second Shift"> Second Shift
                </label>
            </div>
        </div>
    </div>
</div>

<?php
$groupedRequests = [];
foreach ($pendingRequests as $req) {
    // Use the batch_id as the key for grouping, or a unique key for single requests.
    $key = $req['batch_id'] ?? 'single_' . $req['id'];

    // If this is the first time we've seen this key, initialize the group.
    if (!isset($groupedRequests[$key])) {
        $groupedRequests[$key] = [
            'id' => $req['id'], // Use the first request's ID to represent the batch/single request
            'full_name' => $req['full_name'],
            'patient_id' => $req['patient_id'],
            'created_at' => $req['created_at'],
            'batch_id' => $req['batch_id'],
            'note' => $req['note'],
            'details' => [] // This will hold the day-specific info
        ];
    }

    // Add the current day's details to the group.
    $groupedRequests[$key]['details'][] = [
        'day' => $req['day'],
        'shift' => $req['shift'],
        'start_time' => $req['start_time'],
        'duration' => $req['duration'],
    ];
}
?>

<div id="pendingRequestsContainer" class="schedule-view manage-container" style="margin-top: 40px;">
  <h2 id="pending-requests">Pending Schedule Requests</h2>
  <?php if (!empty($groupedRequests)): ?>
    <div class="table-wrapper">
      <table class="request-table" border="1" cellpadding="6" cellspacing="0" style="width: 100%; border-collapse: collapse; text-align: center;">
        <thead>
          <tr>
            <th style="width: 15%;">Patient Name</th>
            <th style="width: 10%;">Day</th>
            <th style="width: 10%;">Shift</th>
            <th style="width: 10%;">Start Time</th>
            <th style="width: 10%;">End Time</th>
            <th>Note</th>
            <th style="width: 15%;">Requested At</th>
            <th style="width: 5%;">Batch</th>
            <th style="width: 10%;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groupedRequests as $group): ?>
            <tr>
              <td style="vertical-align: middle;"><?= htmlspecialchars($group['full_name']) ?></td>
              <td><?php echo implode('<br>', array_map('ucfirst', array_column($group['details'], 'day'))); ?></td>
              <td><?php echo implode('<br>', array_column($group['details'], 'shift')); ?></td>
              <td>
                <?php foreach ($group['details'] as $detail) {
                    echo formatTimeWithAMPM($detail['start_time']) . '<br>';
                } ?>
              </td>
              <td>
                <?php foreach ($group['details'] as $detail) {
                    echo calculateEndTime($detail['start_time'], $detail['duration']) . '<br>';
                } ?>
              </td>
              <td style="vertical-align: middle;"><?= htmlspecialchars($group['note']) ?></td>
              <td style="vertical-align: middle;">
                  <?php
                    $utc_date = new DateTime($group['created_at'], new DateTimeZone('UTC'));
                    $utc_date->setTimezone(new DateTimeZone('Asia/Manila'));
                    echo $utc_date->format('M d, Y h:i A');
                  ?>
              </td>
              <td style="vertical-align: middle;"><?= $group['batch_id'] ? '✅' : '❌' ?></td>
              <td style="vertical-align: middle;">
                <button class="btn btn-primary handle-request-btn"
                  data-request='<?= json_encode($group) ?>'>Review</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p>No pending schedule requests.</p>
  <?php endif; ?>
</div>





<div id="swapScheduleModal" class="modal">
    <div class="modal-content" style="max-width: 850px;">
        <span class="close" onclick="closeSwapModal()">&times;</span>
        <h3>Swap Patient Schedules</h3>

        <form id="swapScheduleForm">
            <div class="swap-modal-grid">
                <div class="swap-patient-column">
                    <h4>Patient 1</h4>
                    <label for="patient1_id">Select Patient:</label>
                    <select name="patient1_id" id="patient1_id" required></select>

                    <label for="patient1_day">Select Day to Swap:</label>
                    <select name="patient1_day" id="patient1_day" required></select>
                    
                    <label>Day's Details:</label>
                    <div id="patient1_details" class="swap-details">
                        <span style="color: #888;">Select a patient and day to see details.</span>
                    </div>
                </div>

                <div class="swap-patient-column">
                    <h4>Patient 2</h4>
                    <label for="patient2_id">Select Patient:</label>
                    <select name="patient2_id" id="patient2_id" required></select>

                    <label for="patient2_day">Select Day to Swap:</label>
                    <select name="patient2_day" id="patient2_day" required></select>

                    <label>Day's Details:</label>
                    <div id="patient2_details" class="swap-details">
                        <span style="color: #888;">Select a patient and day to see details.</span>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn">Confirm Swap</button>
        </form>

        <hr style="margin: 30px 0;">

        <h4>Active Swaps</h4>
        <div id="activeSwapsContainer" style="max-height: 250px; overflow-y: auto;">
            <?php if (!empty($activeSwaps)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Patient 1 (Swapped Day)</th>
                            <th>Patient 2 (Swapped Day)</th>
                            <th>Date Swapped</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeSwaps as $swap): ?>
                            <tr id="swap-row-<?= $swap['id'] ?>">
                                <td><?= htmlspecialchars($swap['p1_name']) ?> (<?= ucfirst($swap['patient1_day']) ?>)</td>
                                <td><?= htmlspecialchars($swap['p2_name']) ?> (<?= ucfirst($swap['patient2_day']) ?>)</td>
                                <td><?= date('M d, Y h:i A', strtotime($swap['swapped_at'])) ?></td>
                                <td>
                                    <button class="btn-revert" onclick="revertSwap(<?= $swap['id'] ?>)">Revert</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No active swaps.</p>
            <?php endif; ?>
        </div>
    </div>
</div>



   <!-- BEGIN: Edit Schedule Modal -->
<div id="scheduleModal" class="modal-edit">
    <div class="modal-content-edit">
        <span id="closeModal" class="close">&times;</span>
        <h3>Edit Dialysis Schedule</h3>
        <form id="editScheduleForm">

            <input type="hidden" name="patient_id" id="modalPatientId">
<input type="hidden" name="unregistered_id" id="modalUnregisteredId">



            <div class="schedule-grid">
                <?php
                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                foreach ($days as $day):
                    $day_uc = ucfirst($day);
                ?>
                <div class="day-card">
                    <h4><?= $day_uc ?> <button type="button" id="clear_<?= $day ?>_btn" class="clear-day-btn" style="display: none; float: right; padding: 5px; color: black; background-color: gold;">Clear</button></h4>
                    <label><?= $day_uc ?> Shift:
                        <select name="<?= $day ?>_shift" id="<?= $day ?>_shift">
    <option value="">-- Select Shift --</option>
    <option value="First Shift">
        First Shift (6AM-10AM) - <?= 13 - $slotCounts[$day]['first'] ?> slots left
    </option>
    <option value="Second Shift">
        Second Shift (11AM-3PM) - <?= 13 - $slotCounts[$day]['second'] ?> slots left
    </option>
</select>
                    </label>
                    <label>Start Time:
                        <input type="time" name="<?= $day ?>_start_time" id="<?= $day ?>_start_time">
                    </label>
                    <label>Duration:
  <div style="display:flex; gap:5px; align-items:center;">
    <input type="number" min="0" id="<?= $day ?>_duration_hours" placeholder="Hrs" style="width:60px;">
    <input type="number" min="0" max="59" id="<?= $day ?>_duration_mins" placeholder="Mins" style="width:60px;">
    <!-- ✅ ensure this is NOT disabled, always submitted -->
    <input type="hidden" name="<?= $day ?>_duration" id="<?= $day ?>_duration">
  </div>
</label>

<label>End Time:
  <input type="time" id="<?= $day ?>_end_time" readonly disabled>
</label>






                </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn">Update Schedule</button>
            <button type="button" id="clearAllScheduleBtn" class="btn btn-danger" style="background-color: red;">Clear Schedule</button>
        </form>
    </div>
</div>
<!-- END: Modal HTML -->

<!-- Archive/Destroy Modal -->
<div id="deleteChoiceModal" class="modal">
  <div class="modal-content" style="max-width: 400px;">
    <h3>Delete Schedule</h3>
    <p>Do you want to Archive or Destroy this patient's schedule?</p>
    <input type="hidden" id="deletePatientId">
    <button onclick="handleDeleteAction('archive')" class="btn" style="background-color: orange;">Archive</button>
    <button onclick="handleDeleteAction('destroy')" class="btn" style="background-color: red;">Destroy</button>
    <button onclick="closeDeleteModal()" class="btn" style="background-color: grey;">Cancel</button>
  </div>
</div>

<!-- VIEW SCHEDULE MODAL -->
<div id="viewScheduleModal" class="modal">
  <div class="modal-content">
    <!-- Close Button -->
    <span class="close" onclick="document.getElementById('viewScheduleModal').style.display='none'">&times;</span>

    <!-- KEEP: Dropdown + Export Button -->
    <div style="display: flex; flex-direction: column; align-items: center; gap: 10px; margin-bottom: 10px;">
  <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: center;">
    <select id="exportFormat" style="
      height: 36px;
      min-width: 180px;
      font-size: 14px;
      border: 1px solid #ccc;
      border-radius: 4px;
      background-color: white;
      cursor: pointer;
    ">
      <option value="" disabled selected>Export Schedule as...</option>
      <option value="excel">📥 Excel</option>
      <option value="pdf">📄 PDF</option>
    </select>

    <button onclick="handleExport()" style="
      height: 36px;
      padding: 0 16px;
      font-size: 14px;
      background-color: #4CAF50;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      margin-right: 10px;
    ">Export</button>
  </div>
</div>


    <!-- HIDE THIS SECTION COMPLETELY -->
    <div class="hide-on-open">

      <div class="view-schedule-wrapper hide-on-open" style="overflow-x: auto; max-width: 100%;">
        <table id="viewScheduleTable" style="min-width: 1000px;">
          <thead>
            <tr>
              <th>MONDAY</th>
              <th>TUESDAY</th>
              <th>WEDNESDAY</th>
              <th>THURSDAY</th>
              <th>FRIDAY</th>
              <th>SATURDAY</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <?php
              $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
              foreach ($days as $day) {
                echo "<td>";
                if (isset($scheduleData[$day])) {
                    $filtered = array_filter($scheduleData[$day], fn($p) => !empty($p['shift']) && !empty($p['updated_at']));
                    $firstShift = array_filter($filtered, fn($p) => $p['shift'] === 'First Shift');
                    $secondShift = array_filter($filtered, fn($p) => $p['shift'] === 'Second Shift');
                    $sorted = array_merge($firstShift, $secondShift);

                    foreach ($sorted as $patient) {
                        $shiftClass = $patient['shift'] === 'First Shift' ? 'shift-green' : 'shift-gold';
                        echo "<div class='view-patient $shiftClass'>" . htmlspecialchars($patient['name']) . "<br><small>" . htmlspecialchars($patient['shift']) . "</small></div>";
                    }
                }
                echo "</td>";
              }
              ?>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>


<!-- Modal HTML -->
<div id="unreg-modal" class="unreg-modal">
    <div class="unreg-modal-content">
        <span class="unreg-close-btn" onclick="closeUnregisteredModal()">&times;</span>
        <h2>Add Unregistered Patient</h2>
<form id="addUnregisteredForm">

            <label for="full_name">Full Name:</label>
            <input type="text" id="full_name" name="full_name" required placeholder="Enter full name">
            <button type="submit">Submit</button>
        </form>
    </div>
</div>
<!-- Modal -->
<div id="requestModal" class="modal-edit" style="display: none;">
  <div class="modal-content-edit">
    <span id="closeRequestModal" class="close">&times;</span>
    <div id="modalRequestContent"></div>
  </div>
</div>

<div id="archiveDayModal" class="modal">
  <div class="modal-content" style="max-width: 500px;">
    <span class="close" id="closeArchiveDayModal">&times;</span>
    <h3>Manage Archived Days</h3>
    <p>Select days to archive or un-archive for this patient. Archived days will free up a slot.</p>
    <form id="archiveDayForm">
        <input type="hidden" id="archivePatientId" name="patient_id">
        <div id="archiveDayCheckboxes" style="margin-top: 15px; margin-bottom: 20px;">
            </div>
        <button type="submit" class="btn">Save Changes</button>
    </form>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const modal = document.getElementById("requestModal");
  const closeBtn = document.getElementById("closeRequestModal");
  const modalContent = document.getElementById("modalRequestContent");

  if (!modal || !closeBtn || !modalContent) {
    console.error("Modal elements not found in DOM.");
    return;
  }

  // Helper function to format time string (e.g., 13:00:00 -> 1:00 PM)
  function formatTimeForModal(timeStr) {
    if (!timeStr || typeof timeStr !== 'string') return '';
    const parts = timeStr.split(':');
    if (parts.length < 2) return timeStr;
    let [hours, minutes] = parts;
    let h = parseInt(hours, 10);
    const ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    h = h ? h : 12; // the hour '0' should be '12'
    return `${h}:${minutes} ${ampm}`;
  }

  document.querySelectorAll(".handle-request-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      let data;
      try {
        data = JSON.parse(btn.dataset.request);
      } catch (e) {
        alert("Invalid request data.");
        return;
      }

      modal.style.display = "block";
      modalContent.innerHTML = `<p>Loading...</p>`;

      let requestedScheduleHtml = '<ul>';
      if (data.details && data.details.length > 0) {
          data.details.forEach(detail => {
              const formattedStartTime = formatTimeForModal(detail.start_time);
              requestedScheduleHtml += `<li><strong>${detail.day.charAt(0).toUpperCase() + detail.day.slice(1)}:</strong> ${detail.shift} (${formattedStartTime} for ${detail.duration} mins)</li>`;
          });
      }
      requestedScheduleHtml += '</ul>';

      modalContent.innerHTML = `
        <div style="color: black;">
          <h3>Review Request for ${data.full_name}</h3>
          <h4>Requested Schedule:</h4>
          ${requestedScheduleHtml}
          <p><strong>Note:</strong> ${data.note || 'No note provided.'}</p>
          <hr>
        </div>
        <button class="btn btn-success" onclick="handleRequest(${data.id}, 'approve')">Approve</button>
        <button class="btn btn-danger" onclick="handleRequest(${data.id}, 'reject')">Reject</button>
      `;
    });
  });

  closeBtn.onclick = () => {
    modal.style.display = "none";
  };
  window.onclick = e => {
    if (e.target === modal) {
      modal.style.display = "none";
    }
  };
});

// Global function to handle approve/reject actions
function handleRequest(id, action) {
  fetch("handle_schedule_request.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action, request_id: id })
  })
  .then(response => {
    if (!response.ok) {
        return response.text().then(text => {
          throw new Error(`Server responded with status ${response.status}: ${text}`);
        });
    }
    return response.json();
  })
  .then(data => {
    if (data.success === true) {
        alert(data.message);
        location.reload();
    } else {
        alert("Server Error: " + (data.message || "An unknown error occurred."));
    }
  })
  .catch(err => {
    console.error("Failed to process request:", err);
    alert("A critical error occurred. Please check the browser console for details.\n\nError: " + err.message);
  });
}
</script>


<style>
.modal-edit {
  display: none;
  position: fixed;
  z-index: 1001;
  left: 0; top: 0;
  width: 100%; height: 100%;
  background: rgba(0, 0, 0, 0.6);
}
.modal-content-edit {
  background: #2b2b2b;
  color: #f0f0f0;
  margin: 10% auto;
  padding: 20px;
  width: 500px;
  border-radius: 10px;
  box-shadow: 0 0 10px #000;
}
.modal-content-edit h3 {
  color: black;
}
.modal-content-edit button {
  margin: 10px 5px 0 0;
}
</style>



<!-- Modal Styling -->
<style>
    .unreg-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.5);
        transition: all 0.3s ease-in-out;
        padding: 1rem;
    }

    .unreg-modal-content {
        background-color: #fff;
        margin: 10% auto;
        padding: 2rem;
        border-radius: 12px;
        width: 90%;
        max-width: 400px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        animation: unreg-fadeIn 0.4s ease;
    }

    .unreg-modal-content h2 {
        margin-top: 0;
        font-size: 1.5rem;
        color: #333;
    }

    .unreg-modal-content label {
        display: block;
        margin-top: 1rem;
        margin-bottom: 0.5rem;
        font-weight: bold;
        color: #555;
    }

    .unreg-modal-content input[type="text"] {
        width: 100%;
        padding: 0.6rem;
        margin-bottom: 1rem;
        border: 1px solid #ccc;
        border-radius: 8px;
        box-sizing: border-box;
        font-size: 1rem;
    }

    .unreg-modal-content button {
        background-color: #4CAF50;
        color: white;
        padding: 0.6rem 1.2rem;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 1rem;
        width: 100%;
    }

    .unreg-modal-content button:hover {
        background-color: #45a049;
    }

    .unreg-close-btn {
        float: right;
        font-size: 1.5rem;
        font-weight: bold;
        color: #aaa;
        cursor: pointer;
    }

    .unreg-close-btn:hover {
        color: #000;
    }

    @keyframes unreg-fadeIn {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 480px) {
        .unreg-modal-content {
            padding: 1.5rem;
        }

        .unreg-modal-content h2 {
            font-size: 1.25rem;
        }

        .unreg-modal-content button {
            font-size: 0.95rem;
        }
    }
</style>

<!-- Script -->
<script>
    function showUnregisteredModal() {
        document.getElementById('unreg-modal').style.display = 'block';
    }

    function closeUnregisteredModal() {
        document.getElementById('unreg-modal').style.display = 'none';
    }

    function handleExport() {
  const format = document.getElementById("exportFormat").value;
  if (!format) return;

  const hiddenSection = document.querySelector(".hide-on-open");
  hiddenSection.classList.remove("hide-on-open");

  setTimeout(() => {
    if (format === "excel") exportTableToExcel();
    else if (format === "pdf") exportToPDF();

    setTimeout(() => {
      hiddenSection.classList.add("hide-on-open");
    }, 200);
  }, 100);
}



    
</script>




 



    <style>
    .modal-edit {
  display: none;
  position: fixed;
  z-index: 9999;
  left: 0; top: 0;
  width: 100%; height: 100%;
  overflow-y: auto;
  background-color: rgba(0, 0, 0, 0.4);
}
     .modal-view {
  display: none;
  position: fixed;
  z-index: 9999;
  left: 0; top: 0;
  width: 100%; height: 100%;
  overflow-y: auto;
  background-color: rgba(0, 0, 0, 0.4);
}

.modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow-y: auto; background-color: rgba(0, 0, 0, 0.4); } .modal-content { background-color: #fff; margin: 30px auto; padding: 20px; width: fit-content; max-width: 500px; border-radius: 10px; }
.modal-content-edit {
  background-color: #fff;
  margin: 30px auto;
  padding: 20px;
  width: 90%;
  max-width: 1000px;
  border-radius: 10px;
}

.view-schedule-wrapper {
  overflow-x: auto;
  margin-top: 15px;
}

#viewScheduleTable {
  border-collapse: collapse;
  width: 100%;
  table-layout: fixed;
}

#viewScheduleTable th,
#viewScheduleTable td {
  border: 1px solid #ccc;
  text-align: center;
  padding: 10px;
  vertical-align: top;
  width: 16.66%;
}

.view-patient {
  margin: 4px 0;
  border-radius: 6px;
  padding: 6px;
  font-weight: bold;
  font-size: 14px;
}

.shift-green {
  background-color: green;
  color: white;
}

.shift-gold {
  background-color: gold;
  color: black;
}

.close {
  float: right;
  font-size: 26px;
  font-weight: bold;
  cursor: pointer;
}

.hide-on-open {
  display: none !important;
}





@media screen and (max-width: 768px) {
    .modal-content {
        width: 95%;
        padding: 15px;
    }

    .day-card {
        padding: 10px;
    }

    .day-card h4 {
        font-size: 15px;
    }

    .day-card label {
        font-size: 13px;
    }

    .day-card input,
    .day-card select {
        font-size: 13px;
        padding: 4px 6px;
    }

    .btn {
        width: 100%;
        font-size: 14px;
        padding: 10px;
    }
}

.close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
}

.close:hover,
.close:focus {
    color: black;
    text-decoration: none;
    cursor: pointer;
}

.schedule-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
    margin-top: 20px;
    /* --- ADD THESE THREE LINES --- */
    max-height: 60vh; /* Sets max height to 60% of the viewport height */
    overflow-y: auto;   /* Adds a scrollbar only when needed */
    padding-right: 10px;/* Adds a little space for the scrollbar */
}

.day-card {
    background-color: #f9f9f9;
    padding: 15px;
    border-radius: 10px;
    border: 1px solid #ccc;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.day-card h4 {
    margin-top: 0;
    margin-bottom: 10px;
    font-size: 16px;
    color: #333;
}

.day-card label {
    display: block;
    margin-bottom: 8px;
    font-size: 14px;
    color: #444;
}

.day-card input,
.day-card select {
    width: 100%;
    padding: 5px 8px;
    margin-top: 2px;
    font-size: 14px;
    border-radius: 5px;
    border: 1px solid #ccc;
}

.btn {
    margin-top: 20px;
    background-color: #2c7be5;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 16px;
    cursor: pointer;
}

.btn:hover {
    background-color: #215db5;
}

    </style>





   <!-- BEGIN: Modal Script -->
<script>


let deleteTimer = null;
let countdownInterval = null;
  
const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

const modal = document.getElementById("scheduleModal");
const closeModal = document.getElementById("closeModal");
const message = document.getElementById("message");




// Close modal
closeModal.onclick = () => modal.style.display = "none";
window.onclick = event => { if (event.target == modal) modal.style.display = "none"; };

<?php if (isset($_SESSION['flash_message'])): ?>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    showToast("<?= addslashes($_SESSION['flash_message']) ?>", "", "<?= $_SESSION['flash_type'] ?? 'success' ?>");
  });
</script>
<?php 
unset($_SESSION['flash_message']);
unset($_SESSION['flash_type']);
endif; 
?>

document.addEventListener("DOMContentLoaded", () => {
  const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
  const modal = document.getElementById("scheduleModal");

  if (!modal) {
    console.error("Modal element #scheduleModal not found");
    return;
  }

  document.querySelectorAll('.edit-btn').forEach(button => {
    button.addEventListener('click', () => {
      const patientId = button.getAttribute('data-patient-id');
      const unregisteredId = button.getAttribute('data-unregistered-id');

      const patientInput = document.getElementById("modalPatientId");
      const unregisteredInput = document.getElementById("modalUnregisteredId");

      if (!patientInput || !unregisteredInput) {
        console.error("Hidden input fields not found");
        return;
      }

      patientInput.value = patientId;
      unregisteredInput.value = unregisteredId;

      const idParam = patientId && patientId !== "null"
        ? `patient_id=${patientId}`
        : `unregistered_id=${unregisteredId}`;

      fetch('./fetch_schedule.php?' + idParam)
        .then(response => {
          if (!response.ok) {
            throw new Error("HTTP status " + response.status);
          }
          return response.json();
        })
        .then(json => {
          if (json.status === 'success') {
    const data = json.data;
    const swappedDays = json.swapped_days || {};

    days.forEach(day => {
        const shiftField = document.getElementById(`${day}_shift`);
        const timeField = document.getElementById(`${day}_start_time`);
        const durationField = document.getElementById(`${day}_duration`);
        const clearBtn = document.getElementById(`clear_${day}_btn`);
        const durHours = document.getElementById(`${day}_duration_hours`);
        const durMins = document.getElementById(`${day}_duration_mins`);

        if (!shiftField || !timeField || !durationField || !clearBtn || !durHours || !durMins) {
            console.warn(`One or more fields missing for ${day}`);
            return;
        }

        // Reset fields before populating
        shiftField.disabled = false;
        timeField.disabled = false;
        durHours.disabled = false;
        durMins.disabled = false;

        // Populate shift
        shiftField.value = data[`${day}_shift`] || "";

        if (data[`${day}_start_time`] && data[`${day}_duration`] && !isNaN(data[`${day}_duration`])) {
            const durationMinutes = Number(data[`${day}_duration`]);
            const h = Math.floor(durationMinutes / 60);
            const m = durationMinutes % 60;

            durHours.value = h;
            durMins.value = (m === 0 ? "" : m);
            durationField.value = durationMinutes;
            timeField.value = data[`${day}_start_time`].slice(0, 5);
            timeField.dispatchEvent(new Event('input'));
        } else {
            durationField.value = "";
            durHours.value = "";
            durMins.value = "";
            timeField.value = "";
            const endTimeField = document.getElementById(`${day}_end_time`);
            endTimeField.value = "";
            endTimeField.disabled = true;
        }

        const hasShift = !!data[`${day}_shift`];
        const isSwapped = !!swappedDays[day];

        // Disable fields if it has no shift OR if it is swapped
        timeField.disabled = !hasShift || isSwapped;
        durHours.disabled = !hasShift || isSwapped;
        durMins.disabled = !hasShift || isSwapped;
        shiftField.disabled = isSwapped; // Also disable the shift dropdown

        // Show clear button only if there's a shift AND it's NOT swapped
        clearBtn.style.display = (hasShift && !isSwapped) ? 'inline-block' : 'none';
    });

    modal.style.display = 'block';
} else {
    showToast("Error: " + json.message, "", "error");
}
        })
        .catch((error) => {
          console.error("Fetch failed:", error);
          showToast("Failed to load schedule.", "", "error");
        });
    });
  });
});




// Validate start time per shift
function validateStartTimeRange(input) {
    const day = input.id.split('_')[0];
    const shift = document.getElementById(`${day}_shift`).value;
    const time = input.value;

    if (shift === 'First Shift' && (time < '06:00' || time > '09:00')) {
        showToast(`${day.charAt(0).toUpperCase() + day.slice(1)} First Shift: 6:00AM to 9:00AM only.`, "", "warning");
        input.value = '';
    } else if (shift === 'Second Shift' && (time < '11:00' || time > '14:00')) {
        showToast(`${day.charAt(0).toUpperCase() + day.slice(1)} Second Shift: 11:00AM to 2:00PM only.`, "", "warning");
        input.value = '';
    }
}

document.querySelectorAll('input[type="time"]').forEach(input => {
    input.addEventListener('change', () => validateStartTimeRange(input));
});

// Shift change logic: Always clear time/duration and toggle clear + input states
days.forEach(day => {
  const shiftSelect = document.getElementById(`${day}_shift`);
  const timeInput = document.getElementById(`${day}_start_time`);
  const durHours = document.getElementById(`${day}_duration_hours`);
  const durMins = document.getElementById(`${day}_duration_mins`);
  const hiddenDur = document.getElementById(`${day}_duration`);
  const endInput = document.getElementById(`${day}_end_time`);
  const clearBtn = document.getElementById(`clear_${day}_btn`);

  shiftSelect.addEventListener('change', (e) => {
    const selected = e.target.value;

    // Always clear all values first
    timeInput.value = "";
    endInput.value = "";
    durHours.value = "";
    durMins.value = "";
    hiddenDur.value = "";

    if (selected) {
      // Enable all fields if a shift is selected
      timeInput.disabled = false;
      endInput.disabled = false;
      durHours.disabled = false;
      durMins.disabled = false;
      clearBtn.style.display = 'inline-block';
    } else {
      // Disable everything if shift cleared
      timeInput.disabled = true;
      endInput.disabled = true;
      durHours.disabled = true;
      durMins.disabled = true;
      clearBtn.style.display = 'none';
    }
  });
});


// Duration Inputs → End Time Calculation + Shift Validation
const shiftLimits = {
  "First Shift": { start: "06:00", end: "10:00" },
  "Second Shift": { start: "11:00", end: "15:00" }
};

days.forEach(day => {
  const startInput = document.getElementById(`${day}_start_time`);
  const durHours = document.getElementById(`${day}_duration_hours`);
  const durMins = document.getElementById(`${day}_duration_mins`);
  const hiddenDur = document.getElementById(`${day}_duration`);
  const endInput = document.getElementById(`${day}_end_time`);
  const shiftInput = document.getElementById(`${day}_shift`);

  function updateEndTime() {
  // If both fields are empty → clear everything
  if (!startInput.value || (durHours.value === "" && durMins.value === "")) {
    endInput.value = "";
    endInput.disabled = true;
    hiddenDur.value = "";
    delete hiddenDur.dataset.mins;
    return;
  }

  // Parse values
  const h = durHours.value !== "" ? parseInt(durHours.value) : 0;
  const m = durMins.value !== "" ? parseInt(durMins.value) : 0;

  // If only hours filled → treat as 60 minutes per hour
  const totalMins = (durHours.value !== "" ? h * 60 : 0) + (durMins.value !== "" ? m : 0);

  if (totalMins === 0) {
    endInput.value = "";
    endInput.disabled = true;
    hiddenDur.value = "";
    return;
  }

  hiddenDur.value = totalMins;
  hiddenDur.dataset.mins = totalMins;

  // Compute end time
  const [sH, sM] = startInput.value.split(":").map(Number);
  const end = new Date(0, 0, 0, sH, sM + totalMins);
  endInput.value = `${String(end.getHours()).padStart(2, '0')}:${String(end.getMinutes()).padStart(2, '0')}`;
  endInput.disabled = false;

  // Shift validation
  const shift = shiftInput.value;
  if (shift && shiftLimits[shift]) {
    const { start, end: limitEnd } = shiftLimits[shift];
    if (startInput.value < start || endInput.value > limitEnd) {
      showToast(`${day.charAt(0).toUpperCase() + day.slice(1)} exceeds ${shift} limit`, "", "warning");
      durHours.value = "";
      durMins.value = "";
      hiddenDur.value = "";
      endInput.value = "";
      endInput.disabled = true;
    }
  }
}


  // Trigger recalculation when any field changes
  startInput.addEventListener("input", updateEndTime);
  durHours.addEventListener("input", updateEndTime);
  durMins.addEventListener("input", updateEndTime);
});








// Clear buttons logic (per-day)
days.forEach(day => {
  const clearBtn = document.getElementById(`clear_${day}_btn`);
  clearBtn.addEventListener('click', () => {
    document.getElementById(`${day}_shift`).value = "";
    document.getElementById(`${day}_start_time`).value = "";
    document.getElementById(`${day}_end_time`).value = "";
    document.getElementById(`${day}_duration_hours`).value = "";
    document.getElementById(`${day}_duration_mins`).value = "";
    document.getElementById(`${day}_duration`).value = "";

    // Disable all fields after clear
    document.getElementById(`${day}_start_time`).disabled = true;
    document.getElementById(`${day}_end_time`).disabled = true;
    document.getElementById(`${day}_duration_hours`).disabled = true;
    document.getElementById(`${day}_duration_mins`).disabled = true;

    clearBtn.style.display = 'none';
  });
});


function validateScheduleForm() {
  const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
  const shiftEndLimits = {
    'First Shift': '10:00',
    'Second Shift': '15:00'
  };

  for (const day of days) {
    const shift = document.getElementById(`${day}_shift`).value;
    const startTime = document.getElementById(`${day}_start_time`).value;
    const durationField = document.getElementById(`${day}_duration`);
    const durationMins = durationField && durationField.value !== "" ? parseInt(durationField.value) : 0;

    if (shift && (!startTime || durationMins === 0)) {
      showToast(`Missing fields for ${day.charAt(0).toUpperCase() + day.slice(1)}`, "", "warning");
      return false;
    }

    if (shift && startTime && durationMins > 0) {
      const [sH, sM] = startTime.split(':').map(Number);
      const startTotal = sH * 60 + sM;
      const endTotal = startTotal + durationMins;

      if (endTotal <= startTotal) {
        showToast(`${day.charAt(0).toUpperCase() + day.slice(1)} End Time must be after Start Time.`, "", "error");
        return false;
      }

      // Ensure it does not go beyond shift end
      const [limitH, limitM] = shiftEndLimits[shift].split(':').map(Number);
      const endLimitMins = limitH * 60 + limitM;
      if (endTotal > endLimitMins + 1) { // Allow 1 minute buffer for calculation rounding
    showToast(`${day.charAt(0).toUpperCase() + day.slice(1)} exceeds ${shift} limit (${shiftEndLimits[shift]}).`, "", "error");
    return false;
}

      // ✅ Always store the correct total minutes
      durationField.value = durationMins;
    }
  }
  return true;
}








const clearBtn = document.getElementById("clearAllScheduleBtn");

clearBtn.addEventListener("click", () => {
    if (confirm("Are you sure you want to clear the entire schedule?")) {
        const patientId = document.getElementById("modalPatientId").value;
        const unregId = document.getElementById("modalUnregisteredId").value;

        let formData = '';
        if (patientId && patientId !== 'null') {
            formData = `patient_id=${patientId}`;
        } else if (unregId && unregId !== 'null') {
            formData = `unregistered_id=${unregId}`;
        } else {
            showToast("No patient selected.", "", "error");
            return;
        }

       fetch('clear_schedule.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: formData
})
.then(async res => {
    const text = await res.text();
    try {
        const json = JSON.parse(text);
        console.log("Clear response:", json);

        if (json.success) {
            showToast(json.message || "Schedule cleared successfully.", "", "success");
            modal.style.display = 'none';
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(json.message || "Failed to clear schedule.", "", "error");
        }
    } catch (e) {
        console.error("Invalid JSON from clear_schedule.php:", text);
        showToast("Invalid response from server.", "", "error");
    }
})
.catch(error => {
    console.error("Clear fetch failed:", error);
    showToast("Clear request failed.", "", "error");
});


    }
});



// Utility - toggle views
function toggleView(view) {
    if (view === 'manage') {
        document.getElementById('manageSchedulesView').style.display = 'block';
        document.getElementById('viewScheduleView').style.display = 'none';
    } else {
        document.getElementById('manageSchedulesView').style.display = 'none';
        document.getElementById('viewScheduleView').style.display = 'block';
    }
}

let currentFilter = 'Scheduled';

function toggleDropdown() {
    const dropdown = document.getElementById('dropdownList');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

function setFilter(status) {
    currentFilter = status;

    const allRows = Array.from(document.querySelectorAll('#manageSchedulesView tbody tr'));
    let statusCount = 0;

    // Count the rows matching the status
    allRows.forEach(row => {
        const rowStatus = row.cells[row.cells.length - 2].textContent.trim();
        if (rowStatus === status) statusCount++;
    });

    // Set the label on the dropdown button
    const label = status === 'All' ? 'All' : `${status} (${statusCount})`;
    document.getElementById('dropdownBtn').textContent = label;
    document.getElementById('dropdownList').style.display = 'none';

    // Apply the filters
    applyFilters();
}

function clearSearch() {
    document.getElementById('searchInput').value = '';
    applyFilters();
}

document.getElementById('searchInput').addEventListener('input', applyFilters);
document.addEventListener('DOMContentLoaded', () => {
    setFilter(currentFilter); // This ensures it shows count immediately

    applyFilters();
});

function applyFilters() {
    // 1. Get filter settings
    const settings = getFilterSettings();
    const search = document.getElementById('searchInput').value.toLowerCase();
    const tbody = document.querySelector('#manageSchedulesView tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const headers = document.querySelectorAll('#manageSchedulesView th[data-day-header]');
    let pendingCount = 0;

    // 2. Filter Columns (Days)
    headers.forEach(th => {
        const day = th.getAttribute('data-day-header');
        const show = settings.showAllDays || settings.selectedDays.includes(day);
        th.style.display = show ? '' : 'none';
    });

    // 3. Filter Rows and Cell Contents
    rows.forEach(row => {
        const name = row.cells[0].textContent.toLowerCase();
        const status = row.cells[row.cells.length - 2].textContent.trim();
        let hasVisibleContent = false;

        if (status === 'Pending') pendingCount++;

        // Filter cell content by shift and day
        row.querySelectorAll('td[data-day-cell]').forEach(cell => {
            const day = cell.getAttribute('data-day-cell');
            const showColumn = settings.showAllDays || settings.selectedDays.includes(day);
            cell.style.display = showColumn ? '' : 'none';

            if (showColumn) {
                const oblong = cell.querySelector('.oblong-group');
                if (oblong) {
                    let showOblong = false;
                    // Get the exact shift from the data attribute
                    const shiftType = oblong.getAttribute('data-shift');
                    
                    if (settings.shift === 'All') {
                        showOblong = true;
                    } else if (settings.shift === 'First Shift') {
                        // Show if data-shift is explicitly "First Shift"
                        if (shiftType === 'First Shift') showOblong = true;
                    } else if (settings.shift === 'Second Shift') {
                        // Show if data-shift is explicitly "Second Shift"
                        if (shiftType === 'Second Shift') showOblong = true;
                    }

                    oblong.style.display = showOblong ? 'inline-flex' : 'none';
                    if (showOblong) hasVisibleContent = true;
                }
            }
        });

        const matchName = name.includes(search);
        const matchStatus = (currentFilter === 'All' || status === currentFilter);

        // --- FIXED VISIBILITY LOGIC ---
        // We allow the row to show if the Name and Status match. 
        // We DO NOT hide the row just because the Shift Filter excludes their schedule.
        // This prevents the "Patient Disappeared" panic.
        const shouldShow = matchName && matchStatus;

        row.style.display = shouldShow ? '' : 'none';
    });

    // 4. Update Pending Badges
    const badge = document.getElementById('pendingBadge');
    badge.textContent = pendingCount;
    badge.style.display = pendingCount > 0 ? 'inline' : 'none';
    const notifBar = document.getElementById('pendingNotif');
    const notifText = document.getElementById('pendingCountDisplay');
    const dropdownPending = document.getElementById('dropdownPendingCount');
    dropdownPending.textContent = pendingCount > 0 ? `(${pendingCount})` : '';
    
    if (notifBar && notifText) {
        if (pendingCount > 0) {
            notifText.textContent = pendingCount;
            notifBar.style.display = 'inline-block';
        } else {
            notifBar.style.display = 'none';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    setFilter(currentFilter);
    applyFilters();

    // ✅ Show the view ONLY after filters are applied to prevent flicker
    document.getElementById('manageSchedulesView').style.display = 'block';
});

document.addEventListener('click', function (e) {
    if (!e.target.closest('.custom-dropdown')) {
        document.getElementById('dropdownList').style.display = 'none';
    }
});

document.getElementById('viewScheduleBtn').addEventListener('click', () => {
  document.getElementById('viewScheduleModal').style.display = 'block';
});

function exportToExcel(tableID, filename = '') {
  const table = document.getElementById(tableID).cloneNode(true);

  // Inline cell styles for shift backgrounds
  table.querySelectorAll('.shift-green').forEach(td => {
    td.style.backgroundColor = 'green';
    td.style.color = 'white';
  });
  table.querySelectorAll('.shift-gold').forEach(td => {
    td.style.backgroundColor = 'gold';
    td.style.color = 'black';
  });

  const html = `
    <html>
    <head><meta charset="UTF-8"></head>
    <body>${table.outerHTML}</body>
    </html>`;

  const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename ? `${filename}.xls` : 'schedule_export.xls';
  link.click();
}

// Delete modal logic
const deleteBtns = document.querySelectorAll('.delete-btn');
const deleteModal = document.getElementById('deleteChoiceModal');
const deletePatientId = document.getElementById('deletePatientId');

deleteBtns.forEach(btn => {
    btn.addEventListener('click', function () {
        const editBtn = this.parentElement.parentElement.querySelector('button.edit-btn');
        const patientId = editBtn.getAttribute('data-patient-id');
        const unregisteredId = editBtn.getAttribute('data-unregistered-id');

        deletePatientId.value = patientId || 'null';
        deletePatientId.setAttribute('data-unregistered-id', unregisteredId || '');
        deleteModal.style.display = 'block';
    });
});


function closeDeleteModal() {
    deleteModal.style.display = 'none';
}

function handleDeleteAction(actionType) {
    const patientId = deletePatientId.value;
    const unregisteredId = deletePatientId.getAttribute('data-unregistered-id');
    closeDeleteModal(); // Close the modal immediately

    if (actionType === 'archive') {
        // Archive logic remains the same (immediate)
        const formData = new URLSearchParams();
        formData.append('action', 'archive');
        if (patientId && patientId !== 'null') {
            formData.append('patient_id', patientId);
        } else if (unregisteredId) {
            formData.append('unregistered_id', unregisteredId);
        } else {
            showToast("No patient selected.", "", "error");
            return;
        }
        
        fetch('delete_schedule.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(async res => { // Added async for better error handling
            const text = await res.text();
            try {
                const json = JSON.parse(text);
                return { success: res.ok, ...json };
            } catch (e) {
                console.error("Invalid JSON from delete_schedule.php (archive):", text);
                return { success: false, message: "Invalid server response." };
            }
        })
        .then(json => {
            if (json.success) {
                showToast(json.message || "Schedule updated.", "", "success");
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(json.message || "Failed to update schedule.", "", "error");
            }
        })
        .catch(error => {
            console.error("Archive fetch failed:", error);
            showToast("Archive request failed.", "", "error");
        });

    } else if (actionType === 'destroy') {
        // NEW: Initiate undo logic instead of fetching immediately
        initiateUndoDelete(patientId, unregisteredId);
    }
}

// ADD THIS NEW FUNCTION
function performHardDelete(patientId, unregisteredId) {
    const formData = new URLSearchParams();
    formData.append('action', 'destroy');

    if (patientId && patientId !== 'null') {
        formData.append('patient_id', patientId);
    } else if (unregisteredId) {
        formData.append('unregistered_id', unregisteredId);
    } else {
        showToast("No patient selected.", "", "error");
        return;
    }

    fetch('delete_schedule.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async res => {
        // This detailed error handling will fix the user's toast bug
        const text = await res.text();
        try {
            const json = JSON.parse(text);
             // Ensure success property is correctly evaluated
            if (!res.ok || json.success === false) {
                 throw new Error(json.message || "Failed to destroy schedule.");
            }
            return json;
        } catch (e) {
            console.error("Invalid JSON from delete_schedule.php (destroy):", text);
            // Re-throw the error message from json if it exists, otherwise use a default
            throw new Error(e.message.includes("Failed to destroy") ? e.message : "Invalid server response.");
        }
    })
    .then(json => {
        console.log("Delete response:", json);
        // This is the correct success toast
        showToast(json.message || "Schedule destroyed successfully.", "", "success");
        setTimeout(() => location.reload(), 1200);
    })
    .catch(error => {
        console.error("Destroy fetch failed:", error);
        // This is the correct error toast
        showToast(error.message || "Destroy request failed.", "", "error");
    });
}

// ADD THIS SECOND NEW FUNCTION
// REPLACE THIS ENTIRE FUNCTION
function initiateUndoDelete(patientId, unregisteredId) {
    // Clear any previous timers
    if (deleteTimer) clearTimeout(deleteTimer);
    if (countdownInterval) clearInterval(countdownInterval);

    let countdown = 5;
    const toast = document.getElementById("toast");

    // Show the undo toast with both buttons
    toast.innerHTML = `
        Destroying schedule in <span id="undo-countdown" style="font-weight:bold;">${countdown}</span>s...
        <button id="destroy-now-btn" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; margin-left: 15px;">Destroy Now</button>
        <button id="undo-btn" style="background: #007bff; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; margin-left: 5px;">Undo</button>
    `;
    toast.className = "show warning"; // Use 'warning' style for attention
    window.scrollTo(0, 0);

    // Add click listener for the new Destroy Now button
    document.getElementById("destroy-now-btn").onclick = function() {
        clearTimeout(deleteTimer);
        clearInterval(countdownInterval);
        deleteTimer = null;
        countdownInterval = null;
        toast.className = ""; // Hide toast
        performHardDelete(patientId, unregisteredId); // Fire the delete immediately
    };

    // Add click listener for the Undo button
    document.getElementById("undo-btn").onclick = function() {
        clearTimeout(deleteTimer);
        clearInterval(countdownInterval);
        deleteTimer = null;
        countdownInterval = null;
        toast.className = ""; // Hide toast
        showToast("Destroy canceled.", "", "info");
    };

    // Start the 1-second countdown interval
    countdownInterval = setInterval(() => {
        countdown--;
        const countdownSpan = document.getElementById("undo-countdown");
        if (countdownSpan) {
            countdownSpan.innerText = countdown;
        }
        if (countdown <= 0) {
            clearInterval(countdownInterval);
            countdownInterval = null;
        }
    }, 1000);

    // Start the 5-second "bomb" timer
    deleteTimer = setTimeout(() => {
        if (toast.className.includes("show")) { // Only delete if toast is still active
            toast.className = ""; // Hide undo toast
            performHardDelete(patientId, unregisteredId); // Fire the delete
        }
        deleteTimer = null;
    }, 5000);
}


window.onclick = function(event) {
    if (event.target == deleteModal) {
        deleteModal.style.display = 'none';
    }
};

// --- BEGIN: SWAP SCHEDULE SCRIPT ---
const swapModal = document.getElementById("swapScheduleModal");
let swappableData = {};

function openSwapModal() {
    fetch('get_swappable_data.php')
        .then(res => res.json())
        .then(result => {
            if (result.status === 'success') {
                swappableData = result.data;
                populateSwapPatientDropdown('patient1_id');
                populateSwapPatientDropdown('patient2_id');
                swapModal.style.display = 'block';
            } else {
                showToast('Failed to load patient data for swapping.', '', 'error');
            }
        }).catch(() => showToast('Error fetching patient data.', '', 'error'));
}

function closeSwapModal() {
    swapModal.style.display = 'none';
    // Clear details on close
    document.getElementById('patient1_details').innerHTML = '<span style="color: #888;">Select a patient and day to see details.</span>';
    document.getElementById('patient2_details').innerHTML = '<span style="color: #888;">Select a patient and day to see details.</span>';
}

function populateSwapPatientDropdown(selectId, excludeId = null) {
    const select = document.getElementById(selectId);
    const currentValue = select.value; // Keep track of the currently selected value
    select.innerHTML = '<option value="">-- Select Patient --</option>';
    for (const patientId in swappableData) {
        if (patientId == excludeId) { // Check against the patient to exclude
            continue;
        }
        const patient = swappableData[patientId];
        // Keep the existing selection if it's still valid
        const selected = (patientId == currentValue) ? ' selected' : '';
        select.innerHTML += `<option value="${patientId}"${selected}>${patient.full_name}</option>`;
    }
}

function populateSwapDayDropdown(selectId, patientId, detailsContainerId) {
    const select = document.getElementById(selectId);
    const detailsContainer = document.getElementById(detailsContainerId);
    select.innerHTML = '<option value="">-- Select Day --</option>';
    detailsContainer.innerHTML = '<span style="color: #888;">Select a day to see details.</span>';
    // Reset details
    if (patientId && swappableData[patientId]) {
        swappableData[patientId].scheduled_days.forEach(day => {
            select.innerHTML += `<option value="${day}">${day.charAt(0).toUpperCase() + day.slice(1)}</option>`;
        });
    }
}

function displaySwapDetails(patientId, day, detailsContainerId) {
    const detailsContainer = document.getElementById(detailsContainerId);
    if (!patientId || !day) {
        detailsContainer.innerHTML = '<span style="color: #888;">Select a day to see details.</span>';
        return;
    }
    detailsContainer.innerHTML = '<em>Loading...</em>';

    fetch(`./fetch_schedule.php?patient_id=${patientId}`)
        .then(response => response.json())
        .then(json => {
            if (json.status === 'success' && json.data) {
                const data = json.data;
                const shift = data[`${day}_shift`];
                const start = data[`${day}_start_time`];
                const duration = data[`${day}_duration`];
                
                if (shift) {
                    const startTime = new Date(`1970-01-01T${start}`);
                    const endTime = new Date(startTime.getTime() + duration * 60000);
                    const formatTime = (date) => date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

                    detailsContainer.innerHTML = `
                        <strong>Shift:</strong> ${shift}<br>
                        <strong>Time:</strong> ${formatTime(startTime)} - ${formatTime(endTime)}<br>
                        <strong>Duration:</strong> ${duration} minutes
                    `;
                } else {
                    detailsContainer.innerHTML = '<span style="color: #888;">No schedule on this day.</span>';
                }
            } else {
                 detailsContainer.innerHTML = '<span style="color: red;">Could not load details.</span>';
            }
        })
        .catch(error => {
            console.error("Fetch failed:", error);
            detailsContainer.innerHTML = '<span style="color: red;">Error loading details.</span>';
        });
}

document.getElementById('patient1_id').addEventListener('change', function() {
    const selectedPatient1 = this.value;
    // Repopulate the second dropdown, excluding the patient selected here
    populateSwapPatientDropdown('patient2_id', selectedPatient1);
    // Populate the available days for patient 1
    populateSwapDayDropdown('patient1_day', selectedPatient1, 'patient1_details');
});

document.getElementById('patient2_id').addEventListener('change', function() {
    const selectedPatient2 = this.value;
    // Repopulate the first dropdown, excluding the patient selected here
    populateSwapPatientDropdown('patient1_id', selectedPatient2);
    // Populate the available days for patient 2
    populateSwapDayDropdown('patient2_day', selectedPatient2, 'patient2_details');
});

document.getElementById('patient1_day').addEventListener('change', function() {
    const patientId = document.getElementById('patient1_id').value;
    displaySwapDetails(patientId, this.value, 'patient1_details');
});

document.getElementById('patient2_day').addEventListener('change', function() {
    const patientId = document.getElementById('patient2_id').value;
    displaySwapDetails(patientId, this.value, 'patient2_details');
});

document.getElementById('swapScheduleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    fetch('perform_swap.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, '', 'success');
            closeSwapModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'An unknown error occurred.', '', 'error');
        }
    })
    .catch(() => showToast('Request failed. Please try again.', '', 'error'));
});

function revertSwap(swapId) {
    if (!confirm('Are you sure you want to revert this schedule swap?')) {
        return;
    }
    const formData = new FormData();
    formData.append('swap_id', swapId);
    fetch('revert_swap.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, '', 'success');
            const row = document.getElementById('swap-row-' + swapId);
            if (row) row.style.display = 'none';
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'An unknown error occurred.', '', 'error');
        }
    })
    .catch(() => showToast('Request failed. Please try again.', '', 'error'));
}
// --- END: SWAP SCHEDULE SCRIPT ---

</script>
<!-- END: Modal Script -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.17.0/xlsx.full.min.js"></script>
<script>
function exportTableToExcel() {
  const wb = XLSX.utils.book_new();
  const ws_data = [["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"]];
  const columns = document.querySelectorAll("#viewScheduleTable tbody tr td");

  const shiftData = Array.from(columns).map(col => {
    const firstShift = [];
    const secondShift = [];

    col.querySelectorAll(".view-patient").forEach(div => {
      const divClone = div.cloneNode(true);
divClone.querySelector('small')?.remove();
const name = divClone.innerText.trim();



      const shift = div.querySelector("small")?.innerText.trim();

      if (shift === "First Shift") firstShift.push(name);
      else if (shift === "Second Shift") secondShift.push(name);
    });

    return {
      first: firstShift,
      second: secondShift
    };
  });

  const maxRows = Math.max(...shiftData.map(day => 
    1 + day.first.length + 1 + 1 + day.second.length
  ));

  for (let i = 0; i < maxRows; i++) {
    const row = [];

    shiftData.forEach(day => {
      if (i === 0) row.push("First Shift:");
      else if (i > 0 && i <= day.first.length) row.push(day.first[i - 1]);
      else if (i === day.first.length + 1) row.push(""); // spacer
      else if (i === day.first.length + 2) row.push("Second Shift:");
      else if (i > day.first.length + 2 && i <= day.first.length + 2 + day.second.length) {
        row.push(day.second[i - (day.first.length + 3)]);
      } else {
        row.push("");
      }
    });

    ws_data.push(row);
  }

  const ws = XLSX.utils.aoa_to_sheet(ws_data);
  ws["!cols"] = Array(6).fill({ wch: 25 });
  XLSX.utils.book_append_sheet(wb, ws, "WeeklySchedule");

  const now = new Date();
  const timestamp = now.toISOString().replace(/[-:T]/g, "_").split(".")[0];
  const filename = `weekly_schedule_${timestamp}.xlsx`;

  XLSX.writeFile(wb, filename);
}

</script>

<!-- PDF export via html2pdf -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
<script>
function printViewSchedule() {
  const table = document.getElementById("viewScheduleTable");
  const clone = table.cloneNode(true);

  // Reconstruct each cell content
  const tds = clone.querySelectorAll("td");
  tds.forEach(cell => {
    const shifts = { first: [], second: [] };
    const divs = cell.querySelectorAll(".view-patient");

    divs.forEach(div => {
     const divClone = div.cloneNode(true);
divClone.querySelector('small')?.remove();
const name = divClone.innerText.trim();

      const shift = div.querySelector("small")?.innerText.trim();
      if (shift === 'First Shift') shifts.first.push(name);
      else if (shift === 'Second Shift') shifts.second.push(name);
    });

    cell.innerHTML = '<strong>First Shift:</strong><br>' + (shifts.first.join('<br>') || '-') +
                     '<br><br><strong>Second Shift:</strong><br>' + (shifts.second.join('<br>') || '-');
  });

  const style = `
    <style>
      table { border-collapse: collapse; width: 100%; }
      th, td { border: 1px solid #ccc; padding: 8px; text-align: center; }
    </style>
  `;

  const win = window.open("", "", "height=800,width=1000");
  win.document.write("<html><head><title>Print</title>");
  win.document.write(style);
  win.document.write("</head><body><h2 style='text-align:center;'>Weekly Dialysis Schedule</h2>");
  win.document.write(clone.outerHTML);
  win.document.write("</body></html>");
  win.document.close();
  win.print();
}

</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>
<script>
function exportToPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: "portrait" });

  const table = document.getElementById("viewScheduleTable").cloneNode(true);
  const days = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const data = [];

  const columns = table.querySelectorAll("tbody tr td");

  columns.forEach(col => {
    const firstShift = [];
    const secondShift = [];

    col.querySelectorAll(".view-patient").forEach(div => {
      const divClone = div.cloneNode(true);
divClone.querySelector('small')?.remove();
const name = divClone.innerText.trim();

      const shift = div.querySelector("small")?.innerText.trim();

      if (shift === "First Shift") firstShift.push(name);
      else if (shift === "Second Shift") secondShift.push(name);
    });

    data.push({ first: firstShift, second: secondShift });
  });

  const body = [];
  const maxRows = Math.max(...data.map(day =>
    1 + day.first.length + 1 + 1 + day.second.length
  ));

  for (let i = 0; i < maxRows; i++) {
    const row = [];

    data.forEach(day => {
      if (i === 0) row.push("First Shift:");
      else if (i > 0 && i <= day.first.length) row.push(day.first[i - 1]);
      else if (i === day.first.length + 1) row.push(""); // spacer
      else if (i === day.first.length + 2) row.push("Second Shift:");
      else if (i > day.first.length + 2 && i <= day.first.length + 2 + day.second.length) {
        row.push(day.second[i - (day.first.length + 3)]);
      } else {
        row.push("");
      }
    });

    body.push(row);
  }

  const headers = [days];

  // Title and Timestamp
  const now = new Date();
  const exportDate = now.toLocaleDateString('en-US', {
    year: 'numeric', month: 'long', day: 'numeric'
  });
  const exportTime = now.toLocaleTimeString('en-US', {
    hour: '2-digit', minute: '2-digit'
  });

  doc.setFontSize(14);
  doc.text("Padre Pio Kidney Care Center Cainta Weekly Schedule", doc.internal.pageSize.getWidth() / 2, 18, { align: "center" });

  doc.setFontSize(10);
  doc.text(`as of ${exportDate}, time exported: ${exportTime}`, doc.internal.pageSize.getWidth() / 2, 30, { align: "center" });

  doc.autoTable({
    head: headers,
    body: body,
    startY: 40,
    styles: {
      fontSize: 8,
      cellPadding: 2,
      valign: 'middle',
      halign: 'center'
    },
    theme: 'striped'
  });

  const fileName = `weekly_schedule_${now.toISOString().replace(/[-:T]/g, "_").split(".")[0]}.pdf`;
  doc.save(fileName);
}

</script>


<script>

function showToast(message, position = '', type = '') {
  const toast = document.getElementById("toast");
  toast.innerHTML = message;
  toast.className = "show";
  toast.classList.add(type);
  window.scrollTo(0, 0); // ⬅ scroll to top to ensure visibility

  setTimeout(() => {
    toast.classList.remove("show");
    toast.classList.remove(type);
  }, 3200);
}





</script>








<?php if (isset($_SESSION['flash_message'])): ?>
  <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
  <script>
    window.addEventListener('load', function () {
      swal({
        title: "<?= ucfirst($_SESSION['flash_type']) ?>",
        text: "<?= addslashes($_SESSION['flash_message']) ?>",
        icon: "<?= $_SESSION['flash_type'] ?>"
      });
    });
  </script>
  <?php 
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
  ?>
<?php endif; ?>


<script>
  window.addEventListener('load', function () {
    document.getElementById('loader').style.display = 'none';
    document.getElementById('content').style.display = 'block';
    document.body.style.overflow = 'auto';
  });
</script>




<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('editScheduleForm');
  const modal = document.getElementById("scheduleModal");
  const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

  function validateDurationLimits() {
    let valid = true;

    const shiftEndTime = {
      'First Shift': '10:00',
      'Second Shift': '15:00'
    };

    for (const day of days) {
      const shift = document.getElementById(`${day}_shift`).value;
      const start = document.getElementById(`${day}_start_time`).value;
      const durationVal = document.getElementById(`${day}_duration`);
      const duration = parseInt(durationVal.value || "0");

      if (shift && start && duration) {
        const [startHour, startMin] = start.split(':').map(Number);
        const startMins = startHour * 60 + startMin;

        const [limitHour, limitMin] = shiftEndTime[shift].split(':').map(Number);
        const shiftLimit = limitHour * 60 + limitMin;

        const maxAllowed = shiftLimit - startMins;

        if (maxAllowed < 0 || duration > maxAllowed) {
          showToast(`${day.charAt(0).toUpperCase() + day.slice(1)} duration too long. Max: ${maxAllowed} mins.`, "", "warning");
          durationVal.style.border = '2px solid red';
          valid = false;
        } else {
          durationVal.style.border = '';
        }
      }
    }

    return valid;
  }

  function checkRequiredFields() {
    let isValid = true;

    for (const day of days) {
      const shift = document.getElementById(`${day}_shift`).value;
      const startTime = document.getElementById(`${day}_start_time`).value;
      const duration = document.getElementById(`${day}_duration`).value;

      if (shift && (startTime === "" || duration === "")) {
        showToast(`Missing fields for ${day.charAt(0).toUpperCase() + day.slice(1)}.`, "", "warning");
        isValid = false;
      }

      if (!shift && (startTime || duration)) {
        showToast(`Please select a shift first for ${day.charAt(0).toUpperCase() + day.slice(1)}.`, "", "warning");
        isValid = false;
      }
    }

    return isValid;
  }

  function hasChanges() {
    let changed = false;
    for (const day of days) {
      const shift = document.getElementById(`${day}_shift`).value;
      const start = document.getElementById(`${day}_start_time`).value;
      const duration = document.getElementById(`${day}_duration`).value;
      if (shift || start || duration) {
        changed = true;
        break;
      }
    }
    return changed;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault(); 

    // --- CRITICAL FIX: Force enable ALL select options ---
    // This ensures that even if the UI thought a slot was full/disabled,
    // the value you selected gets sent to the server.
    const allSelects = form.querySelectorAll('select');
    allSelects.forEach(sel => {
        sel.disabled = false; // Enable the select itself
        Array.from(sel.options).forEach(opt => opt.disabled = false); // Enable all options
    });

    // Calculate total minutes from Hrs/Mins inputs
    days.forEach(day => {
        const hoursInput = document.getElementById(`${day}_duration_hours`);
        const minutesInput = document.getElementById(`${day}_duration_mins`);
        const hiddenDurationInput = document.getElementById(`${day}_duration`);

        const hours = parseInt(hoursInput.value) || 0;
        const minutes = parseInt(minutesInput.value) || 0;
        const totalMinutes = (hours * 60) + minutes;

        if (totalMinutes > 0) {
            hiddenDurationInput.value = totalMinutes;
        } else {
            hiddenDurationInput.value = "";
        }
    });

    // Validate
    if (!validateScheduleForm()) return;
    if (!checkRequiredFields()) return;
    if (!validateDurationLimits()) return;
    if (!hasChanges()) {
      showToast("No changes made.", "", "warning");
      return;
    }

    // Submit
    const formData = new FormData(form);
    fetch('update_schedule.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(result => {
      if (result.status === 'success') {
        showToast(result.message, "✅", "success");
        setTimeout(() => window.location.reload(), 1200);
      } else {
        showToast(result.message || "An error occurred.", "❌", "error");
      }
    })
    .catch(() => {
      showToast("Failed to update schedule. Try again.", "❌", "error");
    });
  });
});
</script>


</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const unregForm = document.getElementById('addUnregisteredForm');
  const modal = document.getElementById('unreg-modal');

  if (!unregForm) return;

  unregForm.addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(unregForm);

    fetch('add_unregistered_patient.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'success') {
        showToast(data.message, "✅", "success");
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast(data.message, "❌", "error");
      }
    })
    .catch(err => {
      console.error(err);
      showToast("Failed to add unregistered patient.", "❌", "error");
    });
  });
});

// --- SCRIPT FOR CUSTOMIZE VIEW MODAL (Corrected) ---
  const customizeModal = document.getElementById('customizeViewModal');
  const openBtn = document.getElementById('customizeViewBtn');
  const closeBtn = document.getElementById('closeCustomizeModal');

  if (customizeModal && openBtn && closeBtn) {
    const showAllToggle = document.getElementById('showAllDaysToggle');
    const dayChipsContainer = document.getElementById('dayChipsContainer');
    const dayChips = document.querySelectorAll('.day-chip');
    const shiftRadios = document.querySelectorAll('input[name="shiftFilter"]');

    // --- Functions to Save/Load settings ---
    window.getFilterSettings = function() {
        const defaults = {
            showAllDays: true,
            selectedDays: ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'],
            shift: 'All'
        };
        const saved = localStorage.getItem('scheduleViewSettings');
        return saved ? JSON.parse(saved) : defaults;
    }

    function saveFilterSettings() {
        const selectedDays = Array.from(document.querySelectorAll('.day-chip.selected'))
                          .map(chip => chip.dataset.day);

const settings = {
    showAllDays: showAllToggle.checked,
    selectedDays: showAllToggle.checked 
        ? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
        : selectedDays, // <-- keep the actual chip selections
    shift: document.querySelector('input[name="shiftFilter"]:checked').value
};


        localStorage.setItem('scheduleViewSettings', JSON.stringify(settings));
        applyFilters();
    }

    // --- Function to apply settings to the modal UI ---
    function applySettingsToUI() {
        const settings = getFilterSettings();

        showAllToggle.checked = settings.showAllDays;
        dayChipsContainer.style.display = settings.showAllDays ? 'none' : 'flex';
        dayChips.forEach(chip => {
            if (settings.selectedDays.includes(chip.dataset.day)) {
                chip.classList.add('selected');
            } else {
                chip.classList.remove('selected');
            }
        });

        document.querySelector(`input[name="shiftFilter"][value="${settings.shift}"]`).checked = true;
    }

    // --- Event Listeners ---
    openBtn.onclick = () => { customizeModal.style.display = 'block'; };
    closeBtn.onclick = () => { customizeModal.style.display = 'none'; };
    window.addEventListener('click', (event) => {
        if (event.target == customizeModal) {
            customizeModal.style.display = 'none';
        }
    });

    showAllToggle.addEventListener('change', () => {
    if (showAllToggle.checked) {
        // All Days ON → auto-select all, hide chips
        dayChips.forEach(chip => chip.classList.add('selected'));
        dayChipsContainer.style.display = 'none';
    } else {
        // Custom OFF → clear all, show chips
        dayChips.forEach(chip => chip.classList.remove('selected'));
        dayChipsContainer.style.display = 'flex';
    }
    saveFilterSettings();
});


    dayChips.forEach(chip => {
        chip.addEventListener('click', () => {
            chip.classList.toggle('selected');
            saveFilterSettings();
        });
    });

    shiftRadios.forEach(radio => {
        radio.addEventListener('change', saveFilterSettings);
    });

    // --- Initial Load ---
    applySettingsToUI();
    // The call to applyFilters() is already in another DOMContentLoaded listener, so it will run correctly.
  }


</script>

<script>
// --- SCRIPT FOR ARCHIVE DAY MODAL ---
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('archiveDayModal');
    const closeBtn = document.getElementById('closeArchiveDayModal');
    const form = document.getElementById('archiveDayForm');
    const checkboxesContainer = document.getElementById('archiveDayCheckboxes');
    const patientIdInput = document.getElementById('archivePatientId');

    if (!modal) return;

    document.querySelectorAll('.archive-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const patientId = btn.dataset.patientId;
            if (!patientId || patientId === 'null') {
                showToast("Archiving is only available for registered patients.", "", "warning");
                return;
            }

            patientIdInput.value = patientId;
            checkboxesContainer.innerHTML = '<p><em>Loading schedule...</em></p>';
            modal.style.display = 'block';

            fetch(`./fetch_schedule.php?patient_id=${patientId}`)
                .then(res => res.json())
                .then(response => {
                    if (response.status === 'success' && response.data) {
                        const schedule = response.data;
                        const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                        let content = '';
                        let hasScheduledDays = false;

                        days.forEach(day => {
                            const shift = schedule[`${day}_shift`];
                            const isArchived = schedule[`${day}_archived`] == 1;

                            if (shift) {
                                hasScheduledDays = true;
                                const dayCapitalized = day.charAt(0).toUpperCase() + day.slice(1);
                                const checkboxId = `archive_${day}`;

                                content += `
                                    <div style="margin-bottom: 10px; padding: 8px; border-radius: 5px; background-color: ${isArchived ? '#e9ecef' : '#fff'};">
                                        <label for="${checkboxId}" style="display: flex; align-items: center; cursor: pointer;">
                                            <input type="checkbox" id="${checkboxId}" name="days[]" value="${day}" ${isArchived ? 'checked' : ''} style="margin-right: 10px;">
                                            <strong>${dayCapitalized}:</strong> ${shift} - ${isArchived ? '<span style="color: gray; font-weight: bold; margin-left: auto;">(Currently Archived)</span>' : '<span style="color: green; margin-left: auto;">(Active)</span>'}
                                        </label>
                                    </div>
                                `;
                            }
                        });

                        if (!hasScheduledDays) {
                            checkboxesContainer.innerHTML = '<p>This patient has no scheduled days to manage.</p>';
                        } else {
                            checkboxesContainer.innerHTML = content;
                        }
                    } else {
                         checkboxesContainer.innerHTML = '<p style="color: red;">Failed to load patient schedule.</p>';
                    }
                })
                .catch(err => {
                    console.error('Fetch schedule for archive error:', err);
                    checkboxesContainer.innerHTML = '<p style="color: red;">An error occurred while fetching data.</p>';
                });
        });
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        const patientId = patientIdInput.value;
        const allCheckboxes = form.querySelectorAll('input[name="days[]"]');

        const daysToArchive = [];
        const daysToUnarchive = [];

        allCheckboxes.forEach(cb => {
            // The original state is fetched and stored in a data attribute when the modal opens
            // For simplicity here, we'll just check against its initial 'checked' property
            // This logic needs to be more robust if original state is complex.
            // A better way: fetch original state and compare.
            // Given the constraints, let's just send two lists to the backend.
            if (cb.checked) {
                daysToArchive.push(cb.value); // Let's simplify: backend can decide based on current state, or we assume all checked are to be archived.
            } else {
                daysToUnarchive.push(cb.value);
            }
        });

        const selectedCheckboxes = Array.from(form.querySelectorAll('input[name="days[]"]:checked')).map(cb => cb.value);
        const unselectedCheckboxes = Array.from(form.querySelectorAll('input[name="days[]"]:not(:checked)')).map(cb => cb.value);

        const payload = {
            patient_id: patientId,
            days_to_archive: selectedCheckboxes,
            days_to_unarchive: unselectedCheckboxes
        };

        fetch('archive_schedule_day.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, '', 'success');
                modal.style.display = 'none';
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.message || 'An error occurred.', '', 'error');
            }
        })
        .catch(err => {
            console.error('Archive submission error:', err);
            showToast('A network error occurred. Please try again.', '', 'error');
        });
    });

    closeBtn.onclick = () => modal.style.display = 'none';
    window.addEventListener('click', (event) => {
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    });
});
</script>
