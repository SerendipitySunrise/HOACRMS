-- ============================================================================
-- PHASE 3 / MIGRATION 02 - vitals: add Source, split BloodPressure
-- ============================================================================
-- WHAT:
--   1. vitals.Source      enum('Triage','Consultation') - which role took the
--                         reading. Defaults to 'Triage' so existing rows and
--                         all current PHP writes are unaffected.
--   2. vitals.Systolic    smallint unsigned - the real systolic value.
--   3. vitals.Diastolic   smallint unsigned - the real diastolic value.
--   4. Two triggers that keep BloodPressure and Systolic/Diastolic in sync
--      in BOTH directions, so all 42 existing PHP references to
--      vitals.BloodPressure keep working unchanged.
--
-- WHY NOT A GENERATED COLUMN:
--   Tested on this server (MySQL 8.0.43): MySQL refuses explicit writes to BOTH
--   VIRTUAL and STORED generated columns - ERROR 3105 "The value specified for
--   generated column is not allowed". A generated Systolic cannot be
--   backfilled by UPDATE, and the existing PHP writes BloodPressure as a string
--   so it would have to become the generated side, which cannot be written
--   either. Triggers are the only bidirectional option.
--
-- DATA: all 118 vitals.BloodPressure values are well-formed NNN/NNN (91-152
--       systolic, 58-95 diastolic). Zero malformed, zero empty. The
--       consultations table has one empty-string BP (ConsultationID 8) but that
--       column is NOT touched by this migration.
--
-- TRIGGER BEHAVIOUR (whichever side the caller supplies wins):
--   INSERT: Systolic/Diastolic given -> BloodPressure rebuilt from them.
--           otherwise BloodPressure parsed into them.
--   UPDATE: if Systolic or Diastolic changed -> BloodPressure rebuilt.
--           else if BloodPressure changed -> parsed into Systolic/Diastolic.
--           else nothing.
--
-- NOTE ON TRANSACTIONS: ALTER TABLE and CREATE TRIGGER are DDL and cause an
--   implicit COMMIT. This file is not transactionally rollback-able; the
--   rollback script is a forward-compensating script.
-- ============================================================================

SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');


-- ---------------------------------------------------------------------------
-- STEP 2.1 - Add the three columns. Purely additive: every new column is
-- nullable or has a default, so no row is affected by this ALTER.
-- ---------------------------------------------------------------------------
ALTER TABLE vitals
  ADD COLUMN Systolic  smallint unsigned NULL AFTER BloodPressure,
  ADD COLUMN Diastolic smallint unsigned NULL AFTER Systolic,
  ADD COLUMN Source    enum('Triage','Consultation') NOT NULL DEFAULT 'Triage' AFTER StaffID;


-- ---------------------------------------------------------------------------
-- STEP 2.2 - Backfill Systolic/Diastolic from the existing BloodPressure string.
-- The REGEXP guard means an unparseable value is left NULL rather than
-- silently becoming 0. Preflight confirmed 0 such rows.
-- ---------------------------------------------------------------------------
UPDATE vitals
SET Systolic  = CAST(SUBSTRING_INDEX(BloodPressure, '/',  1) AS UNSIGNED),
    Diastolic = CAST(SUBSTRING_INDEX(BloodPressure, '/', -1) AS UNSIGNED)
WHERE BloodPressure IS NOT NULL
  AND BloodPressure <> ''
  AND BloodPressure REGEXP '^[0-9]{1,3}/[0-9]{1,3}$';


-- ---------------------------------------------------------------------------
-- STEP 2.3 - Compatibility triggers.
-- Written so that today's PHP (which sets BloodPressure = '120/80') and any
-- future PHP (which sets Systolic/Diastolic) both produce a correct row.
-- ---------------------------------------------------------------------------
DELIMITER $$

DROP TRIGGER IF EXISTS trg_vitals_bp_before_insert$$
CREATE TRIGGER trg_vitals_bp_before_insert
BEFORE INSERT ON vitals
FOR EACH ROW
BEGIN
  IF NEW.Systolic IS NULL AND NEW.Diastolic IS NULL
     AND NEW.BloodPressure IS NOT NULL
     AND NEW.BloodPressure <> ''
     AND NEW.BloodPressure REGEXP '^[0-9]{1,3}/[0-9]{1,3}$'
  THEN
    SET NEW.Systolic  = CAST(SUBSTRING_INDEX(NEW.BloodPressure, '/',  1) AS UNSIGNED),
        NEW.Diastolic = CAST(SUBSTRING_INDEX(NEW.BloodPressure, '/', -1) AS UNSIGNED);
  ELSEIF NEW.Systolic IS NOT NULL AND NEW.Diastolic IS NOT NULL THEN
    SET NEW.BloodPressure = CONCAT(NEW.Systolic, '/', NEW.Diastolic);
  END IF;
END$$

