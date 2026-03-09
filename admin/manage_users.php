<?php
session_start();
include('../includes/auth_check.php');
include('../admin/admin_sidebar.php');
include('../config/db.php');

$pdo->exec("SET time_zone = '+08:00'");
date_default_timezone_set('Asia/Manila');

// Fetch all users including archived
// UPDATED: ORDER BY id DESC to show newest first
$stmt = $pdo->query("SELECT * FROM users WHERE id != 1 ORDER BY id DESC");
$all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$users = [
  'admin' => [], 'nurse' => [], 'patient' => [],
  'archived_admin' => [], 'archived_nurse' => [], 'archived_patient' => []
];

foreach ($all_users as $u) {
  $role = $u['role'];
  $status = $u['status'];
  if ($status === 'archived' && isset($users["archived_$role"])) {
    $users["archived_$role"][] = $u;
  } elseif ($status === 'active' && isset($users[$role])) {
    $users[$role][] = $u;
  }
}

// Unregistered patients
// UPDATED: ORDER BY id DESC to show newest first
$stmt = $pdo->query("SELECT id, full_name, registration_code, code_expiry FROM unregistered_patients ORDER BY id DESC");
$unregistered_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Account logs
$stmt = $pdo->query("SELECT * FROM account_logs ORDER BY id DESC LIMIT 2");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Manage Users - DialiEase</title>
<style>

/* Smaller Recent Logs Table */
.content.logs-panel {
  background: #fff;
  border-left: 2px solid #ccc;
  padding: 15px 20px;
  font-size: 13px;
}

.content.logs-panel table {
  font-size: 13px;
}

.content.logs-panel th,
.content.logs-panel td {
  padding: 6px 10px;
}

.content.logs-panel h2 {
  font-size: 20px;
  margin-bottom: 12px;
}

