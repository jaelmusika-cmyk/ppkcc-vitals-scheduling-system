<?php
session_start();
include('../config/db.php');
include('../admin/admin_sidebar.php');
date_default_timezone_set('Asia/Manila');

// Fetch Announcements
$announcementsStmt = $pdo->query("SELECT a.*, u.full_name AS created_by_name FROM announcements a JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC");
$announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Unpaid Reminders
$remindersStmt = $pdo->query("
    SELECT pr.*, p.full_name 
    FROM payment_reminders pr 
    JOIN patients p ON pr.patient_id = p.id 
    WHERE pr.status = 'unpaid' 
    ORDER BY pr.date_of_absence DESC
");
$reminders = $remindersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch No-Show Fee
$feeStmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'no_show_fee' LIMIT 1");
$no_show_fee = $feeStmt ? $feeStmt->fetchColumn() : '1000.00'; // Default if not found

// Fetch Informed Absences
$absencesStmt = $pdo->query("
    SELECT ia.*, p.full_name
    FROM informed_absences ia
    JOIN patients p ON ia.patient_id = p.id
    WHERE ia.status = 'sent'
    ORDER BY ia.created_at ASC
");
$absences = $absencesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<head>
    <title>Notifications & Payments</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f4f7f6;
            color: #333;
        }
        .content { 
            margin-left: 65px;
            padding: 20px; 
             transition: none !important; /* Crucial: Disables any CSS transition on the element */
        transform: none !important; /* Ensures no initial transform (like translate) is applied */
        animation: none !important; /* Disables any CSS animation */
        }
        .grid-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
        }
        .container { 
            background: #fff;
            padding: 9px; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.08); 
            margin-bottom: 20px;
        }
        h1 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 25px;
            color: #2c3e50;
        }
        h2 { 
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px; 
            margin-bottom: 20px; 
            font-size: 22px;
            font-weight: 500;
            color: #34495e;
            display: flex; /* Aligns icon and text */
            align-items: center;
        }
        h2 i {
            margin-right: 10px; /* Space between icon and text */
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; color: #555; }
        .form-group input, .form-group textarea { 
            width: 100%;
            padding: 10px 12px; 
            border-radius: 6px; 
            border: 1px solid #ccc; 
            box-sizing: border-box; 
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .form-group input:focus, .form-group textarea:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.2);
            outline: none;
        }
        .btn { 
            padding: 10px 20px;
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            color: #fff; 
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: background-color 0.3s;
        }
        .btn-primary { background-color: #007bff; }
        .btn-primary:hover { background-color: #0056b3; }
        .btn-danger { background-color: #dc3545; }
        .btn-danger:hover { background-color: #c82333; }
        .btn-success { background-color: #28a745; }
        .btn-success:hover { background-color: #218838; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 14px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background-color: #f8f9fa; font-weight: 500; }
        tr:hover { background-color: #f1f1f1; }
        .announcement-card, .absence-card { 
            border: 1px solid #eee;
            padding: 20px; 
            margin-bottom: 15px; 
            border-radius: 8px; 
            position: relative;
        }
        .announcement-card h4, .absence-card h4 { margin: 0 0 10px; font-weight: 500; }
        .meta-info { font-size: 0.8em; color: #777; margin-bottom: 10px; }
        .action-button-top-right {
            position: absolute;
            top: 15px;
            right: 15px;
        }
        #toast { opacity: 0; pointer-events: none; position: fixed; top: 20px; left: 50%; transform: translateX(-50%); width: 100%; max-width: 450px; background: #fff; border-left: 5px solid #2185d0; border-radius: 5px; padding: 15px; text-align: center; font-weight: bold; z-index: 999999; transition: opacity 0.4s ease; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        #toast.show { opacity: 1; pointer-events: auto; }
        #toast.success { border-left-color: #28a745; }
        #toast.error { border-left-color: #dc3545; }
    </style>
</head>

<body>
<div class="content">
    <div id="toast"></div>
    <h1>Manage Notifications</h1>
    
    <div class="grid-container">
        
        <div class="container">
            <h2><i class="fas fa-calendar-times"></i> Informed Absences</h2>
            <div id="absencesList">
                <?php if (empty($absences)): ?>
                    <p>No new informed absences.</p>
                <?php else: ?>
                    <?php foreach ($absences as $abs): ?>
                        <div class="absence-card" id="absence-<?= $abs['id'] ?>">
                            <h4><?= htmlspecialchars($abs['full_name']) ?></h4>
                            <div class="meta-info">
                                Received on <?= date('M d, Y h:i A', strtotime($abs['created_at'])) ?>
                            </div>
                            <p><i>"<?= nl2br(htmlspecialchars($abs['message'])) ?>"</i></p>
                            <button class="btn btn-success" onclick="acknowledgeAbsence(<?= $abs['id'] ?>)">Acknowledge</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="container">
            <h2><i class="fas fa-money-bill-wave"></i> No-Call No-Show Fee Reminders</h2>
            <form id="feeForm" style="display: flex; align-items: center; gap: 15px; margin-bottom: 25px; max-width: 400px;">
                <div class="form-group" style="margin-bottom: 0; flex-grow: 1;">
                    <label for="noShowFee" style="font-weight: bold;">Fee Amount (₱)</label>
                    <input type="number" id="noShowFee" name="no_show_fee" value="<?= htmlspecialchars($no_show_fee) ?>" step="0.01" required class="form-group input">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Update Fee</button>
            </form>
            <div id="remindersList">
                 <?php if (empty($reminders)): ?>
                    <p>No pending payments.</p>
                <?php else: ?>
                    <table>
                        <thead>
                             <tr>
                                 <th>Patient Name</th>
                                 <th>Date of Absence</th>
                                 <th>Amount Due</th>
                                 <th>Action</th>
                             </tr>
                        </thead>
                        <tbody>
                             <?php foreach ($reminders as $rem): ?>
                                 <tr id="reminder-<?= $rem['id'] ?>">
                                     <td><?= htmlspecialchars($rem['full_name']) ?></td>
                                     <td><?= date('F d, Y', strtotime($rem['date_of_absence'])) ?></td>
                                     <td>₱<?= number_format($rem['amount'], 2) ?></td>
                                     <td><button class="btn btn-success" onclick="markAsPaid(<?= $rem['id'] ?>)">Mark as Paid</button></td>
                                 </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
    </div>

    <div class="grid-container">
        
        <div class="container">
            <h2><i class="fas fa-bullhorn"></i> Publish New Announcement</h2>
            <form id="announcementForm">
                <input type="hidden" name="action" value="create_announcement">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" required>
                </div>
                <div class="form-group">
                    <label for="message">Message</label>
                    <textarea id="message" name="message" rows="4" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Publish Announcement</button>
            </form>
        </div>

        <div class="container">
            <h2><i class="fas fa-clipboard-list"></i> Current Announcements</h2>
            <div id="announcementsList">
                <?php if (empty($announcements)): ?>
                    <p>No announcements published.</p>
                <?php else: ?>
                    <?php foreach ($announcements as $ann): ?>
                        <div class="announcement-card" id="announcement-<?= $ann['id'] ?>">
                            <h4><?= htmlspecialchars($ann['title']) ?></h4>
                            <p><?= nl2br(htmlspecialchars($ann['message'])) ?></p>
                            <div class="meta-info">
                                Posted by <?= htmlspecialchars($ann['created_by_name']) ?> on <?= date('M d, Y h:i A', strtotime($ann['created_at'])) ?>
                            </div>
                            <button class="btn btn-danger action-button-top-right" onclick="deleteAnnouncement(<?= $ann['id'] ?>)">Remove</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
    </div>

<script>
function showToast(message, type = '') {
    const toast = document.getElementById("toast");
    toast.innerText = message;
    toast.className = "show " + type;
    setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 3200);
}

// NEW: Acknowledge Absence
function acknowledgeAbsence(id) {
    if (!confirm('Acknowledge this absence? A notification will be sent to the patient.')) return;
    const formData = new FormData();
    formData.append('action', 'acknowledge');
    formData.append('absence_id', id);
    fetch('handle_absence.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('Absence acknowledged.', 'success');
            const absenceCard = document.getElementById('absence-' + id);
            if (absenceCard) {
                absenceCard.remove();
            }
            // Check if the list is empty and show a message
            const list = document.getElementById('absencesList');
            if (!list.querySelector('.absence-card')) {
                list.innerHTML = '<p>No new informed absences.</p>';
            }
        } else {
            showToast(data.message || 'An error occurred.', 'error');
        }
    });
}

// Announcements
document.getElementById('announcementForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    fetch('handle_announcements.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('Announcement published!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'An error occurred.', 'error');
        }
    });
});

function deleteAnnouncement(id) {
    if (!confirm('Are you sure you want to remove this announcement?')) return;
    const formData = new FormData();
    formData.append('action', 'delete_announcement');
    formData.append('announcement_id', id);
    fetch('handle_announcements.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('Announcement removed.', 'success');
            const announcementCard = document.getElementById('announcement-' + id);
            if (announcementCard) {
                announcementCard.remove();
            }
        } else {
            showToast(data.message || 'An error occurred.', 'error');
        }
    });
}

// Fee Update
document.getElementById('feeForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('action', 'update_no_show_fee');

    fetch('handle_settings.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('Fee updated successfully!', 'success');
        } else {
            showToast(data.message || 'An error occurred.', 'error');
        }
    });
});

// Payments
function markAsPaid(id) {
    if (!confirm('Are you sure you want to mark this fee as paid?')) return;
    const formData = new FormData();
    formData.append('action', 'mark_paid');
    formData.append('reminder_id', id);
    fetch('handle_payments.php', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('Payment marked as paid.', 'success');
            const reminderRow = document.getElementById('reminder-' + id);
            if(reminderRow) {
                reminderRow.remove();
            }
        } else {
            showToast(data.message || 'An error occurred.', 'error');
           }
    });
}
</script>
</body>
</html>