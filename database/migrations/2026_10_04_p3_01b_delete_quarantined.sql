-- ============================================================================
-- PHASE 3 / MIGRATION 01b - DELETE the quarantined zero-date appointments
-- ============================================================================
--   *** DO NOT RUN THIS UNTIL YOU HAVE REVIEWED 01 AND TAKEN A BACKUP. ***
--
-- HOW TO REVIEW FIRST:
--   1. Run the backup command (see the Phase 3 header).
--   2. Run 01 and check its V1 output shows exactly IDs 4, 5, 6.
--   3. Read the rows: they are unrecoverable junk. Patient 1 (Jedrick Versoza,
--      the seed account) has 41 appointments; these three have a date of
--      0000-00-00, a NULL StaffID, and are referenced by NOTHING:
--        queue 0 | consultations 0 | vitals 0 | no_shows 0
--        appointment_reminders 0 | reschedule_history 0 | notifications 0
--
-- WHY DELETE RATHER THAN REPAIR THE DATE:
--   A date was never chosen for these rows. Any date you invent would be
--   fiction in a clinical record. Cancelling them keeps them visible in history;
--   deleting them removes rows that cannot describe a real event. Because all
--   three are unreferenced and two are still Pending, deletion is the honest
--   option. REVERSIBLE: appointments_quarantine holds a byte-identical copy,
--   so re-inserting is a single INSERT ... SELECT.
--
-- WHY NOT SET Status='Cancelled' INSTEAD:
--   That was the alternative for referenced rows. It does not solve the real
--   problem: AppointmentDate is `date NOT NULL`, so it cannot be set to NULL,
--   and leaving 0000-00-00 in place means any future UPDATE to the row still
--   fails under NO_ZERO_DATE. The status change papers over the symptom.
-- ============================================================================

SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');

-- Safety interlock: abort unless the quarantine holds exactly IDs 4, 5, 6.
-- If this SELECT returns anything but 'READY', do not continue.
SELECT CASE
         WHEN COUNT(*) = 3
          AND SUM(AppointmentID IN (4,5,6)) = 3
         THEN 'READY'
         ELSE 'ABORT - quarantine does not match the expected 3 rows'
       END AS interlock,
       COUNT(*) AS rows_in_quarantine
FROM appointments_quarantine
WHERE AppointmentDate = '0000-00-00';

-- ---------------------------------------------------------------------------
-- The DELETE. Guarded so it can only ever remove rows that are BOTH in the
-- quarantine AND still zero-dated.
-- ---------------------------------------------------------------------------
DELETE a
FROM appointments a
JOIN appointments_quarantine q
  ON q.AppointmentID = a.AppointmentID
WHERE a.AppointmentDate = '0000-00-00';

-- Expect: 3 row(s) affected.


-- ---------------------------------------------------------------------------
-- VERIFY
-- ---------------------------------------------------------------------------

-- V1: must be 0 - no zero dates remain.
SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');
SELECT 'V1 zero-dated appointments remaining (expect 0)' AS verification, COUNT(*) actual
FROM appointments WHERE AppointmentDate = '0000-00-00';

-- V2: must be 197 (was 200).
SELECT 'V2 appointments row count (expect 197)' AS verification, COUNT(*) actual FROM appointments;

-- V3: must be 3 - the quarantined copies still exist and are the restore source.
SELECT 'V3 quarantine intact for restore (expect 3)' AS verification, COUNT(*) actual
FROM appointments_quarantine;

-- V4: must be 0 - nothing else was touched.
SELECT 'V4 other appointments unchanged (expect 197)' AS verification, COUNT(*) actual
FROM appointments WHERE AppointmentDate <> '0000-00-00' OR AppointmentDate IS NULL;

-- ---------------------------------------------------------------------------
-- RESTORE (only if you need the 3 rows back)
-- ---------------------------------------------------------------------------
-- INSERT INTO appointments
--   (AppointmentID, PatientID, StaffID, DepartmentID, AppointmentDate,
--    AppointmentTime, Purpose, Status, CreatedAt, UpdatedAt,
--    OriginalAppointmentDate, OriginalAppointmentTime, RescheduledAt,
--    RescheduleReason, disruption_reason, is_emergency_disruption, reschedule_token)
-- SELECT AppointmentID, PatientID, StaffID, DepartmentID, AppointmentDate,
--        AppointmentTime, Purpose, Status, CreatedAt, UpdatedAt,
--        OriginalAppointmentDate, OriginalAppointmentTime, RescheduledAt,
--        RescheduleReason, disruption_reason, is_emergency_disruption, reschedule_token
-- FROM appointments_quarantine;
--
-- AUTO_INCREMENT is currently 3048, above every quarantined ID, so re-inserting
-- explicit IDs will not collide.