.content.logs-panel .no-data {
  font-style: italic;
  color: #999;
  padding: 12px 0;
}

  /* --- Custom CSS Styling --- */
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f4f7fc;
    min-height: 100vh;
    color: #333;
    font-size: 16px;
  }
  .wrapper {
    display: flex;
    min-height: 100vh;
  }
  .content {
    flex-grow: 1;
    padding: 24px 30px;
    margin-top: 10px;
    margin-bottom: 100px;
    transition: none !important;
    transform: none !important;
    animation: none !important;

  }
  h1 {
    margin-bottom: 20px;
    font-weight: 700;
    font-size: 28px;
  }
  h2 {
    margin-top: 40px;
    margin-bottom: 12px;
    border-bottom: 2px solid #ccc;
    padding-bottom: 6px;
    font-weight: 700;
    font-size: 24px;
  }

  /* Controls */
  .controls {
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
  }
  .controls select {
    padding: 10px;
    font-size: 15px;
    border-radius: 5px;
    border: 1px solid #ccc;
  }

  /* Buttons */
  button.btn {
    padding: 10px 18px;
    font-weight: 700;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    user-select: none;
    background: #28a745;
    color: #fff;
    font-size: 15px;
    transition: background 0.3s ease;
  }
  button.btn:hover { background: #218838; }
  button.btn-sm {
    padding: 6px 12px;
    font-size: 14px;
  }
  button.btn-warning { background: #ffc107; color: #333; }
  button.btn-warning:hover { background: #e0a800; }
  button.btn-danger { background: #dc3545; color: #fff; }
  button.btn-danger:hover { background: #c82333; }
  button.btn-info { background: #17a2b8; color: #fff; }
  button.btn-info:hover { background: #117a8b; }

  /* Tables */
  table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    box-shadow: 0 0 10px rgba(0,0,0,0.05);
    margin-bottom: 40px;
  }
  th, td {
    padding: 10px 14px;
    border-bottom: 1px solid #ddd;
    text-align: left;
    vertical-align: middle;
    font-size: 16px;
  }
  th {
    background: #f8f9fa;
    font-weight: 700;
  }
  tr.archived {
    background: #f0f0f0;
    color: #666;
    font-style: italic;
  }
  .no-data {
    padding: 15px;
    font-style: italic;
    color: #666;
  }

  /* Modal overlay */
  .modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.4);
    overflow-y: auto;
  }

  /* Modal content */
  .modal-content {
    background: #fff;
    border-radius: 8px;
    margin: 4% auto;
    padding: 20px 25px;
    width: 90%;
    max-width: 600px;
    position: relative;
  }
  .modal-content h2 {
    font-size: 22px;
  }

  /* Close button */
  .close {
    position: absolute;
    top: 14px;
    right: 16px;
    font-size: 28px;
    font-weight: bold;
    color: #999;
    cursor: pointer;
    user-select: none;
  }
  .close:hover { color: #333; }

  /* Form fields */
  label {
    font-weight: 600;
    display: block;
    margin-bottom: 6px;
    font-size: 15px;
  }
  input[type=text],
  input[type=email],
  input[type=password],
  select,
  textarea {
    width: 100%;
    padding: 10px;
    margin-bottom: 15px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 15px;
    box-sizing: border-box;
  }
  textarea {
    resize: vertical;
  }

  button[type=submit] {
    background: #007bff;
    color: #fff;
    border: none;
    padding: 10px 20px;
    border-radius: 5px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.3s ease;
  }
  button[type=submit]:hover {
    background: #0056b3;
  }

  /* --- New Toast CSS from manage_schedules.php --- */
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
/* End New Toast CSS */

  /* Responsive */
  @media (max-width: 768px) {
    .wrapper { flex-direction: column; }
    .content { padding: 15px; margin-bottom: 50px; }
    table, th, td { font-size: 13px; }
    .controls { flex-direction: column; gap: 8px; }
  }
  
  .modal[aria-hidden="true"],
.toast[hidden] {
  display: none !important;
  pointer-events: none;
}

/* Print Modal Specific Styles */
@media print {
    body * {
        visibility: hidden;
    }
    #print-area, #print-area * {
        visibility: visible;
    }
    #print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 0;
        border: none;
        box-shadow: none;
    }
    .modal-content {
        border-radius: 0;
    }
    .close, #doPrintBtn {
        display: none !important;
    }
}

</style>

</head>
<body>
    <div id="toast"></div>
<div class="wrapper" style="display: flex; gap: 20px; align-items: flex-start;">
  <div class="content" style="flex: 3;">

    <h1>Manage Users</h1>

    <div class="controls">
      <select id="filterUsers" title="Filter users by type">
        <option value="all" selected>Show All</option>
        <option value="admin">Admins</option>
        <option value="nurse">Nurses</option>
        <option value="patient">Patients</option>
        <option value="unregistered">Unregistered Patients</option>
        <option value="archived">Archived Users</option>
      </select>
      <button class="btn blue" data-role="admin" id="btnAddAdmin">Add Admin</button>
      <button class="btn blue" data-role="nurse" id="btnAddNurse">Add Nurse</button>
      <button class="btn blue" data-role="patient" id="btnAddPatient">Add Patient</button>
      <button class="btn blue" id="btnAddUnreg">Add Unregistered Patient</button>
    </div>

    <?php foreach (['admin', 'nurse', 'patient'] as $role): ?>
      <h2><?= ucfirst($role) ?> Accounts</h2>
      <table class="table user-table" data-role="<?= $role ?>" data-archived="0">
        <thead>
          <tr>
            <th>#</th>
            <th>ID</th><th>Full Name</th><th>Email</th><th>Phone Number</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users[$role])): ?>
            <tr><td colspan="6" class="no-data">No <?= htmlspecialchars($role) ?> accounts found.</td></tr>
          <?php else: ?>
            <?php $cnt = 1; // Initialize Counter ?>
            <?php foreach ($users[$role] as $u): ?>
              <tr>
                <td><?= $cnt++ ?></td>
                <td><?= $u['id'] ?></td>
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['phone_number'] ?? '') ?></td>
                <td>
                  <button class="btn btn-warning btn-sm btn-edit-user" data-id="<?= $u['id'] ?>">Edit</button>
                  <button class="btn btn-danger btn-sm btn-action-user"
                      data-id="<?= $u['id'] ?>"
                      data-action="archive_user"
                      data-type="user"
                      data-name="<?= htmlspecialchars($u['full_name']) ?>">Archive</button>
                  <button class="btn btn-danger btn-sm btn-action-user"
                      data-id="<?= $u['id'] ?>"
                      data-action="delete_user"
                      data-type="user"
                      data-name="<?= htmlspecialchars($u['full_name']) ?>">Delete</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <h2><?= ucfirst($role) ?> Archived</h2>
      <table class="table user-table" data-role="<?= $role ?>" data-archived="1">
        <thead>
          <tr>
            <th>#</th>
            <th>ID</th><th>Full Name</th><th>Email</th><th>Phone Number</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users['archived_' . $role])): ?>
            <tr><td colspan="6" class="no-data">No archived <?= htmlspecialchars($role) ?> accounts found.</td></tr>
          <?php else: ?>
            <?php $cnt = 1; // Initialize Counter ?>
            <?php foreach ($users["archived_$role"] as $u): ?>
              <tr class="archived">
                <td><?= $cnt++ ?></td>
                <td><?= $u['id'] ?></td>
                <td><?= htmlspecialchars($u['full_name']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['phone_number'] ?? '') ?></td>
                <td>
                  <button class="btn btn-info btn-sm btn-action-user" data-id="<?= $u['id'] ?>" data-action="restore_user" data-type="user" data-name="<?= htmlspecialchars($u['full_name']) ?>">Restore</button>
                  <button class="btn btn-danger btn-sm btn-action-user"
                      data-id="<?= $u['id'] ?>"
                      data-action="delete_archived_user"
                      data-type="user"
                      data-name="<?= htmlspecialchars($u['full_name']) ?>">Delete</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    <?php endforeach; ?>

    <h2>Unregistered Patients</h2>
    <table class="table user-table" data-role="unregistered" data-archived="0">
      <thead>
        <tr>
          <th>#</th>
          <th>ID</th>
          <th>Full Name</th>
          <th>Registration Code</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($unregistered_patients)): ?>
          <tr><td colspan="5" class="no-data">No unregistered patients found.</td></tr>
        <?php else: ?>
          <?php $cnt = 1; // Initialize Counter ?>
          <?php foreach ($unregistered_patients as $u): ?>
            <tr>
              <td><?= $cnt++ ?></td>
              <td><?= $u['id'] ?></td>
              <td><?= htmlspecialchars($u['full_name']) ?></td>
              <td>
                <?php if ($u['registration_code']): ?>
                  <strong><?= htmlspecialchars($u['registration_code']) ?></strong>
                  <br><small><em>Expires: <?= date('M d, Y g:i A', strtotime($u['code_expiry'])) ?></em></small>
                <?php else: ?>
                  <span style="color: #888;">Not generated</span>
                <?php endif; ?>
              </td>
              <td>
                <button class="btn btn-warning btn-sm btn-edit-unregistered" data-id="<?= $u['id'] ?>">Edit</button>
                <button class="btn btn-info btn-sm btn-generate-code" data-id="<?= $u['id'] ?>" data-name="<?= htmlspecialchars($u['full_name']) ?>">Generate Code</button>
                <?php if ($u['registration_code']): ?>
                    <button class="btn btn-sm btn-print-code" style="background: #6c757d; color: #fff;" data-code="<?= htmlspecialchars($u['registration_code']) ?>" data-name="<?= htmlspecialchars($u['full_name']) ?>">Print</button>
                <?php endif; ?>
                <button class="btn btn-danger btn-sm btn-action-user"
                  data-id="<?= $u['id'] ?>"
                  data-action="delete_unregistered"
                  data-type="unregistered"
                  data-name="<?= htmlspecialchars($u['full_name']) ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div id="addUserModal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="addUserTitle">
      <div class="modal-content">
        <span class="close" data-close="addUserModal">&times;</span>
        <h2 id="addUserTitle">Add New User</h2>
        <form id="addUserForm">
          <input type="hidden" name="role" id="addUserRole" value="">
          <label for="addFullName">Full Name</label>
          <input type="text" id="addFullName" name="full_name" required>
          <label for="addEmail">Email</label>
          <input type="email" id="addEmail" name="email" required>
          <label for="addPhone">Phone Number</label>
          <input type="text" id="addPhone" name="phone_number" required>
          <label for="addPassword">Password</label>
          <input type="password" id="addPassword" name="password" required minlength="6" autocomplete="new-password">
          <label for="addConfirmPassword">Confirm Password</label>
          <input type="password" id="addConfirmPassword" name="confirm_password" required minlength="6" autocomplete="new-password">
          <button type="submit" class="btn">Create User</button>
        </form>
      </div>
    </div>

    <div id="editUserModal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="editUserTitle">
      <div class="modal-content">
        <span class="close" data-close="editUserModal">&times;</span>
        <h2 id="editUserTitle">Edit User</h2>
        <form id="editUserForm">
          <input type="hidden" name="user_id" id="editUserId">
          <label for="editFullName">Full Name</label>
          <input type="text" id="editFullName" name="full_name" required>
          <label for="editEmail">Email</label>
          <input type="email" id="editEmail" name="email" required>
          <label for="editPhone">Phone Number</label>
          <input type="text" id="editPhone" name="phone_number">
          <label for="editPassword">New Password (leave blank to keep current)</label>
          <input type="password" id="editPassword" name="password" minlength="6" autocomplete="new-password">
          <label for="editConfirmPassword">Confirm New Password</label>
          <input type="password" id="editConfirmPassword" name="confirm_password" minlength="6" autocomplete="new-password">
          <button type="submit" class="btn">Save Changes</button>
        </form>
      </div>
    </div>

    <div id="addUnregisteredModal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="addUnregisteredTitle">
      <div class="modal-content">
        <span class="close" data-close="addUnregisteredModal">&times;</span>
        <h2 id="addUnregisteredTitle">Add Unregistered Patient</h2>
        <form id="addUnregisteredForm">
          <label for="unregFullName">Full Name</label>
          <input type="text" id="unregFullName" name="full_name" required>
          <button type="submit" class="btn">Add Patient</button>
        </form>
      </div>
    </div>

    <div id="editUnregisteredModal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="editUnregisteredTitle">
      <div class="modal-content">
        <span class="close" data-close="editUnregisteredModal">&times;</span>
        <h2 id="editUnregisteredTitle">Edit Unregistered Patient</h2>
        <form id="editUnregisteredForm">
          <input type="hidden" name="unregistered_id" id="editUnregisteredId">
          <label for="editUnregFullName">Full Name</label>
          <input type="text" id="editUnregFullName" name="full_name" required>
          <button type="submit" class="btn">Save Changes</button>
        </form>
      </div>
    </div>

    <div id="printCodeModal" class="modal" aria-hidden="true">
    <div class="modal-content" id="print-area">
         <span class="close" data-close="printCodeModal">&times;</span>
        <div style="text-align: center; font-family: 'Courier New', monospace;">
            <h3 style="margin: 0; font-size: 16px;">Padre Pio Kidney Care</h3>
            <p style="margin: 5px 0; font-size: 12px;">Patient Registration Code</p>
            <hr style="border: none; border-top: 1px dashed #000;">
            <p style="font-size: 12px; margin: 10px 0;">Patient: <strong id="printPatientName"></strong></p>
            <p style="font-size: 14px; margin: 5px 0;">Your Code:</p>
            <p id="printRegCode" style="font-size: 24px; font-weight: bold; letter-spacing: 3px; border: 1px solid #000; padding: 10px; margin: 10px 0;"></p>
            <p style="font-size: 11px; margin-top: 10px;">Please use this code to register in the DialiEase mobile app. This code is valid for 24 hours.</p>
        </div>
    </div>
    <div style="text-align: center; margin-top: 15px;">
         <button id="doPrintBtn" class="btn">Print Receipt</button>
    </div>
