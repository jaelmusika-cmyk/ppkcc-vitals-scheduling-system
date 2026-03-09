<?php
// includes/header.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DialiEase - Kidney Care</title>
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="icon" href="/assets/logo.png">
</head>
<body>
    <nav>
        <ul>
            <?php if (isset($_SESSION['role'])): ?>
                <li><a href="/">Home</a></li>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <li><a href="/admin/dashboard.php">Admin Dashboard</a></li>
                    <li><a href="/admin/manage_users.php">Manage Users</a></li>
                    <li><a href="/admin/manage_schedules.php">Manage Schedules</a></li>
                    <li><a href="/admin/view_announcements.php">Announcements</a></li>
                <?php elseif ($_SESSION['role'] === 'nurse'): ?>
                    <li><a href="/nurse/dashboard.php">Nurse Dashboard</a></li>
                    <li><a href="/nurse/view_schedules.php">View Schedules</a></li>
                    <li><a href="/nurse/record_vitals.php">Record Vitals</a></li>
                    <li><a href="/nurse/vitals_history.php">Vitals History</a></li>
                <?php endif; ?>
                <li><a href="/logout.php">Logout</a></li>
            <?php else: ?>
                <li><a href="/login.php">Login</a></li>
            <?php endif; ?>
        </ul>
    </nav>
