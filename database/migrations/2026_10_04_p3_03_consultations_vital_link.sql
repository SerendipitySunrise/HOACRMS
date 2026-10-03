-- ============================================================================
-- PHASE 3 / MIGRATION 03 - link consultations to the vitals they used
-- ============================================================================
-- WHAT:
--   1. consultations.VitalID  nullable FK to vitals(VitalID).
--   2. Two new vitals rows created from the two ambiguous consultations
--      (IDs 7 and 4503), tagged Source='Consultation'.
--   3. Backfills VitalID for every consultation that has a vitals row recorded
--      by the same staff member.
--   4. Adds the foreign key.
--
-- WHY: vitals is now the single owner of measurements. consultations keeps its
--      own BloodPressure/Temperature/PulseRate/Weight/Height columns untouched
--      for now; Phase 4 repoints the PHP reads at the linked vitals row. Until
--      then both copies exist and nothing changes for the user.
--
-- WHY THE TWO NEW ROWS:
--   Six rows had vitals and consultation values that disagreed. Four were
--   classified during Phase 1 as typos or gaps and were left alone (consultation
--   side NULL or partial - keep the vitals reading). These two were genuinely
--   ambiguous, and the decision was to preserve the consultation values as a
--   second vitals row rather than discard them.
--
--   Appt 38 / ConsultationID 4503 - Staff 4 measured 120/80, 36.8, 72, 68.50kg,
--     1.64m at triage. Staff 8 recorded 119/80, 36.8, 83, 68.47kg, 1.65m.
--     Temperature matches to 0.1C and diastolic to 1mmHg while pulse jumps 11.
--     Assessed as a MISTYPED DIGIT, not a re-measurement. The row is still
--     inserted so nothing is lost, but see STEP 3.2b - you may want to delete
--     just that one afterwards.
--
--   Appt 59 / ConsultationID 7 - Staff 4 measured 120/80, 36.8, 73, 65.00kg,
--     1.68m. Staff 8 recorded 120/80, 36.8, NULL, 68.00kg, 1.70m.
--     A 3.00kg and 2cm difference both exceed scale and stadiometer error by a
--     wide margin and are consistent with each other. Assessed as a GENUINE
--     RE-MEASUREMENT. Keep this row.
--
-- RecordedAt is built from each consultation's own ConsultationDate +
-- ConsultationTime, not from NOW(), so the Vitals History panel orders them
-- sensibly. Note both new rows therefore sort BEFORE their triage row: the
-- seed data has the triage vitals timestamped hours after the consultation.
-- That inconsistency predates this migration and is not corrected here.
--
-- BACKFILL DETERMINISM: preflight confirmed 0 consultations have more than one
-- vitals row from the consulting staff member. The correlated subquery below
-- returns a single value regardless, so the UPDATE can never hit the
-- "target row updated more than once" error.
--
-- NOTE ON TRANSACTIONS: ALTER TABLE causes an implicit COMMIT. The INSERT and
-- UPDATE steps are DML and are transactional, but the ALTER around them is not.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- STEP 3.1 - Add the nullable link column.
-- ---------------------------------------------------------------------------
ALTER TABLE consultations
  ADD COLUMN VitalID int NULL AFTER StaffID,
  ADD KEY idx_consultations_vital (VitalID);


-- ---------------------------------------------------------------------------
-- STEP 3.2 - Create the two Source='Consultation' vitals rows.
--
-- The BEFORE INSERT trigger from migration 02 populates Systolic/Diastolic
-- from the BloodPressure string automatically.
-- ---------------------------------------------------------------------------
INSERT INTO vitals
  (AppointmentID, PatientID, StaffID, BloodPressure, Temperature, PulseRate,
   Weight, Height, RecordedAt, Source)
SELECT
  c.AppointmentID, c.PatientID, c.StaffID, c.BloodPressure, c.Temperature,
  c.PulseRate, c.Weight, c.Height,
  TIMESTAMP(CONCAT(c.ConsultationDate, ' ', c.ConsultationTime)),
  'Consultation'
FROM consultations c
WHERE c.ConsultationID IN (7, 4503)
  AND c.BloodPressure IS NOT NULL AND c.BloodPressure <> ''
  AND NOT EXISTS (
    SELECT 1 FROM (SELECT AppointmentID, StaffID, Source FROM vitals) v
    WHERE v.Source = 'Consultation'
      AND v.AppointmentID = c.AppointmentID
      AND v.StaffID = c.StaffID
  );

-- Expect: 2 row(s) affected.


