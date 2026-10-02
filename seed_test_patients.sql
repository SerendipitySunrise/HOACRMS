-- =====================================================================
--  seed_test_patients.sql
--  HOACRMS (Curora) -- Realistic Test Patient Seed Data
-- =====================================================================
--  Purpose
--  -------
--  Populates the HOACRMS_Lagaban database with 10 realistic Filipino
--  PATIENT accounts (RoleID = 3 only -- no doctors / staff / admins),
--  complete demographic profiles, appointments in every workflow state,
--  sample consultations (the system's medical-record equivalent), and
--  in-app notifications.
--
--  It also seeds one EMERGENCY DISRUPTION test case (Jedrick Versoza)
--  so the priority reschedule flow can be exercised end-to-end.
--
--  Prerequisites
--  -------------
--  1. database/hoacrms.sql has been loaded.
--  2. database/migrations/2026_09_26_appointment_disruption_tokens.sql
--     has been applied (adds disruption_reason, is_emergency_disruption
--     and reschedule_token to `appointments`). The migration is required
--     by the Jedrick Versoza test-case INSERT.
--
--  Credentials
--  -----------
--  Every seeded account uses the standard test password:
--          Password123!
--  Hash (bcrypt, PASSWORD_DEFAULT):
--          $2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi
--
--  Schema-mapping notes
--  --------------------
--  * "medical_records"  -> `consultations` (the system's record table).
--  * "Pending Reschedule" state is represented the way the app models it:
--        Status = 'Cancelled' + disruption_reason + active reschedule_token.
--  * "can_reschedule" is NOT a stored column; the app derives it at runtime
--    as (Status = 'Cancelled' AND disruption_reason IS NOT NULL AND the
--    submitted reschedule_token matches) -- seeded true for Jedrick via the
--    disruption fields below.
--  * The reschedule window (24h before the appointment, emergency bypass)
--    is enforced by PHP (patient/reschedule_appointment.php and
--    api/appointments/reschedule_appointment.php), not by data. The
--    emergency test case sets is_emergency_disruption = 1 so the priority
--    flow skips the 24-hour block.
--
--  Re-runnability
--  --------------
--  All inserts use explicit IDs in the 100+ / 1000+ range (clear of the
--  existing AUTO_INCREMENT values) with ON DUPLICATE KEY UPDATE, together
--  with SET FOREIGN_KEY_CHECKS = 0/1, so the script can be executed
--  repeatedly without duplicate-key or foreign-key errors.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1) users -- Patient authentication accounts (RoleID 3 = Patient)
-- =====================================================================
INSERT INTO `users`
  (`UserID`, `RoleID`, `FirstName`, `MiddleName`, `LastName`, `Email`,
   `Password`, `Sex`, `DateOfBirth`, `ContactNumber`, `Address`, `Status`,
   `CreatedAt`, `FailedAttempts`, `LastLogin`, `ReminderPreference`,
   `ReceiveReminders`)
VALUES
(100, 3, 'Jedrick', 'Manalo', 'Versoza', 'jedrick.versoza@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1994-05-18', '09175550148', '88 San Rafael St., Brgy. San Lorenzo, Makati City, Metro Manila',
 'Active', NOW(), 0, NULL, 'email', 1),
(101, 3, 'Maria', 'Dizon', 'Santos', 'maria.santos@yahoo.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1989-11-02', '09178881234', '221 Mabini St., Brgy. Poblacion, Mandaluyong City, Metro Manila',
 'Active', NOW(), 0, NULL, 'email', 1),
(102, 3, 'Juan', 'Ramos', 'Dela Cruz', 'juan.delacruz@outlook.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1976-03-25', '09205556677', '14 Burgos Ave., Brgy. San Nicolas, Quezon City, Metro Manila',
 'Active', NOW(), 0, NULL, 'email', 1),
(103, 3, 'Angela', 'Pascual', 'Reyes', 'angela.reyes@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1998-07-14', '09173334455', '305 Sampaguita St., Brgy. Sta. Lucia, Pasig City, Metro Manila',
 'Active', NOW(), 0, NULL, 'all', 1),
(104, 3, 'Miguel', 'Torres', 'Fernandez', 'miguel.fernandez@yahoo.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1984-09-30', '09065557788', '77 Rizal Ext., Brgy. San Juan, San Juan City, Metro Manila',
 'Active', NOW(), 0, NULL, 'sms', 1),
(105, 3, 'Grace', 'Lopez', 'Villanueva', 'grace.villanueva@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1971-12-08', '09189998877', '412 Ilang-Ilang St., Brgy. Bagong Silang, Caloocan City, Metro Manila',
 'Active', NOW(), 0, NULL, 'email', 1),
(106, 3, 'Paolo', 'Aquino', 'Gonzales', 'paolo.gonzales@outlook.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '1991-01-21', '09171112233', '56 Katipunan Ave., Brgy. Loyola Heights, Quezon City, Metro Manila',
 'Active', NOW(), 0, NULL, 'email', 1),
(107, 3, 'Carmela', 'Bautista', 'Aquino', 'carmela.aquino@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1968-06-17', '09174445566', '902 Taft Ave., Brgy. Malate, Manila City, Metro Manila',
 'Active', NOW(), 0, NULL, 'email', 1),
(108, 3, 'Ramon', 'Villanueva', 'Bautista', 'ramon.bautista@yahoo.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Male', '2019-04-09', '09172223344', '31 Dahlia St., Brgy. San Isidro, Parañaque City, Metro Manila',
 'Active', NOW(), 0, NULL, 'email', 1),
(109, 3, 'Liza', 'Garcia', 'Ramos', 'liza.ramos@gmail.com',
 '$2y$12$YsDKE1B1noYbH4EvOQBbLu5rMQdwrNNjJySpanzvg1WkaaShzd5mi',
 'Female', '1995-10-11', '09176669988', '150 Chino Roces Ave., Brgy. Pio del Pilar, Makati City, Metro Manila',
 'Active', NOW(), 0, NULL, 'all', 1)
ON DUPLICATE KEY UPDATE
  RoleID = VALUES(`RoleID`), FirstName = VALUES(`FirstName`),
  MiddleName = VALUES(`MiddleName`), LastName = VALUES(`LastName`),
  Email = VALUES(`Email`), Password = VALUES(`Password`),
  Sex = VALUES(`Sex`), DateOfBirth = VALUES(`DateOfBirth`),
  ContactNumber = VALUES(`ContactNumber`), Address = VALUES(`Address`),
  Status = VALUES(`Status`), FailedAttempts = VALUES(`FailedAttempts`),
  ReminderPreference = VALUES(`ReminderPreference`),
  ReceiveReminders = VALUES(`ReceiveReminders`);

-- =====================================================================
-- 2) patients -- Demographics, medical background & emergency contact
-- =====================================================================
INSERT INTO `patients`
  (`PatientID`, `UserID`, `CivilStatus`, `Religion`, `IsPWD`,
   `DisabilityType`, `BloodType`, `Allergies`, `PastMedicalCondition`,
   `CurrentMedication`, `FamilyMedicalHistory`, `EmergencyContactName`,
   `EmergencyContactNo`, `EmergencyRelation`, `CreatedAt`, `UpdatedAt`)
