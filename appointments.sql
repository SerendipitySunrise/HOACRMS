-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 04, 2026 at 03:44 AM
-- Server version: 8.0.43
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
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`AppointmentID`, `PatientID`, `StaffID`, `DepartmentID`, `AppointmentDate`, `AppointmentTime`, `Purpose`, `Status`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 1, NULL, 1, '2026-08-20', '10:30:00', 'General consultation', 'In Consultation', '2026-08-14 00:43:31', '2026-08-20 19:14:32'),
(2, 1, 2, 5, '2026-08-21', '10:00:00', 'General Consulatation', 'Completed', '2026-08-14 15:34:14', '2026-08-21 20:16:39'),
(3, 1, 2, 5, '2026-08-26', '10:00:00', 'General Consulatation', 'Cancelled', '2026-08-14 15:34:27', '2026-08-21 20:40:06'),
(4, 1, NULL, 5, '0000-00-00', '08:30:00', 'General Consulatation', 'Pending', '2026-08-14 16:33:44', '2026-08-14 16:33:44'),
(5, 1, NULL, 5, '0000-00-00', '08:30:00', 'General Consulatation', 'Pending', '2026-08-14 16:33:45', '2026-08-14 16:33:45'),
(6, 1, NULL, 5, '0000-00-00', '06:00:00', 'General Consulatation', 'Cancelled', '2026-08-14 16:37:51', '2026-08-29 02:01:48'),
(7, 1, 2, 5, '2026-05-15', '06:30:00', 'Check up', 'Pending', '2026-08-14 18:00:41', '2026-08-14 18:00:41'),
(8, 1, 1, 1, '2026-08-21', '14:00:00', 'check up', 'Completed', '2026-08-17 19:09:34', '2026-08-21 20:16:35'),
(9, 1, 1, 1, '2026-08-28', '10:30:00', 'General Consultation', 'Cancelled', '2026-08-19 13:19:49', '2026-08-21 20:40:15'),
(10, 1, 1, 1, '2026-08-28', '13:00:00', 'check up', 'Cancelled', '2026-08-19 13:21:04', '2026-08-21 20:40:17'),
(11, 1, 2, 5, '2026-08-27', '14:30:00', 'General Consultation', 'Cancelled', '2026-08-19 13:31:20', '2026-08-21 20:40:14'),
(12, 1, 2, 5, '2026-08-27', '08:00:00', 'General Consultation', 'Cancelled', '2026-08-19 13:41:40', '2026-08-21 20:40:13'),
(13, 1, 2, 5, '2026-08-21', '11:30:00', 'General Consultation', 'Completed', '2026-08-19 13:44:42', '2026-08-21 20:16:37'),
(14, 1, 2, 5, '2026-08-20', '13:00:00', 'General Consultation', 'Called', '2026-08-19 13:46:14', '2026-08-21 00:56:13'),
(15, 1, 2, 5, '2026-08-26', '13:00:00', 'General Consultation', 'Cancelled', '2026-08-19 13:48:03', '2026-08-21 20:40:11'),
(16, 1, 1, 1, '2026-08-20', '16:00:00', 'General Consultation', 'In Consultation', '2026-08-19 13:51:04', '2026-08-20 19:03:02'),
(17, 1, 1, 1, '2026-08-26', '11:30:00', 'General Consultation', 'Cancelled', '2026-08-19 18:27:36', '2026-08-21 20:40:09'),
(18, 1, 1, 1, '2026-08-19', '09:00:00', 'General consultation', 'Pending', '2026-08-19 23:08:18', '2026-08-19 23:08:18'),
(19, 1, 1, 1, '2026-08-19', '09:00:00', 'General consultation', 'Pending', '2026-08-19 23:08:18', '2026-08-19 23:08:18'),
(26, 1, 5, 2, '2026-08-25', '14:00:00', 'check up', 'Cancelled', '2026-08-21 13:48:39', '2026-08-21 20:40:03'),
(27, 1, 5, 2, '2026-09-15', '15:00:00', 'check up', 'Cancelled', '2026-08-21 13:54:24', '2026-08-21 20:40:22'),
(28, 1, 5, 2, '2026-09-29', '15:00:00', 'check up', 'Cancelled', '2026-08-21 14:23:52', '2026-08-21 20:40:25'),
(29, 1, 5, 2, '2026-09-15', '13:30:00', 'General Consultation', 'Cancelled', '2026-08-21 14:28:43', '2026-08-21 20:40:21'),
(30, 1, 5, 2, '2026-09-15', '15:30:00', 'check up', 'Cancelled', '2026-08-21 14:28:57', '2026-08-21 20:40:24'),
(31, 1, 5, 2, '2026-09-08', '13:00:00', 'check up', 'Cancelled', '2026-08-21 14:34:11', '2026-08-21 20:40:19'),
(32, 1, 5, 2, '2026-08-25', '13:00:00', 'check up', 'Cancelled', '2026-08-21 20:17:37', '2026-08-21 20:40:01'),
(33, 1, 8, 3, '2026-09-02', '09:00:00', 'Follow-up for post-op wound check', 'Completed', '2026-09-02 12:54:13', '2026-09-03 02:05:58'),
(34, 1, 8, 3, '2026-09-02', '09:30:00', 'Cough and shortness of breath assessment', 'Completed', '2026-09-02 12:54:13', '2026-09-03 01:36:52'),
(35, 1, 8, 3, '2026-09-02', '10:00:00', 'Routine consultation', 'Completed', '2026-09-02 12:54:13', '2026-09-02 13:32:57'),
(36, 1, 8, 3, '2026-09-02', '10:30:00', 'Hernia surgery follow-up', 'Completed', '2026-09-02 12:54:13', '2026-09-02 12:54:13');

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
