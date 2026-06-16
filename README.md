Repository 1: DialiEase Core System (Desktop/Web)

# DialiEase: Hemodialysis Vitals Scheduling and Monitoring System

DialiEase is a comprehensive web and mobile ecosystem designed to streamline dialysis session scheduling, automate nurse-patient assignments, and log real-time patient vitals. This repository contains the core desktop-accessible management system tailored for **Administrators** and **Nurses**. 

The system operates as a unified platform: Admins and Nurses manage configurations, schedules, and flowsheets via this web platform, while Patients request schedule modifications and track metrics using the companion Android application.

---

## 🚀 Key Modules & System Features

### 1. Administrative Module
* **User & Account Management:** Full CRUD operations on Admin, Nurse, and Patient profiles. Supports archiving inactive accounts to keep historical logs secure while preserving clarity in live workflows.
* **Schedule Optimization:** Comprehensive schedule building tools supporting batch generation and validation of recurring dialysis time-slots.
* **System Metrics & Activity Auditing:** Consolidated, server-side log filters mapping account updates and scheduling changes to maintain compliance and traceability.

### 2. Clinical (Nurse) Module
* **Electronic Vitals Flowsheets:** Real-time generation of digital clinical flowsheets automatically sorted by date and dialysis shifts.
* **Vitals Ingestion:** Quick-entry logs for tracking intra-dialytic patient readings (e.g., blood pressure, pulse rate, arterial/venous pressures) linked directly to the nurse-in-charge.
* **Absence Resolution Workflow:** Automated resolution flags for handling patient informed absence notices, complete with trace actions stored securely in system logs.

### 3. Integrated Mobile API Surface (`/api` folder)
* Secure endpoint surface parsing HTTP/JSON data transfers to and from the mobile patient app.
* Handlers for mobile patient authentication, profile serialization, weekly swap requests, and vitals chart synchronization.

---

## 🛠️ Tech Stack & Requirements

* **Language:** PHP 8.x (Object-Oriented Architecture via PDO)
* **Database:** MySQL 5.7+ / 8.0+
* **Web Server:** Local Development Apache environment (XAMPP / Laragon / Wampserver)
* **Dependency Utilities:** Included standalone PHPMailer package (SMTP integration)

---

## ⚙️ Installation & Local Environment Setup

To run and evaluate this portfolio ecosystem locally, follow these comprehensive setup steps:

### 1. Environment Simulation Setup
1. Download and install **XAMPP** (with PHP 8.x compatibility).
2. Clone this repository directly into the local root server directory:
```bash
   # For standard XAMPP installations
   cd c:/xampp/htdocs/
   git clone [https://github.com/YOUR_USERNAME/ppkcc-vitals-scheduling-system.git](https://github.com/YOUR_USERNAME/ppkcc-vitals-scheduling-system.git) dialiease

### 2. Relational Database Initialization
Launch the XAMPP Control Panel and start both the Apache and MySQL service modules.

Open a web browser and navigate to the database management dashboard: http://localhost/phpmyadmin/.

Select New, establish a database named dialiease_db, and set the collation standard to utf8mb4_general_ci.

Open the dialiease_db database, click the Import tab at the top layout, browse to choose the schema file included in the root folder (dialiease_schema_and_seed.sql), and click Import. This fully instantiates the application structural definitions alongside mock entities.

3. Database Configuration Update
Open config/db.php in a text editor and verify the local pointer variables match the environment setup:

<?php
// config/db.php
try {
    $host = 'localhost';
    $dbname = 'dialiease_db';
    $username = 'root';
    $password = ''; // Default XAMPP configurations use an empty MySQL password

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'message' => 'Database connection broken: ' . $e->getMessage()]));
}
?>

4. Running the Local Initializer Script
To populate a fresh default administrative tier credential outside the pre-seeded users, trigger the embedded PHP script directly in the browser:

http://localhost/dialiease/create_admin.php
Output verification: Admin account created successfully!

🔐 Credentials Checklist for ReviewersThe system layout can be accessed locally using either the developer initializer or the pre-seeded dummy portfolio configurations:RoleEmail Access / UsernamePasswordSetting Module TargetAdminadmin@dialiease.compassword123/admin/dashboard.phpNursejane.nurse@dialiease.compassword123/nurse/dashboard.phpNote: Patients attempting to log into this web platform will receive a redirection error prompting them to use the Android companion application.📧 Outbound Email Configuration (OTP Verification)The system contains an automated mechanism for forgot-password workflows utilizing secure OTP delivery via PHPMailer. To test this feature locally, update the setup variables within forgot_password.php:

RoleEmail Access / UsernamePasswordSetting Module TargetAdminadmin@dialiease.compassword123/admin/dashboard.phpNursejane.nurse@dialiease.compassword123/nurse/dashboard.phpNote: Patients attempting to log into this web platform will receive a redirection error prompting them to use the Android companion application.📧 Outbound Email Configuration (OTP Verification)The system contains an automated mechanism for forgot-password workflows utilizing secure OTP delivery via PHPMailer. To test this feature locally, update the setup variables within forgot_password.php:
// Location snippet inside forgot_password.php
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';             // SMTP server provider
$mail->SMTPAuth = true;
$mail->Username = 'YOUR_EMAIL@gmail.com';    // Authenticated mailbox account
$mail->Password = 'YOUR_APP_PASSWORD';       // Secure generated application access key
$mail->SMTPSecure = 'tls';                  // TLS structural standard encryption
$mail->Port = 587;                          // Port mapping for connection standard