</div>

    <div id="confirmActionModal" class="modal" aria-hidden="true" role="dialog" aria-labelledby="confirmActionTitle">
      <div class="modal-content">
        <span class="close" data-close="confirmActionModal">&times;</span>
        <h2 id="confirmActionTitle">Confirm Action</h2>
        <p id="confirmActionMessage"></p>
        <form id="confirmActionForm">
          <input type="hidden" name="user_id" id="confirmActionId">
          <input type="hidden" name="unregistered_id" id="confirmUnregisteredId">
          <input type="hidden" id="confirmAction" name="action_type" value="">
          <input type="hidden" name="type" id="confirmActionType" value="">
          <div style="text-align:right;">
            <button type="submit" class="btn btn-danger">Confirm</button>
            <button type="button" class="btn btn-warning" data-close="confirmActionModal">Cancel</button>
          </div>
        </form>
      </div>
    </div>

  </div>
  
<div class="content logs-panel" style="flex: 1;">
  <h2>Recent Logs</h2>
  <table class="table">
    <thead>
      <tr><th>Time</th><th>Action</th><th>Details</th><th>By</th></tr>
    </thead>
    <tbody>
      <?php if(empty($logs)): ?>
        <tr><td colspan="4" class="no-data">No logs found.</td></tr>
      <?php else: ?>
        <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= date('M d, Y g:i A', strtotime($log['timestamp'])) ?></td>
            <td><?= htmlspecialchars($log['action_type']) ?></td>
            <td style="white-space: pre-wrap;"><?= htmlspecialchars($log['action_details']) ?></td>
            <td><?= htmlspecialchars($log['performed_by']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let deleteTimer = null;
  let countdownInterval = null;
  // Toast helper
  function showToast(message, type = 'info') {
    const toast = document.getElementById("toast");
    if (!toast) return;

    let typeClass = '';
    if (type === 'error') {
        typeClass = 'error';
    } else if (type === 'warning') {
        typeClass = 'warning';
    } else if (type === 'info') {
        typeClass = 'success';
    } else {
        typeClass = 'success';
    }

    toast.innerHTML = message;
    toast.className = "show";
    toast.classList.add(typeClass);
    window.scrollTo(0, 0);

    setTimeout(() => {
        toast.classList.remove("show");
        toast.classList.remove(typeClass);
    }, 3200);
  }

  // Modal functions
  function openModal(id) {
    document.getElementById(id).style.display = 'block';
    document.getElementById(id).setAttribute('aria-hidden', 'false');
  }
  function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.getElementById(id).setAttribute('aria-hidden', 'true');
  }
  function closeAllModals() {
    document.querySelectorAll('.modal').forEach(m => {
      m.style.display = 'none';
      m.setAttribute('aria-hidden', 'true');
    });
  }

  // Close modal on outside click
  window.onclick = (e) => {
    if (e.target.classList.contains('modal')) closeAllModals();
  };

  // Close buttons
  document.querySelectorAll('.close, button[data-close]').forEach(btn => {
    btn.onclick = () => {
      const id = btn.getAttribute('data-close') || btn.closest('.modal').id;
      closeModal(id);
    };
  });
  
  document.getElementById('filterUsers').addEventListener('change', function () {
  const selected = this.value;
  const tables = document.querySelectorAll('.user-table');

  tables.forEach(tbl => {
    const role = tbl.dataset.role;
    const archived = tbl.dataset.archived === '1';

    if (selected === 'archived') {
      tbl.style.display = archived ? 'table' : 'none';
    } else if (selected === 'all') {
      tbl.style.display = archived ? 'none' : 'table';
    } else if (selected === role && !archived) {
      tbl.style.display = 'table';
    } else if (selected === 'unregistered' && role === 'unregistered') {
      tbl.style.display = 'table';
    } else {
      tbl.style.display = 'none';
    }
  });
});

  // Open Add User modal with role set
  ['btnAddAdmin', 'btnAddNurse', 'btnAddPatient'].forEach(id => {
    const btn = document.getElementById(id);
    if (btn) {
      btn.onclick = () => {
        document.getElementById('addUserRole').value = btn.dataset.role;
        document.getElementById('addUserForm').reset();
        openModal('addUserModal');
      };
    }
  });

  // Add Unregistered Patient modal
  document.getElementById('btnAddUnreg').onclick = () => {
    document.getElementById('addUnregisteredForm').reset();
    openModal('addUnregisteredModal');
  };

  document.body.addEventListener('click', (e) => {
  const t = e.target;

  if (t.classList.contains('btn-edit-user')) {
    const id = t.dataset.id;
    fetch(`manage_users_actions.php?action=get_user&id=${id}`)
      .then(r => r.json())
      .then(data => {
        if (data.status === 'success') {
          const u = data.data;
          const f = document.getElementById('editUserForm');
          f.querySelector('[name="user_id"]').value = u.id;
          f.querySelector('[name="full_name"]').value = u.full_name;
          f.querySelector('[name="email"]').value = u.email;
          f.querySelector('[name="phone_number"]').value = u.phone_number || '';
          f.querySelector('[name="password"]').value = '';
          f.querySelector('[name="confirm_password"]').value = '';
          openModal('editUserModal');
        } else {
          showToast(data.message || 'Error fetching user', 'error');
        }
      }).catch(() => showToast('Error fetching user', 'error'));
  }

  if (t.classList.contains('btn-edit-unregistered')) {
    const id = t.dataset.id;
    fetch(`manage_users_actions.php?action=get_unregistered&id=${id}`)
      .then(r => r.json())
      .then(data => {
        if (data.status === 'success') {
          const u = data.data;
          const f = document.getElementById('editUnregisteredForm');
          f.unregistered_id.value = u.id;
          f.full_name.value = u.full_name;
          openModal('editUnregisteredModal');
        } else {
          showToast(data.message || 'Error fetching unregistered patient', 'error');
        }
      }).catch(() => showToast('Error fetching unregistered patient', 'error'));
  }

  if (t.classList.contains('btn-action-user')) {
    const id = t.dataset.id;
    const action = t.dataset.action;
    const type = t.dataset.type;
    const name = t.dataset.name;

    // Check if this is a "delete" action
    if (action === 'delete_user' || action === 'delete_archived_user' || action === 'delete_unregistered') {
        // It's a delete action, trigger the UNDO toast
        initiateUndoUserDelete(id, action, type, name);
    } else {
        // It's an archive/restore action, use the old modal
        document.getElementById('confirmActionId').value = type === 'user' ? id : '';
        document.getElementById('confirmUnregisteredId').value = type === 'unregistered' ? id : '';
        document.getElementById('confirmAction').value = action;
        document.getElementById('confirmActionType').value = type;
        document.getElementById('confirmActionMessage').textContent = 
          `Are you sure you want to ${action.replace(/_/g, ' ')} ${type} "${name}"?`;
        openModal('confirmActionModal');
    }
  }

if (t.classList.contains('btn-generate-code')) {
    const id = t.dataset.id;
    const name = t.dataset.name;
    if (!confirm(`Are you sure you want to generate a new code for "${name}"? Any existing code will be replaced.`)) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'generate_reg_code');
    fd.append('unregistered_id', id);
    fetch('manage_users_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                showToast(data.message, 'info');
                // Optionally open print modal automatically
                document.getElementById('printPatientName').textContent = name;
                document.getElementById('printRegCode').textContent = data.code;
                openModal('printCodeModal');
                // Reload to show the new code in the table
                setTimeout(() => location.reload(), 2000); 
            } else {
                showToast(data.message || 'Failed to generate code.', 'error');
            }
        })
        .catch(() => showToast('Network or server error.', 'error'));
}

  if (t.classList.contains('btn-print-code')) {
    const name = t.dataset.name;
    const code = t.dataset.code;
    document.getElementById('printPatientName').textContent = name;
    document.getElementById('printRegCode').textContent = code;
    openModal('printCodeModal');
  }

});

