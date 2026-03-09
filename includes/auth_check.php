<?php
// includes/auth_check.php

// Function to check if the user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Function to check if the logged-in user has the given role
function checkRole($role) {
    // Ensure user is logged in and the role matches
    if (isset($_SESSION['role']) && $_SESSION['role'] === $role) {
        return true;
    }
    return false;
}

// Redirect to login page if user is not logged in
function redirectToLogin() {
    header("Location: /login.php");

    exit();
}

// Redirect to the appropriate dashboard based on user role
function redirectToDashboard() {
    if (isset($_SESSION['role'])) {
        if ($_SESSION['role'] === 'patient') {
            echo "<script>alert('Patients cannot log in through the web. Please use the mobile app.'); window.location.href = 'login.php';</script>";
            exit();
        } elseif ($_SESSION['role'] === 'admin') {
            header("Location: admin/dashboard.php");
        } elseif ($_SESSION['role'] === 'nurse') {
            header("Location: nurse/dashboard.php");
        } else {
            header("Location: index.html");
        }
        exit();
    }
}
?>
