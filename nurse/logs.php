<?php
// logs.php (Nurse Side)
// DEBUGGING HELP
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SESSION START (Must be first before any output)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include('../config/db.php');

// Create PDO instance if not defined
if (!isset($pdo)) {
    // Replace with your actual credentials if they differ
    $pdo = new PDO("mysql:host=localhost;dbname=u301954910_dialiease21", "u30194910_dialiease21", "DialiEase#21");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Auth check (must happen before any output)
require_once '../includes/auth_check.php';

// --- AJAX HANDLER FOR ARCHIVE / RESTORE (POST REQUESTS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Read raw input (JSON) from the request body
    $input = json_decode(file_get_contents('php://input'), true);
    
    // The JavaScript sends action and logs in the JSON body.
    $action = $input['action'] ?? ''; // Safely get 'action' from JSON input
    
    if (empty($action) || !in_array($action, ['archive', 'restore'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request payload or action type missing.']);
        exit;
    }
    
    if (empty($input['logs'])) {
        echo json_encode(['success' => false, 'message' => 'No logs selected.']);
        exit;
    }

    $pdo->beginTransaction();

    try {
        if ($action === 'archive') {
            // Prepare statements for archiving
            $insertArchive = $pdo->prepare("INSERT INTO archived_logs (source_table, action_id, action_type, action_details, performed_by, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $delSchedule = $pdo->prepare("DELETE FROM schedule_logs WHERE action_id = ?");

            foreach ($input['logs'] as $log) {
                // Nurse logs only come from schedule_logs
                // 1. Fetch original data to ensure we capture it correctly
                $stmt = $pdo->prepare("SELECT * FROM schedule_logs WHERE action_id = ?");
                $stmt->execute([$log['action_id']]);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($record) {
                    // 2. Insert into archived_logs (Hardcode 'Schedule Log' as source for nurses)
                    $insertArchive->execute([
                        'Schedule Log', 
                        $record['action_id'], 
                        $record['action_type'], 
                        $record['action_details'], 
                        $record['performed_by'], 
                        $record['timestamp']
                    ]);

                    // 3. Delete from original table
                    $delSchedule->execute([$log['action_id']]);
                }
            }
            $message = "Selected logs have been archived.";

        } elseif ($action === 'restore') {
            // Prepare statements for restoring
            $insertSchedule = $pdo->prepare("INSERT INTO schedule_logs (action_id, action_type, action_details, performed_by, timestamp) VALUES (?, ?, ?, ?, ?)");
            $delArchive     = $pdo->prepare("DELETE FROM archived_logs WHERE action_id = ? AND source_table = ?");

            foreach ($input['logs'] as $log) {
                // 1. Fetch from archived_logs
                $stmt = $pdo->prepare("SELECT * FROM archived_logs WHERE action_id = ? AND source_table = ?");
                // Nurse logs are always 'Schedule Log'
                $stmt->execute([$log['action_id'], 'Schedule Log']);
                $record = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($record) {
                    // 2. Insert back into original table
                    $insertSchedule->execute([
                        $record['action_id'], $record['action_type'], $record['action_details'], $record['performed_by'], $record['timestamp']
                    ]);

                    // 3. Delete from archived_logs
                    $delArchive->execute([$log['action_id'], 'Schedule Log']);
                }
            }
            $message = "Selected logs have been restored.";
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $message]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
    }
    exit; // Stop execution after handling POST
}

// --- PAGINATION & FILTERING LOGIC ---

// 1. Define Parameters
$rows_per_page = isset($_GET['rows']) ? intval($_GET['rows']) : 100;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}
$offset = ($current_page - 1) * $rows_per_page;

// Get filter parameters from URL
$search_query = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$from_date = $_GET['from'] ?? '';
$to_date = $_GET['to'] ?? '';
$view_mode = $_GET['view'] ?? 'active'; // 'active' or 'archived'
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] == '1'; // Check for AJAX request

// 2. Build the WHERE clause for the query
// Base condition for nurse logs (Vitals/Readings)
$where_clauses = ["(action_type LIKE '%VITAL%' OR action_type LIKE '%READING%' OR action_type = 'Resolve Informed Absence')"];
$params = [];

if (!empty($search_query)) {
    $where_clauses[] = "(action_id LIKE ? OR action_details LIKE ? OR performed_by LIKE ?)";
    $search_param = "%{$search_query}%";
    array_push($params, $search_param, $search_param, $search_param);
}
if (!empty($type_filter)) {
    $where_clauses[] = "action_type = ?";
    $params[] = $type_filter;
}
if (!empty($from_date)) {
    $where_clauses[] = "timestamp >= ?";
    $params[] = $from_date . " 00:00:00";
}
if (!empty($to_date)) {
    $where_clauses[] = "timestamp <= ?";
    $params[] = $to_date . " 23:59:59";
}

// Combine all clauses with AND
$where_sql = "WHERE " . implode(" AND ", $where_clauses);

// 3. Construct Query based on View Mode
if ($view_mode === 'archived') {
    // Query the archived_logs table, BUT FILTER by Nurse specific types AND 'Schedule Log' source
    $where_clauses[] = "source_table = 'Schedule Log'"; 
    // Rebuild where SQL to include source table check
    $where_sql_archived = "WHERE " . implode(" AND ", $where_clauses);

    $count_sql = "SELECT COUNT(*) FROM archived_logs $where_sql_archived";
    
    $query_sql = "
        SELECT 
            action_id COLLATE utf8mb4_unicode_ci AS action_id,
            action_type COLLATE utf8mb4_unicode_ci AS action_type,
            action_details COLLATE utf8mb4_unicode_ci AS action_details,
            performed_by COLLATE utf8mb4_unicode_ci AS performed_by,
            timestamp,
            source_table COLLATE utf8mb4_unicode_ci AS log_source
        FROM archived_logs
        $where_sql_archived
        ORDER BY timestamp DESC
        LIMIT ? OFFSET ?
    ";
} else {
    // Default: Active Logs (schedule_logs only for Nurse)
    $count_sql = "SELECT COUNT(*) FROM schedule_logs $where_sql";

    $query_sql = "
        SELECT
            action_id COLLATE utf8mb4_unicode_ci AS action_id,
            action_type COLLATE utf8mb4_unicode_ci AS action_type,
            action_details COLLATE utf8mb4_unicode_ci AS action_details,
            performed_by COLLATE utf8mb4_unicode_ci AS performed_by,
            timestamp,
            'Schedule Log' AS log_source
        FROM schedule_logs
        $where_sql
        ORDER BY timestamp DESC
        LIMIT ? OFFSET ?
    ";
}

// 4. Get the total number of records for pagination
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $rows_per_page);

