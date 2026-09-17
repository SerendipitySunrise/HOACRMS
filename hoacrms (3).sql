-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Sep 17, 2026 at 01:12 PM
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`AdminID`, `UserID`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 6, '2026-07-24 22:06:03', '2026-07-24 22:06:03'),
(2, 21, '2026-09-05 18:23:14', '2026-09-05 18:23:14');

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
  `OriginalAppointmentDate` date DEFAULT NULL,
  `OriginalAppointmentTime` time DEFAULT NULL,
  `RescheduledAt` datetime DEFAULT NULL,
  `RescheduleReason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`AppointmentID`),
  KEY `fk_appointments_patient` (`PatientID`),
  KEY `fk_appointments_staff` (`StaffID`),
  KEY `fk_appointments_department` (`DepartmentID`)
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`AppointmentID`, `PatientID`, `StaffID`, `DepartmentID`, `AppointmentDate`, `AppointmentTime`, `Purpose`, `Status`, `CreatedAt`, `UpdatedAt`, `OriginalAppointmentDate`, `OriginalAppointmentTime`, `RescheduledAt`, `RescheduleReason`) VALUES
(1, 1, NULL, 1, '2026-08-20', '10:30:00', 'General consultation', 'In Consultation', '2026-08-14 00:43:31', '2026-08-20 19:14:32', NULL, NULL, NULL, NULL),
(2, 1, 2, 5, '2026-08-21', '10:00:00', 'General Consulatation', 'Completed', '2026-08-14 15:34:14', '2026-08-21 20:16:39', NULL, NULL, NULL, NULL),
(3, 1, 2, 5, '2026-08-26', '10:00:00', 'General Consulatation', 'Cancelled', '2026-08-14 15:34:27', '2026-08-21 20:40:06', NULL, NULL, NULL, NULL),
(4, 1, NULL, 5, '0000-00-00', '08:30:00', 'General Consulatation', 'Pending', '2026-08-14 16:33:44', '2026-08-14 16:33:44', NULL, NULL, NULL, NULL),
(5, 1, NULL, 5, '0000-00-00', '08:30:00', 'General Consulatation', 'Pending', '2026-08-14 16:33:45', '2026-08-14 16:33:45', NULL, NULL, NULL, NULL),
(6, 1, NULL, 5, '0000-00-00', '06:00:00', 'General Consulatation', 'Cancelled', '2026-08-14 16:37:51', '2026-08-29 02:01:48', NULL, NULL, NULL, NULL),
(7, 1, 2, 5, '2026-05-15', '06:30:00', 'Check up', 'Pending', '2026-08-14 18:00:41', '2026-08-14 18:00:41', NULL, NULL, NULL, NULL),
(8, 1, 1, 1, '2026-08-21', '14:00:00', 'check up', 'Completed', '2026-08-17 19:09:34', '2026-08-21 20:16:35', NULL, NULL, NULL, NULL),
(9, 1, 1, 1, '2026-08-28', '10:30:00', 'General Consultation', 'Cancelled', '2026-08-19 13:19:49', '2026-08-21 20:40:15', NULL, NULL, NULL, NULL),
(10, 1, 1, 1, '2026-08-28', '13:00:00', 'check up', 'Cancelled', '2026-08-19 13:21:04', '2026-08-21 20:40:17', NULL, NULL, NULL, NULL),
(11, 1, 2, 5, '2026-08-27', '14:30:00', 'General Consultation', 'Cancelled', '2026-08-19 13:31:20', '2026-08-21 20:40:14', NULL, NULL, NULL, NULL),
(12, 1, 2, 5, '2026-08-27', '08:00:00', 'General Consultation', 'Cancelled', '2026-08-19 13:41:40', '2026-08-21 20:40:13', NULL, NULL, NULL, NULL),
(13, 1, 2, 5, '2026-08-21', '11:30:00', 'General Consultation', 'Completed', '2026-08-19 13:44:42', '2026-08-21 20:16:37', NULL, NULL, NULL, NULL),
(14, 1, 2, 5, '2026-08-20', '13:00:00', 'General Consultation', 'Called', '2026-08-19 13:46:14', '2026-08-21 00:56:13', NULL, NULL, NULL, NULL),
(15, 1, 2, 5, '2026-08-26', '13:00:00', 'General Consultation', 'Cancelled', '2026-08-19 13:48:03', '2026-08-21 20:40:11', NULL, NULL, NULL, NULL),
(16, 1, 1, 1, '2026-08-20', '16:00:00', 'General Consultation', 'In Consultation', '2026-08-19 13:51:04', '2026-08-20 19:03:02', NULL, NULL, NULL, NULL),
(17, 1, 1, 1, '2026-08-26', '11:30:00', 'General Consultation', 'Cancelled', '2026-08-19 18:27:36', '2026-08-21 20:40:09', NULL, NULL, NULL, NULL),
(18, 1, 1, 1, '2026-08-19', '09:00:00', 'General consultation', 'Pending', '2026-08-19 23:08:18', '2026-08-19 23:08:18', NULL, NULL, NULL, NULL),
(19, 1, 1, 1, '2026-08-19', '09:00:00', 'General consultation', 'Pending', '2026-08-19 23:08:18', '2026-08-19 23:08:18', NULL, NULL, NULL, NULL),
(26, 1, 5, 2, '2026-08-25', '14:00:00', 'check up', 'Cancelled', '2026-08-21 13:48:39', '2026-08-21 20:40:03', NULL, NULL, NULL, NULL),
(27, 1, 5, 2, '2026-09-15', '15:00:00', 'check up', 'Cancelled', '2026-08-21 13:54:24', '2026-08-21 20:40:22', NULL, NULL, NULL, NULL),
(28, 1, 5, 2, '2026-09-29', '15:00:00', 'check up', 'Cancelled', '2026-08-21 14:23:52', '2026-08-21 20:40:25', NULL, NULL, NULL, NULL),
(29, 1, 5, 2, '2026-09-15', '13:30:00', 'General Consultation', 'Cancelled', '2026-08-21 14:28:43', '2026-08-21 20:40:21', NULL, NULL, NULL, NULL),
(30, 1, 5, 2, '2026-09-15', '15:30:00', 'check up', 'Cancelled', '2026-08-21 14:28:57', '2026-08-21 20:40:24', NULL, NULL, NULL, NULL),
(31, 1, 5, 2, '2026-09-08', '13:00:00', 'check up', 'Cancelled', '2026-08-21 14:34:11', '2026-08-21 20:40:19', NULL, NULL, NULL, NULL),
(32, 1, 5, 2, '2026-08-25', '13:00:00', 'check up', 'Cancelled', '2026-08-21 20:17:37', '2026-08-21 20:40:01', NULL, NULL, NULL, NULL),
(33, 1, 8, 3, '2026-09-02', '09:00:00', 'Follow-up for post-op wound check', 'Completed', '2026-09-02 12:54:13', '2026-09-03 02:05:58', NULL, NULL, NULL, NULL),
(34, 1, 8, 3, '2026-09-02', '09:30:00', 'Cough and shortness of breath assessment', 'Completed', '2026-09-02 12:54:13', '2026-09-03 01:36:52', NULL, NULL, NULL, NULL),
(35, 1, 8, 3, '2026-09-02', '10:00:00', 'Routine consultation', 'Completed', '2026-09-02 12:54:13', '2026-09-02 13:32:57', NULL, NULL, NULL, NULL),
(36, 1, 8, 3, '2026-09-02', '10:30:00', 'Hernia surgery follow-up', 'Completed', '2026-09-02 12:54:13', '2026-09-02 12:54:13', NULL, NULL, NULL, NULL),
(37, 1, 8, 3, '2026-09-05', '10:00:00', 'Follow-up check-up', 'No Show', '2026-09-05 16:36:27', '2026-09-05 21:48:53', NULL, NULL, NULL, NULL),
(38, 1, 8, 3, '2026-09-05', '15:00:00', 'Lab results review', 'Completed', '2026-09-05 16:53:26', '2026-09-05 21:48:40', NULL, NULL, NULL, NULL),
(39, 2, NULL, 5, '2026-09-05', '13:00:00', 'Chest Pain', 'Checked In', '2026-09-05 21:48:12', '2026-09-05 21:48:12', NULL, NULL, NULL, NULL),
(40, 3, NULL, 5, '2026-09-05', '13:00:00', 'Chest Pain', 'Checked In', '2026-09-05 21:48:16', '2026-09-05 21:48:16', NULL, NULL, NULL, NULL),
(41, 4, NULL, 5, '2026-09-05', '13:00:00', 'Chest Pain', 'Checked In', '2026-09-05 21:48:21', '2026-09-05 21:48:21', NULL, NULL, NULL, NULL),
(45, 1, 2, 5, '2026-09-15', '09:00:00', 'Trouble breathing', 'Pending', '2026-09-05 22:20:38', '2026-09-05 22:21:50', '2026-09-07', '11:30:00', '2026-09-05 22:21:50', 'work related'),
(55, 1, 1, 1, '2026-09-11', '09:00:00', 'General pediatric consultation', 'Checked In', '2026-09-11 17:18:36', '2026-09-11 18:05:56', NULL, NULL, NULL, NULL),
(56, 1, 2, 5, '2026-09-14', '08:00:00', 'check up', 'Pending', '2026-09-11 18:55:42', '2026-09-11 18:55:42', NULL, NULL, NULL, NULL),
(57, 5, NULL, 3, '2026-09-12', '13:00:00', 'Stomachache', 'In Consultation', '2026-09-12 15:05:09', '2026-09-12 15:07:20', NULL, NULL, NULL, NULL),
(58, 1, 8, 3, '2026-09-14', '11:30:00', 'Stomachache', 'Pending', '2026-09-12 15:06:20', '2026-09-12 15:06:20', NULL, NULL, NULL, NULL),
(59, 6, 8, 3, '2026-09-12', '13:00:00', 'Stomachache', 'Completed', '2026-09-12 15:17:03', '2026-09-12 15:24:08', NULL, NULL, NULL, NULL),
(60, 1, 8, 3, '2026-09-15', '08:30:00', 'Trouble breathing', 'Pending', '2026-09-12 15:46:51', '2026-09-12 15:46:51', NULL, NULL, NULL, NULL),
(61, 7, 8, 3, '2026-09-12', '13:00:00', 'headache', 'Completed', '2026-09-12 15:59:29', '2026-09-12 16:00:49', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `appointment_reminders`
--

DROP TABLE IF EXISTS `appointment_reminders`;
CREATE TABLE IF NOT EXISTS `appointment_reminders` (
  `ReminderID` int NOT NULL AUTO_INCREMENT,
  `AppointmentID` int NOT NULL,
  `ReminderType` varchar(20) NOT NULL,
  `SentAt` datetime NOT NULL,
  `SentVia` varchar(20) NOT NULL,
  `Status` varchar(20) DEFAULT 'sent',
  PRIMARY KEY (`ReminderID`),
  KEY `fk_reminder_appointment` (`AppointmentID`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `appointment_reminders`
--

INSERT INTO `appointment_reminders` (`ReminderID`, `AppointmentID`, `ReminderType`, `SentAt`, `SentVia`, `Status`) VALUES
(5, 55, 'confirmation', '2026-09-11 17:18:36', 'push', 'sent'),
(6, 56, 'confirmation', '2026-09-11 18:55:42', 'push', 'sent'),
(7, 58, 'confirmation', '2026-09-12 15:06:20', 'push', 'sent'),
(8, 60, 'confirmation', '2026-09-12 15:46:51', 'push', 'sent');

-- --------------------------------------------------------

--
-- Table structure for table `appointment_reschedule_history`
--

DROP TABLE IF EXISTS `appointment_reschedule_history`;
CREATE TABLE IF NOT EXISTS `appointment_reschedule_history` (
  `RescheduleID` int NOT NULL AUTO_INCREMENT,
  `AppointmentID` int NOT NULL,
  `OriginalDate` date NOT NULL,
  `OriginalTime` time NOT NULL,
  `NewDate` date NOT NULL,
  `NewTime` time NOT NULL,
  `Reason` varchar(255) DEFAULT NULL,
  `CreatedAt` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`RescheduleID`),
  KEY `fk_reschedule_appointment` (`AppointmentID`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `appointment_reschedule_history`
--

INSERT INTO `appointment_reschedule_history` (`RescheduleID`, `AppointmentID`, `OriginalDate`, `OriginalTime`, `NewDate`, `NewTime`, `Reason`, `CreatedAt`) VALUES
(2, 45, '2026-09-07', '11:30:00', '2026-09-09', '11:30:00', 'Work related', '2026-09-05 22:21:17'),
(3, 45, '2026-09-09', '11:30:00', '2026-09-15', '09:00:00', 'work related', '2026-09-05 22:21:50');

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
  `Weight` decimal(5,2) DEFAULT NULL,
  `Height` decimal(5,2) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ConsultationID`),
  KEY `fk_consultations_appointment` (`AppointmentID`),
  KEY `fk_consultations_patient` (`PatientID`),
  KEY `fk_consultations_staff` (`StaffID`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `consultations`
--

INSERT INTO `consultations` (`ConsultationID`, `AppointmentID`, `PatientID`, `StaffID`, `ConsultationDate`, `ConsultationTime`, `ChiefComplaint`, `Diagnosis`, `Treatment`, `LabRequest`, `Notes`, `FollowUpDate`, `Status`, `BloodPressure`, `Temperature`, `PulseRate`, `Weight`, `Height`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 35, 1, 8, '2026-09-02', '05:48:29', 'Routine consultation', 'Primary: Acute Bacterial Sinusitis (ICD-10: J01.90)  Secondary: Allergic Rhinitis, seasonal (ICD-10: J30.1)', 'Complete a 7-day course of oral antibiotics.\r\n\r\nUse over-the-counter warm compresses over facial sinuses for 10–15 minutes twice daily.\r\n\r\nMaintain hypertonic saline nasal rinses twice daily to improve mucociliary clearance.\r\n\r\nFollow up in 10 days if symptoms fail to resolve, or sooner if red-flag symptoms develop (high fever, severe swelling around the eyes, severe headache).', NULL, 'History: Patient reports a 10-day history of nasal congestion, thick yellow nasal discharge, and facial pressure worsening over the last 3 days. Reports a mild frontal headache ($4/10$). Denies fever, shortness of breath, or changes in vision.\r\n\r\nExamination: Purulent discharge noted in bilateral nasal passages with mucosal edema. Mild tenderness to palpation over maxillary sinuses. Tympanic membranes clear bilaterally. Oropharynx clear without cobblestoning.', NULL, 'Completed', '120/80', 36.8, 78, NULL, NULL, '2026-09-02 12:54:13', '2026-09-02 13:48:29'),
(2, 36, 1, 8, '2026-09-02', '10:32:00', 'Hernia surgery follow-up', 'Post-operative healing progressing well', 'Continue aseptic wound dressing twice daily. Avoid heavy lifting for 2 weeks.', 'Complete blood count', 'Wound site clean with no signs of infection.', '2026-09-16', 'Completed', '118/78', 36.6, 74, NULL, NULL, '2026-09-02 12:54:13', '2026-09-02 12:54:13'),
(3, 34, 1, 8, '2026-09-02', '09:30:00', 'Cough and shortness of breath assessment', 'Primary: Acute Bronchitis (ICD-10: J20.9)  \r\nSecondary: Mild Asthma Exacerbation (ICD-10: J45.901)', 'Treatment Plan:\nInitiate short-acting inhaler therapy for acute dyspnea and wheezing.\r\n\r\nPrescribe a short course of oral corticosteroids to control airway inflammation.\r\n\r\nIncrease fluid intake, use a cool-mist humidifier, and rest.\r\n\r\nReturn for reassessment if shortness of breath worsens, chest pain develops, or fever occurs.\n\nPrescriptions:\n- Albuterol Sulfate — 90 mcg/actuation inhaler — 1–2 puffs every 4–6 hours as needed — 30 Days — Inhale deeply; wait 1 minute between puffs for acute shortness of breath.\n- Prednisone — 20 mg oral tablet — 2 tablets (40 mg) daily in the morning — 5 Days — Take with food to avoid stomach irritation. Do not stop abruptly.', NULL, 'History: Patient presents with a 5-day history of persistent productive cough with clear sputum and mild shortness of breath on exertion. Reports tightness in the chest and intermittent wheezing over the last 2 days. Denies high fever, night sweats, coughing up blood, or recent travel.\r\n\r\nExamination: Vital signs stable ($O_2$ saturation 97% on room air, RR 18 breaths/min). Oropharynx clear. Bilateral diffuse expiration wheezes and mild coarse crackles noted on lung auscultation. No accessory muscle use.', NULL, 'Completed', '120/80', 36.8, NULL, NULL, NULL, '2026-09-03 01:19:54', '2026-09-03 01:44:01'),
(4, 33, 1, 8, '2026-09-02', '09:00:00', 'Follow-up for post-op wound check', 'Primary: Postoperative Wound Check — Healing Surgical Incision (ICD-10: Z48.811)\r\n\r\nSecondary: Status Post Laparoscopic Appendectomy, Day 7 (ICD-10: Z98.890)', 'Treatment Plan:\r\nRemoved remaining surface Steri-Strips/sutures in office today.\r\n\r\nClear patient to resume normal daily activities; continue avoiding heavy lifting ($>10\\text{ lbs}$) for another 7–10 days.\r\n\r\nResume normal showering; pat incision sites dry gentle—do not scrub or submerge in baths/pools yet.\r\n\r\nReturn PRN if signs of infection occur (increasing redness, warmth, swelling, foul drainage, or fever $>38^{\\circ}\\text{C}$).\r\n\r\nPrescriptions:\r\n- Acetaminophen — 500 mg oral tablet — 1–2 tablets every 6 hours as needed — 5 Days — Use for mild residual incision discomfort. Do not exceed 3,000 mg/day.', NULL, 'History: Patient presents for a scheduled 1-week follow-up following an uncomplicated laparoscopic appendectomy. Reports well-controlled pain ($1/10$). Denies fever, chills, nausea, vomitting, or drainage from incision sites. Bowel habits have returned to baseline.\r\n\r\nExamination: Vital signs stable (Afebrile, BP 118/76, HR 72). Abdomen soft, non-tender, non-distended. Surgical portal sites in the lower abdomen are clean, dry, and intact. Well-approximated skin edges with minimal surrounding erythema. No signs of infection, hematoma, or seroma. Sutures/Steri-Strips intact.', NULL, 'Completed', '120/80', 36.8, NULL, NULL, NULL, '2026-09-03 01:52:00', '2026-09-03 03:04:59'),
(7, 59, 6, 8, '2026-09-12', '13:00:00', 'Stomachache', 'Primary: Acute Appendicitis (ICD-10: K35.80)\r\n\r\nStatus: Confirmed via Ultrasound', 'Procedure: Emergency Laparoscopic Appendectomy.\r\n\r\nPre-Operative Care: Maintain strict NPO (Nothing Per Os) status. Start continuous IV hydration with 0.9% Normal Saline at 100 mL/hr. Obtain emergency cardiac and anesthesia clearance.\r\n\r\nPost-Operative Care: Early ambulation after full recovery from anesthesia. Gradual diet advancement from clear liquids to regular soft diet as tolerated once bowel sounds return.', 'Complete Blood Count (CBC) with Differential', 'SUBJECTIVE:\nPatient reports sudden onset of periumbilical pain starting yesterday morning, which migrated to the right lower quadrant (RLQ) overnight. Rates pain at 8/10. Reports loss of appetite, one episode of vomiting, and low-grade fever. Denies urinary symptoms or history of similar pain.\n\nOBJECTIVE:\nPatient reports sudden onset of periumbilical pain starting yesterday morning, which migrated to the right lower quadrant (RLQ) overnight. Rates pain at 8/10. Reports loss of appetite, one episode of vomiting, and low-grade fever. Denies urinary symptoms or history of similar pain.\n\nASSESSMENT:\nAcute non-perforated appendicitis. Requires surgical intervention.\n\nPLAN:\nAdmit to general surgery ward for emergency laparoscopic appendectomy. Keep NPO (Nothing Per Os). Start IV fluids and prophylactic IV antibiotics.', '2026-09-22', 'Completed', '120/80', 36.8, NULL, 68.00, 169.94, '2026-09-12 15:19:30', '2026-09-12 15:24:08'),
(8, 61, 7, 8, '2026-09-12', '13:00:00', 'headache', '', '', '', '', NULL, 'Completed', '', NULL, NULL, NULL, NULL, '2026-09-12 16:00:13', '2026-09-12 16:00:49');

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
  `PatientSlots` int DEFAULT '10',
  PRIMARY KEY (`DepartmentScheduleID`),
  UNIQUE KEY `unique_department_schedule` (`DepartmentID`,`DayOfWeek`,`SessionName`)
) ENGINE=MyISAM AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `department_schedules`
--

