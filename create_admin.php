<?php
// PHP script to create an admin account with hashed password

require_once 'config/db.php'; // Make sure this points to your DB connection

$email = 'admin@dialiEase.com';
$password = 'password123'; // The password you want to set for the admin

// Hash the password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Prepare SQL query to insert the new admin account
$sql = "INSERT INTO users (full_name, email, password, role, created_at) 
        VALUES ('Admin', :email, :password, 'admin', NOW())";

$stmt = $pdo->prepare($sql);
$stmt->execute(['email' => $email, 'password' => $hashedPassword]);

echo "Admin account created successfully!";
?>
