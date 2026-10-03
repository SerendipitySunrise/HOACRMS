-- ============================================================================
-- PHASE 3 / MIGRATION 01 ROLLBACK - remove the quarantine table
-- ============================================================================
-- Safe to run at any time. Nothing in the application references
-- appointments_quarantine yet, and appointments itself was never modified.
-- If you still need the quarantined data, the full mysqldump taken before
-- Phase 3 also contains it.
-- ============================================================================

DROP TABLE IF EXISTS appointments_quarantine;

-- VERIFY: must be 0
SELECT 'V1 quarantine table removed (expect 0)' AS verification, COUNT(*) actual
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'hoacrms' AND TABLE_NAME = 'appointments_quarantine';

-- VERIFY: must still be 200
SELECT 'V2 appointments untouched (expect 200)' AS verification, COUNT(*) actual FROM appointments;