INSERT INTO `department_schedules` (`DepartmentScheduleID`, `DepartmentID`, `DayOfWeek`, `SessionName`, `StartTime`, `EndTime`, `PatientSlots`) VALUES
(1, 5, 1, 'Morning', '08:00:00', '12:00:00', 10),
(2, 5, 1, 'Afternoon', '13:00:00', '17:00:00', 10),
(3, 5, 2, 'Morning', '08:00:00', '12:00:00', 10),
(4, 5, 2, 'Afternoon', '13:00:00', '17:00:00', 10),
(5, 5, 3, 'Morning', '08:00:00', '12:00:00', 10),
(6, 5, 3, 'Afternoon', '13:00:00', '17:00:00', 10),
(7, 5, 4, 'Morning', '08:00:00', '12:00:00', 10),
(8, 5, 4, 'Afternoon', '13:00:00', '17:00:00', 10),
(9, 5, 5, 'Morning', '08:00:00', '12:00:00', 10),
(10, 5, 5, 'Afternoon', '13:00:00', '17:00:00', 10),
(11, 4, 3, 'Afternoon', '13:00:00', '17:00:00', 10),
(12, 2, 2, 'Afternoon', '13:00:00', '17:00:00', 10),
(13, 1, 3, 'Morning', '08:00:00', '12:00:00', 10),
(14, 1, 4, 'Morning', '08:00:00', '12:00:00', 10),
(15, 1, 4, 'Afternoon', '13:00:00', '17:00:00', 10),
(16, 1, 5, 'Morning', '08:00:00', '12:00:00', 10),
(17, 1, 5, 'Afternoon', '13:00:00', '17:00:00', 10),
(18, 3, 1, 'Morning', '08:00:00', '12:00:00', 10),
(19, 3, 2, 'Morning', '08:00:00', '12:00:00', 10);

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
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`NotificationID`, `UserID`, `Title`, `Message`, `Type`, `RelatedID`, `RelatedTable`, `IsRead`, `PriorityLevel`, `SentAt`, `ReadAt`, `CreatedAt`) VALUES
(3, 8, 'Appointment Rescheduled', 'Your appointment for Internal Medicine / Pulmonology has been rescheduled from September 7, 2026 at 11:30 AM to September 9, 2026 at 11:30 AM.', 'Appointment', 45, 'appointments', b'1', 'Medium', NULL, '2026-09-11 12:18:52', '2026-09-05 22:21:17'),
(4, 7, 'Appointment Rescheduled', 'Jedrick Versoza\'s appointment for Internal Medicine / Pulmonology has been rescheduled to September 9, 2026 at 11:30 AM (previously September 7, 2026 at 11:30 AM).', 'Appointment', 45, 'appointments', b'0', 'Medium', NULL, NULL, '2026-09-05 22:21:17'),
(5, 8, 'Appointment Rescheduled', 'Your appointment for Internal Medicine / Pulmonology has been rescheduled from September 9, 2026 at 11:30 AM to September 15, 2026 at 9:00 AM.', 'Appointment', 45, 'appointments', b'1', 'Medium', NULL, '2026-09-05 23:02:01', '2026-09-05 22:21:50'),
(6, 7, 'Appointment Rescheduled', 'Jedrick Versoza\'s appointment for Internal Medicine / Pulmonology has been rescheduled to September 15, 2026 at 9:00 AM (previously September 9, 2026 at 11:30 AM).', 'Appointment', 45, 'appointments', b'0', 'Medium', NULL, NULL, '2026-09-05 22:21:50'),
(17, 8, 'Appointment Confirmed', 'Your appointment for September 11, 2026 at 9:00 AM at the Pediatrics Department has been confirmed.', 'Appointment', 55, 'appointments', b'0', 'Medium', NULL, NULL, '2026-09-11 17:18:36'),
(18, 8, 'Appointment Confirmed', 'Your appointment for September 14, 2026 at 8:00 AM at the Internal Medicine / Pulmonology Department has been confirmed.', 'Appointment', 56, 'appointments', b'0', 'Medium', NULL, NULL, '2026-09-11 18:55:42'),
(19, 8, 'Appointment Confirmed', 'Your appointment for September 14, 2026 at 11:30 AM at the Surgery Department has been confirmed.', 'Appointment', 58, 'appointments', b'0', 'Medium', NULL, NULL, '2026-09-12 15:06:20'),
(20, 8, 'Appointment Confirmed', 'Your appointment for September 15, 2026 at 8:30 AM at the Surgery Department has been confirmed.', 'Appointment', 60, 'appointments', b'0', 'Medium', NULL, NULL, '2026-09-12 15:46:51');

