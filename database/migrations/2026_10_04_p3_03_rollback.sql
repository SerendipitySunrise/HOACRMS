-- ============================================================================
-- PHASE 3 / MIGRATION 03 ROLLBACK - unlink consultations from vitals
-- ============================================================================
-- Removes the FK, then the VitalID column, then the two rows created by
-- migration 03 (identifiable by Source='Consultation'). The consultation
-- columns themselves are never touched, so nothing the doctor typed is lost.
--
-- Run AFTER verifying you still have the mysqldump taken before Phase 3.
-- ============================================================================

-- STEP R3.1 - Drop the FK.
ALTER TABLE consultations DROP FOREIGN KEY fk_consultations_vital;

-- STEP R3.2 - Drop the column.
ALTER TABLE consultations
  DROP INDEX idx_consultations_vital,
  DROP COLUMN VitalID;

-- STEP R3.3 - Remove the two rows added by migration 03.
-- They are the only rows with Source='Consultation', which exists only
-- because migration 02 ran. If 02 is still in place, this deletes exactly 2 rows.
DELETE FROM vitals WHERE Source = 'Consultation';

-- VERIFY ---------------------------------------------------------------------
SELECT 'V1 consultations row count (expect 107)' AS verification, COUNT(*) actual FROM consultations;
SELECT 'V2 vitals row count (expect 118)' AS verification, COUNT(*) actual FROM vitals;
SELECT 'V3 consultations has no VitalID (expect 0)' AS verification, COUNT(*) actual
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='consultations' AND COLUMN_NAME='VitalID';
SELECT 'V4 no Source=Consultation rows (expect 0)' AS verification, COUNT(*) actual
FROM vitals WHERE Source = 'Consultation';