-- ---------------------------------------------------------------------------
-- STEP 3.2b - OPTIONAL cleanup, ONLY if you agree with the 4503 assessment.
--
-- The 4503 vitals row is a suspected typo (pulse 83 vs the triage 72, with
-- every other field matching to within rounding). Comment this out to keep the
-- row. Run the SELECT first and read the values.
--
-- SELECT v.VitalID, v.BloodPressure, v.Temperature, v.PulseRate, v.Weight, v.Height, v.RecordedAt
-- FROM vitals v JOIN consultations c ON c.VitalID = v.VitalID
-- WHERE c.ConsultationID = 4503;
--
-- DELETE v FROM vitals v JOIN consultations c ON c.VitalID = v.VitalID
-- WHERE c.ConsultationID = 4503 AND v.Source = 'Consultation';
--
-- Then re-point the consultation at the triage row:
-- UPDATE consultations c
-- SET c.VitalID = (SELECT MAX(v.VitalID) FROM vitals v
--                   WHERE v.AppointmentID = c.AppointmentID AND v.StaffID = c.StaffID)
-- WHERE c.ConsultationID = 4503;


-- ---------------------------------------------------------------------------
-- STEP 3.3 - Backfill VitalID for every consultation whose consulting staff
-- member recorded a vitals row for that appointment.
--
-- NULLABLE ON PURPOSE: 39 consultations have no vitals row from the consulting
-- staff member. Those doctors wrote only into the consultation columns, so
-- there is no vitals row to point at. Their VitalID stays NULL and Phase 4
-- keeps reading their consultation columns.
-- ---------------------------------------------------------------------------
UPDATE consultations c
SET c.VitalID = (
  SELECT MAX(v.VitalID)
  FROM vitals v
  WHERE v.AppointmentID = c.AppointmentID
    AND v.StaffID       = c.StaffID
)
WHERE c.VitalID IS NULL
  AND EXISTS (
    SELECT 1 FROM vitals v
    WHERE v.AppointmentID = c.AppointmentID
      AND v.StaffID       = c.StaffID
  );

-- Expect: 68 row(s) affected (67 pre-existing matches + consultation 4503 and 7
-- now have their own new Source='Consultation' rows, so 68 total).


-- ---------------------------------------------------------------------------
-- STEP 3.4 - Add the foreign key. Added last, after every VitalID is valid.
-- ---------------------------------------------------------------------------
ALTER TABLE consultations
  ADD CONSTRAINT fk_consultations_vital
  FOREIGN KEY (VitalID) REFERENCES vitals (VitalID)
  ON DELETE SET NULL ON UPDATE CASCADE;


-- ---------------------------------------------------------------------------
-- STEP 3.5 - VERIFY
-- ---------------------------------------------------------------------------

-- V1: must be 68 linked, 39 NULL, 107 total.
SELECT 'V1 VitalID backfill' AS verification,
       COUNT(*) total,
       SUM(VitalID IS NOT NULL) linked,
       SUM(VitalID IS NULL)     unlinked
FROM consultations;

-- V2: must be 120 (118 + the 2 new rows).
SELECT 'V2 vitals row count (expect 120)' AS verification, COUNT(*) actual FROM vitals;
SELECT 'V2b Source distribution (expect 118 Triage, 2 Consultation)' AS verification,
       Source, COUNT(*) n FROM vitals GROUP BY Source;

-- V3: the two new rows, with values.
SELECT 'V3 the two consultation-sourced vitals' AS verification;
SELECT c.ConsultationID, c.AppointmentID, c.VitalID, c.BloodPressure AS consult_bp,
       v.VitalID, v.BloodPressure AS vitals_bp, v.Systolic, v.Diastolic,
       v.Temperature, v.PulseRate, v.Weight, v.Height, v.RecordedAt, v.Source
FROM consultations c LEFT JOIN vitals v ON v.VitalID = c.VitalID
WHERE c.ConsultationID IN (7, 4503);

-- V4: must be 0 - every VitalID points at a vitals row recorded by the SAME
-- staff member as the consultation.
SELECT 'V4 mismatched VitalID links (expect 0)' AS verification, COUNT(*) actual
FROM consultations c JOIN vitals v ON v.VitalID = c.VitalID
WHERE v.StaffID <> c.StaffID OR v.AppointmentID <> c.AppointmentID;

-- V5: must be 0 - FK is enforced.
SELECT 'V5 FK present on consultations (expect 1)' AS verification, COUNT(*) actual
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = 'hoacrms' AND CONSTRAINT_NAME = 'fk_consultations_vital';

-- V6: the 39 unlinked consultations are the older doctor-only records.
SELECT 'V6 unlinked consultations by Status (expect all Completed/Ongoing)' AS verification;
SELECT c.Status, COUNT(*) n FROM consultations c WHERE c.VitalID IS NULL GROUP BY c.Status;