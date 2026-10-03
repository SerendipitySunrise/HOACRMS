-- ============================================================================
-- PHASE 3 / MIGRATION 05 ROLLBACK - remove the unique slot key
-- ============================================================================
ALTER TABLE appointments
  DROP INDEX uq_appointments_slot,
  DROP COLUMN SlotKey;

-- VERIFY ---------------------------------------------------------------------
SELECT 'V1 SlotKey column removed (expect 0)' AS verification, COUNT(*) actual
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='appointments' AND COLUMN_NAME='SlotKey';

SELECT 'V2 appointments rows intact (expect 200)' AS verification, COUNT(*) actual FROM appointments;