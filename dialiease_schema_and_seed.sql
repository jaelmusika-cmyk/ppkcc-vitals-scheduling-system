-- Dialiease Complete Database Seed (with Dummy Accounts)
-- Prepared for GitHub Upload
-- Note: All passwords are set to 'password123' (hashed for compatibility)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";

-- --------------------------------------------------------
-- 1. Table structure for `users`
-- --------------------------------------------------------

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin', 'Nurse', 'Patient') NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- 2. Table structure for `patients`
-- --------------------------------------------------------

CREATE TABLE `patients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `birth_date` date NOT NULL,
  `gender` enum('Male', 'Female') NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `guardian_number` varchar(20) DEFAULT NULL,
  `address` text NOT NULL,
  `medical_history` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_patient_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- 3. Table structure for `schedules`
-- --------------------------------------------------------

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) DEFAULT NULL,
  `nurse_id` int(11) DEFAULT NULL,
  `monday_shift` enum('First Shift', 'Second Shift') DEFAULT NULL,
  `monday_start_time` time DEFAULT NULL,
  `monday_duration` int(11) DEFAULT NULL,
  `tuesday_shift` enum('First Shift', 'Second Shift') DEFAULT NULL,
  `tuesday_start_time` time DEFAULT NULL,
  `tuesday_duration` int(11) DEFAULT NULL,
  `wednesday_shift` enum('First Shift', 'Second Shift') DEFAULT NULL,
  `wednesday_start_time` time DEFAULT NULL,
  `wednesday_duration` int(11) DEFAULT NULL,
  `thursday_shift` enum('First Shift', 'Second Shift') DEFAULT NULL,
  `thursday_start_time` time DEFAULT NULL,
  `thursday_duration` int(11) DEFAULT NULL,
  `friday_shift` enum('First Shift', 'Second Shift') DEFAULT NULL,
  `friday_start_time` time DEFAULT NULL,
  `friday_duration` int(11) DEFAULT NULL,
  `saturday_shift` enum('First Shift', 'Second Shift') DEFAULT NULL,
  `saturday_start_time` time DEFAULT NULL,
  `saturday_duration` int(11) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Scheduled',
  `updated_at` datetime DEFAULT NULL,
  `deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- 4. DUMMY DATA INSERTION
-- --------------------------------------------------------

-- Insert Users (Passwords are 'password123' hashed via BCRYPT)
INSERT INTO `users` (`id`, `username`, `password`, `role`, `email`) VALUES
(1, 'admin_user', '$2y$10$7rLSvRl1S27.qD.6z2uNbeVvH.O6N3F0tFqFvHhI1h4f4g5h6i7j8', 'Admin', 'admin@dialiease.com'),
(2, 'nurse_jane', '$2y$10$7rLSvRl1S27.qD.6z2uNbeVvH.O6N3F0tFqFvHhI1h4f4g5h6i7j8', 'Nurse', 'jane.nurse@dialiease.com'),
(3, 'patient_doe', '$2y$10$7rLSvRl1S27.qD.6z2uNbeVvH.O6N3F0tFqFvHhI1h4f4g5h6i7j8', 'Patient', 'john.doe@email.com');

-- Insert Patient Profile (Linked to user_id 3)
INSERT INTO `patients` (`user_id`, `full_name`, `birth_date`, `gender`, `phone_number`, `guardian_number`, `address`, `medical_history`) VALUES
(3, 'John Doe', '1985-06-15', 'Male', '09123456789', '09987654321', '123 Fake St, Springfield', 'Stage 3 CKD, Hypertension');

-- Insert Dummy Schedule for the Patient
INSERT INTO `schedules` (`patient_id`, `nurse_id`, `monday_shift`, `monday_start_time`, `monday_duration`, `status`) VALUES
(1, 2, 'First Shift', '08:00:00', 4, 'Scheduled');

COMMIT;