VALUES
(100, 100, 'Single', 'Roman Catholic', 0, NULL, 'O+',
 'Penicillin', 'Recurrent asthma as a child', 'None',
 'Hypertension (mother)', 'Teresa Versoza', '09171110001', 'Mother', NOW(), NOW()),
(101, 101, 'Married', 'Roman Catholic', 0, NULL, 'A+',
 'None', 'Mild hypertension (controlled)', 'Losartan 50 mg daily',
 'Diabetes (father)', 'Ricardo Santos', '09172220002', 'Spouse', NOW(), NOW()),
(102, 102, 'Married', 'Iglesia ni Cristo', 1, 'Lower-limb mobility impairment', 'B+',
 'None', 'None', 'None', 'None', 'Elena Dela Cruz', '09173330003', 'Spouse', NOW(), NOW()),
(103, 103, 'Single', 'Roman Catholic', 0, NULL, 'AB+',
 'Latex', 'None', 'None', 'Asthma (brother)', 'Rey Reyes', '09174440004', 'Father', NOW(), NOW()),
(104, 104, 'Married', 'Roman Catholic', 0, NULL, 'O-',
 'Aspirin', 'Bronchitis (recurrent)', 'Salbutamol inhaler PRN',
 'Lung disease (grandfather)', 'Lorna Fernandez', '09175550005', 'Spouse', NOW(), NOW()),