// 5. Fetch the data for the current page
// Add LIMIT and OFFSET params
$stmt = $pdo->prepare($query_sql);

// Bind parameters dynamically
$param_index = 1;
foreach ($params as $param) {
    $stmt->bindValue($param_index++, $param);
}
// Bind LIMIT and OFFSET
$stmt->bindValue($param_index++, $rows_per_page, PDO::PARAM_INT);
$stmt->bindValue($param_index, $offset, PDO::PARAM_INT);

$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch only relevant action types for the filter dropdown
// Note: We only fetch these from active logs usually to keep the list clean, or both if needed.
$vitals_filter_string = "(action_type LIKE '%VITAL%' OR action_type LIKE '%READING%' OR action_type = 'Resolve Informed Absence')";
$actionTypesStmt = $pdo->prepare("SELECT DISTINCT action_type FROM schedule_logs WHERE {$vitals_filter_string} ORDER BY action_type ASC");
$actionTypesStmt->execute();
$actionTypes = $actionTypesStmt->fetchAll(PDO::FETCH_COLUMN);

// --- AJAX RESPONSE ---
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'logs' => $logs,
        'total_rows' => (int)$total_rows,
        'total_pages' => (int)$total_pages,
        'current_page' => (int)$current_page,
        'offset' => (int)$offset,
        'log_count' => count($logs),
        'view_mode' => $view_mode
    ]);
    exit; // IMPORTANT: Stop any HTML from being rendered
}

