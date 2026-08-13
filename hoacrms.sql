-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jul 24, 2026 at 02:27 PM
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
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`AdminID`, `UserID`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 6, '2026-07-24 22:06:03', '2026-07-24 22:06:03');

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
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`DepartmentID`, `DepartmentName`) VALUES
(1, 'Pediatrics'),
(2, 'Obstetrics and Gynecology (OB-GYN)'),
(3, 'Surgery'),
(4, 'Nephrology'),
(5, 'Internal Medicine / Pulmonology');

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
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`RoleID`, `RoleName`) VALUES
(1, 'Admin'),
(2, 'Doctor'),
(3, 'Patient');

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
  `Specialization` varchar(100) DEFAULT NULL,
  `AvailabilityStatus` varchar(50) NOT NULL DEFAULT 'Available',
  `ScheduleStart` time DEFAULT NULL,
  `ScheduleEnd` time DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`StaffID`),
  KEY `FK_Staff_Users` (`UserID`),
  KEY `FK_Staff_Departments` (`DepartmentID`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`StaffID`, `UserID`, `DepartmentID`, `StaffRole`, `Specialization`, `AvailabilityStatus`, `ScheduleStart`, `ScheduleEnd`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 5, 1, 'Doctor', NULL, 'Available', NULL, NULL, '2026-07-24 22:02:07', '2026-07-24 22:02:07'),
(2, 7, 5, 'Doctor', NULL, 'Available', NULL, NULL, '2026-07-24 22:24:26', '2026-07-24 22:24:26');

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
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Email` (`Email`),
  KEY `FK_Users_Roles` (`RoleID`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`UserID`, `RoleID`, `FirstName`, `MiddleName`, `LastName`, `Email`, `Password`, `Sex`, `DateOfBirth`, `ContactNumber`, `Address`, `Status`, `CreatedAt`, `FailedAttempts`, `LockUntil`, `ResetToken`, `TokenExpiry`) VALUES
(2, 3, 'ROZ', NULL, 'LAGABAN', 'lagabanroz22@gmail.com', '$2y$10$GJkWCjrPPEGRw8PCPRWFmefWye6Lma24Oca.XK.G/Q9LefqPVxBLu', 'Not Specified', NULL, '09913095266', NULL, 'Active', '2026-07-23 23:31:34', 0, NULL, NULL, NULL),
(3, 3, 'ROZi', NULL, 'LAGABAN', 'lagabanroz@gmail.com', '$2y$10$.G/GbgoZBzj1iQiGYVgk1u70HA3tOm3PuxtFxKFqKBD6F5SvyJWZC', 'Not Specified', NULL, '09913095265', NULL, 'Active', '2026-07-23 23:36:00', 0, NULL, NULL, NULL),
(4, 3, 'ROZii', NULL, 'LAGABAN', 'lagabanro@gmail.com', '$2y$10$BUg/Tfu2XClNq/Hq/mBnPuoDpLoGhZUPbARngVasAsGMWaWwpFsK.', 'Not Specified', NULL, '09913095264', NULL, 'Active', '2026-07-23 23:37:26', 0, NULL, '05f74c765b146243a13d91576fab2febc4a424f4cb4a1bd3436b2589b7d14ff7', '2026-07-23 18:01:06'),
(5, 2, 'RIAN', NULL, 'LAGABAN', 'lagabanrian@gmail.com', '$2y$10$qKGSy23Edh0vO3MdiBTWYuCcp.MEA8AR9oykygpwpwMTh65MNqA9C', 'Not Specified', NULL, '09123456789', NULL, 'Active', '2026-07-24 22:02:07', 0, NULL, NULL, NULL),
(6, 1, 'Rion', NULL, 'Lagaban', 'lagabanrion@gmail.com', '$2y$10$NeHRS6.vel.qeLAqpaEnruWGztDodu6SClcU2H395q44jeUewtGra', 'Not Specified', NULL, '09987654321', NULL, 'Active', '2026-07-24 22:06:03', 0, NULL, NULL, NULL),
(7, 2, 'Edrian', NULL, 'Bagohara', 'edrianbagohar@gmail.com', '$2y$10$2tMALSjSnQdi7ng/SPh7L.Q4tTnUUE9fexRn/5Qwikis377/Zm9xa', 'Not Specified', NULL, '09456789321', NULL, 'Active', '2026-07-24 22:24:26', 0, NULL, NULL, NULL);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