-- --------------------------------------------------------

--
-- Table structure for table `no_shows`
--

DROP TABLE IF EXISTS `no_shows`;
CREATE TABLE IF NOT EXISTS `no_shows` (
  `NoShowID` int NOT NULL AUTO_INCREMENT,
  `QueueID` int NOT NULL,
  `AppointmentID` int NOT NULL,
  `PatientID` int NOT NULL,
  `DepartmentID` int NOT NULL,
  `MarkedBy` int NOT NULL,
  `NoShowReason` varchar(255) DEFAULT NULL,
  `NoShowDate` date NOT NULL,
  `FollowUpStatus` varchar(50) NOT NULL DEFAULT 'Pending',
  `FollowUpNote` text,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`NoShowID`),
  KEY `FK_NoShow_Queue` (`QueueID`),
  KEY `FK_NoShow_Appointment` (`AppointmentID`),
  KEY `FK_NoShow_Patient` (`PatientID`),
  KEY `FK_NoShow_Department` (`DepartmentID`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `no_shows`
--

INSERT INTO `no_shows` (`NoShowID`, `QueueID`, `AppointmentID`, `PatientID`, `DepartmentID`, `MarkedBy`, `NoShowReason`, `NoShowDate`, `FollowUpStatus`, `FollowUpNote`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 11, 37, 1, 3, 10, 'Emergency', '2026-09-05', 'Pending', NULL, '2026-09-05 21:48:53', '2026-09-05 21:48:53');

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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`PatientID`, `UserID`, `CivilStatus`, `Religion`, `IsPWD`, `DisabilityType`, `BloodType`, `Allergies`, `PastMedicalCondition`, `CurrentMedication`, `FamilyMedicalHistory`, `EmergencyContactName`, `EmergencyContactNo`, `EmergencyRelation`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 8, 'Single', 'Catholic', 0, NULL, 'O+', 'Peanut, Shrimp', 'Thyroid', 'Pain Killers', 'Diabetes', 'Edrian Bagohara', '09171234568', 'Friend', '2026-08-13 23:23:33', '2026-09-05 20:30:11'),
(2, 22, NULL, NULL, 0, NULL, 'O+', 'Pain killer', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-05 21:48:12', '2026-09-05 21:48:12'),
(3, 23, NULL, NULL, 0, NULL, 'O+', 'Pain killer', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-05 21:48:16', '2026-09-05 21:48:16'),
(4, 24, NULL, NULL, 0, NULL, 'O+', 'Pain killer', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-05 21:48:21', '2026-09-05 21:48:21'),
(5, 26, NULL, NULL, 0, NULL, 'A-', 'Peanut', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-12 15:05:09', '2026-09-12 15:05:09'),
(6, 27, NULL, NULL, 0, NULL, NULL, 'Peanut', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-12 15:17:03', '2026-09-12 15:17:03'),
(7, 28, NULL, NULL, 0, NULL, 'O-', 'Peanut', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-12 15:59:29', '2026-09-12 15:59:29');

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
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `prescriptions`
--

INSERT INTO `prescriptions` (`PrescriptionID`, `ConsultationID`, `PrescribedDate`, `Instructions`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 1, '2026-09-02', '', '2026-09-02 13:32:56', '2026-09-02 13:32:56'),
(3, 1, '2026-09-02', 'Take with meals to minimize gastrointestinal upset.\nAdminister daily; aim spray away from the nasal septum.', '2026-09-02 13:48:29', '2026-09-02 13:48:29'),
(5, 4, '2026-09-02', NULL, '2026-09-03 03:04:59', '2026-09-03 03:04:59'),
(7, 7, '2026-09-12', NULL, '2026-09-12 15:24:16', '2026-09-12 15:24:16'),
(8, 8, '2026-09-12', NULL, '2026-09-12 16:00:49', '2026-09-12 16:00:49');

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
  `Instructions` text,
  PRIMARY KEY (`PrescriptionItemID`),
  KEY `fk_prescription_items_prescription` (`PrescriptionID`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `prescription_items`
--

INSERT INTO `prescription_items` (`PrescriptionItemID`, `PrescriptionID`, `MedicineName`, `Dosage`, `Frequency`, `Duration`, `Instructions`) VALUES
(2, 3, 'Amoxicillin-Clavulanate', '875 mg / 125 mg oral tablet', '1 tablet twice daily', '7 Days', 'Take with meals to minimize gastrointestinal upset.'),
(3, 3, 'Fluticasone Propionate', '50 mcg/actuation nasal spray', '2 sprays per nostril once daily', '30 Days', 'Administer daily; aim spray away from the nasal septum.'),
(5, 5, 'Acetaminophen', '500 mg oral tablet', '1–2 tablets every 6 hours as needed', '5 Days', 'Use for mild residual incision discomfort. Do not exceed 3,000 mg/day.'),
(7, 7, 'Cefuroxime', '500 mg Tablet', 'Every 12 hours (BID)', '7 Days', 'Take after meals. Complete full course.');

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
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `queue`
--

INSERT INTO `queue` (`QueueID`, `AppointmentID`, `QueueNumber`, `PriorityLevel`, `QueueDate`, `QueueTime`, `Status`, `CreatedAt`, `UpdatedAt`) VALUES
(1, 16, 1, 'Normal', '2026-08-20', '09:34:33', 'In Progress', '2026-08-20 17:34:33', '2026-08-20 19:03:02'),
(2, 1, 2, 'Normal', '2026-08-20', '10:51:48', 'In Progress', '2026-08-20 18:51:48', '2026-08-20 19:14:32'),
(3, 14, 3, 'Normal', '2026-08-20', '10:51:50', 'Called', '2026-08-20 18:51:50', '2026-08-21 00:56:13'),
(4, 8, 1, 'Normal', '2026-08-21', '12:10:31', 'Completed', '2026-08-21 20:10:31', '2026-08-21 20:16:35'),
(5, 13, 2, 'Normal', '2026-08-21', '12:11:50', 'Completed', '2026-08-21 20:11:50', '2026-08-21 20:16:37'),
(6, 2, 3, 'Normal', '2026-08-21', '12:11:54', 'Completed', '2026-08-21 20:11:54', '2026-08-21 20:16:39'),
(7, 33, 1, 'Normal', '2026-09-02', '08:55:00', 'Waiting', '2026-09-02 12:54:13', '2026-09-02 12:54:13'),
(8, 34, 2, 'Normal', '2026-09-02', '09:25:00', 'Called', '2026-09-02 12:54:13', '2026-09-02 12:54:13'),
(9, 35, 3, 'Normal', '2026-09-02', '09:55:00', 'Completed', '2026-09-02 12:54:13', '2026-09-02 13:32:57'),
(10, 36, 4, 'Normal', '2026-09-02', '10:25:00', 'Completed', '2026-09-02 12:54:13', '2026-09-02 12:54:13'),
(11, 37, 1, 'Normal', '2026-09-05', '16:36:27', 'No Show', '2026-09-05 16:36:27', '2026-09-05 21:48:53'),
(12, 38, 2, 'Normal', '2026-09-05', '08:56:42', 'Completed', '2026-09-05 16:56:42', '2026-09-05 21:48:40'),
(13, 39, 3, 'Normal', '2026-09-05', '13:48:12', 'Waiting', '2026-09-05 21:48:12', '2026-09-05 21:48:12'),
(14, 40, 4, 'Normal', '2026-09-05', '13:48:16', 'Waiting', '2026-09-05 21:48:16', '2026-09-05 21:48:16'),
(15, 41, 5, 'Normal', '2026-09-05', '13:48:21', 'Waiting', '2026-09-05 21:48:21', '2026-09-05 21:48:21'),
(16, 55, 1, 'Normal', '2026-09-11', '10:05:56', 'Waiting', '2026-09-11 18:05:56', '2026-09-11 18:05:56'),
(17, 57, 1, 'Normal', '2026-09-12', '07:05:09', 'In Consultation', '2026-09-12 15:05:09', '2026-09-12 15:07:20'),
(18, 59, 2, 'Normal', '2026-09-12', '07:17:03', 'Waiting', '2026-09-12 15:17:03', '2026-09-12 15:17:03'),
(19, 61, 3, 'Normal', '2026-09-12', '07:59:29', 'Waiting', '2026-09-12 15:59:29', '2026-09-12 15:59:29');

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`RoleID`, `RoleName`) VALUES
(1, 'Admin'),
(4, 'Doctor'),
(3, 'Patient'),
(2, 'Staff');

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

DROP TABLE IF EXISTS `staff`;
CREATE TABLE IF NOT EXISTS `staff` (
  `StaffID` int NOT NULL AUTO_INCREMENT,
  `EmployeeID` varchar(50) DEFAULT NULL,
  `UserID` int NOT NULL,
  `DepartmentID` int NOT NULL,
  `StaffRole` varchar(50) NOT NULL,
  `Suffix` varchar(20) DEFAULT NULL,
  `Specialization` varchar(100) DEFAULT NULL,
  `LicenseNumber` varchar(100) DEFAULT NULL,
  `YearsOfExperience` int DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`StaffID`, `EmployeeID`, `UserID`, `DepartmentID`, `StaffRole`, `Suffix`, `Specialization`, `LicenseNumber`, `YearsOfExperience`, `AvailabilityStatus`, `DateHired`, `ScheduleStart`, `ScheduleEnd`, `AssignedDays`, `AssignedResponsibilities`, `CreatedAt`, `UpdatedAt`) VALUES
(1, NULL, 5, 1, 'Doctor', NULL, NULL, NULL, NULL, 'Available', NULL, '08:00:00', '17:00:00', NULL, NULL, '2026-07-24 22:02:07', '2026-09-10 22:48:29'),
(2, NULL, 7, 5, 'Doctor', NULL, NULL, NULL, NULL, 'Available', NULL, NULL, NULL, NULL, NULL, '2026-07-24 22:24:26', '2026-07-24 22:24:26'),
(3, NULL, 9, 1, 'Doctor', NULL, 'Developmental-Behavioral', NULL, NULL, 'Available', NULL, NULL, NULL, NULL, NULL, '2026-08-15 13:59:03', '2026-08-15 13:59:03'),
(4, NULL, 10, 3, 'Nurse', NULL, 'Scrub Nurse', NULL, NULL, 'Available', NULL, NULL, NULL, NULL, NULL, '2026-08-19 19:35:09', '2026-08-19 19:35:09'),
(5, NULL, 11, 2, 'Doctor', NULL, 'Reproductive Endocrinology and Infertility (REI)', NULL, NULL, 'Available', NULL, NULL, NULL, NULL, NULL, '2026-08-20 18:17:31', '2026-08-20 18:17:31'),
(8, NULL, 19, 3, 'Doctor', NULL, 'General Surgery', NULL, 3, 'Available', NULL, '08:00:00', '17:00:00', NULL, NULL, '2026-09-02 11:12:39', '2026-09-09 22:25:24'),
(9, NULL, 20, 4, 'Doctor', NULL, 'Onconephrology', NULL, 5, 'Available', NULL, NULL, NULL, NULL, NULL, '2026-09-02 12:49:17', '2026-09-02 12:49:17'),
(11, NULL, 29, 3, 'Doctor', NULL, 'Cardiologist', NULL, 3, 'Available', NULL, '08:00:00', '17:00:00', NULL, NULL, '2026-09-17 20:55:00', '2026-09-17 20:55:00');

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
  `ReminderPreference` varchar(20) DEFAULT 'email',
  `ReceiveReminders` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Email` (`Email`),
  KEY `FK_Users_Roles` (`RoleID`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`UserID`, `RoleID`, `FirstName`, `MiddleName`, `LastName`, `Email`, `Password`, `Sex`, `DateOfBirth`, `ContactNumber`, `Address`, `Status`, `CreatedAt`, `FailedAttempts`, `LockUntil`, `ResetToken`, `TokenExpiry`, `ProfilePhoto`, `LastLogin`, `ReminderPreference`, `ReceiveReminders`) VALUES
(2, 3, 'ROZ', NULL, 'LAGABAN', 'lagabanroz22@gmail.com', '$2y$10$GJkWCjrPPEGRw8PCPRWFmefWye6Lma24Oca.XK.G/Q9LefqPVxBLu', 'Not Specified', NULL, '09913095266', NULL, 'Active', '2026-07-23 23:31:34', 1, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(3, 3, 'ROZi', NULL, 'LAGABAN', 'lagabanroz@gmail.com', '$2y$10$.G/GbgoZBzj1iQiGYVgk1u70HA3tOm3PuxtFxKFqKBD6F5SvyJWZC', 'Not Specified', NULL, '09913095265', NULL, 'Active', '2026-07-23 23:36:00', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(4, 3, 'ROZii', NULL, 'LAGABAN', 'lagabanro@gmail.com', '$2y$10$BUg/Tfu2XClNq/Hq/mBnPuoDpLoGhZUPbARngVasAsGMWaWwpFsK.', 'Not Specified', NULL, '09913095264', NULL, 'Active', '2026-07-23 23:37:26', 0, NULL, '05f74c765b146243a13d91576fab2febc4a424f4cb4a1bd3436b2589b7d14ff7', '2026-07-23 18:01:06', NULL, NULL, 'email', 1),
(5, 2, 'RIAN', NULL, 'LAGABAN', 'lagabanrian@gmail.com', '$2y$10$qKGSy23Edh0vO3MdiBTWYuCcp.MEA8AR9oykygpwpwMTh65MNqA9C', 'Not Specified', NULL, '09123456789', NULL, 'Inactive', '2026-07-24 22:02:07', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(6, 1, 'Rion', NULL, 'Lagaban', 'lagabanrion@gmail.com', '$2y$10$NeHRS6.vel.qeLAqpaEnruWGztDodu6SClcU2H395q44jeUewtGra', 'Male', NULL, '', '', 'Active', '2026-07-24 22:06:03', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(7, 2, 'Edrian', NULL, 'Bagohara', 'edrianbagohar@gmail.com', '$2y$10$2tMALSjSnQdi7ng/SPh7L.Q4tTnUUE9fexRn/5Qwikis377/Zm9xa', 'Not Specified', NULL, '09456789321', NULL, 'Active', '2026-07-24 22:24:26', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(8, 3, 'Jedrick', 'B.', 'Versoza', 'jedvesoza@gmail.com', '$2y$10$M.3mCghPzuQutueQsMfcAubXxDzz6Z1S6Q4BQfNbBDIw5SK/89Fce', 'Male', '2006-08-30', '09171234567', 'Longos, Malabon City', 'Active', '2026-08-13 23:23:33', 0, NULL, NULL, NULL, 'assets/images/avatars/avatar_8_1787294739.jpg', '2026-09-12 15:44:39', 'push', 1),
(9, 2, 'Roxzia', NULL, 'Kim', 'RoxziaKim@gmai.com', '$2y$10$YTVYBlQfE5M3yC7Qq36MZ.57cnKoa9wrTuKMYXbSLwPScj0HSbVs.', 'Not Specified', NULL, '09369852147', NULL, 'Active', '2026-08-15 13:59:03', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(10, 2, 'Jonah', '', 'Santos', 'JonahSantos@gmail.com', '$2y$10$mr5WCmDu0wX5V5KvHg2qiOJ5k.v3hwGSPN7CY5V2SEkc3W/kc0zbC', 'Male', '2002-08-30', '09835744920', '', 'Active', '2026-08-19 19:35:09', 0, NULL, NULL, NULL, 'assets/images/avatars/avatar_10_1787323071.png', '2026-09-12 15:55:43', 'email', 1),
(11, 4, 'Morgan', '', 'Cruz', 'morgancruz@gmail.com', '$2y$10$1YOmp6D8uva6/x0rn4wV8uwKHjDeeYnoEvUjzKBHhtiZB.uz7sQKG', 'Female', NULL, '09789044731', '', 'Active', '2026-08-20 18:17:31', 0, NULL, NULL, NULL, 'assets/images/avatars/avatar_11_1787323127.jpg', '2026-09-04 13:00:53', 'email', 1),
(19, 4, 'Ana', NULL, 'Luiz', 'analuiz@gmail.com', '$2y$10$k2gvUjtLAeVD1mVGaEQgyenRpe/k2RlC2tPVpt/3jO7CsST3ciclO', 'Not Specified', NULL, '09978856452', NULL, 'Active', '2026-09-02 11:12:39', 0, NULL, NULL, NULL, NULL, '2026-09-12 16:00:00', 'email', 1),
(20, 4, 'Shan', '', 'Meyers', 'shanMeyers@gmail.comm', '$2y$10$2YbxGOlu0gh4DnvPSvN3/eMS/9sUz08zv8cKw.9RM60aKSOVYKIRu', 'Male', '1990-05-10', '09227689054', '', 'Active', '2026-09-02 12:49:17', 0, NULL, NULL, NULL, NULL, '2026-09-09 22:03:02', 'email', 1),
(21, 1, 'Ian', '', 'Bagohara', 'ianbagohara23@gmail.com', '$2y$10$bmMbeyLiSTI6htDGFaixKOMTSARZAYPaECt2uXncEObJ2JUI2MlDy', 'Male', NULL, '09015673357', '', 'Active', '2026-09-05 18:23:14', 0, NULL, NULL, NULL, NULL, '2026-09-17 20:14:55', 'email', 1),
(22, 3, 'Lia', NULL, 'Wayne', 'walkin_20260905134812_liawayne@medicare.local', '$2y$10$nCNRn.yLV9UC/5EKepm9hOmvckQ1XCQknwRBHx3XTKVQUDxbpE.FG', 'Female', '2000-03-02', '09665784321', NULL, 'Active', '2026-09-05 21:48:12', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(23, 3, 'Lia', NULL, 'Wayne', 'walkin_20260905134815_liawayne@medicare.local', '$2y$10$Sz7.00.NnQOztnmIRJUp8uP1A5fN8dU1yC0r6TtTIO72IQZx9uYTG', 'Female', '2000-03-02', '09665784321', NULL, 'Active', '2026-09-05 21:48:16', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(24, 3, 'Lia', NULL, 'Wayne', 'walkin_20260905134820_liawayne@medicare.local', '$2y$10$D4Z0d.DwHFkEULRb3Pb7XuwhsFm2I737yXE/vAxhOTf0nF5rzAIpa', 'Female', '2000-03-02', '09665784321', NULL, 'Active', '2026-09-05 21:48:21', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(26, 3, 'Gian Carl', NULL, 'Coronel', 'walkin_20260912070509_giancarlcoronel@medicare.local', '$2y$10$C6RrJ7a5IHa5Iyadr8VdlOZPtrjKlA.FvV4Npm/0JZ3rKsnN3LxrO', 'Male', '2006-08-23', '09007845583', NULL, 'Active', '2026-09-12 15:05:09', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(27, 3, 'Gian Carl', NULL, 'Coronel', 'walkin_20260912071702_giancarlcoronel@medicare.local', '$2y$10$w0lehp4gmMOFEESqyEadxOuVR2Pb.za2KQx6GMpX5.0G/M4IbLcxS', 'Male', '2006-08-24', '09007845583', NULL, 'Active', '2026-09-12 15:17:03', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(28, 3, 'Gian Carl', NULL, 'Coronel', 'walkin_20260912075929_giancarlcoronel@medicare.local', '$2y$10$68w7lfvJtNoqB2psEG5Bqu9JgauHSzoxJqw9K05phuo3DplO0kF.2', 'Male', '2006-08-24', '09007845583', NULL, 'Active', '2026-09-12 15:59:29', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1),
(29, 4, 'Dr.', NULL, 'Sarah Mitchelle', 'sarah@gmail.com', '$2y$10$o/lO.aM7BnrXqyx6JWLvx.t4.wrfdk4HvPVReKa7dK9S.6P.v/GKq', 'Not Specified', NULL, '09171234567', NULL, 'Active', '2026-09-17 20:55:00', 0, NULL, NULL, NULL, NULL, NULL, 'email', 1);

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
  `Weight` decimal(5,2) DEFAULT NULL,
  `Height` decimal(5,2) DEFAULT NULL,
  `RecordedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`VitalID`),
  KEY `idx_vitals_patient` (`PatientID`),
  KEY `idx_vitals_appointment` (`AppointmentID`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vitals`
--

INSERT INTO `vitals` (`VitalID`, `AppointmentID`, `PatientID`, `StaffID`, `BloodPressure`, `Temperature`, `PulseRate`, `Weight`, `Height`, `RecordedAt`) VALUES
(4, 37, 1, 4, '120/80', 36.8, 72, 68.50, 165.00, '2026-09-05 16:36:27'),
(10, 37, 1, 4, '120/80', 36.8, 72, 68.50, 165.00, '2026-09-05 16:56:03'),
(11, 38, 1, 4, '120/80', 36.8, 72, 68.50, 164.00, '2026-09-05 21:08:41'),
(13, 39, 2, 4, '120/80', 40.0, 72, 45.00, 150.00, '2026-09-05 21:49:40'),
(14, 57, 5, 4, '120/80', 36.8, 73, 65.00, 168.00, '2026-09-12 15:06:57'),
(15, 59, 6, 4, '120/80', 36.8, 73, 65.00, 168.00, '2026-09-12 15:59:41');

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
-- Constraints for table `appointment_reminders`
--
ALTER TABLE `appointment_reminders`
  ADD CONSTRAINT `fk_reminder_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`) ON DELETE CASCADE;

--
-- Constraints for table `appointment_reschedule_history`
--
ALTER TABLE `appointment_reschedule_history`
  ADD CONSTRAINT `fk_reschedule_appointment` FOREIGN KEY (`AppointmentID`) REFERENCES `appointments` (`AppointmentID`) ON DELETE CASCADE ON UPDATE CASCADE;

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
