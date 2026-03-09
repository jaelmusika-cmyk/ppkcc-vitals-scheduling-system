<?php
session_start();
require_once '../config/db.php';
include 'admin_sidebar.php';
include 'log_action.php';

$stmt = $pdo->query("SELECT r.*, p.full_name FROM schedule_requests r 
    JOIN patients p ON r.patient_id = p.id 
    WHERE r.status = 'Pending' ORDER BY r.requested_at DESC");
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2 style="padding: 20px;">⏳ Pending Schedule Requests</h2>

<table border="1" cellpadding="10" cellspacing="0" style="width: 95%; margin: 0 auto; background: #fff;">
  <tr style="background: #f2f2f2;">
    <th>Patient</th>
    <th>Type</th>
    <th>Note</th>
    <th>Requested At</th>
    <th>Action</th>
  </tr>

  <?php foreach ($requests as $req): ?>
  <tr>
    <td><?= htmlspecialchars($req['full_name']) ?></td>
    <td><?= ucfirst($req['type']) ?></td>
    <td><?= nl2br(htmlspecialchars($req['note'])) ?></td>
    <td><?= date('M d, Y h:i A', strtotime($req['requested_at'])) ?></td>
    <td>
      <?php if ($req['type'] === 'modify'): ?>
        <form method="get" action="edit_schedule.php" style="display:inline;">
          <input type="hidden" name="patient_id" value="<?= $req['patient_id'] ?>">
          <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
          <button type="submit">Approve & Proceed ✅</button>
        </form>
      <?php else: ?>
        <form method="post" action="handle_request.php" style="display:inline;">
          <input type="hidden" name="id" value="<?= $req['id'] ?>">
          <input type="hidden" name="action" value="open_request_approve">
          <input type="text" name="note" placeholder="Admin note (optional)">
          <button type="submit">Create & Approve ✅</button>
        </form>
      <?php endif; ?>

      <form method="post" action="handle_request.php" style="display:inline;">
        <input type="hidden" name="id" value="<?= $req['id'] ?>">
        <input type="hidden" name="action" value="reject">
        <input type="text" name="note" placeholder="Rejection reason">
        <button type="submit">Reject ❌</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
