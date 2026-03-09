<?php
session_start();
require_once '../config/db.php';
require_once 'log_action.php';

$patient_id = $_GET['patient_id'] ?? null;
$request_id = $_GET['request_id'] ?? null;

if (!$patient_id) {
    echo "Missing patient ID.";
    exit;
}

// Fetch full_name for display
$stmt = $pdo->prepare("SELECT full_name FROM patients WHERE id = ?");
$stmt->execute([$patient_id]);
$patient_name = $stmt->fetchColumn() ?? 'Unknown';

// Fetch or create schedule row
$stmt = $pdo->prepare("SELECT * FROM schedules WHERE patient_id = ?");
$stmt->execute([$patient_id]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    // Create empty pending schedule
    $pdo->prepare("INSERT INTO schedules (patient_id, status, created_at, updated_at) VALUES (?, 'Pending', NOW(), NOW())")
        ->execute([$patient_id]);
    $schedule_id = $pdo->lastInsertId();
    $schedule = ['id' => $schedule_id];
} else {
    $schedule_id = $schedule['id'];
}

$days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
$shiftEndLimits = [
    'First Shift' => '10:00',
    'Second Shift' => '15:00'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updates = [];
    $status = 'Pending';

    foreach ($days as $day) {
        $shift = $_POST[$day . '_shift'] ?? null;
        $start = $_POST[$day . '_start_time'] ?? null;
        $duration = $_POST[$day . '_duration'] ?? null;

        // Normalize null values
        $shift = $shift ?: null;
        $start = $start ?: null;
        $duration = $duration ?: null;

        // Validation if data is set
        if ($shift && $start && $duration && isset($shiftEndLimits[$shift])) {
            $startMin = (int)date('H', strtotime($start)) * 60 + (int)date('i', strtotime($start));
            [$limitH, $limitM] = explode(':', $shiftEndLimits[$shift]);
            $endLimit = ((int)$limitH) * 60 + ((int)$limitM);
            $maxAllowed = $endLimit - $startMin;

            if ($maxAllowed < 0 || $duration > $maxAllowed) {
                echo "<p style='color:red;'>❌ Invalid duration for " . ucfirst($day) . ". Max allowed: $maxAllowed mins.</p>";
                exit;
            }
        }

        $updates[$day . '_shift'] = $shift;
        $updates[$day . '_start_time'] = $start;
        $updates[$day . '_duration'] = $duration;

        if ($shift || $start || $duration) {
            $status = 'Scheduled';
        }
    }

    // Update schedule
    $sql = "UPDATE schedules SET 
        " . implode(', ', array_map(fn($key) => "$key = :$key", array_keys($updates))) . ",
        updated_at = NOW(),
        status = :status
        WHERE id = :id";

    $stmt = $pdo->prepare($sql);
    $params = array_merge($updates, [':status' => $status, ':id' => $schedule_id]);
    $stmt->execute($params);

    // If request_id is given, mark request as handled
    if ($request_id) {
        $stmt = $pdo->prepare("UPDATE schedule_requests SET status = 'Approved', handled_at = NOW(), handled_by = ?, response_note = 'Schedule updated via admin panel' WHERE id = ?");
        $stmt->execute([$_SESSION['full_name'] ?? 'Unknown', $request_id]);

        logAction('Approved Schedule Request', "Request ID: $request_id marked as approved via schedule update", $_SESSION['full_name'] ?? 'Unknown');
    }

    logAction('Schedule Updated', "Schedule updated for patient ID $patient_id", $_SESSION['full_name'] ?? 'Unknown');
    echo "<p style='color:green;'>✅ Schedule updated successfully!</p>";
}

?>

<h2 style="padding: 15px;">🗓️ Edit Weekly Schedule for <?= htmlspecialchars($patient_name) ?></h2>

<?php if ($request_id): ?>
<div style="background: #fff8db; border: 1px solid #ffc107; padding: 10px; margin: 10px 0;">
    <strong>Note:</strong> Updating this schedule in response to Request ID <strong><?= $request_id ?></strong>.
</div>
<?php endif; ?>

<form method="POST" style="padding: 15px; background: #fff; max-width: 800px; margin: 0 auto;">
  <?php foreach ($days as $day): ?>
    <fieldset style="margin-bottom: 20px; border: 1px solid #ccc; padding: 10px;">
      <legend><?= ucfirst($day) ?></legend>
      <label>Shift:
        <select name="<?= $day ?>_shift">
          <option value="">-- Select Shift --</option>
          <option value="First Shift" <?= ($schedule[$day . '_shift'] ?? '') === 'First Shift' ? 'selected' : '' ?>>First Shift</option>
          <option value="Second Shift" <?= ($schedule[$day . '_shift'] ?? '') === 'Second Shift' ? 'selected' : '' ?>>Second Shift</option>
        </select>
      </label>
      <label>Start Time:
        <input type="time" name="<?= $day ?>_start_time" value="<?= $schedule[$day . '_start_time'] ?? '' ?>">
      </label>
      <label>Duration (mins):
        <input type="number" name="<?= $day ?>_duration" value="<?= $schedule[$day . '_duration'] ?? '' ?>">
      </label>
    </fieldset>
  <?php endforeach; ?>
  <button type="submit">💾 Save Schedule</button>
</form>
