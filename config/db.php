<?php
date_default_timezone_set('Asia/Manila');

// Ensure this runs cleanly
error_reporting(E_ALL);
ini_set('display_errors', 1);

// No output before this point
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** 
 * DATABASE CONFIGURATION
 * Replace these values if you are deploying to a different server.
 * Default values below are for local development (XAMPP/WAMP).
 */
$db_host = "localhost";
$db_name = "db_dialiease"; // You can rename this to your actual database name
$db_user = "root";         // Default XAMPP user
$db_pass = "";             // Default XAMPP password is empty

// MySQLi connection (used by older scripts)
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("MySQLi Connection failed: " . mysqli_connect_error());
}

// PDO connection (used by logs.php and secure queries)
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die("PDO Connection failed: " . $e->getMessage());
}
?>