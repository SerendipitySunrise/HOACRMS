-- ============================================================================
-- PHASE 3 / MIGRATION 01 - Quarantine table for unusable appointments
-- ============================================================================
-- WHAT:  Creates appointments_quarantine (structural clone of appointments)
--        plus two audit columns, then COPIES the 3 zero-date rows into it.
--
-- WHY:   Appointments 4, 5 and 6 have AppointmentDate = '0000-00-00'. MySQL 8
--        runs with NO_ZERO_DATE in strict mode, so ANY future UPDATE to those
--        rows fails. They also have StaffID NULL, so they are excluded from the
--        new slot-unique key, but the strict-mode failure is enough on its own.
--
--        The dates are unrecoverable: they came from a hand-written INSERT in
--        database/hoacrms.sql lines 83-85 that predates the booking validation.
--        api/appointments/save_appointment.php now rejects empty, malformed and
--        past dates, so no new zero dates can be created.
--
-- SAFE:  This migration DELETES NOTHING. The 3 rows stay in appointments.
--        You review appointments_quarantine, then run the separate DELETE
--        script (2026_10_04_p3_01b_delete_quarantined.sql) only if you agree.
--
-- NOTE ON TRANSACTIONS: MySQL DDL (CREATE/ALTER) causes an implicit COMMIT, so
--        this file cannot be wrapped in a real transaction. The rollback script
--        is a forward-compensating DROP, not a ROLLBACK.
-- ============================================================================

-- NO_ZERO_DATE must be relaxed to even SELECT and INSERT a zero date.
SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');


-- ---------------------------------------------------------------------------
-- STEP 1.1 - Create the quarantine table.
-- CREATE TABLE ... LIKE copies column definitions and indexes but NOT
-- foreign keys, so the quarantine table is intentionally unconstrained.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS appointments_quarantine LIKE appointments;

ALTER TABLE appointments_quarantine
  ADD COLUMN QuarantineReason varchar(255) NOT NULL DEFAULT '',
  ADD COLUMN QuarantinedAt    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- Proof that no FK was inherited.
-- Expect fk_count = 0.


-- ---------------------------------------------------------------------------
-- STEP 1.2 - Copy the zero-date rows in. NOT a delete.
-- ---------------------------------------------------------------------------
INSERT INTO appointments_quarantine (
  AppointmentID, PatientID, StaffID, DepartmentID, AppointmentDate,
  AppointmentTime, Purpose, Status, CreatedAt, UpdatedAt,
  OriginalAppointmentDate, OriginalAppointmentTime, RescheduledAt,
  RescheduleReason, disruption_reason, is_emergency_disruption,
  reschedule_token, QuarantineReason
)
SELECT
  a.AppointmentID, a.PatientID, a.StaffID, a.DepartmentID, a.AppointmentDate,
  a.AppointmentTime, a.Purpose, a.Status, a.CreatedAt, a.UpdatedAt,
  a.OriginalAppointmentDate, a.OriginalAppointmentTime, a.RescheduledAt,
  a.RescheduleReason, a.disruption_reason, a.is_emergency_disruption,
  a.reschedule_token,
  'Zero appointment date. Origin: hand-written INSERT in database/hoacrms.sql lines 83-85.'
FROM appointments a
WHERE a.AppointmentDate = '0000-00-00';


-- ---------------------------------------------------------------------------
-- STEP 1.3 - VERIFY
-- ---------------------------------------------------------------------------

-- V1: must be exactly 3 rows, IDs 4, 5, 6.
SELECT 'V1 quarantined row count (expect 3)' AS verification, COUNT(*) actual FROM appointments_quarantine;
SELECT AppointmentID, PatientID, DepartmentID, StaffID, AppointmentDate,
       AppointmentTime, Status, QuarantineReason, QuarantinedAt
FROM appointments_quarantine ORDER BY AppointmentID;

-- V2: must be 3 - appointments is untouched.
SELECT 'V2 appointments still holds them (expect 200)' AS verification, COUNT(*) actual FROM appointments;

-- V3: must be 0 - the quarantine table inherited no foreign keys.
SELECT 'V3 quarantine FK count (expect 0)' AS verification, COUNT(*) actual
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'hoacrms' AND TABLE_NAME = 'appointments_quarantine'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- V4: must be 0 - a fresh copy can be diffed against the source later.
SELECT 'V4 quarantine rows not matching source (expect 0)' AS verification, COUNT(*) actual
FROM appointments_quarantine q
LEFT JOIN appointments a
  ON a.AppointmentID = q.AppointmentID
 AND a.AppointmentDate = q.AppointmentDate
 AND a.AppointmentTime = q.AppointmentTime
 AND a.PatientID     = q.PatientID
 AND a.DepartmentID  = q.DepartmentID
 AND a.Status        = q.Status
WHERE a.AppointmentID IS NULL;