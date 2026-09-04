-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 15, 2026 at 07:51 AM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hoacrms`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

DROP TABLE IF EXISTS `admin`;
CREATE TABLE IF NOT EXISTS `admin` (
  `AdminID` int NOT NULL AUTO_INCREMENT,
  `UserID` int NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`AdminID`),
  KEY `FK_Admin_Users` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`AdminID`, `UserID`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 6, '2026-07-24 22:06:03', '2026-07-24 22:06:03');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
CREATE TABLE IF NOT EXISTS `appointments` (
  `AppointmentID` int NOT NULL AUTO_INCREMENT,
  `PatientID` int NOT NULL,
  `StaffID` int DEFAULT NULL,
  `DepartmentID` int NOT NULL,
  `AppointmentDate` date NOT NULL,
  `AppointmentTime` time NOT NULL,
  `Purpose` varchar(255) DEFAULT NULL,
  `Status` varchar(50) NOT NULL DEFAULT 'Pending',
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`AppointmentID`),
  KEY `fk_appointments_patient` (`PatientID`),
  KEY `fk_appointments_staff` (`StaffID`),
  KEY `fk_appointments_department` (`DepartmentID`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`AppointmentID`, `PatientID`, `StaffID`, `DepartmentID`, `AppointmentDate`, `AppointmentTime`, `Purpose`, `Status`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 1, NULL, 1, '2026-08-20', '10:30:00', 'General consultation', 'Scheduled', '2026-08-14 00:43:31', '2026-08-14 00:43:31'),
(2, 1, 2, 5, '2026-08-21', '10:00:00', 'General Consulatation', 'Pending', '2026-08-14 15:34:14', '2026-08-14 15:34:14'),
(3, 1, 2, 5, '2026-08-26', '10:00:00', 'General Consulatation', 'Pending', '2026-08-14 15:34:27', '2026-08-14 15:34:27'),
(4, 1, NULL, 5, '0000-00-00', '08:30:00', 'General Consulatation', 'Pending', '2026-08-14 16:33:44', '2026-08-14 16:33:44'),
(5, 1, NULL, 5, '0000-00-00', '08:30:00', 'General Consulatation', 'Pending', '2026-08-14 16:33:45', '2026-08-14 16:33:45'),
(6, 1, NULL, 5, '0000-00-00', '06:00:00', 'General Consulatation', 'Pending', '2026-08-14 16:37:51', '2026-08-14 16:37:51'),
(7, 1, 2, 5, '2026-05-15', '06:30:00', 'Check up', 'Pending', '2026-08-14 18:00:41', '2026-08-14 18:00:41');

-- --------------------------------------------------------

--
-- Table structure for table `audit_trail`
--

DROP TABLE IF EXISTS `audit_trail`;
CREATE TABLE IF NOT EXISTS `audit_trail` (
  `AuditID` int NOT NULL AUTO_INCREMENT,
  `UserID` int NOT NULL,
  `Action` varchar(50) NOT NULL,
  `TableName` varchar(100) NOT NULL,
  `RecordID` int DEFAULT NULL,
  `OldValue` longtext,
  `NewValue` longtext,
  `IPAddress` varchar(45) DEFAULT NULL,
  `UserAgent` varchar(255) DEFAULT NULL,
  `ActionTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`AuditID`),
  KEY `fk_audit_trail_user` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consultations`
--

DROP TABLE IF EXISTS `consultations`;
CREATE TABLE IF NOT EXISTS `consultations` (
  `ConsultationID` int NOT NULL AUTO_INCREMENT,
  `AppointmentID` int NOT NULL,
  `PatientID` int NOT NULL,
  `StaffID` int NOT NULL,
  `ConsultationDate` date NOT NULL,
  `ConsultationTime` time NOT NULL,
  `ChiefComplaint` varchar(255) DEFAULT NULL,
  `Diagnosis` text,
  `Treatment` text,
  `LabRequest` text,
  `Notes` text,
  `FollowUpDate` date DEFAULT NULL,
  `Status` varchar(50) NOT NULL DEFAULT 'Ongoing',
  `BloodPressure` varchar(20) DEFAULT NULL,
  `Temperature` decimal(4,1) DEFAULT NULL,
  `PulseRate` int DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ConsultationID`),
  KEY `fk_consultations_appointment` (`AppointmentID`),
  KEY `fk_consultations_patient` (`PatientID`),
  KEY `fk_consultations_staff` (`StaffID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vitals`
--

DROP TABLE IF EXISTS `vitals`;
CREATE TABLE IF NOT EXISTS `vitals` (
  `VitalID` int NOT NULL AUTO_INCREMENT,
  `AppointmentID` int NOT NULL,
  `PatientID` int NOT NULL,
  `StaffID` int NOT NULL,
  `BloodPressure` varchar(20) DEFAULT NULL,
  `Temperature` decimal(4,1) DEFAULT NULL,
  `PulseRate` int DEFAULT NULL,
  `RespiratoryRate` int DEFAULT NULL,
  `Weight` decimal(5,2) DEFAULT NULL,
  `Height` decimal(5,2) DEFAULT NULL,
  `OxygenSaturation` int DEFAULT NULL,
  `RecordedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`VitalID`),
  KEY `idx_vitals_patient` (`PatientID`),
  KEY `idx_vitals_appointment` (`AppointmentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
CREATE TABLE IF NOT EXISTS `departments` (
  `DepartmentID` int NOT NULL AUTO_INCREMENT,
  `DepartmentName` varchar(100) NOT NULL,
  PRIMARY KEY (`DepartmentID`),
  UNIQUE KEY `DepartmentName` (`DepartmentName`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`DepartmentID`, `DepartmentName`) VALUES
(5, 'Internal Medicine / Pulmonology'),
(4, 'Nephrology'),
(2, 'Obstetrics and Gynecology (OB-GYN)'),
(1, 'Pediatrics'),
(3, 'Surgery');

-- --------------------------------------------------------

--
-- Table structure for table `department_schedules`
--

DROP TABLE IF EXISTS `department_schedules`;
CREATE TABLE IF NOT EXISTS `department_schedules` (
  `DepartmentScheduleID` int NOT NULL AUTO_INCREMENT,
  `DepartmentID` int NOT NULL,
  `DayOfWeek` tinyint NOT NULL,
  `SessionName` varchar(20) NOT NULL,
  `StartTime` time NOT NULL,
  `EndTime` time NOT NULL,
  PRIMARY KEY (`DepartmentScheduleID`),
  UNIQUE KEY `unique_department_schedule` (`DepartmentID`,`DayOfWeek`,`SessionName`)
) ENGINE=MyISAM AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `department_schedules`
--

INSERT INTO `department_schedules` (`DepartmentScheduleID`, `DepartmentID`, `DayOfWeek`, `SessionName`, `StartTime`, `EndTime`) VALUES
(1, 5, 1, 'Morning', '08:00:00', '12:00:00'),
(2, 5, 1, 'Afternoon', '13:00:00', '17:00:00'),
(3, 5, 2, 'Morning', '08:00:00', '12:00:00'),
(4, 5, 2, 'Afternoon', '13:00:00', '17:00:00'),
(5, 5, 3, 'Morning', '08:00:00', '12:00:00'),
(6, 5, 3, 'Afternoon', '13:00:00', '17:00:00'),
(7, 5, 4, 'Morning', '08:00:00', '12:00:00'),
(8, 5, 4, 'Afternoon', '13:00:00', '17:00:00'),
(9, 5, 5, 'Morning', '08:00:00', '12:00:00'),
(10, 5, 5, 'Afternoon', '13:00:00', '17:00:00'),
(11, 4, 3, 'Afternoon', '13:00:00', '17:00:00'),
(12, 2, 2, 'Afternoon', '13:00:00', '17:00:00'),
(13, 1, 3, 'Morning', '08:00:00', '12:00:00'),
(14, 1, 4, 'Morning', '08:00:00', '12:00:00'),
(15, 1, 4, 'Afternoon', '13:00:00', '17:00:00'),
(16, 1, 5, 'Morning', '08:00:00', '12:00:00'),
(17, 1, 5, 'Afternoon', '13:00:00', '17:00:00'),
(18, 3, 1, 'Morning', '08:00:00', '12:00:00'),
(19, 3, 2, 'Morning', '08:00:00', '12:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `NotificationID` int NOT NULL AUTO_INCREMENT,
  `UserID` int NOT NULL,
  `Title` varchar(255) NOT NULL,
  `Message` text NOT NULL,
  `Type` varchar(100) DEFAULT NULL,
  `RelatedID` int DEFAULT NULL,
  `RelatedTable` varchar(100) DEFAULT NULL,
  `IsRead` bit(1) NOT NULL DEFAULT b'0',
  `PriorityLevel` varchar(50) NOT NULL DEFAULT 'Low',
  `SentAt` datetime DEFAULT NULL,
  `ReadAt` datetime DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`NotificationID`),
  KEY `fk_notifications_user` (`UserID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
CREATE TABLE IF NOT EXISTS `patients` (
  `PatientID` int NOT NULL AUTO_INCREMENT,
  `UserID` int NOT NULL,
  `CivilStatus` varchar(50) DEFAULT NULL,
  `Religion` varchar(50) DEFAULT NULL,
  `IsPWD` tinyint(1) NOT NULL DEFAULT '0',
  `DisabilityType` varchar(100) DEFAULT NULL,
  `BloodType` varchar(5) DEFAULT NULL,
  `Allergies` text,
  `PastMedicalCondition` text,
  `CurrentMedication` text,
  `FamilyMedicalHistory` text,
  `EmergencyContactName` varchar(100) DEFAULT NULL,
  `EmergencyContactNo` varchar(20) DEFAULT NULL,
  `EmergencyRelation` varchar(50) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`PatientID`),
  KEY `FK_Patients_Users` (`UserID`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`PatientID`, `UserID`, `CivilStatus`, `Religion`, `IsPWD`, `DisabilityType`, `BloodType`, `Allergies`, `PastMedicalCondition`, `CurrentMedication`, `FamilyMedicalHistory`, `EmergencyContactName`, `EmergencyContactNo`, `EmergencyRelation`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 8, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-13 23:23:33', '2026-08-13 23:23:33');

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

DROP TABLE IF EXISTS `prescriptions`;
CREATE TABLE IF NOT EXISTS `prescriptions` (
  `PrescriptionID` int NOT NULL AUTO_INCREMENT,
  `ConsultationID` int NOT NULL,
  `PrescribedDate` date NOT NULL,
  `Instructions` text,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`PrescriptionID`),
  KEY `fk_prescriptions_consultation` (`ConsultationID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescription_items`
--

DROP TABLE IF EXISTS `prescription_items`;
CREATE TABLE IF NOT EXISTS `prescription_items` (
  `PrescriptionItemID` int NOT NULL AUTO_INCREMENT,
  `PrescriptionID` int NOT NULL,
  `MedicineName` varchar(255) NOT NULL,
  `Dosage` varchar(100) DEFAULT NULL,
  `Frequency` varchar(100) DEFAULT NULL,
  `Duration` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`PrescriptionItemID`),
  KEY `fk_prescription_items_prescription` (`PrescriptionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `queue`
--

DROP TABLE IF EXISTS `queue`;
CREATE TABLE IF NOT EXISTS `queue` (
  `QueueID` int NOT NULL AUTO_INCREMENT,
  `AppointmentID` int NOT NULL,
  `QueueNumber` int NOT NULL,
  `PriorityLevel` varchar(50) NOT NULL DEFAULT 'Normal',
  `QueueDate` date NOT NULL,
  `QueueTime` time NOT NULL,
  `Status` varchar(50) NOT NULL DEFAULT 'Waiting',
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`QueueID`),
  KEY `fk_queue_appointment` (`AppointmentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

DROP TABLE IF EXISTS `reports`;
CREATE TABLE IF NOT EXISTS `reports` (
  `ReportID` int NOT NULL AUTO_INCREMENT,
  `GeneratedBy` int NOT NULL,
  `ReportType` varchar(100) NOT NULL,
  `ReportTitle` varchar(255) NOT NULL,
  `Description` text,
  `StartDate` date DEFAULT NULL,
  `EndDate` date DEFAULT NULL,
  `FilePath` varchar(255) DEFAULT NULL,
  `FormatStatus` varchar(50) DEFAULT NULL,
  `Status` varchar(50) NOT NULL DEFAULT 'Pending',
  `GeneratedAt` datetime DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ReportID`),
  KEY `fk_reports_user` (`GeneratedBy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `RoleID` int NOT NULL AUTO_INCREMENT,
  `RoleName` varchar(50) NOT NULL,
  PRIMARY KEY (`RoleID`),
  UNIQUE KEY `RoleName` (`RoleName`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`RoleID`, `RoleName`) VALUES
(1, 'Admin'),
(3, 'Patient'),
(2, 'Staff');

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

DROP TABLE IF EXISTS `staff`;
CREATE TABLE IF NOT EXISTS `staff` (
  `StaffID` int NOT NULL AUTO_INCREMENT,
  `UserID` int NOT NULL,
  `DepartmentID` int NOT NULL,
  `StaffRole` varchar(50) NOT NULL,
  `Suffix` varchar(20) DEFAULT NULL,
  `Specialization` varchar(100) DEFAULT NULL,
  `AvailabilityStatus` varchar(50) NOT NULL DEFAULT 'Available',
  `DateHired` date DEFAULT NULL,
  `ScheduleStart` time DEFAULT NULL,
  `ScheduleEnd` time DEFAULT NULL,
  `AssignedDays` varchar(100) DEFAULT NULL,
  `AssignedResponsibilities` text,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`StaffID`),
  KEY `FK_Staff_Users` (`UserID`),
  KEY `FK_Staff_Departments` (`DepartmentID`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`StaffID`, `UserID`, `DepartmentID`, `StaffRole`, `Specialization`, `AvailabilityStatus`, `ScheduleStart`, `ScheduleEnd`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 5, 1, 'Doctor', NULL, 'Available', NULL, NULL, '2026-07-24 22:02:07', '2026-07-24 22:02:07'),
(2, 7, 5, 'Doctor', NULL, 'Available', NULL, NULL, '2026-07-24 22:24:26', '2026-07-24 22:24:26'),
(3, 9, 1, 'Doctor', 'Developmental-Behavioral', 'Available', NULL, NULL, '2026-08-15 13:59:03', '2026-08-15 13:59:03');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `UserID` int NOT NULL AUTO_INCREMENT,
  `RoleID` int NOT NULL,
  `FirstName` varchar(100) NOT NULL,
  `MiddleName` varchar(100) DEFAULT NULL,
  `LastName` varchar(100) NOT NULL,
  `Email` varchar(191) NOT NULL,
  `Password` varchar(255) NOT NULL,
  `Sex` varchar(13) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `DateOfBirth` date DEFAULT NULL,
  `ContactNumber` varchar(20) DEFAULT NULL,
  `Address` text,
  `Status` varchar(50) NOT NULL DEFAULT 'Active',
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `FailedAttempts` int DEFAULT '0',
  `LockUntil` datetime DEFAULT NULL,
  `ResetToken` varchar(255) DEFAULT NULL,
  `TokenExpiry` datetime DEFAULT NULL,
  `ProfilePhoto` varchar(255) DEFAULT NULL,
  `LastLogin` datetime DEFAULT NULL,
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Email` (`Email`),
  KEY `FK_Users_Roles` (`RoleID`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`UserID`, `RoleID`, `FirstName`, `MiddleName`, `LastName`, `Email`, `Password`, `Sex`, `DateOfBirth`, `ContactNumber`, `Address`, `Status`, `CreatedAt`, `FailedAttempts`, `LockUntil`, `ResetToken`, `TokenExpiry`) VALUES
(2, 3, 'ROZ', NULL, 'LAGABAN', 'lagabanroz22@gmail.com', '$2y$10$GJkWCjrPPEGRw8PCPRWFmefWye6Lma24Oca.XK.G/Q9LefqPVxBLu', 'Not Specified', NULL, '09913095266', NULL, 'Active', '2026-07-23 23:31:34', 1, NULL, NULL, NULL),
(3, 3, 'ROZi', NULL, 'LAGABAN', 'lagabanroz@gmail.com', '$2y$10$.G/GbgoZBzj1iQiGYVgk1u70HA3tOm3PuxtFxKFqKBD6F5SvyJWZC', 'Not Specified', NULL, '09913095265', NULL, 'Active', '2026-07-23 23:36:00', 0, NULL, NULL, NULL),
(4, 3, 'ROZii', NULL, 'LAGABAN', 'lagabanro@gmail.com', '$2y$10$BUg/Tfu2XClNq/Hq/mBnPuoDpLoGhZUPbARngVasAsGMWaWwpFsK.', 'Not Specified', NULL, '09913095264', NULL, 'Active', '2026-07-23 23:37:26', 0, NULL, '05f74c765b146243a13d91576fab2febc4a424f4cb4a1bd3436b2589b7d14ff7', '2026-07-23 18:01:06'),
(5, 2, 'RIAN', NULL, 'LAGABAN', 'lagabanrian@gmail.com', '$2y$10$qKGSy23Edh0vO3MdiBTWYuCcp.MEA8AR9oykygpwpwMTh65MNqA9C', 'Not Specified', NULL, '09123456789', NULL, 'Active', '2026-07-24 22:02:07', 0, NULL, NULL, NULL),
(6, 1, 'Rion', NULL, 'Lagaban', 'lagabanrion@gmail.com', '$2y$10$NeHRS6.vel.qeLAqpaEnruWGztDodu6SClcU2H395q44jeUewtGra', 'Not Specified', NULL, '09987654321', NULL, 'Active', '2026-07-24 22:06:03', 0, NULL, NULL, NULL),
(7, 2, 'Edrian', NULL, 'Bagohara', 'edrianbagohar@gmail.com', '$2y$10$2tMALSjSnQdi7ng/SPh7L.Q4tTnUUE9fexRn/5Qwikis377/Zm9xa', 'Not Specified', NULL, '09456789321', NULL, 'Active', '2026-07-24 22:24:26', 0, NULL, NULL, NULL),
(8, 3, 'Jedrick', NULL, 'Versoza', 'jedvesoza@gmail.com', '$2y$10$M.3mCghPzuQutueQsMfcAubXxDzz6Z1S6Q4BQfNbBDIw5SK/89Fce', 'Not Specified', NULL, '09456789321', NULL, 'Active', '2026-08-13 23:23:33', 0, NULL, NULL, NULL),
(9, 2, 'Roxzia', NULL, 'Kim', 'RoxziaKim@gmai.com', '$2y$10$YTVYBlQfE5M3yC7Qq36MZ.57cnKoa9wrTuKMYXbSLwPScj0HSbVs.', 'Not Specified', NULL, '09369852147', NULL, 'Active', '2026-08-15 13:59:03', 0, NULL, NULL, NULL);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_department` FOREIGN KEY (`DepartmentID`) REFERENCES `departments` (`DepartmentID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`PatientID`) REFERENCES `patients` (`PatientID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_staff` FOREIGN KEY (`StaffID`) REFERENCES `staff` (`StaffID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD CONSTRAINT `fk_audit_trail_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `consultations`
--
ALTER TABLE `consultations`
  ADD CONSTRAINT `fk_consultations_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consultations_patient` FOREIGN KEY (`PatientID`) REFERENCES `patients` (`PatientID`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consultations_staff` FOREIGN KEY (`StaffID`) REFERENCES `staff` (`StaffID`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `fk_prescriptions_consultation` FOREIGN KEY (`ConsultationID`) REFERENCES `consultations` (`ConsultationID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `prescription_items`
--
ALTER TABLE `prescription_items`
  ADD CONSTRAINT `fk_prescription_items_prescription` FOREIGN KEY (`PrescriptionID`) REFERENCES `prescriptions` (`PrescriptionID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `queue`
--
ALTER TABLE `queue`
  ADD CONSTRAINT `fk_queue_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `fk_reports_user` FOREIGN KEY (`GeneratedBy`) REFERENCES `users` (`UserID`) ON DELETE RESTRICT ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