DROP TRIGGER IF EXISTS trg_vitals_bp_before_update$$
CREATE TRIGGER trg_vitals_bp_before_update
BEFORE UPDATE ON vitals
FOR EACH ROW
BEGIN
  IF NOT (NEW.Systolic  <=> OLD.Systolic)
     OR NOT (NEW.Diastolic <=> OLD.Diastolic)
  THEN
    IF NEW.Systolic IS NOT NULL AND NEW.Diastolic IS NOT NULL THEN
      SET NEW.BloodPressure = CONCAT(NEW.Systolic, '/', NEW.Diastolic);
    ELSE
      SET NEW.BloodPressure = NULL;
    END IF;
  ELSEIF NOT (NEW.BloodPressure <=> OLD.BloodPressure) THEN
    IF NEW.BloodPressure IS NOT NULL
       AND NEW.BloodPressure <> ''
       AND NEW.BloodPressure REGEXP '^[0-9]{1,3}/[0-9]{1,3}$'
    THEN
      SET NEW.Systolic  = CAST(SUBSTRING_INDEX(NEW.BloodPressure, '/',  1) AS UNSIGNED),
          NEW.Diastolic = CAST(SUBSTRING_INDEX(NEW.BloodPressure, '/', -1) AS UNSIGNED);
    ELSE
      SET NEW.Systolic  = NULL,
          NEW.Diastolic = NULL;
    END IF;
  END IF;
END$$

DELIMITER ;


-- ---------------------------------------------------------------------------
-- STEP 2.4 - VERIFY
-- ---------------------------------------------------------------------------

-- V1: must be 118 of 118 rows populated.
SELECT 'V1 vitals with Systolic/Diastolic backfilled (expect 118 of 118)' AS verification,
       COUNT(*) total,
       SUM(Systolic IS NOT NULL AND Diastolic IS NOT NULL) populated
FROM vitals;

-- V2: must be 0. Proves every parsed value round-trips back to its original string.
SELECT 'V2 round-trip mismatches (expect 0)' AS verification, COUNT(*) actual
FROM vitals
WHERE Systolic IS NOT NULL
  AND BloodPressure <> CONCAT(Systolic, '/', Diastolic);

-- V3: must be 118 rows all Source='Triage'.
SELECT 'V3 Source distribution' AS verification, Source, COUNT(*) n FROM vitals GROUP BY Source;

-- V4: sanity ranges.
SELECT 'V4 systolic/diastolic ranges' AS verification,
       MIN(Systolic) min_sys, MAX(Systolic) max_sys,
       MIN(Diastolic) min_dia, MAX(Diastolic) max_dia FROM vitals;

-- V5: must be 118 - no data lost.
SELECT 'V5 vitals row count (expect 118)' AS verification, COUNT(*) actual FROM vitals;

-- V6: both triggers present.
SELECT 'V6 vitals triggers' AS verification, TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = 'hoacrms' AND EVENT_OBJECT_TABLE = 'vitals';


-- ---------------------------------------------------------------------------
-- STEP 2.5 - LIVE BEHAVIOUR TEST (wrapped so it always cleans up)
-- Proves the triggers work in both directions, then removes the test row.
-- ---------------------------------------------------------------------------
START TRANSACTION;

-- Test A: old-style write (string only) must fill Systolic/Diastolic.
INSERT INTO vitals (AppointmentID, PatientID, StaffID, BloodPressure, Temperature, PulseRate, Weight, Height)
SELECT a.AppointmentID, a.PatientID, s.StaffID, '118/74', 36.6, 70, 70.10, 1.72
FROM appointments a CROSS JOIN staff s WHERE a.AppointmentID = 1 LIMIT 1;

-- Test B: new-style write (columns only) must build BloodPressure.
INSERT INTO vitals (AppointmentID, PatientID, StaffID, Systolic, Diastolic, Temperature, Weight, Height)
SELECT a.AppointmentID, a.PatientID, s.StaffID, 132, 86, 36.9, 70.10, 1.72
FROM appointments a CROSS JOIN staff s WHERE a.AppointmentID = 1 LIMIT 1;

SELECT 'TEST RESULTS (A must be 118/74, B must be 132/86)' AS verification;
SELECT VitalID, BloodPressure, Systolic, Diastolic, Source
FROM vitals WHERE AppointmentID = 1 AND RecordedAt >= NOW() - INTERVAL 1 MINUTE
ORDER BY VitalID;

-- Test C: update the columns only -> BloodPressure must follow.
UPDATE vitals SET Systolic = 140, Diastolic = 90
WHERE VitalID = (SELECT MAX(VitalID) FROM (SELECT VitalID FROM vitals) z);
SELECT 'TEST C - expect 140/90' AS verification;
SELECT VitalID, BloodPressure, Systolic, Diastolic FROM vitals ORDER BY VitalID DESC LIMIT 1;

ROLLBACK;  -- discards the test rows; vitals is back to 118 rows.

SELECT 'POST-TEST vitals row count (expect 118)' AS verification, COUNT(*) actual FROM vitals;