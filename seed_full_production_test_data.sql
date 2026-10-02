-- =========================================================================
--  seed_full_production_test_data.sql
--  HOACRMS (Curora) -- Full Production-Like Test Data Seed
-- =========================================================================
--  Purpose
--  --------
--  Simulates a clinic system in active production use for ~6 months by
--  seeding realistic data across every module and role:
--    Admin      : system settings, announcements (active/archived/emergency),
--                 historical reports & audit log
--    Staff/Nurse: today's live queue (Waiting / Called / In Consultation /
--                 Completed / No Show), staff profiles, notifications
--    Doctor     : doctors across OB-GYN, Pediatrics, Surgery, Nephrology and
--                 Internal Medicine/Pulmonology, consultations + vitals +
--                 prescriptions, schedules, queue & dashboards. Every doctor
--                 has multiple searchable patient records AND at least one
--                 patient In Consultation today (ready consultation).
--    Patient    : authentic Filipino accounts (password Password123!), all
--                 appointment states, queue + consultation history, in-app
--                 alerts incl. an EMERGENCY disruption with active
--                 reschedule_token
--
--  Dates
--  -----
--  All dates are dynamic (NOW(), CURDATE(), NOW() +/- INTERVAL ...) so the
--  dataset stays realistic whenever it is executed (last 6 months of
--  history + upcoming appointments + a live queue for today).
--
--  Passwords
--  ---------
--  Every account uses the SAME bcrypt (PASSWORD_DEFAULT) hash so all seeded
--  accounts log in immediately with:  Password123!
--  Hash: $2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi
--
--  Brand note
--  ----------
--  The clinic name is seeded as "Curora Outpatient Center" (the system was
--  renamed from CarePath* to Curora throughout the codebase). If you need
--  the legacy name instead, change the clinic_name value in
--  system_settings below.
--
--  Reschedule / "Pending Reschedule" model
--  ----------------------------------------
--  "Pending Reschedule" is represented the way the app models it:
--  Status = 'Cancelled' + disruption_reason + active reschedule_token
--  (validated by hash_equals() in patient/reschedule_appointment.php).
--  is_emergency_disruption = 1 gives priority access (bypasses the 24-hour
--  reschedule block) -- this is how the 6-hour emergency window is exposed.
--
--  Prerequisites
--  -------------
--  * database/hoacrms.sql loaded.
--  * database/migrations/2026_09_26_schedule_disruptions.sql and
--    database/migrations/2026_09_26_appointment_disruption_tokens.sql
--    applied (DoctorUnavailability, AnnouncementRecipients, and the
--    appointments disruption columns).
--  * A system_settings table is created by this script if missing (the app
--    currently persists only RBAC role_permissions at runtime).
--
--  Idempotency
--  -----------
--  Explicit, collision-free IDs + INSERT ... ON DUPLICATE KEY UPDATE (and
--  INSERT IGNORE for lookups), wrapped in FOREIGN_KEY_CHECKS = 0/1, so the
--  script can be re-run repeatedly without errors.
-- =========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================================
-- A. LOOKUPS (idempotent ensures)
-- =========================================================================
INSERT IGNORE INTO `roles` (`RoleID`, `RoleName`) VALUES
(1, 'Admin'), (2, 'Staff'), (4, 'Doctor'), (3, 'Patient');

INSERT IGNORE INTO `departments` (`DepartmentID`, `DepartmentName`) VALUES
(1, 'Pediatrics'),
(2, 'Obstetrics and Gynecology (OB-GYN)'),
(3, 'Surgery'),
(4, 'Nephrology'),
(5, 'Internal Medicine / Pulmonology');