(105, 105, 'Widowed', 'Roman Catholic', 0, NULL, 'B-',
 'None', 'Pre-diabetes', 'Metformin 500 mg twice daily',
 'Diabetes (parents)', 'Nena Lopez', '09176660006', 'Sister', NOW(), NOW()),
(106, 106, 'Single', 'Roman Catholic', 0, NULL, 'A-',
 'Sulfa drugs', 'Kidney stones (2019)', 'None',
 'Nephrolithiasis (father)', 'Cora Gonzales', '09177770007', 'Mother', NOW(), NOW()),
(107, 107, 'Married', 'Roman Catholic', 0, NULL, 'O+',
 'None', 'Hypertension Stage I', 'Amlodipine 5 mg daily',
 'Hypertension (mother)', 'Dante Aquino', '09178880008', 'Spouse', NOW(), NOW()),
(108, 108, 'Single', 'Roman Catholic', 0, NULL, 'B+',
 'Peanuts', 'None', 'None', 'None', 'Mira Bautista', '09179990009', 'Mother', NOW(), NOW()),
(109, 109, 'Single', 'Roman Catholic', 0, NULL, 'AB-',
 'None', 'None', 'Oral contraceptive', 'None', 'Nora Ramos', '09171110010', 'Mother', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  UserID = VALUES(`UserID`), CivilStatus = VALUES(`CivilStatus`),
  Religion = VALUES(`Religion`), IsPWD = VALUES(`IsPWD`),
  DisabilityType = VALUES(`DisabilityType`), BloodType = VALUES(`BloodType`),
  Allergies = VALUES(`Allergies`), PastMedicalCondition = VALUES(`PastMedicalCondition`),
  CurrentMedication = VALUES(`CurrentMedication`),
  FamilyMedicalHistory = VALUES(`FamilyMedicalHistory`),
  EmergencyContactName = VALUES(`EmergencyContactName`),
  EmergencyContactNo = VALUES(`EmergencyContactNo`),
  EmergencyRelation = VALUES(`EmergencyRelation`);

-- =====================================================================
-- 3) appointments -- Workflow-state coverage
--    Departments: 1 Pediatrics | 2 OB-GYN | 3 Surgery | 4 Nephrology |
--                 5 Internal Medicine / Pulmonology
--    Doctors (staff): 1 = R. Lagaban (Pediatrics), 2 = E. Bagohara (IM),
--                     3 = R. Kim (Pediatrics)
-- =====================================================================

-- 3a) Standard appointments (all workflow states)
INSERT INTO `appointments`
  (`AppointmentID`, `PatientID`, `StaffID`, `DepartmentID`, `AppointmentDate`,
   `AppointmentTime`, `Purpose`, `Status`, `OriginalAppointmentDate`,
   `OriginalAppointmentTime`, `RescheduledAt`, `RescheduleReason`,
   `CreatedAt`, `UpdatedAt`)
VALUES
(1001, 100, 1, 1, '2026-09-12', '10:00:00', 'Well-child checkup', 'Completed',
 NULL, NULL, NULL, NULL, '2026-09-01 09:00:00', NOW()),
