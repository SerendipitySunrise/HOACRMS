-- =====================================================================
-- 2026_10_19_appointment_booking_rules.sql
-- Idempotent schema changes for appointment booking rules
-- =====================================================================

-- departments: age bands
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'MinAge');
SET @sql := IF(@col = 0,
  'ALTER TABLE `departments` ADD COLUMN `MinAge` TINYINT NOT NULL DEFAULT 0, ADD COLUMN `MaxAge` TINYINT NOT NULL DEFAULT 120',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- department_schedules: breaks + capacity
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'department_schedules' AND COLUMN_NAME = 'BreakStart');
SET @sql := IF(@col = 0,
  'ALTER TABLE `department_schedules`
     ADD COLUMN `BreakStart` time DEFAULT NULL,
     ADD COLUMN `BreakEnd` time DEFAULT NULL,
     ADD COLUMN `MaxPatientsPerSession` int DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- departments: initial age bands (mirrors the pediatrics_age_limit setting
-- default of 18; booking validation re-derives from the setting at check time)
UPDATE `departments` SET `MinAge` = 0,  `MaxAge` = 17  WHERE `DepartmentName` LIKE 'Pediatrics%';
UPDATE `departments` SET `MinAge` = 18, `MaxAge` = 120 WHERE NOT (`DepartmentName` LIKE 'Pediatrics%');

-- patients
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'NoShowCount');
SET @sql := IF(@col = 0,
  'ALTER TABLE `patients`
     ADD COLUMN `NoShowCount` int NOT NULL DEFAULT 0,
     ADD COLUMN `GuardianConsentAt` datetime DEFAULT NULL,
     ADD COLUMN `GuardianConsentByUserID` int DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'CheckInTime');
SET @sql := IF(@col = 0,
  'ALTER TABLE `appointments`
     ADD COLUMN `CheckInTime` datetime DEFAULT NULL,
     ADD COLUMN `RescheduleCount` int NOT NULL DEFAULT 0,
     ADD COLUMN `IsFollowup` tinyint(1) NOT NULL DEFAULT 0,
     ADD COLUMN `IsWalkIn` tinyint(1) NOT NULL DEFAULT 0,
     ADD COLUMN `AccompaniedBy` varchar(100) DEFAULT NULL,
     ADD COLUMN `BookedByUserID` int DEFAULT NULL,
     ADD COLUMN `Urgent` tinyint(1) NOT NULL DEFAULT 0,
     ADD COLUMN `MarkedUrgentBy` int DEFAULT NULL',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- appointments: stored Session column (populated at booking/reschedule;
-- backfilled below from department_schedules)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = 'Session');
SET @sql := IF(@col = 0,
  'ALTER TABLE `appointments` ADD COLUMN `Session` varchar(50) DEFAULT NULL AFTER `AppointmentTime`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- backfill Session from department_schedules (DayOfWeek convention is ISO
-- 1=Mon..7=Sun; DAYOFWEEK() is 1=Sun..7=Sat, hence the +5 %7 +1 transform)
UPDATE `appointments` a
JOIN `department_schedules` ds
  ON ds.`DepartmentID` = a.`DepartmentID`
 AND ds.`DayOfWeek` = ((DAYOFWEEK(a.`AppointmentDate`) + 5) % 7) + 1
 AND a.`AppointmentTime` >= ds.`StartTime`
 AND a.`AppointmentTime` < ds.`EndTime`
SET a.`Session` = ds.`SessionName`
WHERE a.`Session` IS NULL;

-- indexes
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_patient_date');
SET @sql := IF(@idx = 0,
  'ALTER TABLE `appointments` ADD INDEX `idx_appt_patient_date` (`PatientID`,`AppointmentDate`), ADD INDEX `idx_appt_dept_date` (`DepartmentID`,`AppointmentDate`)',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- holidays
CREATE TABLE IF NOT EXISTS `hospital_holidays` (
  `HolidayID` int NOT NULL AUTO_INCREMENT,
  `HolidayDate` date NOT NULL,
  `Name` varchar(120) NOT NULL,
  `CreatedBy` int DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`HolidayID`),
  UNIQUE KEY `uq_hospital_holiday_date` (`HolidayDate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- guardian_links
CREATE TABLE IF NOT EXISTS `guardian_links` (
  `GuardianLinkID` int NOT NULL AUTO_INCREMENT,
  `PatientID` int NOT NULL,
  `GuardianUserID` int NOT NULL,
  `Relationship` varchar(50) NOT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `IsPrimary` tinyint(1) NOT NULL DEFAULT 0,
  `Status` enum('Unverified','Verified','Restricted') NOT NULL DEFAULT 'Unverified',
  `ProofType` varchar(50) DEFAULT NULL,
  `VerifiedBy` int DEFAULT NULL,
  `VerifiedAt` datetime DEFAULT NULL,
  `GuardianConsentAt` datetime DEFAULT NULL,
  `RestrictReason` varchar(255) DEFAULT NULL,
  `CreatedBy` int DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`GuardianLinkID`),
  UNIQUE KEY `uq_guardian_link` (`PatientID`,`GuardianUserID`),
  KEY `idx_guardian_user` (`GuardianUserID`),
  CONSTRAINT `fk_guardian_patient` FOREIGN KEY (`PatientID`) REFERENCES `patients` (`PatientID`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_guardian_user` FOREIGN KEY (`GuardianUserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- guardian_links: consent timestamp required for 18+ guardian booking
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'guardian_links' AND COLUMN_NAME = 'GuardianConsentAt');
SET @sql := IF(@col = 0,
  'ALTER TABLE `guardian_links` ADD COLUMN `GuardianConsentAt` datetime DEFAULT NULL AFTER `VerifiedAt`',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- settings
INSERT IGNORE INTO `system_settings` (`SettingKey`, `SettingValue`, `Description`, `UpdatedBy`) VALUES
  ('booking_window_min_hours','2','Earliest a patient may book ahead (hours)',500),
  ('booking_window_max_days','30','Latest a patient may book ahead (days)',500),
  ('cancel_cutoff_hours','4','Hours before appointment that self-cancel/reschedule stops',500),
  ('reschedule_limit','2','Max reschedules per appointment',500),
  ('no_show_limit','3','No-shows within the window that block online booking',500),
  ('no_show_window_days','90','Rolling window for the no-show block (days)',500),
  ('active_appointment_limit','3','Max active appointments per patient',500),
  ('per_department_limit','1','Max active (non-followup) appointments per patient per department',500),
  ('pediatrics_age_limit','18','Patients under this age go to Pediatrics; adults otherwise',500),
  ('max_guardians_per_child','2','Max linked guardians per child patient',500),
  ('unverified_guardians_can_book','1','1 = Unverified guardians may book for the child',500),
  ('accepted_proof_types','Valid ID,Birth certificate,Barangay clearance','Comma-separated accepted guardian proof types',500),
  ('keep_id_copies','0','1 = staff may keep copies of guardian ID proof',500),
  ('on_time_window_minutes','0','Minutes before scheduled time still counted as on-time (default 0 = strict)',500),
  ('notification_method','app','app,email,sms fallback logic',500),
  ('walkins_may_exceed_session_max','0','1 = walk-ins may exceed session maximum',500),
  ('who_may_mark_urgent','Staff','Role allowed to mark urgent',500),
  ('max_patients_per_session','20','Default max patients per session',500),
  ('checkin_opens_minutes_before','30','Minutes before check-in opens',500);