-- =========================================================================
-- B. SYSTEM SETTINGS (created here if not present)
-- =========================================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
  `SettingID` int NOT NULL AUTO_INCREMENT,
  `SettingKey` varchar(50) NOT NULL,
  `SettingValue` varchar(255) NOT NULL,
  `Description` varchar(255) DEFAULT NULL,
  `UpdatedBy` int DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`SettingID`),
  UNIQUE KEY `uq_system_settings_key` (`SettingKey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `system_settings`
  (`SettingID`, `SettingKey`, `SettingValue`, `Description`, `UpdatedBy`, `UpdatedAt`)
VALUES
(1,    'clinic_name',                       'Curora Outpatient Center', 'Display name of the clinic', 500, NOW()),
(2,    'operating_hours_start',             '08:00',                    'Clinic opening time (24h)', 500, NOW()),
(3,    'operating_hours_end',               '17:00',                    'Clinic closing time (24h)', 500, NOW()),
(4,    'queue_limit_per_session',           '20',                       'Max queue slots per department session', 500, NOW()),
(5,    'queue_limit_per_day',               '120',                      'Max queue slots per day across departments', 500, NOW()),
(6,    'emergency_reschedule_window_hours', '6',                        'Emergency reschedule window (hours)', 500, NOW()),
(7,    'max_reschedule_cap',                '3',                        'Max reschedules allowed per appointment', 500, NOW())
ON DUPLICATE KEY UPDATE
  SettingValue = VALUES(`SettingValue`), Description = VALUES(`Description`),
  UpdatedBy = VALUES(`UpdatedBy`), UpdatedAt = NOW();

-- =========================================================================
-- C. USERS (admins, staff, doctors, patients) -- all: Password123!
-- =========================================================================
INSERT INTO `users`
  (`UserID`, `RoleID`, `FirstName`, `MiddleName`, `LastName`, `Email`,
   `Password`, `Sex`, `DateOfBirth`, `ContactNumber`, `Address`, `Status`,
   `CreatedAt`, `LastLogin`, `ReminderPreference`,
   `ReceiveReminders`)
VALUES
-- Admins (RoleID 1)
(500, 1, 'Rion', 'L.', 'Lagaban', 'admin@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1988-02-14', '09987654321', '101 Ayala Ave., Makati City, Metro Manila',
 'Active', NOW() - INTERVAL 180 DAY, NOW() - INTERVAL 1 DAY, 'email', 1),
(501, 1, 'Sarah', 'R.', 'Reyes', 'sarah.reyes@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1992-07-30', '09178881122', '205 Tomas Morato Ave., Quezon City, Metro Manila',
 'Active', NOW() - INTERVAL 150 DAY, NOW() - INTERVAL 2 DAY, 'email', 1),

-- Staff / Nurses / Receptionist / Technician (RoleID 2)
(600, 2, 'Leah', 'M.', 'Mendoza', 'leah.mendoza@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1995-04-12', '09175550001', '88 San Miguel St., Pasig City, Metro Manila',
 'Active', NOW() - INTERVAL 160 DAY, NOW(), 'email', 1),
(601, 2, 'Christine', 'D.', 'Del Rosario', 'christine.delrosario@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1990-11-25', '09175550002', '320 Juan Luna St., Manila City, Metro Manila',
 'Active', NOW() - INTERVAL 120 DAY, NOW(), 'email', 1),
(602, 2, 'Sofia', 'C.', 'Cabrera', 'sofia.cabrera@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1998-09-03', '09175550003', '55 Banawe St., Quezon City, Metro Manila',
 'Active', NOW() - INTERVAL 90 DAY, NOW(), 'email', 1),
(603, 2, 'Mark', 'V.', 'Villanueva', 'mark.villanueva@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1993-01-17', '09175550004', '12 Rotary Parkway, Mandaluyong City, Metro Manila',
 'Active', NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 6 HOUR, 'email', 1),

-- Doctors (RoleID 4) -- one per department plus cover staff
(700, 4, 'Maricel', 'A.', 'Arroyo', 'maricel.arroyo@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1980-03-22', '09172223344', 'OB-GYN Dept, Floor 2, Curora Outpatient Center',
 'Active', NOW() - INTERVAL 170 DAY, NOW(), 'email', 1),
(701, 4, 'Rafael', 'D.', 'Domingo', 'rafael.domingo@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1978-08-08', '09172223345', 'Pediatrics Dept, Floor 1, Curora Outpatient Center',
 'Active', NOW() - INTERVAL 170 DAY, NOW(), 'email', 1),
(702, 4, 'Santiago', 'M.', 'Mercado', 'santiago.mercado@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1975-12-01', '09172223346', 'Surgery Dept, Floor 3, Curora Outpatient Center',
 'Active', NOW() - INTERVAL 165 DAY, NOW() - INTERVAL 3 HOUR, 'email', 1),
(703, 4, 'Eleanor', 'V.', 'Villar', 'eleanor.villar@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1983-06-19', '09172223347', 'Internal Medicine Dept, Floor 2, Curora Outpatient Center',
 'Active', NOW() - INTERVAL 160 DAY, NOW(), 'email', 1),
(704, 4, 'Emmanuel', 'B.', 'Bautista', 'emmanuel.bautista@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1986-10-09', '09172223348', 'Internal Medicine Dept, Floor 2, Curora Outpatient Center',
 'Active', NOW() - INTERVAL 140 DAY, NOW(), 'email', 1),
(705, 4, 'Patricia', 'L.', 'Lim', 'patricia.lim@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1989-05-27', '09172223349', 'Surgery Dept, Floor 3, Curora Outpatient Center',
 'Active', NOW() - INTERVAL 130 DAY, NOW(), 'email', 1),
(706, 4, 'Jose', 'R.', 'Ramos', 'jose.ramos@curoraportal.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1981-09-14', '09172223350', 'Nephrology Dept, Floor 2, Curora Outpatient Center',
 'Active', NOW() - INTERVAL 150 DAY, NOW() - INTERVAL 1 DAY, 'email', 1),

-- Patients (RoleID 3)
(100, 3, 'Jedrick', 'Manalo', 'Versoza', 'jedrick.versoza@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1994-05-18', '09175550148', '88 San Rafael St., Brgy. San Lorenzo, Makati City, Metro Manila',
 'Active', NOW() - INTERVAL 120 DAY, NULL, 'email', 1),
(101, 3, 'Maria', 'Dizon', 'Santos', 'maria.santos@yahoo.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1989-11-02', '09178881234', '221 Mabini St., Brgy. Poblacion, Mandaluyong City, Metro Manila',
 'Active', NOW() - INTERVAL 150 DAY, NULL, 'email', 1),
(102, 3, 'Juan', 'Ramos', 'Dela Cruz', 'juan.delacruz@outlook.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1976-03-25', '09205556677', '14 Burgos Ave., Brgy. San Nicolas, Quezon City, Metro Manila',
 'Active', NOW() - INTERVAL 140 DAY, NULL, 'email', 1),
(103, 3, 'Angela', 'Pascual', 'Reyes', 'angela.reyes@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1998-07-14', '09173334455', '305 Sampaguita St., Brgy. Sta. Lucia, Pasig City, Metro Manila',
 'Active', NOW() - INTERVAL 130 DAY, NULL, 'all', 1),
(104, 3, 'Miguel', 'Torres', 'Fernandez', 'miguel.fernandez@yahoo.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1984-09-30', '09065557788', '77 Rizal Ext., Brgy. San Juan, San Juan City, Metro Manila',
 'Active', NOW() - INTERVAL 110 DAY, NULL, 'sms', 1),
(105, 3, 'Grace', 'Lopez', 'Villanueva', 'grace.villanueva@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1971-12-08', '09189998877', '412 Ilang-Ilang St., Brgy. Bagong Silang, Caloocan City, Metro Manila',
 'Active', NOW() - INTERVAL 100 DAY, NULL, 'email', 1),
(106, 3, 'Paolo', 'Aquino', 'Gonzales', 'paolo.gonzales@outlook.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1991-01-21', '09171112233', '56 Katipunan Ave., Brgy. Loyola Heights, Quezon City, Metro Manila',
 'Active', NOW() - INTERVAL 95 DAY, NULL, 'email', 1),
(107, 3, 'Carmela', 'Bautista', 'Aquino', 'carmela.aquino@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1968-06-17', '09174445566', '902 Taft Ave., Brgy. Malate, Manila City, Metro Manila',
 'Active', NOW() - INTERVAL 85 DAY, NULL, 'email', 1),
(108, 3, 'Ramon', 'Villanueva', 'Bautista', 'ramon.bautista@yahoo.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '2019-04-09', '09172223344', '31 Dahlia St., Brgy. San Isidro, Parañaque City, Metro Manila',
 'Active', NOW() - INTERVAL 80 DAY, NULL, 'email', 1),
(109, 3, 'Liza', 'Garcia', 'Ramos', 'liza.ramos@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1995-10-11', '09176669988', '150 Chino Roces Ave., Brgy. Pio del Pilar, Makati City, Metro Manila',
 'Active', NOW() - INTERVAL 75 DAY, NULL, 'all', 1),
(810, 3, 'Kathleen', 'Soriano', 'Navarro', 'kathleen.navarro@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1990-02-05', '09175550010', '18 Escuela St., Brgy. Poblacion, Taguig City, Metro Manila',
 'Active', NOW() - INTERVAL 70 DAY, NULL, 'email', 1),
(811, 3, 'Eduardo', 'Cruz', 'Salvador', 'eduardo.salvador@yahoo.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1972-08-28', '09175550011', '47 F. Blumentritt St., San Juan City, Metro Manila',
 'Active', NOW() - INTERVAL 65 DAY, NULL, 'email', 1),
(812, 3, 'Nina', 'L.', 'Castillo', 'nina.castillo@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '2001-12-19', '09175550012', '9 A. Mabini Cor. Bonifacio, Calamba City, Laguna',
 'Active', NOW() - INTERVAL 60 DAY, NULL, 'sms', 1),
(813, 3, 'Bryan', 'O.', 'Ocampo', 'bryan.ocampo@outlook.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1996-06-23', '09175550013', '63 Quezon Blvd., Brgy. Baesa, Quezon City, Metro Manila',
 'Active', NOW() - INTERVAL 55 DAY, NULL, 'email', 1)
ON DUPLICATE KEY UPDATE
  RoleID = VALUES(`RoleID`), FirstName = VALUES(`FirstName`),
  MiddleName = VALUES(`MiddleName`), LastName = VALUES(`LastName`),
  Email = VALUES(`Email`), Password = VALUES(`Password`),
  Sex = VALUES(`Sex`), DateOfBirth = VALUES(`DateOfBirth`),
  ContactNumber = VALUES(`ContactNumber`), Address = VALUES(`Address`),
  Status = VALUES(`Status`),
  ReminderPreference = VALUES(`ReminderPreference`),
  ReceiveReminders = VALUES(`ReceiveReminders`);

-- =========================================================================
-- D. ADMIN PROFILE LINKS
-- =========================================================================
INSERT INTO `admin` (`AdminID`, `UserID`, `CreatedAt`, `UpdatedAt`) VALUES
(500, 500, NOW() - INTERVAL 180 DAY, NOW() - INTERVAL 1 DAY),
(501, 501, NOW() - INTERVAL 150 DAY, NOW() - INTERVAL 2 DAY)
ON DUPLICATE KEY UPDATE UserID = VALUES(`UserID`);

-- =========================================================================
-- E. STAFF PROFILES (doctors + nurses + receptionist + technician)
-- =========================================================================
INSERT INTO `staff`
  (`StaffID`, `UserID`, `DepartmentID`, `StaffRole`, `Suffix`,
   `Specialization`, `AvailabilityStatus`, `DateHired`, `ScheduleStart`,
   `ScheduleEnd`, `AssignedDays`, `AssignedResponsibilities`,
   `CreatedAt`, `UpdatedAt`)
VALUES
-- Doctors (StaffRole = 'Doctor', linked to Doctor users)
(110, 700, 2, 'Doctor', 'MD', 'Obstetrics & Gynecology', 'Available',
 DATE(NOW()) - INTERVAL 170 DAY, '08:00:00', '17:00:00', 'Mon,Tue,Wed,Thu,Fri',
 'Prenatal check-ups, high-risk pregnancy management, well-woman exams', NOW() - INTERVAL 170 DAY, NOW()),
(111, 701, 1, 'Doctor', 'MD', 'General Pediatrics', 'Available',
 DATE(NOW()) - INTERVAL 170 DAY, '08:00:00', '12:00:00', 'Mon,Tue,Wed,Thu',
 'Well-child visits, immunization reviews, pediatric consultations', NOW() - INTERVAL 170 DAY, NOW()),
(112, 702, 3, 'Doctor', 'MD', 'General Surgery', 'Available',
 DATE(NOW()) - INTERVAL 165 DAY, '13:00:00', '17:00:00', 'Mon,Tue,Fri',
 'Pre-operative clearance, minor procedures, wound care', NOW() - INTERVAL 165 DAY, NOW()),
(113, 703, 5, 'Doctor', 'MD', 'Pulmonology', 'Available',
 DATE(NOW()) - INTERVAL 160 DAY, '08:00:00', '17:00:00', 'Mon,Tue,Wed,Thu,Fri',
 'Respiratory and pulmonary follow-ups, spirometry reviews', NOW() - INTERVAL 160 DAY, NOW()),
(114, 704, 5, 'Doctor', 'MD', 'Internal Medicine', 'Available',
 DATE(NOW()) - INTERVAL 140 DAY, '08:00:00', '17:00:00', 'Mon,Wed,Thu,Fri',
 'Hypertension, diabetes and general internal medicine consult', NOW() - INTERVAL 140 DAY, NOW()),
(115, 705, 3, 'Doctor', 'MD', 'Surgical Oncology', 'Available',
 DATE(NOW()) - INTERVAL 130 DAY, '08:00:00', '12:00:00', 'Wed,Thu,Fri',
 'Surgical consults, biopsy reviews, post-op follow-ups', NOW() - INTERVAL 130 DAY, NOW()),
(116, 706, 4, 'Doctor', 'MD', 'Nephrology', 'Available',
 DATE(NOW()) - INTERVAL 150 DAY, '13:00:00', '17:00:00', 'Tue,Thu',
 'Kidney disease management, renal colic consults, dialysis coordination', NOW() - INTERVAL 150 DAY, NOW()),
-- Non-doctor staff
(100, 600, 1, 'Nurse', NULL, NULL, 'Available',
 DATE(NOW()) - INTERVAL 160 DAY, '07:30:00', '16:30:00', 'Mon,Tue,Wed,Thu,Fri',
 'Patient check-in, vitals, queue management (Pediatrics)', NOW() - INTERVAL 160 DAY, NOW()),
(101, 601, 5, 'Nurse', NULL, NULL, 'Available',
 DATE(NOW()) - INTERVAL 120 DAY, '07:30:00', '16:30:00', 'Mon,Tue,Wed,Thu,Fri',
 'Patient check-in, vitals, queue management (Internal Medicine)', NOW() - INTERVAL 120 DAY, NOW()),
(102, 602, 3, 'Receptionist', NULL, NULL, 'Available',
 DATE(NOW()) - INTERVAL 90 DAY, '08:00:00', '17:00:00', 'Mon,Tue,Wed,Thu,Fri',
 'Front desk, appointment booking, payment coordination', NOW() - INTERVAL 90 DAY, NOW()),
(103, 603, 3, 'Medical Technician', NULL, NULL, 'Available',
 DATE(NOW()) - INTERVAL 100 DAY, '08:00:00', '17:00:00', 'Mon,Tue,Wed,Thu,Fri',
 'Lab specimen handling, ECG and basic diagnostics', NOW() - INTERVAL 100 DAY, NOW())
ON DUPLICATE KEY UPDATE
  UserID = VALUES(`UserID`), DepartmentID = VALUES(`DepartmentID`),
  StaffRole = VALUES(`StaffRole`), Specialization = VALUES(`Specialization`),
  AvailabilityStatus = VALUES(`AvailabilityStatus`),
  ScheduleStart = VALUES(`ScheduleStart`), ScheduleEnd = VALUES(`ScheduleEnd`),
  AssignedDays = VALUES(`AssignedDays`),
  AssignedResponsibilities = VALUES(`AssignedResponsibilities`);

-- =========================================================================
-- E2. PRE-EXISTING DOCTOR ACCOUNTS (INSERT IGNORE -- passwords PRESERVED)
--      Edrian Bagohara (IM), Roxzia Kim (Pediatrics), Morgan Cruz (OB-GYN),
--      Ana Luiz (Surgery), Shan Meyers (Nephrology), Sarah Mitchelle
--      (Surgery). These accounts ALREADY exist in the live database, so
--      INSERT IGNORE skips them entirely -- their current emails, logins
--      and passwords are NOT touched. They are only created on a fresh
--      install (with Password123! as a placeholder). Appointments and
--      consultations for these doctors are seeded below so every doctor
--      in the system has records + a live queue.
-- =========================================================================
INSERT IGNORE INTO `users`
  (`UserID`, `RoleID`, `FirstName`, `MiddleName`, `LastName`, `Email`,
   `Password`, `Sex`, `DateOfBirth`, `ContactNumber`, `Address`, `Status`,
   `CreatedAt`, `LastLogin`, `ReminderPreference`, `ReceiveReminders`)
VALUES
(7,  4, 'Edrian',      'D.',  'Bagohara', 'edrianbagohar@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1984-03-19', '09179990007', '88 Sampaguita St., Brgy. Veterans, Quezon City', 'Active',
 NOW() - INTERVAL 170 DAY, NULL, 'email', 1),
(9,  4, 'Roxzia',      'P.',  'Kim',      'RoxziaKim@gmai.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1990-07-24', '09179990009', '42 K-1st St., Kamuning, Quezon City', 'Active',
 NOW() - INTERVAL 160 DAY, NULL, 'email', 1),
(11, 4, 'Morgan',      'C.',  'Cruz',     'morgancruz@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1982-11-09', '09179990011', '77 Shaw Blvd., Brgy. Wack-Wack, Mandaluyong City', 'Active',
 NOW() - INTERVAL 150 DAY, NULL, 'all', 1),
(19, 4, 'Ana',         'L.',  'Luiz',     'analuiz@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1986-05-30', '09179990019', '120 Katipunan Ave., Brgy. Loyola Heights, Quezon City', 'Active',
 NOW() - INTERVAL 140 DAY, NULL, 'email', 1),
(20, 4, 'Shan',        'M.',  'Meyers',   'shanMeyers@gmail.comm',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1988-02-13', '09179990020', '55 Jupiter St., Makati City', 'Active',
 NOW() - INTERVAL 130 DAY, NULL, 'email', 1),
(29, 4, 'Dr. Sarah',   'M.',  'Mitchelle','sarah@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1981-09-27', '09179990029', '3 Barangka Dr., Brgy. Highway Hills, Mandaluyong City', 'Active',
 NOW() - INTERVAL 120 DAY, NULL, 'email', 1);

INSERT IGNORE INTO `staff`
  (`StaffID`, `UserID`, `DepartmentID`, `StaffRole`, `Suffix`,
   `Specialization`, `AvailabilityStatus`, `DateHired`, `ScheduleStart`,
   `ScheduleEnd`, `AssignedDays`, `AssignedResponsibilities`,
   `CreatedAt`, `UpdatedAt`)
VALUES
(2, 7,  5, 'Doctor', 'MD', 'Internal Medicine / Pulmonary', 'Available',
 DATE(NOW()) - INTERVAL 170 DAY, '08:00:00', '17:00:00', 'Mon,Tue,Wed,Thu,Fri',
 'Pulmonary and general internal medicine consults', NOW() - INTERVAL 170 DAY, NOW()),
(3, 9,  1, 'Doctor', 'MD', 'Pediatrics', 'Available',
 DATE(NOW()) - INTERVAL 160 DAY, '08:00:00', '12:00:00', 'Mon,Wed,Fri',
 'Well-child visits, immunization reviews, pediatric consults', NOW() - INTERVAL 160 DAY, NOW()),
(5, 11, 2, 'Doctor', 'MD', 'Obstetrics & Gynecology', 'Available',
 DATE(NOW()) - INTERVAL 150 DAY, '13:00:00', '17:00:00', 'Tue,Thu',
 'Prenatal check-ups, well-woman exams', NOW() - INTERVAL 150 DAY, NOW()),
(8, 19, 3, 'Doctor', 'MD', 'General Surgery', 'Available',
 DATE(NOW()) - INTERVAL 140 DAY, '08:00:00', '17:00:00', 'Mon,Tue,Wed,Thu,Fri',
 'Post-op reviews, wound care, minor procedures', NOW() - INTERVAL 140 DAY, NOW()),
(9, 20, 4, 'Doctor', 'MD', 'Nephrology', 'Available',
 DATE(NOW()) - INTERVAL 130 DAY, '13:00:00', '17:00:00', 'Tue,Thu',
 'Kidney disease management, renal profile follow-ups', NOW() - INTERVAL 130 DAY, NOW()),
(11, 29, 3, 'Doctor', 'MD', 'Surgical Oncology', 'Available',
 DATE(NOW()) - INTERVAL 120 DAY, '08:00:00', '12:00:00', 'Wed,Fri',
 'Surgical consults, pre-op clearance', NOW() - INTERVAL 120 DAY, NOW());

-- =========================================================================
-- F. PATIENT PROFILES
-- =========================================================================
INSERT INTO `patients`
  (`PatientID`, `UserID`, `CivilStatus`, `Religion`, `IsPWD`,
   `DisabilityType`, `BloodType`, `Allergies`, `PastMedicalCondition`,
   `CurrentMedication`, `FamilyMedicalHistory`, `EmergencyContactName`,
   `EmergencyContactNo`, `EmergencyRelation`, `CreatedAt`, `UpdatedAt`)
VALUES
(100, 100, 'Single', 'Roman Catholic', 0, NULL, 'O+', 'Penicillin',
 'Recurrent asthma as a child', 'None', 'Hypertension (mother)',
 'Teresa Versoza', '09171110001', 'Mother', NOW() - INTERVAL 120 DAY, NOW()),
(101, 101, 'Married', 'Roman Catholic', 0, NULL, 'A+', 'None',
 'Mild hypertension (controlled)', 'Losartan 50 mg daily', 'Diabetes (father)',
 'Ricardo Santos', '09172220002', 'Spouse', NOW() - INTERVAL 150 DAY, NOW()),
(102, 102, 'Married', 'Iglesia ni Cristo', 1, 'Lower-limb mobility impairment', 'B+',
 'None', 'None', 'None', 'None',
 'Elena Dela Cruz', '09173330003', 'Spouse', NOW() - INTERVAL 140 DAY, NOW()),
(103, 103, 'Single', 'Roman Catholic', 0, NULL, 'AB+', 'Latex',
 'None', 'None', 'Asthma (brother)',
 'Rey Reyes', '09174440004', 'Father', NOW() - INTERVAL 130 DAY, NOW()),
(104, 104, 'Married', 'Roman Catholic', 0, NULL, 'O-', 'Aspirin',
 'Bronchitis (recurrent)', 'Salbutamol inhaler PRN', 'Lung disease (grandfather)',
 'Lorna Fernandez', '09175550005', 'Spouse', NOW() - INTERVAL 110 DAY, NOW()),
(105, 105, 'Widowed', 'Roman Catholic', 0, NULL, 'B-', 'None',
 'Pre-diabetes', 'Metformin 500 mg twice daily', 'Diabetes (parents)',
 'Nena Lopez', '09176660006', 'Sister', NOW() - INTERVAL 100 DAY, NOW()),
(106, 106, 'Single', 'Roman Catholic', 0, NULL, 'A-', 'Sulfa drugs',
 'Kidney stones (2019)', 'None', 'Nephrolithiasis (father)',
 'Cora Gonzales', '09177770007', 'Mother', NOW() - INTERVAL 95 DAY, NOW()),
(107, 107, 'Married', 'Roman Catholic', 0, NULL, 'O+', 'None',
 'Hypertension Stage I', 'Amlodipine 5 mg daily', 'Hypertension (mother)',
 'Dante Aquino', '09178880008', 'Spouse', NOW() - INTERVAL 85 DAY, NOW()),
(108, 108, 'Single', 'Roman Catholic', 0, NULL, 'B+', 'Peanuts',
 'None', 'None', 'None',
 'Mira Bautista', '09179990009', 'Mother', NOW() - INTERVAL 80 DAY, NOW()),
(109, 109, 'Single', 'Roman Catholic', 0, NULL, 'AB-', 'None',
 'None', 'Oral contraceptive', 'None',
 'Nora Ramos', '09171110010', 'Mother', NOW() - INTERVAL 75 DAY, NOW()),
(810, 810, 'Single', 'Roman Catholic', 0, NULL, 'O+', 'None',
 'None', 'Prenatal vitamins', 'None',
 'Bella Navarro', '09171110011', 'Mother', NOW() - INTERVAL 70 DAY, NOW()),
(811, 811, 'Married', 'Roman Catholic', 0, NULL, 'A+', 'Iodine contrast',
 'Inguinal hernia (repaired 2020)', 'None', 'Colon cancer (father)',
 'Lourdes Salvador', '09171110012', 'Spouse', NOW() - INTERVAL 65 DAY, NOW()),
(812, 812, 'Single', 'Roman Catholic', 0, NULL, 'B+', 'None',
 'Mild asthma', 'Salbutamol inhaler PRN', 'Asthma (mother)',
 'Cecilia Castillo', '09171110013', 'Mother', NOW() - INTERVAL 60 DAY, NOW()),
(813, 813, 'Single', 'Roman Catholic', 0, NULL, 'AB+', 'None',
 'Appendectomy (2022)', 'None', 'Diabetes (father)',
 'Ricardo Ocampo', '09171110014', 'Father', NOW() - INTERVAL 55 DAY, NOW())
ON DUPLICATE KEY UPDATE
  UserID = VALUES(`UserID`), CivilStatus = VALUES(`CivilStatus`),
  Religion = VALUES(`Religion`), IsPWD = VALUES(`IsPWD`),
  DisabilityType = VALUES(`DisabilityType`), BloodType = VALUES(`BloodType`),
  Allergies = VALUES(`Allergies`),
  PastMedicalCondition = VALUES(`PastMedicalCondition`),
  CurrentMedication = VALUES(`CurrentMedication`),
  FamilyMedicalHistory = VALUES(`FamilyMedicalHistory`),
  EmergencyContactName = VALUES(`EmergencyContactName`),
  EmergencyContactNo = VALUES(`EmergencyContactNo`),
  EmergencyRelation = VALUES(`EmergencyRelation`);

-- =========================================================================
-- G. APPOINTMENTS -- 6 months of history + upcoming + today's live queue
--    Departments: 1 Pediatrics | 2 OB-GYN | 3 Surgery | 4 Nephrology | 5 IM
--    Statuses: Pending, Confirmed, Scheduled, Completed, Cancelled, No Show,
--              and disruption "Pending Reschedule" (Cancelled + token).
-- =========================================================================
INSERT INTO `appointments`
  (`AppointmentID`, `PatientID`, `StaffID`, `DepartmentID`, `AppointmentDate`,
   `AppointmentTime`, `Purpose`, `Status`, `OriginalAppointmentDate`,
   `OriginalAppointmentTime`, `RescheduledAt`, `RescheduleReason`,
   `CreatedAt`, `UpdatedAt`)
VALUES
-- Jedrick Versoza (Pediatrics + emergency disruption OB-GYN case)
(2000, 100, 111, 1, DATE(NOW()) - INTERVAL 120 DAY, '09:00:00', 'Well-child checkup', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 130 DAY, NOW()),
(2001, 100, 111, 1, DATE(NOW()) - INTERVAL 60 DAY, '10:00:00', 'Pediatric follow-up', 'Completed',
 DATE(NOW()) - INTERVAL 62 DAY, '10:00:00', NOW() - INTERVAL 62 DAY, 'Requested by patient',
 NOW() - INTERVAL 70 DAY, NOW()),
(2002, 100, 110, 2, DATE(NOW()) + INTERVAL 2 DAY, '09:30:00', 'OB-GYN consult (referral)', 'Cancelled',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 30 DAY, NOW()),
(2003, 100, 111, 1, DATE(NOW()) + INTERVAL 12 DAY, '09:00:00', 'Pediatric follow-up', 'Pending',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 3 DAY, NOW()),
-- Maria Santos
(2004, 101, 111, 1, DATE(NOW()) - INTERVAL 30 DAY, '09:30:00', 'Fever and cough', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 45 DAY, NOW()),
(2005, 101, 111, 1, DATE(NOW()) - INTERVAL 100 DAY, '08:30:00', 'General consultation', 'Completed',
 DATE(NOW()) - INTERVAL 105 DAY, '08:30:00', NOW() - INTERVAL 105 DAY, 'Doctor schedule change',
 NOW() - INTERVAL 115 DAY, NOW()),
(2006, 101, 111, 1, DATE(NOW()) + INTERVAL 7 DAY, '09:30:00', 'General consultation', 'Pending',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 2 DAY, NOW()),
(2007, 101, 111, 1, DATE(NOW()) - INTERVAL 50 DAY, '11:00:00', 'General consultation', 'Cancelled',
 NULL, NULL, NULL, 'Unable to attend', NOW() - INTERVAL 60 DAY, NOW()),
-- Juan Dela Cruz (Internal Medicine)
(2008, 102, 113, 5, DATE(NOW()) - INTERVAL 45 DAY, '13:30:00', 'Hypertension follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 55 DAY, NOW()),
(2009, 102, 113, 5, DATE(NOW()) + INTERVAL 5 DAY, '13:30:00', 'Hypertension follow-up', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 1 DAY, NOW()),
(2010, 102, 113, 5, DATE(NOW()) - INTERVAL 90 DAY, '14:00:00', 'Pulmonology consult', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 100 DAY, NOW()),
-- Angela Reyes (OB-GYN)
(2011, 103, 110, 2, DATE(NOW()) - INTERVAL 35 DAY, '15:00:00', 'Prenatal check-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 48 DAY, NOW()),
(2012, 103, 110, 2, DATE(NOW()) + INTERVAL 8 DAY, '15:00:00', 'Prenatal check-up', 'Scheduled',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 4 DAY, NOW()),
(2013, 103, 110, 2, DATE(NOW()) + INTERVAL 20 DAY, '14:30:00', 'Prenatal check-up', 'Pending',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 1 DAY, NOW()),
-- Miguel Fernandez (Internal Medicine / Pulmonology)
(2014, 104, 114, 5, DATE(NOW()) - INTERVAL 25 DAY, '10:30:00', 'Cough with phlegm', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 38 DAY, NOW()),
(2015, 104, 114, 5, DATE(NOW()) + INTERVAL 10 DAY, '10:30:00', 'Lung function follow-up', 'Scheduled',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
(2016, 104, 114, 5, DATE(NOW()) - INTERVAL 70 DAY, '11:00:00', 'Acute bronchitis revisit', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 80 DAY, NOW()),
-- Grace Villanueva (Surgery / pre-op)
(2017, 105, 112, 3, DATE(NOW()) - INTERVAL 55 DAY, '08:30:00', 'Pre-operative clearance', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 65 DAY, NOW()),
(2018, 105, 112, 3, DATE(NOW()) + INTERVAL 15 DAY, '08:30:00', 'Pre-operative clearance', 'Pending',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 2 DAY, NOW()),
(2019, 105, 112, 3, DATE(NOW()) - INTERVAL 130 DAY, '09:00:00', 'Wound check', 'Cancelled',
 NULL, NULL, NULL, 'Scheduling conflict', NOW() - INTERVAL 135 DAY, NOW()),
-- Paolo Gonzales (Nephrology)
(2020, 106, 116, 4, DATE(NOW()) - INTERVAL 20 DAY, '14:30:00', 'Renal colic follow-up', 'Completed',
 DATE(NOW()) - INTERVAL 22 DAY, '14:30:00', NOW() - INTERVAL 22 DAY, 'Requested by patient',
 NOW() - INTERVAL 30 DAY, NOW()),
(2021, 106, 116, 4, DATE(NOW()) + INTERVAL 6 DAY, '14:30:00', 'Nephrology consultation', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 1 DAY, NOW()),
(2022, 106, 116, 4, DATE(NOW()) - INTERVAL 75 DAY, '15:00:00', 'Kidney stone follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 85 DAY, NOW()),
-- Carmela Aquino (Internal Medicine)
(2023, 107, 113, 5, DATE(NOW()) - INTERVAL 15 DAY, '09:00:00', 'Hypertension follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 25 DAY, NOW()),
(2024, 107, 113, 5, DATE(NOW()) + INTERVAL 18 DAY, '09:00:00', 'Hypertension follow-up', 'Pending',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 3 DAY, NOW()),
(2025, 107, 113, 5, DATE(NOW()) - INTERVAL 110 DAY, '09:30:00', 'Diabetes screening', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 120 DAY, NOW()),
-- Ramon Bautista (Pediatrics)
(2026, 108, 111, 1, DATE(NOW()) - INTERVAL 40 DAY, '15:30:00', 'Well-child checkup', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 50 DAY, NOW()),
(2027, 108, 111, 1, DATE(NOW()) + INTERVAL 9 DAY, '15:30:00', 'Immunization review', 'Scheduled',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 2 DAY, NOW()),
-- Liza Ramos (Pediatrics)
(2028, 109, 111, 1, DATE(NOW()) - INTERVAL 10 DAY, '14:00:00', 'Skin rash consult', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 20 DAY, NOW()),
(2029, 109, 111, 1, DATE(NOW()) + INTERVAL 4 DAY, '10:45:00', 'General consultation', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 1 DAY, NOW()),
(2030, 109, 111, 1, DATE(NOW()) - INTERVAL 95 DAY, '14:30:00', 'General consultation', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 105 DAY, NOW()),
-- Kathleen Navarro (OB-GYN)
(2031, 810, 110, 2, DATE(NOW()) - INTERVAL 65 DAY, '16:00:00', 'Well-woman exam', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 75 DAY, NOW()),
(2032, 810, 110, 2, DATE(NOW()) + INTERVAL 16 DAY, '15:30:00', 'Well-woman exam', 'Pending',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 2 DAY, NOW()),
-- Eduardo Salvador (Surgery)
(2033, 811, 112, 3, DATE(NOW()) - INTERVAL 82 DAY, '09:30:00', 'Post-op wound review', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 92 DAY, NOW()),
(2034, 811, 115, 3, DATE(NOW()) + INTERVAL 11 DAY, '10:00:00', 'Surgical consult', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 4 DAY, NOW()),
-- Nina Castillo (Internal Medicine)
(2035, 812, 114, 5, DATE(NOW()) - INTERVAL 52 DAY, '10:00:00', 'Asthma follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 62 DAY, NOW()),
(2036, 812, 114, 5, DATE(NOW()) + INTERVAL 13 DAY, '10:00:00', 'Asthma follow-up', 'Scheduled',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 3 DAY, NOW()),
-- Bryan Ocampo (Surgery)
(2037, 813, 115, 3, DATE(NOW()) - INTERVAL 18 DAY, '11:30:00', 'Appendectomy post-op', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 28 DAY, NOW()),
(2038, 813, 112, 3, DATE(NOW()) + INTERVAL 22 DAY, '09:00:00', 'General surgery follow-up', 'Pending',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 1 DAY, NOW()),
-- ===== TODAY (live queue appointments) =====
(2039, 101, 111, 1, CURDATE(), '09:00:00', 'Vaccination follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
(2040, 102, 113, 5, CURDATE(), '09:30:00', 'Hypertension follow-up', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2041, 810, 110, 2, CURDATE(), '10:00:00', 'Prenatal check-up', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2042, 813, 115, 3, CURDATE(), '10:00:00', 'Wound dressing review', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2043, 103, 110, 2, CURDATE(), '14:00:00', 'Prenatal check-up', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2044, 811, 112, 3, CURDATE(), '11:00:00', 'Post-op review', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2045, 109, 111, 1, CURDATE(), '11:30:00', 'General consultation', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2046, 104, 114, 5, CURDATE(), '13:30:00', 'Spirometry review', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2047, 812, 114, 5, CURDATE(), '09:00:00', 'Asthma follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2048, 108, 111, 1, CURDATE(), '10:30:00', 'Immunization review', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2049, 102, 113, 5, CURDATE(), '15:00:00', 'Medication refill', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
-- Past no-show appointment
(2050, 105, 112, 3, DATE(NOW()) - INTERVAL 1 DAY, '08:30:00', 'Pre-op clearance revisit', 'No Show',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 8 DAY, NOW()),
-- ===== READY CONSULTATION -- TODAY, In Consultation (one per doctor so
--       every doctor opens doctor_queue.php to a live consult) =====
(2051, 106, 116, 4, CURDATE(), '14:30:00', 'Nephrology follow-up', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2052, 811, 115, 3, CURDATE(), '13:00:00', 'Post-op review', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2053, 105, 112, 3, CURDATE(), '08:30:00', 'Pre-op clearance', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2054, 108, 111, 1, CURDATE(), '15:30:00', 'Well-child checkup', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2055, 107, 113, 5, CURDATE(), '09:00:00', 'Hypertension follow-up', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
(2056, 100, 114, 5, CURDATE(), '11:00:00', 'General consultation', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 6 DAY, NOW()),
-- ===== EXTENDED PER-DOCTOR MEDICAL RECORDS (historical, searchable) =====
(2058, 103, 110, 2, DATE(NOW()) - INTERVAL 80 DAY, '15:00:00', 'Postpartum follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 90 DAY, NOW()),
(2059, 810, 110, 2, DATE(NOW()) - INTERVAL 140 DAY, '16:00:00', 'Prenatal check-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 150 DAY, NOW()),
(2060, 108, 111, 1, DATE(NOW()) - INTERVAL 135 DAY, '15:30:00', 'Well-child checkup', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 145 DAY, NOW()),
(2061, 100, 111, 1, DATE(NOW()) - INTERVAL 150 DAY, '09:00:00', 'Fever consult', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 160 DAY, NOW()),
(2062, 811, 112, 3, DATE(NOW()) - INTERVAL 125 DAY, '09:30:00', 'Post-op follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 135 DAY, NOW()),
(2063, 813, 112, 3, DATE(NOW()) - INTERVAL 105 DAY, '10:00:00', 'Wound care review', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 115 DAY, NOW()),
(2064, 102, 113, 5, DATE(NOW()) - INTERVAL 135 DAY, '13:30:00', 'Hypertension follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 145 DAY, NOW()),
(2065, 104, 113, 5, DATE(NOW()) - INTERVAL 115 DAY, '10:00:00', 'Chronic cough', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 125 DAY, NOW()),
(2066, 105, 114, 5, DATE(NOW()) - INTERVAL 90 DAY, '11:00:00', 'Medication refill / BP check', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 100 DAY, NOW()),
(2067, 812, 114, 5, DATE(NOW()) - INTERVAL 130 DAY, '10:00:00', 'Asthma follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 140 DAY, NOW()),
(2068, 105, 115, 3, DATE(NOW()) - INTERVAL 70 DAY, '11:30:00', 'Post-op checkup', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 80 DAY, NOW()),
(2069, 811, 115, 3, DATE(NOW()) - INTERVAL 160 DAY, '10:30:00', 'Hernia consult', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 170 DAY, NOW()),
(2070, 106, 116, 4, DATE(NOW()) - INTERVAL 150 DAY, '14:00:00', 'Renal colic consult', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 160 DAY, NOW()),
(2071, 105, 116, 4, DATE(NOW()) - INTERVAL 130 DAY, '14:30:00', 'Renal profile review', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 140 DAY, NOW()),
-- ===== PRE-EXISTING DOCTORS -- READY CONSULTATION TODAY (In Consultation) =====
-- Dr. Edrian Bagohara (Staff 2, IM), Dr. Roxzia Kim (Staff 3, Peds),
-- Dr. Morgan Cruz (Staff 5, OB), Dr. Ana Luiz (Staff 8, Surgery),
-- Dr. Shan Meyers (Staff 9, Nephro), Dr. Sarah Mitchelle (Staff 11, Surgery)
(2072, 109, 2,  5, CURDATE(), '16:00:00', 'General consultation', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
(2073, 108, 3,  1, CURDATE(), '16:30:00', 'Pediatric follow-up', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
(2074, 103, 5,  2, CURDATE(), '16:15:00', 'Prenatal check-up', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
(2075, 813, 8,  3, CURDATE(), '16:00:00', 'Post-op review', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
(2076, 106, 9,  4, CURDATE(), '16:30:00', 'Nephrology follow-up', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
(2077, 811, 11, 3, CURDATE(), '16:45:00', 'Surgical consult', 'In Consultation',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 5 DAY, NOW()),
-- ===== PRE-EXISTING DOCTORS -- HISTORICAL MEDICAL RECORDS =====
(2078, 102, 2,  5, DATE(NOW()) - INTERVAL 40 DAY, '13:00:00', 'Hypertension follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 50 DAY, NOW()),
(2079, 107, 2,  5, DATE(NOW()) - INTERVAL 28 DAY, '14:00:00', 'BP check', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 38 DAY, NOW()),
(2080, 108, 3,  1, DATE(NOW()) - INTERVAL 33 DAY, '09:30:00', 'Well-child checkup', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 43 DAY, NOW()),
(2081, 109, 3,  1, DATE(NOW()) - INTERVAL 55 DAY, '10:00:00', 'General consultation', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 65 DAY, NOW()),
(2082, 810, 5,  2, DATE(NOW()) - INTERVAL 22 DAY, '15:00:00', 'Prenatal check-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 32 DAY, NOW()),
(2083, 103, 5,  2, DATE(NOW()) - INTERVAL 48 DAY, '16:00:00', 'Prenatal check-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 58 DAY, NOW()),
(2084, 813, 8,  3, DATE(NOW()) - INTERVAL 30 DAY, '11:00:00', 'Post-op review', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 40 DAY, NOW()),
(2085, 811, 8,  3, DATE(NOW()) - INTERVAL 78 DAY, '10:30:00', 'Wound review', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 88 DAY, NOW()),
(2086, 106, 9,  4, DATE(NOW()) - INTERVAL 26 DAY, '15:30:00', 'Renal profile follow-up', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 36 DAY, NOW()),
(2087, 105, 9,  4, DATE(NOW()) - INTERVAL 95 DAY, '14:00:00', 'Renal profile review', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 105 DAY, NOW()),
(2088, 105, 11, 3, DATE(NOW()) - INTERVAL 36 DAY, '08:30:00', 'Pre-op clearance', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 46 DAY, NOW()),
(2089, 811, 11, 3, DATE(NOW()) - INTERVAL 72 DAY, '09:30:00', 'Surgical consult', 'Completed',
 NULL, NULL, NULL, NULL, NOW() - INTERVAL 82 DAY, NOW())
ON DUPLICATE KEY UPDATE
  PatientID = VALUES(`PatientID`), StaffID = VALUES(`StaffID`),
  DepartmentID = VALUES(`DepartmentID`), AppointmentDate = VALUES(`AppointmentDate`),
  AppointmentTime = VALUES(`AppointmentTime`), Purpose = VALUES(`Purpose`),
  Status = VALUES(`Status`),
  OriginalAppointmentDate = VALUES(`OriginalAppointmentDate`),
  OriginalAppointmentTime = VALUES(`OriginalAppointmentTime`),
  RescheduledAt = VALUES(`RescheduledAt`), RescheduleReason = VALUES(`RescheduleReason`);

-- ===== G2. EMERGENCY DISRUPTION FLAGS on appointment 2002 (Jedrick Versoza) =====
-- Same-day emergency leave -- priority "Pending Reschedule" flow:
-- Status = 'Cancelled' + disruption_reason + active reschedule_token +
-- is_emergency_disruption = 1 (bypasses the 24-hour block).
INSERT INTO `appointments`
  (`AppointmentID`, `PatientID`, `StaffID`, `DepartmentID`, `AppointmentDate`,
   `AppointmentTime`, `Purpose`, `Status`, `disruption_reason`,
   `is_emergency_disruption`, `reschedule_token`, `CreatedAt`, `UpdatedAt`)
VALUES
(2002, 100, 110, 2, DATE(NOW()) + INTERVAL 2 DAY, '09:30:00',
 'OB-GYN consult (referral)', 'Cancelled', 'Doctor Emergency Leave',
 1, '5f4dcc3b5aa765d61d8327deb882cf995f4dcc3b5aa765d61d8327deb882cf99',
 NOW() - INTERVAL 30 DAY, NOW())
ON DUPLICATE KEY UPDATE
  PatientID = VALUES(`PatientID`), StaffID = VALUES(`StaffID`),
  DepartmentID = VALUES(`DepartmentID`), AppointmentDate = VALUES(`AppointmentDate`),
  AppointmentTime = VALUES(`AppointmentTime`), Purpose = VALUES(`Purpose`),
  Status = VALUES(`Status`), disruption_reason = VALUES(`disruption_reason`),
  is_emergency_disruption = VALUES(`is_emergency_disruption`),
  reschedule_token = VALUES(`reschedule_token`);

-- =========================================================================
-- H. CONSULTATIONS (medical records for completed visits)
-- =========================================================================
INSERT INTO `consultations`
  (`ConsultationID`, `AppointmentID`, `PatientID`, `StaffID`,
   `ConsultationDate`, `ConsultationTime`, `ChiefComplaint`, `Diagnosis`,
   `Treatment`, `LabRequest`, `Notes`, `FollowUpDate`, `Status`,
   `BloodPressure`, `Temperature`, `PulseRate`, `Weight`, `Height`,
   `CreatedAt`, `UpdatedAt`)
VALUES
(3000, 2000, 100, 111, DATE(NOW()) - INTERVAL 120 DAY, '09:00:00',
 'Routine check-up', 'No acute findings; normal growth parameters',
 'Nutrition counseling; no medication required', NULL,
 'Growth on track; continue immunization schedule.', DATE(NOW()) - INTERVAL 40 DAY, 'Completed',
 '110/70', 36.6, 76, 62.00, NULL, NOW() - INTERVAL 120 DAY, NOW()),
(3001, 2001, 100, 111, DATE(NOW()) - INTERVAL 60 DAY, '10:00:00',
 'Follow-up after cold', 'Mild allergic rhinitis',
 'Cetirizine 10 mg once daily for 7 days', NULL,
 'Avoid known triggers; review in 3 months.', DATE(NOW()) + INTERVAL 12 DAY, 'Completed',
 '112/71', 36.7, 78, NULL, NULL, NOW() - INTERVAL 60 DAY, NOW()),
(3002, 2004, 101, 111, DATE(NOW()) - INTERVAL 30 DAY, '09:30:00',
 'Fever and cough', 'Acute upper respiratory tract infection (viral)',
 'Paracetamol 500 mg as needed; increased fluids', NULL,
 'Reassess if fever persists beyond 5 days.', DATE(NOW()) + INTERVAL 7 DAY, 'Completed',
 '120/80', 37.8, 84, 58.00, 1.60, NOW() - INTERVAL 30 DAY, NOW()),
(3003, 2005, 101, 111, DATE(NOW()) - INTERVAL 100 DAY, '08:30:00',
 'Headache and fatigue', 'Tension-type headache',
 'Rest; hydration; paracetamol PRN', NULL,
 'Improving lifestyle factors; ergonomics.', DATE(NOW()) - INTERVAL 70 DAY, 'Completed',
 '118/79', 36.9, 74, 59.00, 1.60, NOW() - INTERVAL 100 DAY, NOW()),
(3004, 2008, 102, 113, DATE(NOW()) - INTERVAL 45 DAY, '13:30:00',
 'High BP at home', 'Essential Hypertension Stage I',
 'Amlodipine 5 mg once daily; low-sodium diet', 'CBC; lipid panel; urinalysis',
 'Home BP diary twice daily.', DATE(NOW()) + INTERVAL 5 DAY, 'Completed',
 '150/95', 36.8, 82, 72.00, 1.68, NOW() - INTERVAL 45 DAY, NOW()),
(3005, 2010, 102, 113, DATE(NOW()) - INTERVAL 90 DAY, '14:00:00',
 'Chronic cough', 'Chronic bronchitis',
 'Salbutamol inhaler 2 puffs PRN; smoking cessation advice', 'Chest X-ray',
 'Stop smoking reinforced.', DATE(NOW()) - INTERVAL 70 DAY, 'Completed',
 '132/84', 37.0, 86, 73.00, 1.68, NOW() - INTERVAL 90 DAY, NOW()),
(3006, 2011, 103, 110, DATE(NOW()) - INTERVAL 35 DAY, '15:00:00',
 'Prenatal check-up', 'Normal pregnancy - ANC',
 'Prenatal vitamins; iron supplementation', 'CBC; urinalysis; ultrasound',
 'Fetal growth appropriate for dates.', DATE(NOW()) + INTERVAL 8 DAY, 'Completed',
 '110/68', 36.8, 80, 56.00, 1.58, NOW() - INTERVAL 35 DAY, NOW()),
(3007, 2014, 104, 114, DATE(NOW()) - INTERVAL 25 DAY, '10:30:00',
 'Cough with greenish phlegm', 'Acute bronchitis',
 'Amoxicillin 500 mg TID x 7 days; mucolytic syrup', 'Chest X-ray',
 'Frequent smoker; reinforce cessation.', DATE(NOW()) + INTERVAL 10 DAY, 'Completed',
 '124/78', 37.5, 92, 74.00, 1.72, NOW() - INTERVAL 25 DAY, NOW()),
(3008, 2016, 104, 114, DATE(NOW()) - INTERVAL 70 DAY, '11:00:00',
 'Cough persisted after cold', 'Acute bronchitis (resolved)',
 'Salbutamol inhaler PRN', 'Spirometry',
 'Improved compliance; follow-up PRN.', DATE(NOW()) - INTERVAL 55 DAY, 'Completed',
 '118/76', 36.9, 84, 75.00, 1.72, NOW() - INTERVAL 70 DAY, NOW()),
(3009, 2017, 105, 112, DATE(NOW()) - INTERVAL 55 DAY, '08:30:00',
 'Pre-operative clearance', 'Fit for surgery (cholecystectomy planned)',
 'Clearance issued; pre-op labs recommended', 'CBC; PT/INR; ECG; fasting blood sugar',
 'Coordinate with anesthesia team.', DATE(NOW()) - INTERVAL 50 DAY, 'Completed',
 '128/82', 36.7, 78, 68.00, 1.55, NOW() - INTERVAL 55 DAY, NOW()),
(3010, 2020, 106, 116, DATE(NOW()) - INTERVAL 20 DAY, '14:30:00',
 'Episodic flank pain', 'Renal colic - small right ureteral stone',
 'Analgesics; hydration; strain urine; tamsulosin 0.4 mg once daily', 'Urinalysis; KUB ultrasound',
 'Stone < 5 mm; expect spontaneous passage.', DATE(NOW()) + INTERVAL 6 DAY, 'Completed',
 '122/80', 36.9, 76, 70.00, 1.70, NOW() - INTERVAL 20 DAY, NOW()),
(3011, 2022, 106, 116, DATE(NOW()) - INTERVAL 75 DAY, '15:00:00',
 'Follow-up after colic', 'Nephrolithiasis (stable)',
 'Continued hydration; dietary oxalate restriction', 'Serum creatinine; urinalysis',
 'Stable renal function.', DATE(NOW()) - INTERVAL 55 DAY, 'Completed',
 '118/78', 36.8, 72, 69.00, 1.70, NOW() - INTERVAL 75 DAY, NOW()),
(3012, 2023, 107, 113, DATE(NOW()) - INTERVAL 15 DAY, '09:00:00',
 'Routine BP check', 'Hypertension Stage I (controlled)',
 'Amlodipine 5 mg once daily; continue low-salt diet', 'Lipid panel',
 'BP within target.', DATE(NOW()) + INTERVAL 18 DAY, 'Completed',
 '138/88', 36.8, 80, 66.00, 1.55, NOW() - INTERVAL 15 DAY, NOW()),
(3013, 2025, 107, 113, DATE(NOW()) - INTERVAL 110 DAY, '09:30:00',
 'Fatigue; thirst', 'Prediabetes',
 'Metformin 500 mg twice daily; diet and exercise', 'Fasting blood sugar; HbA1c',
 'Recheck HbA1c in 3 months.', DATE(NOW()) - INTERVAL 20 DAY, 'Completed',
 '126/82', 36.7, 78, 67.00, 1.55, NOW() - INTERVAL 110 DAY, NOW()),
(3014, 2026, 108, 111, DATE(NOW()) - INTERVAL 40 DAY, '15:30:00',
 'Well-child visit', 'Healthy; growth on track',
 'Continued immunization schedule', NULL,
 'Next well-child in 2 months.', DATE(NOW()) + INTERVAL 9 DAY, 'Completed',
 '95/60', 36.8, 90, 14.00, 0.95, NOW() - INTERVAL 40 DAY, NOW()),
(3015, 2028, 109, 111, DATE(NOW()) - INTERVAL 10 DAY, '14:00:00',
 'Itchy rash on both forearms', 'Contact dermatitis',
 'Topical corticosteroid cream for 7 days; avoid suspected irritants', NULL,
 'Use mild soap and moisturizer.', DATE(NOW()) + INTERVAL 4 DAY, 'Completed',
 '110/72', 36.9, 72, 55.00, 1.63, NOW() - INTERVAL 10 DAY, NOW()),
(3016, 2030, 109, 111, DATE(NOW()) - INTERVAL 95 DAY, '14:30:00',
 'Occasional dizziness', 'Orthostatic hypotension (mild)',
 'Hydration; standing slowly', NULL,
 'Review BP if symptoms recur.', DATE(NOW()) - INTERVAL 65 DAY, 'Completed',
 '108/66', 36.6, 70, 54.00, 1.63, NOW() - INTERVAL 95 DAY, NOW()),
(3017, 2031, 810, 110, DATE(NOW()) - INTERVAL 65 DAY, '16:00:00',
 'Annual well-woman exam', 'Normal exam; no abnormalities',
 'Routine screening advised (pap smear due)', 'Pap smear',
 'Repeat pap in 1 year.', DATE(NOW()) - INTERVAL 5 DAY, 'Completed',
 '112/74', 36.7, 76, 52.00, 1.57, NOW() - INTERVAL 65 DAY, NOW()),
(3018, 2033, 811, 112, DATE(NOW()) - INTERVAL 82 DAY, '09:30:00',
 'Post-op wound review', 'Healing post-op wound; no infection',
 'Continue wound care; suture removal done', NULL,
 'Come back for next review as scheduled.', DATE(NOW()) - INTERVAL 60 DAY, 'Completed',
 '124/80', 36.8, 80, 78.00, 1.65, NOW() - INTERVAL 82 DAY, NOW()),
(3019, 2035, 812, 114, DATE(NOW()) - INTERVAL 52 DAY, '10:00:00',
 'Wheezing episodes', 'Mild persistent asthma',
 'Salbutamol inhaler PRN; controller inhaler low dose', 'Peak flow; spirometry',
 'Improve inhaler technique.', DATE(NOW()) + INTERVAL 13 DAY, 'Completed',
 '116/74', 36.9, 88, 50.00, 1.58, NOW() - INTERVAL 52 DAY, NOW()),
(3020, 2037, 813, 115, DATE(NOW()) - INTERVAL 18 DAY, '11:30:00',
 'Post-appendectomy pain', 'Post-op recovery; healing well',
 'Analgesics as needed; wound care', NULL,
 'Activity restrictions for 2 weeks.', DATE(NOW()) - INTERVAL 5 DAY, 'Completed',
 '118/76', 36.8, 82, 70.00, 1.75, NOW() - INTERVAL 18 DAY, NOW()),
-- ===== EXTENDED PER-DOCTOR MEDICAL RECORDS =====
-- Dr. Maricel Arroyo / OB-GYN (Staff 110)
(3021, 2058, 103, 110, DATE(NOW()) - INTERVAL 80 DAY, '15:00:00',
 'Postpartum follow-up', 'Postpartum recovery (normal)',
 'Continue iron; pelvic rest', NULL,
 'Lochia within normal limits; wound healed.', DATE(NOW()) - INTERVAL 35 DAY, 'Completed',
 '112/72', 36.7, 78, 57.00, 1.58, NOW() - INTERVAL 80 DAY, NOW()),
(3022, 2059, 810, 110, DATE(NOW()) - INTERVAL 140 DAY, '16:00:00',
 'Prenatal check-up', 'Normal pregnancy - ANC',
 'Prenatal vitamins; iron supplementation', 'CBC; urinalysis',
 'Fetal heart tones noted; growth appropriate.', DATE(NOW()) - INTERVAL 65 DAY, 'Completed',
 '108/66', 36.6, 82, 51.00, 1.57, NOW() - INTERVAL 140 DAY, NOW()),
-- Dr. Rafael Domingo / Pediatrics (Staff 111)
(3023, 2060, 108, 111, DATE(NOW()) - INTERVAL 135 DAY, '15:30:00',
 'Well-child checkup', 'Healthy; growth on track',
 'Immunization on schedule', NULL,
 'Height and weight around 50th percentile.', DATE(NOW()) - INTERVAL 40 DAY, 'Completed',
 '96/60', 36.8, 92, 13.00, 0.93, NOW() - INTERVAL 135 DAY, NOW()),
(3024, 2061, 100, 111, DATE(NOW()) - INTERVAL 150 DAY, '09:00:00',
 'Fever and body aches', 'Viral upper respiratory infection',
 'Paracetamol as needed; fluids', NULL,
 'Resolved within 3 days.', DATE(NOW()) - INTERVAL 120 DAY, 'Completed',
 '115/74', 37.6, 86, NULL, NULL, NOW() - INTERVAL 150 DAY, NOW()),
-- Dr. Santiago Mercado / Surgery (Staff 112)
(3025, 2062, 811, 112, DATE(NOW()) - INTERVAL 125 DAY, '09:30:00',
 'Post-op follow-up', 'Healing surgical site',
 'Suture removal; continue wound care', NULL,
 'No signs of infection.', DATE(NOW()) - INTERVAL 82 DAY, 'Completed',
 '126/82', 36.8, 78, 78.00, 1.65, NOW() - INTERVAL 125 DAY, NOW()),
(3026, 2063, 813, 112, DATE(NOW()) - INTERVAL 105 DAY, '10:00:00',
 'Wound care review', 'Granulating wound',
 'Continue dressings; recheck in 5 days', NULL,
 'Dressing change every 2 days.', DATE(NOW()) - INTERVAL 18 DAY, 'Completed',
 '120/78', 36.9, 80, 70.00, 1.75, NOW() - INTERVAL 105 DAY, NOW()),
-- Dr. Eleanor Villar / IM-Pulmonology (Staff 113)
(3027, 2064, 102, 113, DATE(NOW()) - INTERVAL 135 DAY, '13:30:00',
 'Routine BP follow-up', 'Essential Hypertension Stage I',
 'Losartan 50 mg once daily; low-salt diet', 'CBC',
 'Hold home BP diary twice daily.', DATE(NOW()) - INTERVAL 45 DAY, 'Completed',
 '148/94', 36.8, 84, NULL, NULL, NOW() - INTERVAL 135 DAY, NOW()),
(3028, 2065, 104, 113, DATE(NOW()) - INTERVAL 115 DAY, '10:00:00',
 'Persistent cough', 'Acute bronchitis',
 'Amoxicillin 500 mg TID; ambroxol syrup', 'Chest X-ray',
 'Follow-up if no improvement after 7 days.', DATE(NOW()) - INTERVAL 25 DAY, 'Completed',
 '125/80', 37.4, 90, NULL, NULL, NOW() - INTERVAL 115 DAY, NOW()),
-- Dr. Emmanuel Bautista / Internal Medicine (Staff 114)
(3029, 2066, 105, 114, DATE(NOW()) - INTERVAL 90 DAY, '11:00:00',
 'Medication refill request', 'Pre-diabetes (stable)',
 'Continue Metformin 500 mg BID', 'Fasting blood sugar',
 'Lifestyle modification continues.', DATE(NOW()) - INTERVAL 55 DAY, 'Completed',
 '124/80', 36.8, 76, 68.00, 1.55, NOW() - INTERVAL 90 DAY, NOW()),
(3030, 2067, 812, 114, DATE(NOW()) - INTERVAL 130 DAY, '10:00:00',
 'Asthma follow-up', 'Mild persistent asthma (controlled)',
 'Continue controller inhaler', 'Peak flow',
 'Peak flow within 80% of personal best.', DATE(NOW()) - INTERVAL 52 DAY, 'Completed',
 '114/72', 36.9, 86, NULL, NULL, NOW() - INTERVAL 130 DAY, NOW()),
-- Dr. Patricia Lim / Surgical Oncology (Staff 115)
(3031, 2068, 105, 115, DATE(NOW()) - INTERVAL 70 DAY, '11:30:00',
 'Post-op checkup', 'Post-op recovery; healing well',
 'Advance activity as tolerated', NULL,
 'Cleared for normal activity.', DATE(NOW()) - INTERVAL 55 DAY, 'Completed',
 '122/80', 36.7, 74, NULL, NULL, NOW() - INTERVAL 70 DAY, NOW()),
(3032, 2069, 811, 115, DATE(NOW()) - INTERVAL 160 DAY, '10:30:00',
 'Groin swelling', 'Inguinal hernia',
 'Surgical consult; elective repair scheduled', 'Ultrasound',
 'Referred for elective repair.', DATE(NOW()) - INTERVAL 125 DAY, 'Completed',
 '128/84', 36.9, 82, NULL, NULL, NOW() - INTERVAL 160 DAY, NOW()),
-- Dr. Jose Ramos / Nephrology (Staff 116)
(3033, 2070, 106, 116, DATE(NOW()) - INTERVAL 150 DAY, '14:00:00',
 'Flank pain / hematuria', 'Renal colic (right ureteral stone)',
 'Hydration; analgesics; strain urine', 'Urinalysis; KUB',
 'Stone ~4 mm; reassess monthly.', DATE(NOW()) - INTERVAL 75 DAY, 'Completed',
 '124/82', 36.9, 76, 71.00, 1.70, NOW() - INTERVAL 150 DAY, NOW()),
(3034, 2071, 105, 116, DATE(NOW()) - INTERVAL 130 DAY, '14:30:00',
 'Baseline renal profile', 'Mildly elevated creatinine (repeat)',
 'Repeat creatinine; hydration', 'Serum creatinine; eGFR',
 'Repeat creatinine in 4 weeks.', DATE(NOW()) - INTERVAL 90 DAY, 'Completed',
 '120/78', 36.8, 72, NULL, NULL, NOW() - INTERVAL 130 DAY, NOW()),
-- ===== PRE-EXISTING DOCTORS -- MEDICAL RECORDS =====
-- Dr. Edrian Bagohara / IM (Staff 2)
(3035, 2078, 102, 2, DATE(NOW()) - INTERVAL 40 DAY, '13:00:00',
 'Routine hypertension follow-up', 'Essential Hypertension Stage I (controlled)',
 'Continue Amlodipine 5 mg OD; low-salt diet', 'CBC; lipid panel',
 'BP readings improving toward target.', DATE(NOW()) - INTERVAL 5 DAY, 'Completed',
 '142/90', 36.9, 82, NULL, NULL, NOW() - INTERVAL 40 DAY, NOW()),
(3036, 2079, 107, 2, DATE(NOW()) - INTERVAL 28 DAY, '14:00:00',
 'Elevated home BP readings', 'Hypertension Stage I (medication adjustment)',
 'Increase Amlodipine to 10 mg once daily; review in 2 weeks', 'Lipid panel',
 'Home BP diary encouraged twice daily.', DATE(NOW()) + INTERVAL 18 DAY, 'Completed',
 '146/92', 36.8, 80, NULL, NULL, NOW() - INTERVAL 28 DAY, NOW()),
-- Dr. Roxzia Kim / Pediatrics (Staff 3)
(3037, 2080, 108, 3, DATE(NOW()) - INTERVAL 33 DAY, '09:30:00',
 'Well-child checkup', 'Healthy; developmentally on track',
 'Continued immunization schedule', NULL,
 'Milestones reached appropriately for age.', DATE(NOW()) + INTERVAL 9 DAY, 'Completed',
 '94/58', 36.7, 88, 12.50, 0.92, NOW() - INTERVAL 33 DAY, NOW()),
(3038, 2081, 109, 3, DATE(NOW()) - INTERVAL 55 DAY, '10:00:00',
 'Mild fever and cold', 'Viral URI',
 'Paracetamol PRN; increased fluids', NULL,
 'Resolved within 2 days.', DATE(NOW()) - INTERVAL 10 DAY, 'Completed',
 '112/70', 37.4, 84, NULL, NULL, NOW() - INTERVAL 55 DAY, NOW()),
-- Dr. Morgan Cruz / OB-GYN (Staff 5)
(3039, 2082, 810, 5, DATE(NOW()) - INTERVAL 22 DAY, '15:00:00',
 'Prenatal check-up', 'Normal pregnancy - ANC',
 'Prenatal vitamins; iron supplementation', 'CBC; urinalysis',
 'All parameters within normal range.', DATE(NOW()) + INTERVAL 16 DAY, 'Completed',
 '109/67', 36.6, 78, 53.00, 1.57, NOW() - INTERVAL 22 DAY, NOW()),
(3040, 2083, 103, 5, DATE(NOW()) - INTERVAL 48 DAY, '16:00:00',
 'Prenatal check-up', 'Normal pregnancy - ANC',
 'Continue prenatal vitamins', 'Ultrasound',
 'Fetal movement reported normally.', DATE(NOW()) - INTERVAL 35 DAY, 'Completed',
 '111/69', 36.7, 80, NULL, NULL, NOW() - INTERVAL 48 DAY, NOW()),
-- Dr. Ana Luiz / General Surgery (Staff 8)
(3041, 2084, 813, 8, DATE(NOW()) - INTERVAL 30 DAY, '11:00:00',
 'Post-appendectomy review', 'Post-op recovery; healing well',
 'Continue wound care', NULL,
 'Cleared for light activity.', DATE(NOW()) - INTERVAL 18 DAY, 'Completed',
 '119/77', 36.8, 80, NULL, NULL, NOW() - INTERVAL 30 DAY, NOW()),
(3042, 2085, 811, 8, DATE(NOW()) - INTERVAL 78 DAY, '10:30:00',
 'Wound review', 'Healed surgical wound',
 'No further intervention needed', NULL,
 'Site fully healed.', DATE(NOW()) - INTERVAL 20 DAY, 'Completed',
 '125/81', 36.9, 78, NULL, NULL, NOW() - INTERVAL 78 DAY, NOW()),
-- Dr. Shan Meyers / Nephrology (Staff 9)
(3043, 2086, 106, 9, DATE(NOW()) - INTERVAL 26 DAY, '15:30:00',
 'Renal profile follow-up', 'Nephrolithiasis (stable); creatinine normal',
 'Continue hydration; dietary oxalate restriction', 'Serum creatinine; urinalysis',
 'Recheck renal profile in 3 months.', DATE(NOW()) + INTERVAL 85 DAY, 'Completed',
 '121/79', 36.8, 74, NULL, NULL, NOW() - INTERVAL 26 DAY, NOW()),
(3044, 2087, 105, 9, DATE(NOW()) - INTERVAL 95 DAY, '14:00:00',
 'Baseline renal profile', 'Normal renal function',
 'No intervention; continue hydration', 'Serum creatinine; eGFR',
 'Recheck annually.', DATE(NOW()) - INTERVAL 5 DAY, 'Completed',
 '118/76', 36.9, 72, NULL, NULL, NOW() - INTERVAL 95 DAY, NOW()),
-- Dr. Sarah Mitchelle / Surgical Oncology (Staff 11)
(3045, 2088, 105, 11, DATE(NOW()) - INTERVAL 36 DAY, '08:30:00',
 'Pre-operative clearance', 'Cleared for planned procedure',
 'Pre-op labs completed', 'CBC; ECG; fasting blood sugar',
 'Coordinate with anesthesiology team.', DATE(NOW()) - INTERVAL 55 DAY, 'Completed',
 '127/83', 36.7, 76, NULL, NULL, NOW() - INTERVAL 36 DAY, NOW()),
(3046, 2089, 811, 11, DATE(NOW()) - INTERVAL 72 DAY, '09:30:00',
 'Surgical consult', 'Inguinal hernia (recurrent evaluation)',
 'Plan elective repair; imaging reviewed', 'Ultrasound',
 'Reviewed with patient; scheduling discussed.', DATE(NOW()) - INTERVAL 34 DAY, 'Completed',
 '130/85', 36.9, 82, NULL, NULL, NOW() - INTERVAL 72 DAY, NOW())
ON DUPLICATE KEY UPDATE
  AppointmentID = VALUES(`AppointmentID`), PatientID = VALUES(`PatientID`),
  StaffID = VALUES(`StaffID`), ConsultationDate = VALUES(`ConsultationDate`),
  ConsultationTime = VALUES(`ConsultationTime`),
  ChiefComplaint = VALUES(`ChiefComplaint`), Diagnosis = VALUES(`Diagnosis`),
  Treatment = VALUES(`Treatment`), LabRequest = VALUES(`LabRequest`),
  Notes = VALUES(`Notes`), FollowUpDate = VALUES(`FollowUpDate`),
  Status = VALUES(`Status`), BloodPressure = VALUES(`BloodPressure`),
  Temperature = VALUES(`Temperature`), PulseRate = VALUES(`PulseRate`),
  Weight = VALUES(`Weight`), Height = VALUES(`Height`);

-- =========================================================================
-- I. VITALS (per consultation)
-- =========================================================================
INSERT INTO `vitals`
  (`VitalID`, `AppointmentID`, `PatientID`, `StaffID`, `BloodPressure`,
   `Temperature`, `PulseRate`, `Weight`, `Height`, `RecordedAt`)
VALUES
(4000, 2000, 100, 111, '110/70', 36.6, 76, 62.00, NULL, NOW() - INTERVAL 120 DAY),
(4001, 2001, 100, 111, '112/71', 36.7, 78, NULL, NULL, NOW() - INTERVAL 60 DAY),
(4002, 2004, 101, 111, '120/80', 37.8, 84, 58.00, 1.60, NOW() - INTERVAL 30 DAY),
(4003, 2005, 101, 111, '118/79', 36.9, 74, 59.00, 1.60, NOW() - INTERVAL 100 DAY),
(4004, 2008, 102, 113, '150/95', 36.8, 82, 72.00, 1.68, NOW() - INTERVAL 45 DAY),
(4005, 2010, 102, 113, '132/84', 37.0, 86, 73.00, 1.68, NOW() - INTERVAL 90 DAY),
(4006, 2011, 103, 110, '110/68', 36.8, 80, 56.00, 1.58, NOW() - INTERVAL 35 DAY),
(4007, 2014, 104, 114, '124/78', 37.5, 92, 74.00, 1.72, NOW() - INTERVAL 25 DAY),
(4008, 2016, 104, 114, '118/76', 36.9, 84, 75.00, 1.72, NOW() - INTERVAL 70 DAY),
(4009, 2017, 105, 112, '128/82', 36.7, 78, 68.00, 1.55, NOW() - INTERVAL 55 DAY),
(4010, 2020, 106, 116, '122/80', 36.9, 76, 70.00, 1.70, NOW() - INTERVAL 20 DAY),
(4011, 2022, 106, 116, '118/78', 36.8, 72, 69.00, 1.70, NOW() - INTERVAL 75 DAY),
(4012, 2023, 107, 113, '138/88', 36.8, 80, 66.00, 1.55, NOW() - INTERVAL 15 DAY),
(4013, 2025, 107, 113, '126/82', 36.7, 78, 67.00, 1.55, NOW() - INTERVAL 110 DAY),
(4014, 2026, 108, 111, '95/60', 36.8, 90, 14.00, 0.95, NOW() - INTERVAL 40 DAY),
(4015, 2028, 109, 111, '110/72', 36.9, 72, 55.00, 1.63, NOW() - INTERVAL 10 DAY),
(4016, 2030, 109, 111, '108/66', 36.6, 70, 54.00, 1.63, NOW() - INTERVAL 95 DAY),
(4017, 2031, 810, 110, '112/74', 36.7, 76, 52.00, 1.57, NOW() - INTERVAL 65 DAY),
(4018, 2033, 811, 112, '124/80', 36.8, 80, 78.00, 1.65, NOW() - INTERVAL 82 DAY),
(4019, 2035, 812, 114, '116/74', 36.9, 88, 50.00, 1.58, NOW() - INTERVAL 52 DAY),
(4020, 2037, 813, 115, '118/76', 36.8, 82, 70.00, 1.75, NOW() - INTERVAL 18 DAY),
(4021, 2058, 103, 110, '112/72', 36.7, 78, 57.00, 1.58, NOW() - INTERVAL 80 DAY),
(4022, 2059, 810, 110, '108/66', 36.6, 82, 51.00, 1.57, NOW() - INTERVAL 140 DAY),
(4023, 2060, 108, 111, '96/60', 36.8, 92, 13.00, 0.93, NOW() - INTERVAL 135 DAY),
(4024, 2061, 100, 111, '115/74', 37.6, 86, NULL, NULL, NOW() - INTERVAL 150 DAY),
(4025, 2062, 811, 112, '126/82', 36.8, 78, 78.00, 1.65, NOW() - INTERVAL 125 DAY),
(4026, 2063, 813, 112, '120/78', 36.9, 80, 70.00, 1.75, NOW() - INTERVAL 105 DAY),
(4027, 2064, 102, 113, '148/94', 36.8, 84, NULL, NULL, NOW() - INTERVAL 135 DAY),
(4028, 2065, 104, 113, '125/80', 37.4, 90, NULL, NULL, NOW() - INTERVAL 115 DAY),
(4029, 2066, 105, 114, '124/80', 36.8, 76, 68.00, 1.55, NOW() - INTERVAL 90 DAY),
(4030, 2067, 812, 114, '114/72', 36.9, 86, NULL, NULL, NOW() - INTERVAL 130 DAY),
(4031, 2068, 105, 115, '122/80', 36.7, 74, NULL, NULL, NOW() - INTERVAL 70 DAY),
(4032, 2069, 811, 115, '128/84', 36.9, 82, NULL, NULL, NOW() - INTERVAL 160 DAY),
(4033, 2070, 106, 116, '124/82', 36.9, 76, 71.00, 1.70, NOW() - INTERVAL 150 DAY),
(4034, 2071, 105, 116, '120/78', 36.8, 72, NULL, NULL, NOW() - INTERVAL 130 DAY),
(4035, 2078, 102, 2, '142/90', 36.9, 82, NULL, NULL, NOW() - INTERVAL 40 DAY),
(4036, 2079, 107, 2, '146/92', 36.8, 80, NULL, NULL, NOW() - INTERVAL 28 DAY),
(4037, 2080, 108, 3, '94/58', 36.7, 88, 12.50, 0.92, NOW() - INTERVAL 33 DAY),
(4038, 2081, 109, 3, '112/70', 37.4, 84, NULL, NULL, NOW() - INTERVAL 55 DAY),
(4039, 2082, 810, 5, '109/67', 36.6, 78, 53.00, 1.57, NOW() - INTERVAL 22 DAY),
(4040, 2083, 103, 5, '111/69', 36.7, 80, NULL, NULL, NOW() - INTERVAL 48 DAY),
(4041, 2084, 813, 8, '119/77', 36.8, 80, NULL, NULL, NOW() - INTERVAL 30 DAY),
(4042, 2085, 811, 8, '125/81', 36.9, 78, NULL, NULL, NOW() - INTERVAL 78 DAY),
(4043, 2086, 106, 9, '121/79', 36.8, 74, NULL, NULL, NOW() - INTERVAL 26 DAY),
(4044, 2087, 105, 9, '118/76', 36.9, 72, NULL, NULL, NOW() - INTERVAL 95 DAY),
(4045, 2088, 105, 11, '127/83', 36.7, 76, NULL, NULL, NOW() - INTERVAL 36 DAY),
(4046, 2089, 811, 11, '130/85', 36.9, 82, NULL, NULL, NOW() - INTERVAL 72 DAY)
ON DUPLICATE KEY UPDATE
  AppointmentID = VALUES(`AppointmentID`), PatientID = VALUES(`PatientID`),
  StaffID = VALUES(`StaffID`), BloodPressure = VALUES(`BloodPressure`),
  Temperature = VALUES(`Temperature`), PulseRate = VALUES(`PulseRate`),
  Weight = VALUES(`Weight`), Height = VALUES(`Height`);

-- =========================================================================
-- J. PRESCRIPTIONS + PRESCRIPTION ITEMS
-- =========================================================================
INSERT INTO `prescriptions`
  (`PrescriptionID`, `ConsultationID`, `PrescribedDate`, `Instructions`,
   `CreatedAt`, `UpdatedAt`)
VALUES
(5000, 3001, DATE(NOW()) - INTERVAL 60 DAY, 'Take once daily at bedtime.', NOW() - INTERVAL 60 DAY, NOW()),
(5001, 3002, DATE(NOW()) - INTERVAL 30 DAY, 'Take as needed for fever every 4-6 hours; max 4 doses/day.', NOW() - INTERVAL 30 DAY, NOW()),
(5002, 3004, DATE(NOW()) - INTERVAL 45 DAY, 'Take once daily in the morning. Monitor BP daily.', NOW() - INTERVAL 45 DAY, NOW()),
(5003, 3005, DATE(NOW()) - INTERVAL 90 DAY, 'Inhale 2 puffs when symptoms occur; max 8 puffs/day.', NOW() - INTERVAL 90 DAY, NOW()),
(5004, 3006, DATE(NOW()) - INTERVAL 35 DAY, 'Take one capsule daily with meals.', NOW() - INTERVAL 35 DAY, NOW()),
(5005, 3007, DATE(NOW()) - INTERVAL 25 DAY, 'Take 1 capsule TID for 7 days with food. Complete the course.', NOW() - INTERVAL 25 DAY, NOW()),
(5006, 3012, DATE(NOW()) - INTERVAL 15 DAY, 'Take once daily in the morning.', NOW() - INTERVAL 15 DAY, NOW()),
(5007, 3013, DATE(NOW()) - INTERVAL 110 DAY, 'Take 1 tablet twice daily after meals.', NOW() - INTERVAL 110 DAY, NOW()),
(5008, 3015, DATE(NOW()) - INTERVAL 10 DAY, 'Apply thin layer on affected areas twice daily for 7 days.', NOW() - INTERVAL 10 DAY, NOW()),
(5009, 3019, DATE(NOW()) - INTERVAL 52 DAY, 'Inhale 1-2 puffs as needed; controller inhaler twice daily.', NOW() - INTERVAL 52 DAY, NOW()),
(5010, 3024, DATE(NOW()) - INTERVAL 150 DAY, 'Take 500 mg every 4-6 hours as needed for fever; max 4 doses/day.', NOW() - INTERVAL 150 DAY, NOW()),
(5011, 3028, DATE(NOW()) - INTERVAL 115 DAY, 'Take 1 capsule 3 times daily after meals for 7 days.', NOW() - INTERVAL 115 DAY, NOW()),
(5012, 3027, DATE(NOW()) - INTERVAL 135 DAY, 'Take once daily in the morning. Monitor BP.', NOW() - INTERVAL 135 DAY, NOW()),
(5013, 3036, DATE(NOW()) - INTERVAL 28 DAY, 'Take once daily in the morning. Review in 2 weeks.', NOW() - INTERVAL 28 DAY, NOW())
ON DUPLICATE KEY UPDATE
  ConsultationID = VALUES(`ConsultationID`),
  PrescribedDate = VALUES(`PrescribedDate`), Instructions = VALUES(`Instructions`);

INSERT INTO `prescription_items`
  (`PrescriptionItemID`, `PrescriptionID`, `MedicineName`, `Dosage`,
   `Frequency`, `Duration`)
VALUES
(5100, 5000, 'Cetirizine', '10 mg', 'Once daily', '7 days'),
(5101, 5001, 'Paracetamol', '500 mg', 'Every 4-6 hours PRN', '5 days'),
(5102, 5002, 'Amlodipine', '5 mg', 'Once daily', '30 days'),
(5103, 5003, 'Salbutamol (inhaler)', '100 mcg/puff', '2 puffs PRN', '30 days'),
(5104, 5004, 'Prenatal vitamins with iron', '1 capsule', 'Once daily', '60 days'),
(5105, 5005, 'Amoxicillin', '500 mg', 'Three times daily', '7 days'),
(5106, 5005, 'Carbocisteine (mucolytic)', '500 mg', 'Three times daily', '7 days'),
(5107, 5006, 'Amlodipine', '5 mg', 'Once daily', '30 days'),
(5108, 5007, 'Metformin', '500 mg', 'Twice daily', '90 days'),
(5109, 5008, 'Betamethasone valerate (topical cream)', '0.1%', 'Twice daily', '7 days'),
(5110, 5009, 'Salbutamol (inhaler)', '100 mcg/puff', '2 puffs PRN', '30 days'),
(5111, 5009, 'Fluticasone (controller inhaler)', '88 mcg', 'Twice daily', '30 days'),
(5112, 5010, 'Paracetamol', '500 mg', 'Every 4-6 hours PRN', '5 days'),
(5113, 5011, 'Amoxicillin', '500 mg', 'Three times daily', '7 days'),
(5114, 5011, 'Ambroxol syrup', '30 mg', 'Three times daily', '7 days'),
(5115, 5012, 'Losartan', '50 mg', 'Once daily', '30 days'),
(5116, 5013, 'Amlodipine', '10 mg', 'Once daily', '30 days')
ON DUPLICATE KEY UPDATE
  PrescriptionID = VALUES(`PrescriptionID`), MedicineName = VALUES(`MedicineName`),
  Dosage = VALUES(`Dosage`), Frequency = VALUES(`Frequency`),
  Duration = VALUES(`Duration`);

-- =========================================================================
-- K. QUEUE -- today's live queue across departments + yesterday's history
--    QueueNumber is the numeric slot; the "OB-001 / PED-004" prefixes shown
--    in the UI are department abbreviations rendered by the front end.
-- =========================================================================
INSERT INTO `queue`
  (`QueueID`, `AppointmentID`, `QueueNumber`, `PriorityLevel`, `QueueDate`,
   `QueueTime`, `Status`, `CreatedAt`, `UpdatedAt`)
VALUES
-- Today (live)
(6000, 2039, 1, 'Normal', CURDATE(), '09:00:00', 'Completed', NOW() - INTERVAL 8 HOUR, NOW() - INTERVAL 1 HOUR),
(6001, 2040, 1, 'Normal', CURDATE(), '09:30:00', 'In Consultation', NOW() - INTERVAL 8 HOUR, NOW() - INTERVAL 20 MINUTE),
(6002, 2041, 1, 'Normal', CURDATE(), '10:00:00', 'Waiting', NOW() - INTERVAL 7 HOUR, NOW()),
(6003, 2042, 1, 'Priority', CURDATE(), '10:00:00', 'Waiting', NOW() - INTERVAL 7 HOUR, NOW()),
(6004, 2043, 2, 'Normal', CURDATE(), '14:00:00', 'In Consultation', NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 10 MINUTE),
(6005, 2044, 2, 'Normal', CURDATE(), '11:00:00', 'Called', NOW() - INTERVAL 6 HOUR, NOW() - INTERVAL 5 MINUTE),
(6006, 2045, 2, 'Normal', CURDATE(), '11:30:00', 'Waiting', NOW() - INTERVAL 6 HOUR, NOW()),
(6007, 2046, 2, 'Normal', CURDATE(), '13:30:00', 'Waiting', NOW() - INTERVAL 4 HOUR, NOW()),
(6008, 2047, 3, 'Normal', CURDATE(), '09:00:00', 'Completed', NOW() - INTERVAL 8 HOUR, NOW() - INTERVAL 2 HOUR),
(6009, 2048, 3, 'Normal', CURDATE(), '10:30:00', 'No Show', NOW() - INTERVAL 7 HOUR, NOW() - INTERVAL 2 HOUR),
(6010, 2049, 4, 'Normal', CURDATE(), '15:00:00', 'Completed', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 30 MINUTE),
-- Yesterday (history / analytics)
(6011, 2028, 1, 'Normal', DATE(NOW()) - INTERVAL 1 DAY, '14:00:00', 'Completed', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(6012, 2023, 1, 'Normal', DATE(NOW()) - INTERVAL 1 DAY, '09:00:00', 'Completed', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(6013, 2014, 1, 'Priority', DATE(NOW()) - INTERVAL 1 DAY, '10:30:00', 'Completed', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(6014, 2020, 1, 'Normal', DATE(NOW()) - INTERVAL 1 DAY, '14:30:00', 'Completed', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(6015, 2037, 1, 'Normal', DATE(NOW()) - INTERVAL 1 DAY, '11:30:00', 'Completed', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(6016, 2050, 1, 'Normal', DATE(NOW()) - INTERVAL 1 DAY, '08:30:00', 'No Show', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
-- Today -- ready consultation (queue status = In Consultation for every doctor)
(6017, 2051, 1, 'Normal', CURDATE(), '14:30:00', 'In Consultation', NOW(), NOW()),
(6018, 2053, 3, 'Normal', CURDATE(), '08:30:00', 'In Consultation', NOW(), NOW()),
(6019, 2052, 4, 'Normal', CURDATE(), '13:00:00', 'In Consultation', NOW(), NOW()),
(6020, 2054, 4, 'Normal', CURDATE(), '15:30:00', 'In Consultation', NOW(), NOW()),
(6021, 2055, 5, 'Normal', CURDATE(), '09:00:00', 'In Consultation', NOW(), NOW()),
(6022, 2056, 6, 'Normal', CURDATE(), '11:00:00', 'In Consultation', NOW(), NOW()),
-- Today -- ready consultation for pre-existing doctors (queue = In Consultation)
(6023, 2072, 7, 'Normal', CURDATE(), '16:00:00', 'In Consultation', NOW(), NOW()),
(6024, 2073, 5, 'Normal', CURDATE(), '16:30:00', 'In Consultation', NOW(), NOW()),
(6025, 2074, 3, 'Normal', CURDATE(), '16:15:00', 'In Consultation', NOW(), NOW()),
(6026, 2075, 5, 'Normal', CURDATE(), '16:00:00', 'In Consultation', NOW(), NOW()),
(6027, 2076, 2, 'Normal', CURDATE(), '16:30:00', 'In Consultation', NOW(), NOW()),
(6028, 2077, 6, 'Normal', CURDATE(), '16:45:00', 'In Consultation', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  AppointmentID = VALUES(`AppointmentID`), QueueNumber = VALUES(`QueueNumber`),
  PriorityLevel = VALUES(`PriorityLevel`), QueueDate = VALUES(`QueueDate`),
  QueueTime = VALUES(`QueueTime`), Status = VALUES(`Status`);

-- =========================================================================
-- L. NO-SHOWS
-- =========================================================================
INSERT INTO `no_shows`
  (`NoShowID`, `QueueID`, `AppointmentID`, `PatientID`, `DepartmentID`,
   `MarkedBy`, `NoShowReason`, `NoShowDate`, `FollowUpStatus`, `FollowUpNote`,
   `CreatedAt`, `UpdatedAt`)
VALUES
(7000, 6009, 2048, 108, 1, 600, 'Patient did not arrive within the session window',
 CURDATE(), 'Pending', 'Call guardian to confirm the next immunization date.', NOW(), NOW()),
(7001, 6016, 2050, 105, 3, 602, 'Patient did not arrive; no cancellation received',
 DATE(NOW()) - INTERVAL 1 DAY, 'Pending', 'Reach out to reschedule the pre-op clearance.', NOW() - INTERVAL 1 DAY, NOW())
ON DUPLICATE KEY UPDATE
  QueueID = VALUES(`QueueID`), AppointmentID = VALUES(`AppointmentID`),
  PatientID = VALUES(`PatientID`), DepartmentID = VALUES(`DepartmentID`),
  MarkedBy = VALUES(`MarkedBy`), NoShowReason = VALUES(`NoShowReason`),
  NoShowDate = VALUES(`NoShowDate`), FollowUpStatus = VALUES(`FollowUpStatus`),
  FollowUpNote = VALUES(`FollowUpNote`);

-- =========================================================================
-- M. NOTIFICATIONS (in-app alerts: confirmations, queue, disruption)
-- =========================================================================
INSERT INTO `notifications`
  (`NotificationID`, `UserID`, `Title`, `Message`, `Type`, `RelatedID`,
   `RelatedTable`, `IsRead`, `PriorityLevel`, `SentAt`, `ReadAt`, `CreatedAt`)
VALUES
(8000, 100, 'Urgent: Appointment cancelled — Doctor Emergency Leave',
 CONCAT('Your OB-GYN consult (referral) scheduled for ', DATE_FORMAT(DATE(NOW()) + INTERVAL 2 DAY, '%b %e, %Y'), ' at 9:30 AM was cancelled because the doctor is on emergency leave. A priority reschedule link is available via your email or the Reschedule Appointment page.'),
 'Schedule Disruption', 2002, 'appointments', b'0', 'High', NOW(), NULL, NOW()),
(8001, 100, 'Appointment rescheduled',
 CONCAT('Your pediatric follow-up was moved to ', DATE_FORMAT(DATE(NOW()) - INTERVAL 60 DAY, '%b %e, %Y'), ' at 10:00 AM.'),
 'Appointment Reschedule', 2001, 'appointments', b'1', 'Medium', NOW() - INTERVAL 62 DAY, NOW() - INTERVAL 61 DAY, NOW()),
(8002, 101, 'Appointment pending confirmation',
 CONCAT('Your general consultation on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 7 DAY, '%b %e, %Y'), ' at 9:30 AM is pending confirmation.'),
 'Appointment Confirmation', 2006, 'appointments', b'0', 'Low', NOW() - INTERVAL 2 DAY, NULL, NOW()),
(8003, 101, 'You are called to your queue',
 'Please proceed to the Pediatrics window. Queue number PED-001.',
 'Queue Alert', 2039, 'appointments', b'1', 'Medium', NOW() - INTERVAL 8 HOUR, NOW() - INTERVAL 7 HOUR, NOW()),
(8004, 102, 'Appointment confirmed',
 CONCAT('Your hypertension follow-up on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 5 DAY, '%b %e, %Y'), ' at 1:30 PM has been confirmed.'),
 'Appointment Confirmation', 2009, 'appointments', b'0', 'Medium', NOW() - INTERVAL 1 DAY, NULL, NOW()),
(8005, 102, 'You are called to your queue',
 'Please proceed to the Internal Medicine window. Queue number IM-001.',
 'Queue Alert', 2040, 'appointments', b'0', 'Medium', NOW() - INTERVAL 20 MINUTE, NULL, NOW()),
(8006, 103, 'Appointment scheduled',
 CONCAT('Your prenatal check-up on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 8 DAY, '%b %e, %Y'), ' at 3:00 PM has been scheduled.'),
 'Appointment Confirmation', 2012, 'appointments', b'0', 'Medium', NOW() - INTERVAL 4 DAY, NULL, NOW()),
(8007, 104, 'Appointment reminder',
 CONCAT('Reminder: lung function follow-up on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 10 DAY, '%b %e, %Y'), ' at 10:30 AM.'),
 'Appointment Reminder', 2015, 'appointments', b'0', 'Low', NOW(), NULL, NOW()),
(8008, 106, 'Appointment confirmed',
 CONCAT('Your nephrology consultation on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 6 DAY, '%b %e, %Y'), ' at 2:30 PM has been confirmed.'),
 'Appointment Confirmation', 2021, 'appointments', b'0', 'Medium', NOW() - INTERVAL 1 DAY, NULL, NOW()),
(8009, 107, 'Appointment pending confirmation',
 CONCAT('Your hypertension follow-up on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 18 DAY, '%b %e, %Y'), ' at 9:00 AM is pending confirmation.'),
 'Appointment Confirmation', 2024, 'appointments', b'0', 'Low', NOW() - INTERVAL 3 DAY, NULL, NOW()),
(8010, 109, 'Appointment confirmed',
 CONCAT('Your general consultation on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 4 DAY, '%b %e, %Y'), ' at 10:45 AM has been confirmed.'),
 'Appointment Confirmation', 2029, 'appointments', b'0', 'Medium', NOW() - INTERVAL 1 DAY, NULL, NOW()),
(8011, 811, 'Appointment confirmed',
 CONCAT('Your surgical consult on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 11 DAY, '%b %e, %Y'), ' at 10:00 AM has been confirmed.'),
 'Appointment Confirmation', 2034, 'appointments', b'0', 'Medium', NOW() - INTERVAL 4 DAY, NULL, NOW()),
(8012, 810, 'Queue alert',
 'Please proceed to the OB-GYN window. Queue number OB-001.',
 'Queue Alert', 2041, 'appointments', b'0', 'Medium', NOW(), NULL, NOW()),
(8013, 105, 'No-show follow-up',
 'We missed you at your pre-op clearance revisit. Please contact the front desk to reschedule.',
 'No Show Follow-up', 2050, 'appointments', b'0', 'Medium', NOW() - INTERVAL 1 DAY, NULL, NOW()),
(8014, 812, 'Appointment scheduled',
 CONCAT('Your asthma follow-up on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 13 DAY, '%b %e, %Y'), ' at 10:00 AM has been scheduled.'),
 'Appointment Confirmation', 2036, 'appointments', b'0', 'Medium', NOW() - INTERVAL 3 DAY, NULL, NOW()),
(8015, 813, 'Appointment pending confirmation',
 CONCAT('Your general surgery follow-up on ', DATE_FORMAT(DATE(NOW()) + INTERVAL 22 DAY, '%b %e, %Y'), ' at 9:00 AM is pending confirmation.'),
 'Appointment Confirmation', 2038, 'appointments', b'0', 'Low', NOW() - INTERVAL 1 DAY, NULL, NOW())
ON DUPLICATE KEY UPDATE
  UserID = VALUES(`UserID`), Title = VALUES(`Title`), Message = VALUES(`Message`),
  Type = VALUES(`Type`), RelatedID = VALUES(`RelatedID`),
  RelatedTable = VALUES(`RelatedTable`), IsRead = VALUES(`IsRead`),
  PriorityLevel = VALUES(`PriorityLevel`), SentAt = VALUES(`SentAt`),
  ReadAt = VALUES(`ReadAt`);

-- =========================================================================
-- N. ANNOUNCEMENTS (active, archived, emergency) + reads + disruption audit
-- =========================================================================
INSERT INTO `announcements` (`id`, `title`, `priority`, `audience`, `content`,
                             `author`, `created_at`)
VALUES
(1001, 'Emergency Leave — OB-GYN Consultations Cancelled (Oct 3)',
 'HIGH', 'Patients Only',
 'All OB-GYN consultations on Oct 3, 2026 are cancelled due to a doctor emergency leave. Affected patients have been sent a priority reschedule link to choose a new schedule. We apologize for the inconvenience.',
 'Curora Administration', NOW()),
(1002, 'Facility Maintenance — Lobby & Lab (This Saturday)',
 'MEDIUM', 'All Staff',
 'The ground-floor lobby and laboratory will undergo scheduled maintenance this Saturday. Please route patients through the east wing entrance during this time.',
 'Curora Administration', NOW() - INTERVAL 2 DAY),
(1003, 'Holiday Schedule — Special Non-Working Days',
 'MEDIUM', 'All',
 'The clinic will follow the official holiday schedule for the upcoming special non-working days. Emergency on-call coverage will remain available for urgent cases.',
 'Curora Administration', NOW() - INTERVAL 5 DAY),
(1004, 'Mandatory Annual Staff Training',
 'LOW', 'All Staff',
 'All staff are required to attend the annual patient safety and data privacy training next month. Attendance will be tracked in the HR system.',
 'Curora Administration', NOW() - INTERVAL 15 DAY),
(1005, 'Archived: Pharmacy Holiday Hours (3 months ago)',
 'LOW', 'All',
 'Archived notice: the clinic pharmacy operated on reduced hours during the previous holiday season. This notice is retained for reference.',
 'Curora Administration', NOW() - INTERVAL 90 DAY),
(1006, 'Emergency: Intermittent Power Outage Notice',
 'HIGH', 'All',
 'Emergency notice from last month: the clinic experienced an intermittent power outage. Backup generators were deployed and all scheduled appointments were honored.',
 'Curora Administration', NOW() - INTERVAL 20 DAY)
ON DUPLICATE KEY UPDATE
  title = VALUES(`title`), priority = VALUES(`priority`),
  audience = VALUES(`audience`), content = VALUES(`content`),
  author = VALUES(`author`), created_at = VALUES(`created_at`);

INSERT INTO `announcement_reads` (`announcement_id`, `UserID`, `read_at`)
VALUES
(1001, 100, NOW()), (1001, 101, NOW()), (1001, 103, NOW()),
(1001, 104, NOW()), (1002, 600, NOW()), (1002, 601, NOW()),
(1003, 100, NOW() - INTERVAL 4 DAY), (1003, 102, NOW() - INTERVAL 4 DAY),
(1004, 603, NOW() - INTERVAL 14 DAY), (1006, 500, NOW() - INTERVAL 20 DAY)
ON DUPLICATE KEY UPDATE `read_at` = VALUES(`read_at`);

-- =========================================================================
-- O. DOCTOR UNAVAILABILITY + ANNOUNCEMENT RECIPIENTS (notification audit)
-- =========================================================================
INSERT INTO `DoctorUnavailability`
  (`UnavailabilityID`, `DoctorID`, `DepartmentID`, `StartDate`, `EndDate`,
   `Reason`, `Priority`, `CreatedBy`, `CreatedAt`, `ConfirmedBy`, `ConfirmedAt`)
VALUES
(1001, 110, NULL, CURDATE(), DATE(NOW()) + INTERVAL 2 DAY,
 'Doctor Emergency Leave', 'EMERGENCY', 500, NOW(), 500, NOW()),
(1002, NULL, 1, DATE(NOW()) + INTERVAL 10 DAY, DATE(NOW()) + INTERVAL 11 DAY,
 'Department training / unavailable', 'NORMAL', 500, NOW(), 501, NOW()),
(1003, NULL, 2, DATE(NOW()) - INTERVAL 20 DAY, DATE(NOW()) - INTERVAL 20 DAY,
 'Emergency clinic closure (power)', 'HIGH', 500, NOW() - INTERVAL 20 DAY, 501, NOW() - INTERVAL 20 DAY)
ON DUPLICATE KEY UPDATE
  DoctorID = VALUES(`DoctorID`), DepartmentID = VALUES(`DepartmentID`),
  StartDate = VALUES(`StartDate`), EndDate = VALUES(`EndDate`),
  Reason = VALUES(`Reason`), Priority = VALUES(`Priority`),
  CreatedBy = VALUES(`CreatedBy`), ConfirmedBy = VALUES(`ConfirmedBy`),
  ConfirmedAt = VALUES(`ConfirmedAt`);

INSERT INTO `AnnouncementRecipients`
  (`RecipientID`, `AnnouncementID`, `PatientID`, `Channel`, `Status`,
   `SentAt`, `DeliveredAt`, `ReadAt`, `ErrorMessage`, `RetryCount`, `CreatedAt`)
VALUES
(1001, 1001, 100, 'IN_APP', 'READ', NOW(), NOW(), NOW(), NULL, 0, NOW()),
(1002, 1001, 100, 'EMAIL',  'SENT', NOW(), NOW(), NULL, NULL, 0, NOW()),
(1003, 1001, 103, 'IN_APP', 'SENT', NOW(), NOW(), NULL, NULL, 0, NOW()),
(1004, 1001, 103, 'EMAIL',  'FAILED', NULL, NULL, NULL,
 'Skipped: email is not the patient preferred channel.', 0, NOW()),
(1005, 1001, 104, 'IN_APP', 'SENT', NOW(), NOW(), NULL, NULL, 0, NOW()),
(1006, 1001, 104, 'EMAIL',  'FAILED', NULL, NULL, NULL,
 'Skipped: email is not the patient preferred channel.', 0, NOW())
ON DUPLICATE KEY UPDATE
  AnnouncementID = VALUES(`AnnouncementID`), PatientID = VALUES(`PatientID`),
  Channel = VALUES(`Channel`), Status = VALUES(`Status`),
  SentAt = VALUES(`SentAt`), DeliveredAt = VALUES(`DeliveredAt`),
  ReadAt = VALUES(`ReadAt`), ErrorMessage = VALUES(`ErrorMessage`),
  RetryCount = VALUES(`RetryCount`);

-- =========================================================================
-- P. RESCHEDULE HISTORY & REMINDERS
-- =========================================================================
INSERT INTO `appointment_reschedule_history`
  (`RescheduleID`, `AppointmentID`, `OriginalDate`, `OriginalTime`,
   `NewDate`, `NewTime`, `Reason`, `CreatedAt`)
VALUES
(3000, 2001, DATE(NOW()) - INTERVAL 62 DAY, '10:00:00', DATE(NOW()) - INTERVAL 60 DAY, '10:00:00',
 'Requested by patient', NOW() - INTERVAL 62 DAY),
(3001, 2005, DATE(NOW()) - INTERVAL 105 DAY, '08:30:00', DATE(NOW()) - INTERVAL 100 DAY, '08:30:00',
 'Doctor schedule change', NOW() - INTERVAL 105 DAY),
(3002, 2020, DATE(NOW()) - INTERVAL 22 DAY, '14:30:00', DATE(NOW()) - INTERVAL 20 DAY, '14:30:00',
 'Requested by patient', NOW() - INTERVAL 22 DAY)
ON DUPLICATE KEY UPDATE
  AppointmentID = VALUES(`AppointmentID`), OriginalDate = VALUES(`OriginalDate`),
  OriginalTime = VALUES(`OriginalTime`), NewDate = VALUES(`NewDate`),
  NewTime = VALUES(`NewTime`), Reason = VALUES(`Reason`);

INSERT INTO `appointment_reminders`
  (`ReminderID`, `AppointmentID`, `ReminderType`, `SentAt`, `SentVia`, `Status`)
VALUES
(4000, 2009, 'confirmation', NOW() - INTERVAL 1 DAY, 'email', 'sent'),
(4001, 2012, 'confirmation', NOW() - INTERVAL 4 DAY, 'email', 'sent'),
(4002, 2021, 'confirmation', NOW() - INTERVAL 1 DAY, 'push', 'sent'),
(4003, 2036, 'reminder', NOW(), 'email', 'sent'),
(4004, 2029, 'confirmation', NOW() - INTERVAL 1 DAY, 'push', 'sent'),
(4005, 2006, 'reminder', NOW() - INTERVAL 1 DAY, 'email', 'sent')
ON DUPLICATE KEY UPDATE
  AppointmentID = VALUES(`AppointmentID`), ReminderType = VALUES(`ReminderType`),
  SentAt = VALUES(`SentAt`), SentVia = VALUES(`SentVia`), Status = VALUES(`Status`);

-- =========================================================================
-- Q. REPORTS (analytics artifacts)
-- =========================================================================
INSERT INTO `reports`
  (`ReportID`, `GeneratedBy`, `ReportType`, `ReportTitle`, `Description`,
   `StartDate`, `EndDate`, `FilePath`, `FormatStatus`, `Status`,
   `GeneratedAt`, `CreatedAt`)
VALUES
(6600, 500, 'Volume Report', 'Monthly Patient Volume (Last 6 Months)',
 'Appointment volume per month across all departments for the past 6 months.',
 DATE(NOW()) - INTERVAL 180 DAY, NOW(), NULL, 'Completed', 'Completed',
 NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY),
(6601, 500, 'Cancellation Report', 'Cancellation Rate (Last 6 Months)',
 'Monthly cancellation rate including disruption cancellations.',
 DATE(NOW()) - INTERVAL 180 DAY, NOW(), NULL, 'Completed', 'Completed',
 NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 8 DAY),
(6602, 501, 'Department Report', 'Department Distribution (Last 3 Months)',
 'Appointment distribution across OB-GYN, Pediatrics, Surgery, Nephrology and Internal Medicine.',
 DATE(NOW()) - INTERVAL 90 DAY, NOW(), NULL, 'Completed', 'Completed',
 NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY),
(6603, 500, 'Queue Report', 'Daily Queue Performance (Yesterday)',
 'Queue flow, wait times and no-show summary for yesterday.',
 DATE(NOW()) - INTERVAL 1 DAY, DATE(NOW()) - INTERVAL 1 DAY, NULL, 'Completed', 'Completed',
 NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY)
ON DUPLICATE KEY UPDATE
  GeneratedBy = VALUES(`GeneratedBy`), ReportType = VALUES(`ReportType`),
  ReportTitle = VALUES(`ReportTitle`), Description = VALUES(`Description`),
  StartDate = VALUES(`StartDate`), EndDate = VALUES(`EndDate`),
  FormatStatus = VALUES(`FormatStatus`), Status = VALUES(`Status`),
  GeneratedAt = VALUES(`GeneratedAt`);

-- =========================================================================
-- R. AUDIT TRAIL (admin / system activity)
-- =========================================================================
INSERT INTO `audit_trail`
  (`AuditID`, `UserID`, `Action`, `TableName`, `RecordID`, `OldValue`,
   `NewValue`, `IPAddress`, `UserAgent`, `ActionTimestamp`, `CreatedAt`)
VALUES
(6000, 500, 'LOGIN', 'users', 500, NULL, '{"status":"success"}',
 '192.168.1.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
(6001, 500, 'CREATE', 'DoctorUnavailability', 1001, NULL,
 '{"doctor_id":110,"start":CURDATE(),"end":"+2d","priority":"EMERGENCY"}',
 '192.168.1.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW(), NOW()),
(6002, 500, 'CONFIRM', 'DoctorUnavailability', 1001, NULL,
 '{"decision":"cancel","appointment_ids":[2002],"sent":2,"failed":0}',
 '192.168.1.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW(), NOW()),
(6003, 501, 'UPDATE', 'announcements', 1002, '{"audience":"All Staff"}',
 '{"audience":"All Staff","priority":"MEDIUM"}',
 '192.168.1.11', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY),
(6004, 500, 'GENERATE', 'reports', 6600, NULL, '{"type":"Volume Report"}',
 '192.168.1.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY)
ON DUPLICATE KEY UPDATE
  UserID = VALUES(`UserID`), Action = VALUES(`Action`),
  TableName = VALUES(`TableName`), RecordID = VALUES(`RecordID`),
  OldValue = VALUES(`OldValue`), NewValue = VALUES(`NewValue`),
  IPAddress = VALUES(`IPAddress`), UserAgent = VALUES(`UserAgent`),
  ActionTimestamp = VALUES(`ActionTimestamp`);

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- Verification queries (optional)
-- =========================================================================
-- Seeded users by role:
--   SELECT RoleID, COUNT(*) FROM users WHERE UserID IN (500,501,600,601,602,603,700,701,702,703,704,705,706,100,101,102,103,104,105,106,107,108,109,810,811,812,813) GROUP BY RoleID;
-- Appointment state coverage:
--   SELECT Status, COUNT(*) FROM appointments WHERE AppointmentID BETWEEN 2000 AND 2071 GROUP BY Status;
-- Today's queue:
--   SELECT q.QueueID, q.QueueNumber, q.Status, d.DepartmentName
--     FROM queue q JOIN departments d ON d.DepartmentID = (SELECT DepartmentID FROM appointments a WHERE a.AppointmentID = q.AppointmentID)
--    WHERE q.QueueDate = CURDATE() ORDER BY q.QueueID;
-- Ready consultation today -- one In Consultation patient per doctor:
--   SELECT s.StaffID, CONCAT('Dr. ', u.FirstName, ' ', u.LastName) AS doctor, d.DepartmentName,
--          COUNT(DISTINCT a.AppointmentID) AS in_consult_today
--     FROM appointments a
--     JOIN staff s ON s.StaffID = a.StaffID
--     JOIN users  u ON u.UserID = s.UserID
--     JOIN departments d ON d.DepartmentID = a.DepartmentID
--    WHERE a.AppointmentDate = CURDATE()
--      AND a.Status = 'In Consultation'
--    GROUP BY s.StaffID, u.FirstName, u.LastName, d.DepartmentName
--    ORDER BY s.StaffID;
-- Per-doctor medical record counts (searchable history) -- all doctors incl.
-- pre-existing accounts (StaffID 2,3,5,8,9,11):
--   SELECT s.StaffID, CONCAT('Dr. ', u.FirstName, ' ', u.LastName) AS doctor,
--          d.DepartmentName, COUNT(c.ConsultationID) AS records
--     FROM staff s
--     JOIN users u ON u.UserID = s.UserID
--     JOIN departments d ON d.DepartmentID = s.DepartmentID
--     LEFT JOIN consultations c ON c.StaffID = s.StaffID
--    WHERE s.StaffRole = 'Doctor'
--    GROUP BY s.StaffID, u.FirstName, u.LastName, d.DepartmentName
--    ORDER BY s.StaffID;
-- Emergency disruption case:
--   SELECT AppointmentID, Status, disruption_reason, is_emergency_disruption,
--          LENGTH(reschedule_token) AS token_len FROM appointments WHERE AppointmentID = 2002;