(1002, 101, 1, 1, '2026-10-14', '09:00:00', 'General consultation', 'Pending',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1003, 101, 1, 1, '2026-09-08', '09:30:00', 'General consultation', 'Completed',
 NULL, NULL, NULL, NULL, '2026-08-28 10:00:00', NOW()),
(1004, 102, 2, 5, '2026-10-16', '13:30:00', 'Pulmonology follow-up', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1005, 102, 2, 5, '2026-09-06', '14:00:00', 'Chest examination', 'Cancelled',
 NULL, NULL, NULL, NULL, '2026-08-20 08:30:00', NOW()),
(1006, 103, 3, 1, '2026-10-21', '10:00:00', 'Developmental-behavioral assessment', 'Scheduled',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1007, 103, NULL, 2, '2026-10-28', '15:00:00', 'Prenatal consultation', 'Pending',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1008, 104, 2, 5, '2026-09-15', '11:00:00', 'Respiratory check-up', 'Completed',
 NULL, NULL, NULL, NULL, '2026-09-05 13:00:00', NOW()),
(1009, 104, 2, 5, '2026-11-05', '10:30:00', 'Lung function follow-up', 'Scheduled',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1010, 105, NULL, 3, '2026-10-30', '08:30:00', 'Pre-operative clearance', 'Pending',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1011, 106, NULL, 4, '2026-10-22', '14:30:00', 'Nephrology consultation', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1012, 107, 2, 5, '2026-08-28', '09:00:00', 'Hypertension follow-up', 'Completed',
 NULL, NULL, NULL, NULL, '2026-08-14 08:00:00', NOW()),
(1013, 107, 2, 5, '2026-11-12', '09:30:00', 'Hypertension follow-up', 'Pending',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1014, 108, 1, 1, '2026-10-19', '15:30:00', 'Childhood immunization review', 'Scheduled',
 NULL, NULL, NULL, NULL, NOW(), NOW()),
(1015, 109, 1, 1, '2026-09-20', '14:00:00', 'General consultation', 'Completed',
 NULL, NULL, NULL, NULL, '2026-09-10 09:00:00', NOW()),
(1016, 109, 1, 1, '2026-10-25', '10:45:00', 'General consultation', 'Confirmed',
 NULL, NULL, NULL, NULL, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  PatientID = VALUES(`PatientID`), StaffID = VALUES(`StaffID`),
  DepartmentID = VALUES(`DepartmentID`), AppointmentDate = VALUES(`AppointmentDate`),
  AppointmentTime = VALUES(`AppointmentTime`), Purpose = VALUES(`Purpose`),
  Status = VALUES(`Status`),
  OriginalAppointmentDate = VALUES(`OriginalAppointmentDate`),
  OriginalAppointmentTime = VALUES(`OriginalAppointmentTime`),
  RescheduledAt = VALUES(`RescheduledAt`), RescheduleReason = VALUES(`RescheduleReason`);

-- 3b) EMERGENCY DISRUPTION TEST CASE -- Jedrick Versoza
--     Appointment pushed to "Cancelled" by a same-day/urgent disruption and
--     flagged as high-priority so the patient can self-reschedule inside the
--     emergency window (is_emergency_disruption = 1 bypasses the 24-hour
--     block in patient/reschedule_appointment.php).
--     NOTE: reschedule_token is a fixed 64-hex test token; hash_equals() is
--     used by the app to validate it. Requires the appointment-disruption
--     migration to have been applied.
INSERT INTO `appointments`
  (`AppointmentID`, `PatientID`, `StaffID`, `DepartmentID`, `AppointmentDate`,
   `AppointmentTime`, `Purpose`, `Status`, `disruption_reason`,
   `is_emergency_disruption`, `reschedule_token`, `CreatedAt`, `UpdatedAt`)
VALUES
(1000, 100, 1, 1, '2026-10-03', '09:30:00',
 'Pediatric follow-up consultation', 'Cancelled', 'Doctor Emergency Leave',
 1, '5f4dcc3b5aa765d61d8327deb882cf995f4dcc3b5aa765d61d8327deb882cf99',
 '2026-10-01 07:45:00', NOW())
