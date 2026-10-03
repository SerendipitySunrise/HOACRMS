-- ============================================================================
-- TEST-DATA CLEANUP (separate from the 8 Phase 3 schema migrations)
-- ============================================================================
-- WHAT: Remove 8 duplicate vitals rows from the seed/teaching data set.
--       These are exact-value repeats of the same measurement taken minutes
--       apart by the same nurse — a clear fingerprint of a form posting the
--       same reading 7 times in 35 minutes.
--
-- KEEP: one row per appointment with the earliest RecordedAt.
--   appt 37:    keep VitalID 4   (2026-09-05 16:36:27), delete 10
--   appt 2046:  keep VitalID 4049 (2026-10-03 00:44:43), delete 4050..4055
--
-- PRE-FLIGHT (manually verified 2026-10-04):
--   - None of the 9 suspect VitalIDs appear in consultations.VitalID.
--   - No notification, no_show, or queue row FK-references them via VitalID.
--   - The two appointment rows are still present; deleting duplicate vitals
--     rows does not affect them.
--   - All 9 rows have identical values per appointment and same StaffID (4),
--     confirming they are duplicate submissions, not separate measurements.
--
-- NOT A SCHEMA CHANGE — DML only.
-- ============================================================================

-- Identify what we're deleting (run this SELECT first, confirm against backup).
SELECT VitalID, AppointmentID, PatientID, StaffID, BloodPressure, Temperature,
       PulseRate, Weight, Height, RecordedAt, Source
FROM vitals
WHERE VitalID IN (10, 4050, 4051, 4052, 4053, 4054, 4055)
ORDER BY AppointmentID, RecordedAt;

-- Delete the duplicates.
DELETE FROM vitals
WHERE VitalID IN (10, 4050, 4051, 4052, 4053, 4054, 4055);

-- Expect: 8 row(s) affected.

-- VERIFY
SELECT 'V1 vitals total (expect 112 = 120 - 8)' AS verification, COUNT(*) actual FROM vitals;
SELECT 'V2 appt 2046 vitals remain (expect 1: VitalID 4049)' AS verification, COUNT(*) actual FROM vitals WHERE AppointmentID = 2046;
SELECT 'V3 appt 37 vitals remain (expect 1: VitalID 4)' AS verification, COUNT(*) actual FROM vitals WHERE AppointmentID = 37;
SELECT 'V4 containers still have their appointment (expect 2: 37, 2046)' AS verification, COUNT(DISTINCT AppointmentID) actual FROM vitals WHERE AppointmentID IN (37, 2046);