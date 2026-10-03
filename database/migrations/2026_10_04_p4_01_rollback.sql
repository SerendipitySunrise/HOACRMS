-- ============================================================================
-- PHASE 4 / MIGRATION 01 ROLLBACK — restore Scheduled/Confirmed vocabulary
-- ============================================================================
-- Data is not reversible exactly. 'Scheduled' and 'Confirmed' rows have been
-- rewritten to 'Pending', so we cannot recover which was which. Restoring
-- this needs the mysqldump from before Phase 4.
--
-- If you only need the TYPE back but accept that the merged rows stay
-- 'Pending', the ALTER below re-adds the two legacy members.
-- ============================================================================

ALTER TABLE appointments
  MODIFY COLUMN Status enum(
    'Pending','Checked In','Called','In Consultation','Completed',
    'Cancelled','No Show','Scheduled','Confirmed'
  ) NOT NULL DEFAULT 'Pending';

-- VERIFY
SELECT 'V1 type restored' AS verification, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='appointments' AND COLUMN_NAME='Status';
SELECT 'V2 appointments row count' AS verification, COUNT(*) actual FROM appointments;