// --- NEW UNDO FUNCTIONS START ---
    function performUserHardDelete(id, action, type) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('type', type);

        if (type === 'user') {
            fd.append('user_id', id);
        } else if (type === 'unregistered') {
            fd.append('unregistered_id', id);
        } else {
            showToast('Invalid type for deletion', 'error');
            return;
        }

        fetch('manage_users_actions.php', { method: 'POST', body: fd })
        .then(async res => {
            const text = await res.text();
            try {
                const json = JSON.parse(text);
                if (!res.ok || json.status === 'error') {
                    throw new Error(json.message || "Failed to delete item.");
                }
                return json;
            } catch (e) {
                console.error("Invalid JSON from manage_users_actions.php (delete):", text);
                throw new Error(e.message.includes("Failed to delete") ? e.message : "Invalid server response.");
            }
        })
        .then(json => {
            showToast(json.message || "Item deleted successfully.", 'info');
            setTimeout(() => location.reload(), 1000);
        })
        .catch(error => {
             console.error("Delete fetch failed:", error);
            showToast(error.message || "Delete request failed.", 'error');
        });
    }

    function initiateUndoUserDelete(id, action, type, name) {
        // 1. Clear any previous timers
        if (deleteTimer) clearTimeout(deleteTimer);
        if (countdownInterval) clearInterval(countdownInterval);

        // 2. Get the main toast element
        const toast = document.getElementById("toast");
        if (!toast) return; 

        let countdown = 5;
        const actionText = action.replace(/_/g, ' ');

        // 3. Set its content and make it visible
        toast.innerHTML = `
            Deleting ${type} "${name}" in <span id="undo-countdown" style="font-weight:bold;">${countdown}</span>s...
            <button id="destroy-now-btn" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; margin-left: 15px;">Delete Now</button>
            <button id="undo-btn" style="background: #007bff; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; margin-left: 5px;">Undo</button>
        `;
        toast.className = "show warning";
        window.scrollTo(0, 0);

        // 4. Add listeners to the buttons *inside* the main toast element
        document.getElementById("destroy-now-btn").onclick = function() {
            clearTimeout(deleteTimer);
            clearInterval(countdownInterval);
            deleteTimer = null;
            countdownInterval = null;
            toast.className = "";
            performUserHardDelete(id, action, type);
        };
        document.getElementById("undo-btn").onclick = function() {
            clearTimeout(deleteTimer);
            clearInterval(countdownInterval);
            deleteTimer = null;
            countdownInterval = null;
            toast.className = ""; // Hide toast
            showToast("Delete canceled.", "info");
        };

        // 5. Start the countdown interval
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

        // 6. Start the 5-second "bomb" timer
        deleteTimer = setTimeout(() => {
            if (toast.className.includes("show")) { // Only delete if toast is still active
                toast.className = ""; // Hide toast
                performUserHardDelete(id, action, type); // Fire the delete
            }
            deleteTimer = null;
        }, 5000);
    }
    // --- NEW UNDO FUNCTIONS END ---


  // Form submission helper
  function submitForm(formId, action) {
    const form = document.getElementById(formId);
    const fd = new FormData(form);
    fd.append('action', action);

    // Password validation for relevant forms
    if (['create_user', 'edit_user', 'register_unregistered'].includes(action)) {
      const pwd = fd.get('password');
      const confirmPwd = fd.get('confirm_password');
      if (pwd !== confirmPwd) {
        showToast('Passwords do not match', 'error');
        return;
      }
      if (pwd && pwd.length > 0 && pwd.length < 6) {
        showToast('Password must be at least 6 characters', 'error');
        return;
      }
    }

    fetch('manage_users_actions.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, 'info');
            closeAllModals();
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Operation failed', 'error');
        }
    })
    .catch(() => showToast('Network or server error', 'error'));
  }

    // Add User form submit
    document.getElementById('addUserForm').onsubmit = e => {
    e.preventDefault();
    submitForm('addUserForm', 'create_user');
    };
    // Edit User form submit
    document.getElementById('editUserForm').onsubmit = e => {
    e.preventDefault();
    submitForm('editUserForm', 'edit_user');
    };
    // Add Unregistered Patient form submit
    document.getElementById('addUnregisteredForm').onsubmit = e => {
    e.preventDefault();
    submitForm('addUnregisteredForm', 'add_unregistered');
    };
    // Edit Unregistered Patient form submit
    document.getElementById('editUnregisteredForm').onsubmit = e => {
    e.preventDefault();
    submitForm('editUnregisteredForm', 'edit_unregistered');
    };



    document.getElementById('confirmActionForm').onsubmit = e => {
      e.preventDefault();
      const form = e.target;
      const fd = new FormData(form);
      const rawAction = document.getElementById('confirmAction').value;

      let actualAction = '';
      // You must include 'delete_archived_user' here
      if (['delete_user', 'archive_user', 'restore_user', 'delete_archived_user'].includes(rawAction)) {
        actualAction = rawAction;
        fd.set('user_id', document.getElementById('confirmActionId').value);
      } else if (['delete_unregistered', 'register_unregistered', 'edit_unregistered'].includes(rawAction)) {
        actualAction = rawAction;
        fd.set('unregistered_id', document.getElementById('confirmUnregisteredId').value);
      } else {
        showToast('Unknown action type: ' + rawAction, 'error');
        return;
      }

      fd.set('action', actualAction);
      fetch('manage_users_actions.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            showToast(data.message, 'info');
            closeAllModals();
            setTimeout(() => location.reload(), 1000);
          } else {
            showToast(data.message || 'Operation failed', 'error');
          }
        })
        .catch(() => showToast('Network or server error', 'error'));
    };

    document.getElementById('filterUsers').dispatchEvent(new Event('change'));

});

document.getElementById('doPrintBtn').onclick = () => {
  window.print();
};

</script>
</body> 
</html>