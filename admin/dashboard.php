<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include('../config/db.php');
require_once '../includes/auth_check.php';
// this must define the 3 auth functions
include('../admin/admin_sidebar.php');

if (!isLoggedIn() || !checkRole('admin')) {
    redirectToLogin();
}

// =================== COUNTS ===================
// Count active registered patients
$registeredPatientsCount = $pdo->query("
    SELECT COUNT(*) FROM users WHERE role = 'patient' AND status = 'active'
")->fetchColumn();

// Count unregistered patients
$unregisteredPatientsCount = $pdo->query("SELECT COUNT(*) FROM unregistered_patients")->fetchColumn();

// Calculate total patients
$totalPatientsCount = $registeredPatientsCount + $unregisteredPatientsCount;

// **NEW:** Count pending informed absences
$absentNoticesCount = $pdo->query("
    SELECT COUNT(*) FROM informed_absences WHERE status IN ('sent')
")->fetchColumn();

// Pending Schedule Requests count (only active users)
$pendingRequestsCount = $pdo->query("
    SELECT COUNT(sr.id)
    FROM schedule_requests sr
    JOIN patients p ON sr.patient_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE sr.status = 'Pending' AND u.status = 'active'
")->fetchColumn();

// Pending Fee Payments count (only active users)
$pendingPaymentsCount = $pdo->query("
    SELECT COUNT(pr.id)
    FROM payment_reminders pr
    JOIN patients p ON pr.patient_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE pr.status = 'unpaid' AND u.status = 'active'
")->fetchColumn();

// =================== WEEKLY SCHEDULE ===================
$scheduleData = [];
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$stmt = $pdo->prepare("
    SELECT schedules.*,
        COALESCE(patients.full_name, unregistered_patients.full_name) AS full_name
    FROM schedules
    LEFT JOIN patients ON schedules.patient_id = patients.id
    LEFT JOIN users ON patients.user_id = users.id
    LEFT JOIN unregistered_patients ON schedules.id = unregistered_patients.schedule_id
    WHERE schedules.status = 'Scheduled'
      AND schedules.deleted = 0
      AND (
        (schedules.patient_id IS NOT NULL AND users.status = 'active')
          OR unregistered_patients.id IS NOT NULL
      )
");
$stmt->execute();
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($schedules as $row) {
    foreach ($days as $day) {
        $day_lc = strtolower($day);
        $shift = $row["{$day_lc}_shift"];
        if (!empty($shift)) {
            $scheduleData[$day][] = [
                'name' => $row['full_name'],
                'shift' => $shift
            ];
        }
    }
}

// =================== LATEST LOG ===================
$log = $pdo->query("
    SELECT * FROM schedule_logs
    ORDER BY timestamp DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// =================== LATEST ANNOUNCEMENT ===================
$latestAnnouncement = $pdo->query("
    SELECT * FROM announcements
    ORDER BY created_at DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);


// =================== Format Helpers ===================
function formatTimestamp($ts) {
    return $ts ? date('n/j/y | g:i A', strtotime($ts)) : 'N/A';
}

/**
 * Truncates a string to a specified length.
 *
 * @param string $string The string to truncate.
 * @param int $length The maximum length of the string.
 * @param string $ellipsis The characters to append if the string is truncated.
 * @return string The truncated string.
 */
function truncateText($string, $length = 100, $ellipsis = '...') {
    if (strlen($string) > $length) {
        return substr($string, 0, $length) . $ellipsis;
    }
    return $string;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - DialiEase</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon_io/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon_io/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon_io/favicon-16x16.png">
    <link rel="manifest" href="/favicon_io/site.webmanifest">
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; margin: 0; }
        .content { padding: 20px; margin-left: 60px; }
        .metrics { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px; margin-top: 10px; }

        /* --- CARD STYLES --- */
        .card-link-wrapper {
            flex: 1 1 220px;
            text-decoration: none;
            color: inherit;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
            height: 100%;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .card h2 { font-size: 32px; margin: 0 0 8px; }
        .card h2 .total-count { font-size: 20px; color: #888; }
        .card p { margin: 0; color: #555; }
        /* --- END CARD STYLES --- */

        .main-content { display: flex; flex-wrap: wrap; gap: 20px; }
        
        /* Right column for logs and announcements */
        .right-column {
            flex: 1;
            min-width: 260px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .schedule-box, .updates-box, .announcement-box {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            position: relative;
        }

        .schedule-box { flex: 2; min-width: 300px; }

        .schedule-box .top-button {
            position: absolute; top: 15px; right: 20px;
            font-size: 14px; /* Adjusted for icon */
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            padding: 8px 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .schedule-box .top-button:hover {
            background-color: #0056b3;
        }

        .day-col { display: flex; flex-direction: column; padding: 10px; }
        .day-label { font-weight: bold; margin-bottom: 8px; }

        .shift-green {
            background: green; color: white; border-radius: 6px;
            padding: 2px 8px; margin-bottom: 3px;
            font-size: 14px; line-height: 1.2;
        }
        .shift-gold {
            background: gold; color: black; border-radius: 6px;
            padding: 2px 8px; margin-bottom: 3px;
            font-size: 14px; line-height: 1.2;
        }

        .legend { margin-bottom: 10px; font-size: 14px; }
        .legend span { margin-right: 15px; }
        .legend .green { color: green; font-weight: bold; }
        .legend .gold { color: goldenrod; font-weight: bold; }

        .updates-box .log-entry, .announcement-box .announcement-entry {
            border-left: 4px solid #ccc; padding-left: 10px;
        }
        .updates-box .log-entry small, .announcement-box .announcement-entry small {
            color: #999; font-size: 12px; display: block; margin-top: 4px;
        }
        .announcement-box .announcement-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 5px;
        }

        .log-footer { text-align: right; margin-top: 15px; }
        .log-footer a { font-size: 14px; text-decoration: none; color: #007bff; }
        .log-footer a:hover { text-decoration: underline; }
        .log-footer a i { margin-right: 5px; }

        @media screen and (max-width: 768px) {
            .metrics, .main-content { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="content">
    <h1>Admin Dashboard</h1>

    <div class="metrics">
        <a href="manage_users.php" class="card-link-wrapper">
            <div class="card">
                <h2><?= $registeredPatientsCount ?> <span class="total-count">/ <?= $totalPatientsCount ?></span></h2>
                <p>Registered Patients</p>
            </div>
        </a>
        
        <a href="notifications.php" class="card-link-wrapper">
            <div class="card">
                <h2><?= $absentNoticesCount ?></h2>
                <p>Absent Notices</p>
            </div>
        </a>
        
        <a href="manage_schedules.php" class="card-link-wrapper">
            <div class="card">
                <h2><?= $pendingRequestsCount ?></h2>
                <p>Pending Schedule Requests</p>
            </div>
        </a>
        <a href="notifications.php" class="card-link-wrapper">
            <div class="card">
                <h2><?= $pendingPaymentsCount ?></h2>
                <p>Pending Fee Payments</p>
            </div>
        </a>
    </div>

    <div class="main-content">
        <div class="schedule-box">
            <a href="manage_schedules.php" class="top-button">
                <i class="fa-solid fa-calendar-days"></i> Manage Schedules
            </a>
            <div class="legend">
                <span class="green">First Shift</span>
                <span class="gold">Second Shift</span>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php foreach ($days as $day): ?>
                    <div class="day-col" style="flex: 1;">
                        <div class="day-label"><?= $day ?></div>
                        <?php
                            if (!empty($scheduleData[$day])) {
                                $first = array_filter($scheduleData[$day], fn($p) => $p['shift'] === 'First Shift');
                                $second = array_filter($scheduleData[$day], fn($p) => $p['shift'] === 'Second Shift');
                                $combined = array_merge($first, $second);
                                foreach ($combined as $entry):
                        ?>
                            <div class="<?= $entry['shift'] === 'First Shift' ? 'shift-green' : 'shift-gold' ?>">
                                <?= htmlspecialchars($entry['name']) ?>
                            </div>
                        <?php endforeach; } else { ?>
                            <div style="color: gray; font-size: 13px;">No patients</div>
                        <?php } ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="right-column">
            <div class="announcement-box">
                <h3>Latest Announcement</h3>
                <?php if ($latestAnnouncement): ?>
                    <div class="announcement-entry">
                        <div class="announcement-title"><?= htmlspecialchars($latestAnnouncement['title']) ?></div>
                        <p><?= nl2br(htmlspecialchars(truncateText($latestAnnouncement['message']))) ?></p>
                        <small><i class="fa-regular fa-clock"></i> <?= formatTimestamp($latestAnnouncement['created_at']) ?></small>
                    </div>
                <?php else: ?>
                    <p>No announcements found.</p>
                <?php endif; ?>
                <div class="log-footer">
                    <a href="notifications.php"><i class="fa-solid fa-bullhorn"></i> View Announcements</a>
                </div>
            </div>
            <div class="updates-box">
                <h3>Latest Scheduling Update</h3>
                <?php if ($log): ?>
                    <div class="log-entry">
                        <strong><?= htmlspecialchars($log['performed_by']) ?></strong><br>
                        <?= nl2br(htmlspecialchars(truncateText($log['action_details'], 80))) ?><br>
                        <small><i class="fa-regular fa-clock"></i> <?= formatTimestamp($log['timestamp']) ?></small>
                    </div>
                <?php else: ?>
                    <p>No recent scheduling updates.</p>
                <?php endif; ?>
                <div class="log-footer">
                    <a href="logs.php"><i class="fa-solid fa-history"></i> View All Logs</a>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>