ON DUPLICATE KEY UPDATE
  PatientID = VALUES(`PatientID`), StaffID = VALUES(`StaffID`),
  DepartmentID = VALUES(`DepartmentID`), AppointmentDate = VALUES(`AppointmentDate`),
  AppointmentTime = VALUES(`AppointmentTime`), Purpose = VALUES(`Purpose`),
  Status = VALUES(`Status`), disruption_reason = VALUES(`disruption_reason`),
  is_emergency_disruption = VALUES(`is_emergency_disruption`),
  reschedule_token = VALUES(`reschedule_token`);

-- =====================================================================
-- 4) consultations -- Sample visit records (the system's medical records)
--    Only linked to appointments with Status = 'Completed' above.
-- =====================================================================
INSERT INTO `consultations`
  (`ConsultationID`, `AppointmentID`, `PatientID`, `StaffID`,
   `ConsultationDate`, `ConsultationTime`, `ChiefComplaint`, `Diagnosis`,
   `Treatment`, `Notes`, `FollowUpDate`, `Status`, `BloodPressure`,
   `Temperature`, `PulseRate`, `Weight`, `Height`, `CreatedAt`, `UpdatedAt`)
VALUES
(1000, 1001, 100, 1, '2026-09-12', '10:00:00', 'Routine well-child checkup',
 'No acute findings; normal growth parameters',
 'Education on nutrition and immunizations; no medication required',
 'Return for scheduled immunization review.', '2026-10-19', 'Completed',
 '110/70', 36.6, 76, 62.00, NULL, NOW(), NOW()),
(1001, 1003, 101, 1, '2026-09-08', '09:30:00', 'Fever and cough',
 'Acute upper respiratory tract infection (viral)',
 'Paracetamol as needed; increased fluid intake; rest',
 'Reassess if fever persists beyond 5 days.', '2026-10-14', 'Completed',
 '120/80', 37.8, 84, 58.00, 1.60, NOW(), NOW()),
(1002, 1008, 104, 2, '2026-09-15', '11:00:00', 'Persistent cough with phlegm',
 'Acute bronchitis',
 'Salbutamol inhaler 2 puffs PRN; mucolytic syrup 7 days',
 'Stop smoking advice reinforced.', '2026-11-05', 'Completed',
 '118/76', 37.2, 88, 74.00, 1.72, NOW(), NOW()),
(1003, 1012, 107, 2, '2026-08-28', '09:00:00', 'High blood pressure reading at home',
 'Hypertension Stage I',
 'Amlodipine 5 mg once daily; low-sodium diet',
 'Home BP monitoring twice daily.', '2026-11-12', 'Completed',
 '145/92', 36.8, 80, 68.00, 1.55, NOW(), NOW()),
(1004, 1015, 109, 1, '2026-09-20', '14:00:00', 'Itchy rash on forearms',
 'Contact dermatitis',
 'Topical corticosteroid cream for 7 days; avoid suspected irritants',
 'Use mild soap and moisturizer.', '2026-10-25', 'Completed',
 '110/72', 36.9, 72, 55.00, 1.63, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  AppointmentID = VALUES(`AppointmentID`), PatientID = VALUES(`PatientID`),
  StaffID = VALUES(`StaffID`), ConsultationDate = VALUES(`ConsultationDate`),
  ConsultationTime = VALUES(`ConsultationTime`),
  ChiefComplaint = VALUES(`ChiefComplaint`), Diagnosis = VALUES(`Diagnosis`),
  Treatment = VALUES(`Treatment`), Notes = VALUES(`Notes`),
  FollowUpDate = VALUES(`FollowUpDate`), Status = VALUES(`Status`),
  BloodPressure = VALUES(`BloodPressure`), Temperature = VALUES(`Temperature`),
  PulseRate = VALUES(`PulseRate`), Weight = VALUES(`Weight`),
  Height = VALUES(`Height`);

-- =====================================================================
-- 5) notifications -- Sample in-app alerts
--    Covers confirmations, reminders, reschedules and the priority
--    disruption alert for Jedrick Versoza.
-- =====================================================================
INSERT INTO `notifications`
  (`NotificationID`, `UserID`, `Title`, `Message`, `Type`, `RelatedID`,
   `RelatedTable`, `IsRead`, `PriorityLevel`, `SentAt`, `ReadAt`,
   `CreatedAt`)
VALUES
(1000, 100, 'Urgent: Appointment cancellation',
 'Your Curora appointment for Pediatric follow-up consultation on Oct 3, 2026 at 9:30 AM was cancelled because the doctor is on emergency leave. A priority reschedule link has been sent to your registered email so you can choose a new schedule right away.',
 'Schedule Disruption', 1000, 'appointments', b'0', 'High',
 '2026-10-01 07:46:00', NULL, NOW()),
(1001, 100, 'Appointment confirmed',
 'Your well-child checkup on Sep 12, 2026 at 10:00 AM has been confirmed.',
 'Appointment Confirmation', 1001, 'appointments', b'1', 'Medium',
 '2026-09-02 08:00:00', '2026-09-02 19:20:00', NOW()),
(1002, 101, 'Appointment pending confirmation',
 'Your general consultation on Oct 14, 2026 at 9:00 AM is pending confirmation.',
 'Appointment Confirmation', 1002, 'appointments', b'0', 'Low',
 '2026-10-01 09:10:00', NULL, NOW()),
(1003, 102, 'Appointment confirmed',
 'Your pulmonology follow-up on Oct 16, 2026 at 1:30 PM has been confirmed.',
 'Appointment Confirmation', 1004, 'appointments', b'0', 'Medium',
 '2026-10-01 10:00:00', NULL, NOW()),
(1004, 104, 'Appointment reminder',
 'Reminder: lung function follow-up on Nov 5, 2026 at 10:30 AM.',
 'Appointment Reminder', 1009, 'appointments', b'0', 'Low',
 '2026-10-01 08:30:00', NULL, NOW()),
(1005, 107, 'Appointment pending confirmation',
 'Your hypertension follow-up on Nov 12, 2026 at 9:30 AM is pending confirmation.',
 'Appointment Confirmation', 1013, 'appointments', b'0', 'Low',
 '2026-10-01 09:00:00', NULL, NOW()),
(1006, 109, 'Appointment rescheduled',
 'Your general consultation was moved to Oct 25, 2026 at 10:45 AM.',
 'Appointment Reschedule', 1016, 'appointments', b'0', 'Medium',
 '2026-10-01 11:20:00', NULL, NOW())
ON DUPLICATE KEY UPDATE
  UserID = VALUES(`UserID`), Title = VALUES(`Title`), Message = VALUES(`Message`),
  Type = VALUES(`Type`), RelatedID = VALUES(`RelatedID`),
  RelatedTable = VALUES(`RelatedTable`), IsRead = VALUES(`IsRead`),
  PriorityLevel = VALUES(`PriorityLevel`), SentAt = VALUES(`SentAt`),
  ReadAt = VALUES(`ReadAt`);

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Verification queries (optional)
-- =====================================================================
-- Patients seeded:
--   SELECT UserID, Email FROM users WHERE RoleID = 3 AND UserID BETWEEN 100 AND 109 ORDER BY UserID;
-- Appointment-state coverage:
--   SELECT Status, COUNT(*) FROM appointments WHERE AppointmentID BETWEEN 1000 AND 1016 GROUP BY Status;
-- Emergency disruption test case:
--   SELECT a.AppointmentID, a.Status, a.disruption_reason, a.is_emergency_disruption,
--          a.reschedule_token IS NOT NULL AS has_token
--     FROM appointments a WHERE a.AppointmentID = 1000;