// --- REGULAR PAGE LOAD (Include sidebar only on non-AJAX) ---
include('../nurse/nurse_sidebar.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>System Logs (Vitals)</title>
  <link rel="stylesheet" href="/assets/style.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.3/html2pdf.bundle.min.js"></script>
  <style>
    body { font-family: 'Poppins', sans-serif; background-color: #f8f9fa; margin: 0; }
    .content { margin-left: 35px; padding: 20px; transition: none !important; transform: none !important; animation: none !important; }
    .logs-container { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); overflow-x: auto; }
    h2 { margin-bottom: 20px; }
    
    .filters { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 20px; flex-wrap: nowrap; width: 100%; }
    .filters label { display: flex; align-items: center; gap: 5px; white-space: nowrap; }
    .filters input, .filters select, .filters button, .filters a { padding: 8px 10px; font-size: 14px; border-radius: 6px; border: 1px solid #ccc; text-decoration: none; flex-shrink: 0; }
    
    .filters a.clear { background-color: #ffc107; color: black; }
    
    /* View Toggle Buttons */
    .view-toggle { display: inline-flex; border-radius: 6px; overflow: hidden; border: 1px solid #ccc; margin-right: 15px; }
    .view-toggle button { border: none; padding: 8px 16px; cursor: pointer; border-radius: 0; background: #f8f9fa; color: #555; }
    .view-toggle button.active { background: #1a73e8; color: white; }

    /* Action Buttons */
    .delete-selected { background-color: #dc3545; color: white; white-space: nowrap; border: none; cursor: pointer; }
    .delete-selected:hover { background-color: #c82333; }
    
    .archive-selected { background-color: #ffc107; color: #000; white-space: nowrap; border: none; cursor: pointer; margin-right: 5px; }
    .archive-selected:hover { background-color: #e0a800; }
    
    .restore-selected { background-color: #28a745; color: white; white-space: nowrap; border: none; cursor: pointer; margin-right: 5px; }
    .restore-selected:hover { background-color: #218838; }

    .filter-controls-left, .filter-controls-right { display: flex; align-items: center; gap: 10px; flex-wrap: nowrap; }
    .export-buttons { display: flex; gap: 10px; }
    
    .delete-btn { background-color: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; margin-left: 6px; }
    .delete-btn:hover { background-color: #a71d2a; }
    
    table { width: 100%; border-collapse: collapse; text-align: left; }
    th, td { padding: 12px 16px; border-bottom: 1px solid #ccc; }
    th { background-color: #f2f2f2; }
    tr:hover { background-color: #f9f9f9; }
    .log-type { font-weight: bold; color: #1a73e8; }
    .timestamp { font-size: 13px; color: #555; }
    
    /* Pagination Styles */
    .pagination { display: inline-flex; list-style: none; padding: 0; margin: 0; }
    .pagination li a, .pagination li span { display: block; padding: 8px 12px; color: #007bff; text-decoration: none; border: 1px solid #ddd; background-color: #fff; font-size: 13px; }
    .pagination li:not(:last-child) a, .pagination li:not(:last-child) span { border-right: none; }
    .pagination li a:hover { background-color: #f2f2f2; }
    .pagination li.active span { background-color: #007bff; color: white; border-color: #007bff; }
    .pagination li.disabled span { color: #6c757d; background-color: #e9ecef; cursor: not-allowed; }
    .page-info { color: #555; font-size: 14px; white-space: nowrap; margin: 0; }
    
    @media screen and (max-width: 576px) {
        table, thead, tbody, th, td, tr { display: block; width: 100%; }
        thead tr { display: none; }
        tbody tr { margin-bottom: 15px; background: #fff; border: 1px solid #ddd; padding: 10px; border-radius: 10px; }
        tbody td { display: flex; justify-content: space-between; padding: 8px 10px; border-bottom: 1px solid #eee; }
        tbody td::before { content: attr(data-label); font-weight: bold; color: #555; }
    }
  </style>
</head>
<body>
  <div class="content">
    <div class="logs-container">
      <h2><i class="fa-solid fa-notes-medical"></i> ‎ System Logs (Vitals)</h2>

      <form id="filterForm" method="GET" action="logs.php">
        <input type="hidden" name="view" id="viewInput" value="<?= htmlspecialchars($view_mode) ?>">

        <div class="filters">
            <div class="filter-controls-left">
                <div class="view-toggle">
                    <button type="button" class="<?= $view_mode === 'active' ? 'active' : '' ?>" onclick="switchView('active')">Active</button>
                    <button type="button" class="<?= $view_mode === 'archived' ? 'active' : '' ?>" onclick="switchView('archived')">Archived</button>
                </div>

                <label>Rows:
                    <select name="rows" id="rowLimitSelect">
                        <option value="25" <?= $rows_per_page == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $rows_per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $rows_per_page == 100 ? 'selected' : '' ?>>100</option>
                        <option value="250" <?= $rows_per_page == 250 ? 'selected' : '' ?>>250</option>
                        <option value="500" <?= $rows_per_page == 500 ? 'selected' : '' ?>>500</option>
                    </select>
                </label>
                <input type="text" name="search" id="searchInput" placeholder="Search logs..." value="<?= htmlspecialchars($search_query) ?>">
                <select name="type" id="typeFilter">
                    <option value="">All Action Types</option>
                    <?php foreach ($actionTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= $type_filter == $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="from" id="fromDate" value="<?= htmlspecialchars($from_date) ?>">
                <input type="date" name="to" id="toDate" value="<?= htmlspecialchars($to_date) ?>">
                <a href="logs.php" class="clear">Clear</a>
            </div>
            <div class="filter-controls-right">
                <div class="export-buttons">
                    <?php if ($view_mode === 'active'): ?>
                        <button type="button" class="archive-selected" id="archiveSelectedBtn">
                            <i class="fa fa-archive"></i> Archive Selected
                        </button>
                    <?php else: ?>
                        <button type="button" class="restore-selected" id="restoreSelectedBtn">
                            <i class="fa fa-undo"></i> Restore Selected
                        </button>
                    <?php endif; ?>

                    <button type="button" class="delete-selected" id="deleteSelectedBtn">
                        <i class="fa fa-trash"></i> Delete Selected
                    </button>

                    <select id="exportSelect">
                        <option value="" disabled selected>Export as...</option>
                        <option value="pdf">PDF</option>
                        <option value="excel">Excel</option>
                        <option value="json">JSON</option>
                        <option value="csv">CSV</option>
                    </select>
                    <button type="button" onclick="handleExport()"><i class="fas fa-download"></i></button>
                </div>
            </div>
        </div>
      </form>

      
      <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; margin-bottom: 15px;">
          <p class="page-info">
              Showing <?= count($logs) > 0 ? $offset + 1 : 0; ?> to <?= $offset + count($logs) ?> of <?= $total_rows ?> entries
              <strong>(<?= ucfirst($view_mode) ?> View)</strong>
          </p>
          <?php if ($total_pages > 1): ?>
          <ul class="pagination">
           <?php
             $query_params = $_GET;
             if ($current_page > 1) {
                 $query_params['page'] = $current_page - 1;
                 echo '<li><a href="?' . http_build_query($query_params) . '" data-page="' . ($current_page - 1) . '">&laquo;</a></li>';
             } else {
                 echo '<li class="disabled"><span>&laquo;</span></li>';
             }

             $start_page = (ceil($current_page / 10) - 1) * 10 + 1;
             $end_page = min($start_page + 9, $total_pages);

             for ($i = $start_page; $i <= $end_page; $i++) {
                 $query_params['page'] = $i;
                 if ($i == $current_page) {
                     echo '<li class="active"><span>' . $i . '</span></li>';
                 } else {
                     echo '<li><a href="?' . http_build_query($query_params) . '" data-page="' . $i . '">' . $i . '</a></li>';
                 }
             }

             if ($current_page < $total_pages) {
                 $query_params['page'] = $current_page + 1;
                 echo '<li><a href="?' . http_build_query($query_params) . '" data-page="' . ($current_page + 1) . '">&raquo;</a></li>';
             } else {
                 echo '<li class="disabled"><span>&raquo;</span></li>';
             }
             ?>
          </ul>
          <?php endif; ?>
      </div>

     
      <div id="table-container">
           <table id="logsTable" style="<?= count($logs) > 0 ? '' : 'display:none;' ?>">
            <thead>
              <tr>
                <th><input type="checkbox" id="selectAllCheckbox"></th>
                <th>#</th>
                <th>Action ID</th>
                <th>Action Type</th>
                <th>Details</th>
                <th>Performed By</th>
                <th>Date & Time</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="logsBody">
             <?php $count = $offset + 1; foreach ($logs as $log): ?>
            <tr data-action-id="<?= htmlspecialchars($log['action_id']) ?>" data-log-source="<?= htmlspecialchars($log['log_source']) ?>">
              <td><input type="checkbox" class="row-checkbox"></td>
              <td><?= $count++ ?></td>
              <td><?= htmlspecialchars($log['action_id'] ?? 'N/A') ?></td>
              <td class="log-type"><?= htmlspecialchars($log['action_type'] ?? '') ?></td>
              <td>
                <?= nl2br(htmlspecialchars($log['action_details'] ?? '')) ?>
                <div style="color: #888; font-size: 12px;">Source: <?= htmlspecialchars($log['log_source'] ?? '') ?></div>
              </td>
              <td><?= htmlspecialchars($log['performed_by'] ?? '') ?></td>
              <td class="timestamp"><?= date("Y-m-d h:i:s A", strtotime($log['timestamp'])) ?></td>
              <td><button class="delete-btn" title="Delete"><i class="fa fa-trash-o"></i></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p id="no-logs-message" style="<?= count($logs) > 0 ? 'display:none;' : 'display:block;' ?>">No vitals-related logs found matching your criteria.</p>
      </div>
      <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
          <p class="page-info">
              Showing <?= count($logs) > 0 ? $offset + 1 : 0; ?> to <?= $offset + count($logs) ?> of <?= $total_rows ?> entries
          </p>
          <?php if ($total_pages > 1): ?>
          <ul class="pagination">
           <?php
             if ($current_page > 1) {
                 $query_params['page'] = $current_page - 1;
                 echo '<li><a href="?' . http_build_query($query_params) . '" data-page="' . ($current_page - 1) . '">&laquo;</a></li>';
             } else {
                 echo '<li class="disabled"><span>&laquo;</span></li>';
             }

             for ($i = $start_page; $i <= $end_page; $i++) {
                 $query_params['page'] = $i;
                 if ($i == $current_page) {
                     echo '<li class="active"><span>' . $i . '</span></li>';
                 } else {
                     echo '<li><a href="?' . http_build_query($query_params) . '" data-page="' . $i . '">' . $i . '</a></li>';
                 }
             }

             if ($current_page < $total_pages) {
                 $query_params['page'] = $current_page + 1;
                 echo '<li><a href="?' . http_build_query($query_params) . '" data-page="' . ($current_page + 1) . '">&raquo;</a></li>';
             } else {
                 echo '<li class="disabled"><span>&raquo;</span></li>';
             }
             ?>
          </ul>
          <?php endif; ?>
      </div>
    </div>
  </div>

<script>
// --- GLOBAL VARS ---
const filterForm = document.getElementById('filterForm');
const searchInput = document.getElementById('searchInput');
const typeFilter = document.getElementById('typeFilter');
const fromDate = document.getElementById('fromDate');
const toDate = document.getElementById('toDate');
const rowLimitSelect = document.getElementById('rowLimitSelect');
const logsTable = document.getElementById('logsTable');
const tableBody = document.getElementById('logsBody');
const noLogsMessage = document.getElementById('no-logs-message');
const viewInput = document.getElementById('viewInput');

/**
 * Switch between Active and Archived views
 */
function switchView(mode) {
    viewInput.value = mode;
    fetchAndUpdateLogs(1); // Reset to page 1
}

/**
 * Main function to fetch and update logs via AJAX
 */
async function fetchAndUpdateLogs(page = 1) {
    const formData = new FormData(filterForm);
    const params = new URLSearchParams(formData);

    params.set('page', page);
    params.set('ajax', '1');
    
    const queryString = params.toString();
    const fetchUrl = `logs.php?${queryString}`;
    
    try {
        tableBody.style.opacity = 0.5;
        const response = await fetch(fetchUrl);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        
        const data = await response.json();
        
        // Update DOM
        updateTableBody(data.logs, data.offset);
        updatePaginationControls(data.current_page, data.total_pages, params);
        updatePageInfo(data.offset, data.log_count, data.total_rows, data.view_mode);
        updateButtons(data.view_mode);

        // Update Buttons visual state in toggle
        document.querySelectorAll('.view-toggle button').forEach(btn => {
            if(btn.textContent.toLowerCase() === data.view_mode) btn.classList.add('active');
            else btn.classList.remove('active');
        });

        // Update Browser URL
        const urlParams = new URLSearchParams(params);
        urlParams.delete('ajax');
        const newUrl = window.location.pathname + '?' + urlParams.toString();
        history.pushState({ path: newUrl }, '', newUrl);

    } catch (error) {
        console.error('Error fetching logs:', error);
        alert('Failed to update logs. Please check the console.');
    } finally {
        tableBody.style.opacity = 1;
    }
}

// --- DOM Update Helper Functions ---

function updateTableBody(logs, offset) {
    tableBody.innerHTML = '';
    let startCount = offset + 1;
    if (logs.length === 0) {
        logsTable.style.display = 'none';
        noLogsMessage.innerText = 'No vitals-related logs found matching your criteria.';
        noLogsMessage.style.display = 'block';
        return;
    }

    logsTable.style.display = ''; 
    noLogsMessage.style.display = 'none';

    logs.forEach(log => {
        const timestamp = new Date(log.timestamp);
        const formattedDate = timestamp.getFullYear() + '-' +
                              String(timestamp.getMonth() + 1).padStart(2, '0') + '-' +
                              String(timestamp.getDate()).padStart(2, '0');
        let hours = timestamp.getHours();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        const formattedTime = String(hours).padStart(2, '0') + ':' +
                              String(timestamp.getMinutes()).padStart(2, '0') + ':' +
                              String(timestamp.getSeconds()).padStart(2, '0') + ' ' + ampm;
        const finalTimestamp = `${formattedDate} ${formattedTime}`;

        const row = `
            <tr data-action-id="${escapeHTML(log.action_id)}" data-log-source="${escapeHTML(log.log_source)}">
                <td><input type="checkbox" class="row-checkbox"></td>
                <td>${startCount++}</td>
                <td>${escapeHTML(log.action_id ?? 'N/A')}</td>
                <td class="log-type">${escapeHTML(log.action_type ?? '')}</td>
                <td>
                    ${escapeHTML(log.action_details ?? '').replace(/\n/g, '<br>')}
                    <div style="color: #888; font-size: 12px;">Source: ${escapeHTML(log.log_source ?? '')}</div>
                </td>
                <td>${escapeHTML(log.performed_by ?? '')}</td>
                <td class="timestamp">${finalTimestamp}</td>
                <td><button class="delete-btn"><i class="fa fa-trash-o"></i></button></td>
            </tr>
        `;
        tableBody.insertAdjacentHTML('beforeend', row);
    });
    addTableDataLabels();
    
    const selectAll = document.getElementById("selectAllCheckbox");
    if(selectAll) selectAll.checked = false;
}

function updateButtons(viewMode) {
    const btnContainer = document.querySelector('.export-buttons');
    const existingArchive = btnContainer.querySelector('.archive-selected');
    const existingRestore = btnContainer.querySelector('.restore-selected');
    
    if(viewMode === 'active') {
        if(existingRestore) existingRestore.remove();
        if(!existingArchive) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'archive-selected';
            btn.id = 'archiveSelectedBtn';
            btn.innerHTML = '<i class="fa fa-archive"></i> Archive Selected';
            btn.addEventListener("click", handleArchiveSelected);
            const delBtn = btnContainer.querySelector('.delete-selected');
            btnContainer.insertBefore(btn, delBtn);
        }
    } else {
        if(existingArchive) existingArchive.remove();
        if(!existingRestore) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'restore-selected';
            btn.id = 'restoreSelectedBtn';
            btn.innerHTML = '<i class="fa fa-undo"></i> Restore Selected';
            btn.addEventListener("click", handleRestoreSelected);
            const delBtn = btnContainer.querySelector('.delete-selected');
            btnContainer.insertBefore(btn, delBtn);
        }
    }
}

function updatePaginationControls(currentPage, totalPages, queryParams) {
    const paginationContainers = document.querySelectorAll('.pagination');
    if (totalPages <= 1) {
        paginationContainers.forEach(container => container.innerHTML = '');
        return;
    }

    let paginationHTML = '';
    const queryBuilder = (page) => {
        const params = new URLSearchParams(queryParams);
        params.set('page', page);
        params.delete('ajax');
        return '?' + params.toString();
    };

    if (currentPage > 1) {
        paginationHTML += `<li><a href="${queryBuilder(currentPage - 1)}" data-page="${currentPage - 1}">&laquo;</a></li>`;
    } else {
        paginationHTML += `<li class="disabled"><span>&laquo;</span></li>`;
    }

    const start_page = (Math.ceil(currentPage / 10) - 1) * 10 + 1;
    const end_page = Math.min(start_page + 9, totalPages);

    for (let i = start_page; i <= end_page; i++) {
        if (i == currentPage) {
            paginationHTML += `<li class="active"><span>${i}</span></li>`;
        } else {
            paginationHTML += `<li><a href="${queryBuilder(i)}" data-page="${i}">${i}</a></li>`;
        }
    }

    if (currentPage < totalPages) {
        paginationHTML += `<li><a href="${queryBuilder(currentPage + 1)}" data-page="${currentPage + 1}">&raquo;</a></li>`;
    } else {
        paginationHTML += `<li class="disabled"><span>&raquo;</span></li>`;
    }

    paginationContainers.forEach(container => container.innerHTML = paginationHTML);
}

function updatePageInfo(offset, logCount, totalRows, viewMode) {
    const pageInfoElements = document.querySelectorAll('.page-info');
    const start = totalRows > 0 ? offset + 1 : 0;
    const end = offset + logCount;
    const modeText = viewMode.charAt(0).toUpperCase() + viewMode.slice(1);
    const text = `Showing ${start} to ${end} of ${totalRows} entries <strong>(${modeText} View)</strong>`;
    pageInfoElements.forEach(el => el.innerHTML = text);
}

function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return str.toString()
         .replace(/&/g, '&amp;')
         .replace(/</g, '&lt;')
         .replace(/>/g, '&gt;')
         .replace(/"/g, '&quot;')
         .replace(/'/g, '&#039;');
}

function debounce(func, delay) {
    let timeout;
    return function(...args) {
        const context = this;
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(context, args), delay);
    };
}

// --- EVENT LISTENERS ---

filterForm.addEventListener('submit', function(e) { e.preventDefault(); fetchAndUpdateLogs(1); });
searchInput.addEventListener('input', debounce(e => { fetchAndUpdateLogs(1); }, 300));
[typeFilter, fromDate, toDate, rowLimitSelect].forEach(element => {
    element.addEventListener('change', () => { fetchAndUpdateLogs(1); });
});

document.querySelector('.content').addEventListener('click', function(e) {
    const target = e.target.closest('.pagination li a');
    if (target) {
        e.preventDefault();
        const page = target.dataset.page;
        if (page) fetchAndUpdateLogs(page);
    }
});

document.getElementById("selectAllCheckbox").addEventListener("change", function(e) {
    document.querySelectorAll("#logsBody .row-checkbox").forEach(checkbox => {
        checkbox.checked = e.target.checked;
    });
});

document.getElementById("logsBody").addEventListener("click", function(e) {
    const deleteButton = e.target.closest(".delete-btn");
    if (deleteButton) {
        const row = deleteButton.closest("tr");
        const actionId = row.dataset.actionId;
        const logSource = row.dataset.logSource;
        
        if (confirm("Are you sure you want to delete this log?")) {
            deleteLogs([{ action_id: actionId, log_source: logSource }]);
        }
    }
});

// Bind initial buttons
if(document.getElementById("deleteSelectedBtn")) {
    document.getElementById("deleteSelectedBtn").addEventListener("click", function() {
        const selectedLogs = getSelectedLogs();
        if (selectedLogs.length === 0) { alert("Please select at least one log."); return; }
        if (confirm(`Are you sure you want to delete ${selectedLogs.length} selected log(s)?`)) {
            deleteLogs(selectedLogs);
        }
    });
}
if(document.getElementById("archiveSelectedBtn")) {
    document.getElementById("archiveSelectedBtn").addEventListener("click", handleArchiveSelected);
}
if(document.getElementById("restoreSelectedBtn")) {
    document.getElementById("restoreSelectedBtn").addEventListener("click", handleRestoreSelected);
}

// Handlers for Archive/Restore buttons
function handleArchiveSelected() {
    const selectedLogs = getSelectedLogs();
    if (selectedLogs.length === 0) { alert("Please select logs to archive."); return; }
    if (confirm(`Archive ${selectedLogs.length} selected log(s)?`)) {
        processAction('archive', selectedLogs);
    }
}

function handleRestoreSelected() {
    const selectedLogs = getSelectedLogs();
    if (selectedLogs.length === 0) { alert("Please select logs to restore."); return; }
    if (confirm(`Restore ${selectedLogs.length} selected log(s)?`)) {
        processAction('restore', selectedLogs);
    }
}

function getSelectedLogs() {
    const selectedLogs = [];
    document.querySelectorAll("#logsBody .row-checkbox:checked").forEach(checkbox => {
        const row = checkbox.closest("tr");
        selectedLogs.push({
            action_id: row.dataset.actionId,
            log_source: row.dataset.logSource
        });
    });
    return selectedLogs;
}

function processAction(actionType, logsToProcess) {
    fetch('logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: actionType, logs: logsToProcess })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            fetchAndUpdateLogs(new URLSearchParams(new FormData(filterForm)).get('page') || 1);
            document.getElementById("selectAllCheckbox").checked = false;
        } else {
            alert(`Error: ${data.message}`);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred.');
    });
}

function deleteLogs(logsToDelete) {
    fetch('delete_logs.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ logs: logsToDelete })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            fetchAndUpdateLogs(new URLSearchParams(new FormData(filterForm)).get('page') || 1);
            document.getElementById("selectAllCheckbox").checked = false;
        } else {
            alert(`Error: ${data.message}`);
        }
    })
    .catch(error => {
        console.error('Deletion error:', error);
        alert('An error occurred while trying to delete logs.');
    });
}

// --- EXPORT ---
function handleExport() {
  const format = document.getElementById("exportSelect").value;
  switch(format) {
    case "pdf": exportToPDF(); break;
    case "excel": exportToExcel(); break;
    case "json": downloadBackup(); break;
    case "csv": exportToCSV(); break;
  }
}

function exportToExcel() {
  const rows = Array.from(document.querySelectorAll("#logsTable tbody tr"));
  const ws_data = [["#", "Action ID", "Action Type", "Details", "Performed By", "Date", "Time"]];
  rows.forEach(row => {
    const cells = row.querySelectorAll("td");
    const datetime = cells[6].innerText.trim().split(" ");
    const date = datetime[0];
    const time = datetime[1] + ' ' + (datetime[2] || '');
    ws_data.push([cells[1].innerText, cells[2].innerText, cells[3].innerText, cells[4].innerText, cells[5].innerText, date, time]);
  });
  const ws = XLSX.utils.aoa_to_sheet(ws_data);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, "Vitals Logs");
  const now = new Date().toISOString().replace(/[-:T]/g,"_").split(".")[0];
  XLSX.writeFile(wb, `vitals_logs_export_${now}.xlsx`);
}

function exportToPDF() {
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ orientation: "portrait" });
  const headers = [["#", "Action ID", "Action Type", "Details", "Performed By", "Date & Time"]];
  const data = Array.from(document.querySelectorAll("#logsTable tbody tr"))
    .map(row => {
      const cells = row.querySelectorAll("td");
      return [ cells[1].innerText, cells[2].innerText, cells[3].innerText, cells[4].innerText, cells[5].innerText, cells[6].innerText ];
    });
  doc.autoTable({
    head: headers,
    body: data,
    startY: 20,
    styles: { fontSize: 8 }
  });
  const now = new Date().toISOString().replace(/[-:T]/g, "_").split(".")[0];
  doc.save(`vitals_logs_export_${now}.pdf`);
}

function exportToCSV() {
  const rows = Array.from(document.querySelectorAll("#logsTable tbody tr"));
  let csv = '"#","Action ID","Action Type","Details","Performed By","Date & Time"\n';
  rows.forEach(row => {
    const cells = Array.from(row.querySelectorAll("td"));
    const rowData = cells.slice(1, -1).map(cell => `"${cell.innerText.replace(/"/g, '""')}"`).join(",");
    csv += rowData + "\n";
  });
  const blob = new Blob([csv], { type: "text/csv" });
  const link = document.createElement("a");
  const now = new Date().toISOString().replace(/[-:T]/g,"_").split(".")[0];
  link.href = URL.createObjectURL(blob);
  link.download = `vitals_logs_export_${now}.csv`;
  link.click();
}

function downloadBackup() {
  const rows = Array.from(document.querySelectorAll("#logsTable tbody tr"));
  const data = rows.map(row => {
    const cells = row.querySelectorAll("td");
    return {
      action_id: cells[2].innerText,
      action_type: cells[3].innerText,
      details: cells[4].innerText,
      performed_by: cells[5].innerText,
      timestamp: cells[6].innerText
    };
  });
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
  const link = document.createElement("a");
  const now = new Date().toISOString().replace(/[-:T]/g,"_").split(".")[0];
  link.href = URL.createObjectURL(blob);
  link.download = `vitals_logs_backup_${now}.json`;
  link.click();
}

function addTableDataLabels() {
    document.querySelectorAll("#logsTable tbody tr").forEach(row => {
        const headers = ["Select", "#", "Action ID", "Action Type", "Details", "Performed By", "Date & Time", "Action"];
        row.querySelectorAll("td").forEach((td, i) => {
          td.setAttribute("data-label", headers[i]);
        });
    });
}
addTableDataLabels();
</script>
</body>
</html>