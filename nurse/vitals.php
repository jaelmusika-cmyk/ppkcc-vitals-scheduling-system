<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');

include('../config/db.php');
include('../nurse/nurse_sidebar.php');
require_once '../includes/auth_check.php';
if (!isLoggedIn() || !checkRole('nurse')) {
    redirectToLogin();
}
// *** ADD THIS CODE BLOCK ***
$absencesStmt = $pdo->prepare("SELECT COUNT(id) FROM informed_absences WHERE status = 'acknowledged'");
$absencesStmt->execute();
$acknowledged_absences_count = $absencesStmt->fetchColumn();
// *** END OF ADDED CODE BLOCK ***

$selected_date = $_GET['date'] ?? date('Y-m-d');
$day_of_week = date('l', strtotime($selected_date));
$nurses = $pdo->query("SELECT id, full_name FROM users WHERE role = 'nurse' AND status = 'active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$flowsheets_data = [];
$stmt = $pdo->prepare("
    (SELECT 
        f.id AS flowsheet_id, f.shift,
        ve.id AS entry_id, ve.pre_hd_bp, ve.post_hd_bp, ve.pre_hd_wt, ve.post_hd_wt, ve.medication, ve.attendance_status,
        p.full_name AS patient_name,
        nic.full_name AS nurse_in_charge_name, nic.id AS nurse_in_charge_id, 'registered' as patient_type
    FROM flowsheets f
    JOIN vitals_entries ve ON f.id = ve.flowsheet_id
    JOIN patients p ON ve.patient_id = p.id
    LEFT JOIN users nic ON ve.nurse_in_charge_id = nic.id
    WHERE f.flowsheet_date = :selected_date_reg)
    UNION ALL
    (SELECT 
        f.id AS flowsheet_id, f.shift,
        ve.id AS entry_id, ve.pre_hd_bp, ve.post_hd_bp, ve.pre_hd_wt, ve.post_hd_wt, ve.medication, ve.attendance_status,
        up.full_name AS patient_name,
        nic.full_name AS nurse_in_charge_name, nic.id AS nurse_in_charge_id, 'unregistered' as patient_type
    FROM flowsheets f
    JOIN vitals_entries ve ON f.id = ve.flowsheet_id
    JOIN unregistered_patients up ON ve.unregistered_patient_id = up.id
    LEFT JOIN users nic ON ve.nurse_in_charge_id = nic.id
    WHERE f.flowsheet_date = :selected_date_unreg)
    ORDER BY shift, patient_name ASC
");
$stmt->execute(['selected_date_reg' => $selected_date, 'selected_date_unreg' => $selected_date]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($results as $row) {
    $flowsheets_data[$row['shift']]['flowsheet_id'] = $row['flowsheet_id'];
    $flowsheets_data[$row['shift']]['entries'][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vitals Monitoring - DialiEase</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f8f9fa; margin: 0; }
        .content { margin-left: 65px; transition: none !important; /* Crucial: Disables any CSS transition on the element */
        transform: none !important; /* Ensures no initial transform (like translate) is applied */
        animation: none !important; /* Disables any CSS animation */ padding: 20px; }
        .table-wrapper { 
            display: block;
            width: 100%; 
            padding: 10px; 
            border-radius: 20px; 
            background-color: #fff; 
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); 
            overflow-x: auto;
        }
        table { 
            border-collapse: collapse;
            width: 100%; 
            table-layout: fixed; 
        }
        .table-wrapper th { 
            background-color: #f4f4f4;
            padding: 8px 5px;
            text-align: center; 
            font-weight: bold; 
        }
        .table-wrapper td { 
            border: 1px solid #ddd;
            padding: 5px 3px;
            text-align: center; 
            vertical-align: middle; 
            word-wrap: break-word; 
        }
        
        /* Column Widths for screen display */
        .table-wrapper th:nth-child(1), .table-wrapper td:nth-child(1) { width: 18%; } /* Patient Name */
        .table-wrapper th:nth-child(2), .table-wrapper td:nth-child(2) { width: 8%; }  /* Attendance */
        .table-wrapper th:nth-child(3), .table-wrapper td:nth-child(3) { width: 9%; }  /* Pre-HD BP */
        .table-wrapper th:nth-child(4), .table-wrapper td:nth-child(4) { width: 9%; }  /* Post-HD BP */
        .table-wrapper th:nth-child(5), .table-wrapper td:nth-child(5) { width: 10%; } /* Pre-HD WT */
        .table-wrapper th:nth-child(6), .table-wrapper td:nth-child(6) { width: 10%; } /* Post-HD WT */
        .table-wrapper th:nth-child(7), .table-wrapper td:nth-child(7) { width: 20%; } /* Medication */
        .table-wrapper th:nth-child(8), .table-wrapper td:nth-child(8) { width: 16%; } /* Nurse in Charge */
        
        /* Force Patient Name and Nurse Name to stay on one line, showing ellipsis if too long */
        .table-wrapper td:nth-child(1), .table-wrapper td:nth-child(8) { 
            white-space: nowrap;
            overflow: hidden; 
            text-overflow: ellipsis; 
        }

        /* Smaller font for compact view */
        .table-wrapper table {
            font-size: 0.9em;
        }
        
        .form-table td { padding: 5px 3px; }
        .form-table input, .form-table select { font-size: 0.9em; } 
        /* Match form table widths to display table */
        .form-table th:nth-child(1), .form-table td:nth-child(1) { width: 18%; } 
        .form-table th:nth-child(2), .form-table td:nth-child(2) { width: 8%; } 
        .form-table th:nth-child(3), .form-table td:nth-child(3) { width: 9%; } 
        .form-table th:nth-child(4), .form-table td:nth-child(4) { width: 9%; } 
        .form-table th:nth-child(5), .form-table td:nth-child(5) { width: 10%; } 
        .form-table th:nth-child(6), .form-table td:nth-child(6) { width: 10%; } 
        .form-table th:nth-child(7), .form-table td:nth-child(7) { width: 20%; } 
        .form-table th:nth-child(8), .form-table td:nth-child(8) { width: 16%; }
        
        .btn { margin-top: 20px; background-color: #2c7be5; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-size: 16px; cursor: pointer; transition: background-color 0.3s; }
        .btn:hover { background-color: #215db5; }
        .btn-success { background-color: #4CAF50; }
        .btn-success:hover { background-color: #45a049; }
        .btn-warning { background-color: #ffc107; color: black; }
        .btn-warning:hover { background-color: #e0a800; }
        .btn-danger { background-color: #dc3545; }
        .btn-danger:hover { background-color: #c82333; }
        .header-controls { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; }
        .header-controls .date-picker { display: flex; align-items: center; gap: 10px; }
        .header-controls label { font-weight: bold; }
        .header-controls input[type="date"] { padding: 8px; border-radius: 6px; border: 1px solid #ccc; font-size: 16px; }
        .header-controls .btn { margin-top: 0; }
        .flowsheet-container { margin-top: 30px; }
        .flowsheet-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .flowsheet-header h3 { margin: 0; }
        .flowsheet-actions { display: flex; gap: 10px; }
        #toast { opacity: 0; pointer-events: none; position: fixed; top: 20px; left: 50%; transform: translateX(-50%); width: 100%; max-width: 450px; background: #f8ffff; border-left: 5px solid #2185d0; border-radius: 5px; padding: 15px; text-align: center; font-weight: bold; z-index: 999999; transition: opacity 0.4s ease; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        #toast.show { opacity: 1; pointer-events: auto; }
        #toast.success { border-left-color: #38d021; }
        #toast.error { border-left-color: #d02121; }
        .attendance-icon { font-size: 24px; cursor: pointer; }
        .status-present { color: green; }
        .status-absent { color: red; }
        .status-late { color: orange; }
        .status-excused { color: grey; }
        .form-table input, .form-table select { width: 100%; padding: 6px; box-sizing: border-box; }
        .form-table input:disabled, .form-table select:disabled { background-color: #f2f2f2; cursor: not-allowed; }
        .form-actions { display: flex; justify-content: flex-end; gap: 10px; align-items: center; margin-top: 20px; }
        .form-actions .btn-danger { margin-right: auto; }
        
        /* PDF-Specific Styles for the hidden content wrapper used by html2pdf */
        #pdf-content-wrapper {
            display: none;
            width: 100%;
            font-family: Arial, sans-serif;
            padding: 5px;
        }

        /* Styles applied to the generated PDF content */
        .pdf-header h2, .pdf-header h3, .pdf-header p { 
            text-align: center;
            margin: 5px 0; 
        }
        .pdf-table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
            table-layout: fixed;
        }
        .pdf-table th, .pdf-table td {
            border: 1px solid #000;
            padding: 3px 5px;
            text-align: center;
            font-size: 9pt;
            vertical-align: top;
            line-height: 1.2;
        }
        .pdf-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        /* Set column widths for the PDF table to fit on landscape A4 */
        .pdf-table th:nth-child(1), .pdf-table td:nth-child(1) { width: 18%; } /* Patient Name */
        .pdf-table th:nth-child(2), .pdf-table td:nth-child(2) { width: 7%; }  /* Attendance */
        .pdf-table th:nth-child(3), .pdf-table td:nth-child(3) { width: 9%; }  /* Pre-HD BP */
        .pdf-table th:nth-child(4), .pdf-table td:nth-child(4) { width: 9%; }  /* Post-HD BP */
        .pdf-table th:nth-child(5), .pdf-table td:nth-child(5) { width: 10%; } /* Pre-HD WT */
        .pdf-table th:nth-child(6), .pdf-table td:nth-child(6) { width: 10%; } /* Post-HD WT */
        .pdf-table th:nth-child(7), .pdf-table td:nth-child(7) { width: 18%; } /* Medication */
        .pdf-table th:nth-child(8), .pdf-table td:nth-child(8) { width: 19%; } /* Nurse in Charge */
        
        .absence-btn-badge {
            background-color: #dc3545;
            color: white;
            padding: 2px 7px;
            border-radius: 50%;
            font-size: 0.8em;
            margin-left: 8px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-width: 20px;
            min-height: 20px;
            vertical-align: middle;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }

        .modal-header h3 { margin: 0; }

        .modal-close-btn {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .modal-close-btn:hover, .modal-close-btn:focus {
            color: #000;
        }
        
        #absencesList { list-style: none; padding: 0; }
        #absencesList li { 
            padding: 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }
        #absencesList li:last-child { border-bottom: none; }
        #absencesList .absence-details { flex-grow: 1; }
        #absencesList .absence-details strong { font-size: 1.1em; }
        #absencesList .absence-details small { color: #666; display: block; margin-top: 4px; }
        #absencesList .btn-sm { padding: 5px 10px; font-size: 0.9em; margin-top: 0; }
        /* *** END OF ADDED STYLES *** */

    </style>
</head>
<body>

<div class="content">
    <div id="toast"></div>

    <div class="header-controls">
        <h2>Vitals Monitoring</h2>
        <button id="showAbsencesBtn" class="btn" style="background-color: #17a2b8;">
            View Absent Notices
            <?php if ($acknowledged_absences_count > 0): ?>
                <span class="absence-btn-badge"><?php echo $acknowledged_absences_count; ?></span>
            <?php endif; ?>
        </button>
        <div class="date-picker">
            <label for="vitalsDate">Select Date:</label>
            <input type="date" id="vitalsDate" value="<?php echo htmlspecialchars($selected_date ?? ''); ?>">
        </div>
    </div>
    
    <p style="text-align: center; font-size: 1.2em; font-weight: bold;">Displaying records for: <?php echo date('F d, Y', strtotime($selected_date)) . " ($day_of_week)"; ?></p>

    
    
        <?php foreach (['First Shift', 'Second Shift'] as $shift): ?>
            <div class="flowsheet-container" id="container-<?php echo str_replace(' ', '-', $shift); ?>">
                <div class="flowsheet-header">
                    <h3><?php echo $shift; ?></h3>
                    
                    <div class="flowsheet-actions">
                        <?php if (isset($flowsheets_data[$shift])): ?>
                            <button class="btn print-flowsheet-btn" style="background-color: #6c757d;" data-shift-name="<?php echo $shift; ?>">⬇️ Export PDF</button>
                            <button class="btn btn-warning edit-flowsheet-btn" data-flowsheet-id="<?php echo $flowsheets_data[$shift]['flowsheet_id']; ?>" data-shift="<?php echo $shift; ?>">Edit Entry</button>
                        <?php else: ?>
                            <button class="btn btn-success start-flowsheet-btn" data-shift="<?php echo $shift; ?>">✚ Start Shift Entry</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flowsheet-body">
                    <?php if (isset($flowsheets_data[$shift])): ?>
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <div id="flowsheetsDisplay">
        <div style="margin-bottom: 20px; font-size: 0.9em; margin-top: 10px;">
        <span style="margin: 0 10px; vertical-align: middle;">
            <i class="fas fa-minus-circle" style="color: grey; font-size: 20px; vertical-align: middle;"></i> ‎ Unmarked
        </span>
        <span style="margin: 0 10px; vertical-align: middle;">
            <i class="fas fa-check-circle status-present" style="font-size: 20px; vertical-align: middle;"></i> ‎ Present
        </span>
        <span style="margin: 0 10px; vertical-align: middle;">
            <i class="fas fa-clock status-late" style="font-size: 20px; vertical-align: middle;"></i> ‎ Late
        </span>
        <span style="margin: 0 10px; vertical-align: middle;">
            <i class="fas fa-times-circle status-absent" style="font-size: 20px; vertical-align: middle;"></i> ‎ Absent
        </span>
        <span style="margin: 0 10px; vertical-align: middle;">
            <i class="fas fa-question-circle status-excused" style="font-size: 20px; vertical-align: middle;"></i> ‎ Excused
        </span>
    </div>
                                    <tr>
                                        <th>Patient Name</th><th>Attendance</th><th>Pre-HD BP</th><th>Post-HD BP</th><th>Pre-HD WT (kg)</th><th>Post-HD WT (kg)</th><th>Medication</th><th>Nurse in Charge</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($flowsheets_data[$shift]['entries'] as $entry): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($entry['patient_name'] ?? ''); ?></td>
                                            <td>
                                                <?php 
                                                    $icon = 'fa-minus-circle';
                                                    $color = 'grey';
                                                    switch($entry['attendance_status']) {
                                                        case 'present': $icon = 'fa-check-circle'; $color = 'green'; break;
                                                        case 'absent':  $icon = 'fa-times-circle'; $color = 'red'; break;
                                                        case 'late':    $icon = 'fa-clock'; $color = 'orange'; break;
                                                        case 'excused': $icon = 'fa-question-circle'; $color = 'grey'; break;
                                                    }
                                                    echo "<i class='fas $icon' style='color: $color; font-size: 20px;'></i>";
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($entry['pre_hd_bp'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['post_hd_bp'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['pre_hd_wt'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['post_hd_wt'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['medication'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($entry['nurse_in_charge_name'] ?? ''); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p>No vitals entry recorded for this shift.</p>
                    <?php endif; ?>
                </div>
                <div class="flowsheet-form-container"></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="absencesModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Acknowledged Absences</h3>
            <span class="modal-close-btn">&times;</span>
        </div>
        <div class="modal-body">
            <ul id="absencesList">
                </ul>
        </div>
    </div>
</div>

<div id="pdf-content-wrapper"></div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const nurses = <?php echo json_encode($nurses); ?>;
    const flowsheetsData = <?php echo json_encode($flowsheets_data); ?>;
    const selectedDate = '<?php echo date('F d, Y', strtotime($selected_date)); ?>';

    const datePicker = document.getElementById('vitalsDate');
    datePicker.addEventListener('change', (e) => window.location.href = `vitals.php?date=${e.target.value}`);
    const absenceModal = document.getElementById('absencesModal');
    const showAbsencesBtn = document.getElementById('showAbsencesBtn');
    const closeBtn = absenceModal.querySelector('.modal-close-btn');
    const absencesList = document.getElementById('absencesList');
    const absenceBadge = showAbsencesBtn.querySelector('.absence-btn-badge');
    let absenceCount = <?php echo $acknowledged_absences_count ?? 0; ?>;

    if (absenceCount === 0) {
        showAbsencesBtn.style.display = 'none'; // Hide button if there are no absences
    }

    showAbsencesBtn.addEventListener('click', async () => {
        try {
            absencesList.innerHTML = '<li>Loading...</li>';
            absenceModal.style.display = 'block';
            
            const response = await fetch('vitals_handler.php?action=get_acknowledged_absences');
            const data = await response.json();

            if (data.status === 'success' && data.absences.length > 0) {
                renderAbsences(data.absences);
            } else if (data.status === 'success') {
                absencesList.innerHTML = '<li>No acknowledged absences found.</li>';
            } else {
                absencesList.innerHTML = `<li>Error: ${data.message}</li>`;
            }
        } catch (error) {
            absencesList.innerHTML = '<li>A network error occurred.</li>';
        }
    });

    const renderAbsences = (absences) => {
        absencesList.innerHTML = '';
        absences.forEach(absence => {
            const li = document.createElement('li');
            li.dataset.absenceId = absence.id;
            const acknowledgedDate = new Date(absence.acknowledged_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

            li.innerHTML = `
                <div class="absence-details">
                    <strong>${absence.full_name}</strong>
                    <p>${absence.message}</p>
                    <small>Acknowledged: ${acknowledgedDate}</small>
                </div>
                <button class="btn btn-success btn-sm resolve-absence-btn" data-id="${absence.id}">Mark as Resolved</button>
            `;
            absencesList.appendChild(li);
        });
    };

    absencesList.addEventListener('click', async (e) => {
        if (e.target && e.target.classList.contains('resolve-absence-btn')) {
            const absenceId = e.target.dataset.id;
            if (!confirm('Are you sure you want to mark this absence as resolved?')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'resolve_absence');
            formData.append('absence_id', absenceId);

            try {
                e.target.disabled = true;
                e.target.textContent = '...';

                const response = await fetch('vitals_handler.php', { method: 'POST', body: formData });
                const result = await response.json();

                if (result.status === 'success') {
                    showToast(result.message, 'success');
                    const listItem = absencesList.querySelector(`li[data-absence-id="${absenceId}"]`);
                    if (listItem) {
                        listItem.remove();
                    }
                    
                    // Update count
                    absenceCount--;
                    if (absenceBadge) {
                        if (absenceCount > 0) {
                            absenceBadge.textContent = absenceCount;
                        } else {
                            absenceBadge.remove();
                            showAbsencesBtn.style.display = 'none'; // Hide button after last one is resolved
                        }
                    }
                    if (absencesList.children.length === 0) {
                        absenceModal.style.display = 'none';
                    }

                } else {
                    showToast(result.message || 'An error occurred.', 'error');
                    e.target.disabled = false;
                    e.target.textContent = 'Mark as Resolved';
                }
            } catch (error) {
                showToast('A network error occurred.', 'error');
                e.target.disabled = false;
                e.target.textContent = 'Mark as Resolved';
            }
        }
    });

    closeBtn.onclick = () => { absenceModal.style.display = 'none'; };
    window.onclick = (event) => {
        if (event.target == absenceModal) {
            absenceModal.style.display = 'none';
        }
    };

    // *** END: NEW CODE FOR ABSENCES MODAL ***


    document.querySelectorAll('.start-flowsheet-btn').forEach(button => {
        button.addEventListener('click', async (e) => {
            const shift = e.target.dataset.shift;
            const container = e.target.closest('.flowsheet-container');
            try {
                const response = await fetch(`vitals_handler.php?action=get_scheduled_patients&date=${datePicker.value}&shift=${encodeURIComponent(shift)}`);
                const data = await response.json();
                if (data.status === 'success') {
                    if (!data.patients || data.patients.length === 0) {
                        showToast('No patients are scheduled for this shift.', 'error');
                        return;
                    }
                    renderEditableForm(container, { shift: shift, action: 'save_flowsheet', patients: data.patients });
                } else {
                    showToast(data.message || 'Could not fetch patients.', 'error');
                }
            } catch (error) {
                showToast('A network error occurred while fetching patients.', 'error');
            }
        });
    });

    document.querySelectorAll('.edit-flowsheet-btn').forEach(button => {
        button.addEventListener('click', async (e) => {
            const flowsheetId = e.target.dataset.flowsheetId;
            const container = e.target.closest('.flowsheet-container');
            try {
                const response = await fetch(`vitals_handler.php?action=get_flowsheet_details_for_edit&flowsheet_id=${flowsheetId}`);
                const data = await response.json();
                if (data.status === 'success') {
                     renderEditableForm(container, { flowsheetId: flowsheetId, action: 'update_flowsheet', patients: data.patients });
                } else {
                    showToast(data.message || 'Could not fetch details.', 'error');
                }
            } catch (error) {
                showToast('A network error occurred while preparing the edit form.', 'error');
            }
        });
    });

    document.querySelectorAll('.print-flowsheet-btn').forEach(button => {
        button.addEventListener('click', (e) => {
            const shiftName = e.currentTarget.dataset.shiftName;
            handleExportToPdf(shiftName, e.currentTarget); // Pass the button element
        });
    });

    // --- MODIFIED FUNCTION: PDF Export using html2pdf.js ---
    // --- CORRECTED FUNCTION: PDF Export using html2pdf.js ---
    function handleExportToPdf(shiftName, button) {
        const data = flowsheetsData[shiftName];
        if (!data || !data.entries || data.entries.length === 0) {
            showToast('No data available to export to PDF.', 'error');
            return;
        }

        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';

        const formatAttendance = (status) => {
            if (!status || status === 'unmarked') return 'Unmarked';
            return status.charAt(0).toUpperCase() + status.slice(1);
        };

        const now = new Date();
        const exportDateFormatted = now.toLocaleDateString('en-US', {
            year: 'numeric', month: 'long', day: 'numeric'
        });
        const exportTimeFormatted = now.toLocaleTimeString('en-US', {
            hour: '2-digit', minute: '2-digit'
        });
        const filenameDate = new Date(datePicker.value).toISOString().split('T')[0];
        
        let tableRows = '';
        data.entries.forEach(entry => {
            tableRows += `
                <tr>
                    <td>${entry.patient_name || ''}</td>
                    <td>${formatAttendance(entry.attendance_status)}</td>
                    <td>${entry.pre_hd_bp || ''}</td>
                    <td>${entry.post_hd_bp || ''}</td>
                    <td>${entry.pre_hd_wt || ''}</td>
                    <td>${entry.post_hd_wt || ''}</td>
                    <td>${entry.medication || ''}</td>
                    <td>${entry.nurse_in_charge_name || ''}</td>
                </tr>
            `;
        });

        const htmlContent = `
            <div class="pdf-header">
                <h2>Padre Pio Kidney Care Center Cainta</h2>
                <h3>Vitals Flowsheet for ${shiftName} - ${selectedDate}</h3>
                <p style='font-size: 9pt;'>Generated: ${exportDateFormatted}, ${exportTimeFormatted}</p>
            </div>
            <table class="pdf-table">
                <thead>
                    <tr>
                        <th>Patient Name</th>
                        <th>Attendance</th>
                        <th>Pre-HD BP</th>
                        <th>Post-HD BP</th>
                        <th>Pre-HD WT (kg)</th>
                        <th>Post-HD WT (kg)</th>
                        <th>Medication</th>
                        <th>Nurse in Charge</th>
                    </tr>
                </thead>
                <tbody>
                    ${tableRows}
                </tbody>
            </table>
        `;

        const contentWrapper = document.getElementById('pdf-content-wrapper');
        
        // 1. Temporarily make the wrapper visible so html2pdf can render it correctly.
        //    (Even though it's positioned off-screen, display: none often breaks rendering)
        contentWrapper.style.display = 'block';
        contentWrapper.innerHTML = htmlContent;

        // Configuration for html2pdf
        const opt = {
            margin: 5, // mm
            filename: `Vitals_Flowsheet_${shiftName.replace(' ', '_')}_${filenameDate}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2, logging: false },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
        };

        // 2. Execute the PDF generation and chain the promises
        html2pdf().set(opt).from(contentWrapper).save()
            .then(() => {
                // 3. Restore hidden state and button status
                contentWrapper.style.display = 'none';
                button.disabled = false;
                button.innerHTML = originalText;
                showToast('Flowsheet successfully exported to PDF!', 'success');
            })
            .catch(error => {
                // 3. Restore hidden state and button status on error
                contentWrapper.style.display = 'none';
                button.disabled = false;
                button.innerHTML = originalText;
                console.error("html2pdf error:", error);
                showToast('Error exporting to PDF. Check console for details.', 'error');
            });
    }
    // --- END CORRECTED FUNCTION ---
    // --- END MODIFIED FUNCTION ---


    function renderEditableForm(container, config) {
    const bodyContainer = container.querySelector('.flowsheet-body');
    const formContainer = container.querySelector('.flowsheet-form-container');
    const header = container.querySelector('.flowsheet-header');

    bodyContainer.style.display = 'none';
    header.style.display = 'none';

    let patientRows = '';
    if (!config.patients || config.patients.length === 0) {
        patientRows = '<tr><td colspan="8">No patients scheduled.</td></tr>';
    } else {
        config.patients.forEach((patient, index) => {
            const patientId = patient.patient_id || patient.id;
            const patientName = patient.patient_name || patient.full_name;
            const entryId = patient.entry_id || '';
            const status = patient.attendance_status || 'unmarked';
            const isRowDisabled = ['absent', 'excused', 'unmarked'].includes(status);
            const patientIdField = patient.patient_type === 'unregistered' ? 'unregistered_patient_id' : 'patient_id';

            patientRows += `
                <tr>
                    <td>
                        ${patientName}
                        <input type="hidden" name="patients[${index}][${patientIdField}]" value="${patientId}">
                        <input type="hidden" name="patients[${index}][patient_name]" value="${patientName}">
                        <input type="hidden" name="patients[${index}][entry_id]" value="${entryId}">
                        <input type="hidden" name="patients[${index}][patient_type]" value="${patient.patient_type}">
                    </td>
                    <td>
                        <div class="attendance-icon" data-status="${status}">${getAttendanceIcon(status)}</div>
                        <input type="hidden" name="patients[${index}][attendance_status]" value="${status}">
                    </td>
                    <td><input type="text" name="patients[${index}][pre_hd_bp]" value="${patient.pre_hd_bp || ''}" ${isRowDisabled ? 'disabled' : ''}></td>
                    <td><input type="text" name="patients[${index}][post_hd_bp]" value="${patient.post_hd_bp || ''}" ${isRowDisabled ? 'disabled' : ''}></td>
                    <td><input type="number" step="0.01" name="patients[${index}][pre_hd_wt]" value="${patient.pre_hd_wt || ''}" ${isRowDisabled ? 'disabled' : ''}></td>
                    <td><input type="number" step="0.01" name="patients[${index}][post_hd_wt]" value="${patient.post_hd_wt || ''}" ${isRowDisabled ? 'disabled' : ''}></td>
                    <td><input type="text" name="patients[${index}][medication]" value="${patient.medication || ''}" ${isRowDisabled ? 'disabled' : ''}></td>
                    <td>
                        <select name="patients[${index}][nurse_in_charge_id]" ${isRowDisabled ? 'disabled' : ''}>
                            <option value="">-- Select Nurse --</option>
                            ${nurses.map(nurse => `<option value="${nurse.id}" ${patient.nurse_in_charge_id == nurse.id ? 'selected' : ''}>${nurse.full_name}</option>`).join('')}
                        </select>
                    </td>
                </tr>`;
        });
    }

    // ✨ THIS IS THE NEW LEGEND CODE BLOCK ✨
    const legendHTML = `
        <div style="margin-bottom: 20px; font-size: 0.9em; margin-top: 10px;">
            <span style="margin: 0 10px; vertical-align: middle;">
                <i class="fas fa-minus-circle" style="color: grey; font-size: 20px; vertical-align: middle;"></i> ‎ Unmarked
            </span>
            <span style="margin: 0 10px; vertical-align: middle;">
                <i class="fas fa-check-circle status-present" style="font-size: 20px; vertical-align: middle;"></i> ‎ Present
            </span>
            <span style="margin: 0 10px; vertical-align: middle;">
                <i class="fas fa-clock status-late" style="font-size: 20px; vertical-align: middle;"></i> Late
            </span>
            <span style="margin: 0 10px; vertical-align: middle;">
                <i class="fas fa-times-circle status-absent" style="font-size: 20px; vertical-align: middle;"></i> ‎ Absent
            </span>
            <span style="margin: 0 10px; vertical-align: middle;">
                <i class="fas fa-question-circle status-excused" style="font-size: 20px; vertical-align: middle;"></i> ‎ Excused
            </span>
        </div>
    `;

    const formHTML = `
        <form class="vitals-form">
            <input type="hidden" name="action" value="${config.action}">
            <input type="hidden" name="flowsheet_date" value="${datePicker.value}">
            <input type="hidden" name="shift" value="${config.shift || ''}">
            <input type="hidden" name="flowsheet_id" value="${config.flowsheetId || ''}">
            
            ${legendHTML} 

            <div class="table-wrapper">
                <table class="form-table">
                    <thead>
                        <tr>
                            <th>Patient Name</th><th>Attendance</th><th>Pre-HD BP</th><th>Post-HD BP</th><th>Pre-HD WT (kg)</th><th>Post-HD WT (kg)</th><th>Medication</th><th>Nurse in Charge</th>
                        </tr>
                    </thead>
                    <tbody>${patientRows}</tbody>
                </table>
            </div>
            <div class="form-actions">
                ${config.action === 'update_flowsheet' ? `<button type="button" class="btn btn-danger delete-flowsheet-btn" data-flowsheet-id="${config.flowsheetId}">Delete Entry</button>` : ''}
                <button type="button" class="btn btn-warning cancel-edit-btn">Cancel</button>
                <button type="submit" class="btn btn-success">Save Changes</button>
            </div>
        </form>`;
    formContainer.innerHTML = formHTML;
    addFormEventListeners(formContainer);
}

    function addFormEventListeners(formContainer) {
        formContainer.querySelector('.vitals-form').addEventListener('submit', handleFormSubmit);
        formContainer.querySelectorAll('.attendance-icon').forEach(icon => icon.addEventListener('click', handleAttendanceClick));
        formContainer.querySelector('.cancel-edit-btn').addEventListener('click', () => window.location.reload());
        const deleteBtn = formContainer.querySelector('.delete-flowsheet-btn');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', handleDelete);
        }
    }

    async function handleFormSubmit(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.innerText = 'Saving...';
        try {
            const response = await fetch('vitals_handler.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.status === 'success') {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message || 'An error occurred.', 'error');
                submitButton.disabled = false;
                submitButton.innerText = 'Save Changes';
            }
        } catch(error) {
            showToast('A network error occurred. Please try again.', 'error');
            submitButton.disabled = false;
            submitButton.innerText = 'Save Changes';
        }
    }

    async function handleDelete(e) {
        if (!confirm('Are you sure you want to permanently delete this entire flowsheet entry? This action cannot be undone.')) {
            return;
        }
        const flowsheetId = e.target.dataset.flowsheetId;
        const formData = new FormData();
        formData.append('action', 'delete_flowsheet');
        formData.append('flowsheet_id', flowsheetId);
        try {
            const response = await fetch('vitals_handler.php', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.status === 'success') {
                showToast(result.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(result.message || 'Could not delete entry.', 'error');
            }
        } catch (error) {
            showToast('A network error occurred while trying to delete.', 'error');
        }
    }

    function handleAttendanceClick(e) {
        const iconDiv = e.currentTarget;
        const hiddenInput = iconDiv.nextElementSibling;
        const parentRow = iconDiv.closest('tr');
        let currentStatus = iconDiv.dataset.status;
        let nextStatus;
        switch (currentStatus) {
            case 'unmarked': nextStatus = 'present'; break;
            case 'present':  nextStatus = 'late'; break;
            case 'late':     nextStatus = 'absent'; break;
            case 'absent':   nextStatus = 'excused'; break;
            case 'excused':  nextStatus = 'unmarked'; break;
            default:         nextStatus = 'unmarked';
        }
        iconDiv.dataset.status = nextStatus;
        hiddenInput.value = nextStatus;
        iconDiv.innerHTML = getAttendanceIcon(nextStatus);

        const fieldsToToggle = parentRow.querySelectorAll('input[type="text"], input[type="number"], select');
        const shouldBeDisabled = ['absent', 'excused', 'unmarked'].includes(nextStatus);
        fieldsToToggle.forEach(field => {
            field.disabled = shouldBeDisabled;
            if (shouldBeDisabled) {
                // Clear inputs when marking as absent/excused
                if (field.tagName === 'SELECT') field.selectedIndex = 0;
                else field.value = '';
            }
        });
    }

    function getAttendanceIcon(status) {
        switch (status) {
            case 'present': return '<i class="fas fa-check-circle status-present"></i>';
            case 'absent': return '<i class="fas fa-times-circle status-absent"></i>';
            case 'late': return '<i class="fas fa-clock status-late"></i>';
            case 'excused': return '<i class="fas fa-question-circle status-excused"></i>';
            default: return '<i class="fas fa-minus-circle" style="color: grey;"></i>';
        }
    }

    function showToast(message, type = '') {
        const toast = document.getElementById("toast");
        toast.innerText = message;
        toast.className = "show " + type;
        setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 3200);
    }
});
</script>

</body>
</html>