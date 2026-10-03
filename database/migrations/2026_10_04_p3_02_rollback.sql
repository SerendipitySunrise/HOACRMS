-- ============================================================================
-- PHASE 3 / MIGRATION 02 ROLLBACK - revert the blood-pressure split
-- ============================================================================
-- Drops the triggers and the three added columns. The original BloodPressure
-- string column is left exactly as it was, so all 42 PHP references keep
-- working both before and after.
--
-- ORDER MATTERS: run the trigger drops first. If you drop the columns while the
-- triggers still exist, the triggers reference missing columns and the next
-- INSERT or UPDATE on vitals fails.
--
-- LOSSY IN ONE DIRECTION: if any row was written using Systolic/Diastolic
-- rather than the BloodPressure string, removing Systolic/Diastolic does not
-- lose the reading, because the trigger kept BloodPressure in sync. No
-- measurement is lost by this rollback.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Verify no row would lose data. Must return 0.
-- A row with Systolic/Diastolic set but no BloodPressure means the string
-- column and the split columns have diverged, which the trigger should make
-- impossible.
-- ---------------------------------------------------------------------------
SELECT 'PRE-CHECK rows with split values but no BP string (expect 0)' AS verification,
       COUNT(*) actual
FROM vitals
WHERE (Systolic IS NOT NULL OR Diastolic IS NOT NULL)
  AND (BloodPressure IS NULL OR BloodPressure = '');


-- ---------------------------------------------------------------------------
-- STEP R2.1 - Remove the triggers.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_vitals_bp_before_insert;
DROP TRIGGER IF EXISTS trg_vitals_bp_before_update;


-- ---------------------------------------------------------------------------
-- STEP R2.2 - Remove the added columns.
-- ---------------------------------------------------------------------------
ALTER TABLE vitals
  DROP COLUMN Systolic,
  DROP COLUMN Diastolic,
  DROP COLUMN Source;


-- ---------------------------------------------------------------------------
-- VERIFY
-- ---------------------------------------------------------------------------

-- V1: must be 118 - no rows lost.
SELECT 'V1 vitals row count (expect 118)' AS verification, COUNT(*) actual FROM vitals;

-- V2: must be 0 - no triggers remain.
SELECT 'V2 vitals triggers remaining (expect 0)' AS verification, COUNT(*) actual
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = 'hoacrms' AND EVENT_OBJECT_TABLE = 'vitals';

-- V3: BloodPressure still intact for all rows.
SELECT 'V3 BloodPressure still populated (expect 118)' AS verification,
       COUNT(*) total,
       SUM(BloodPressure IS NOT NULL AND BloodPressure <> '') populated
FROM vitals;

-- V4: the column list is back to the original 10 columns.
SELECT 'V4 vitals columns (expect 10)' AS verification, COUNT(*) actual
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'hoacrms' AND TABLE_NAME = 'vitals';