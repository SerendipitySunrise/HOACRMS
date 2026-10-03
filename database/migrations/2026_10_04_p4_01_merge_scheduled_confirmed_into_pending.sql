-- ============================================================================
-- PHASE 4 / MIGRATION 01 — merge appointments.Status 'Scheduled' and
-- 'Confirmed' into 'Pending', then narrow the ENUM to 7 values
-- ============================================================================
-- WHY: Phase 3 defined the target vocabulary but deliberately left the two
--      legacy values in the ENUM so no data could be orphaned. After all PHP
--      writers and readers have been updated (see README §Phase 4 PHP edits),
--      this migration folds the legacy rows into the canonical value and
--      drops them from the type.
--
-- BACKWARD-COMPATIBLE ORDERING: do NOT run this until every PHP reference to
-- 'Scheduled' / 'Confirmed' has been replaced. Otherwise queries that read
-- those literals start returning 0 rows silently rather than erroring.
--
-- NOTE ON TRANSACTIONS: ALTER TABLE implicitly commits; not rollback-able.
-- ============================================================================

-- STEP 1.1 — rewrite the data first.
UPDATE appointments SET Status = 'Pending' WHERE Status IN ('Scheduled','Confirmed');

-- Expect: 16 row(s) affected (7 Scheduled + 9 Confirmed from the 2026-10-03 baseline).

-- STEP 1.2 — narrow the ENUM to the 7 canonical values.
ALTER TABLE appointments
  MODIFY COLUMN Status enum(
    'Pending','Checked In','Called','In Consultation','Completed',
    'Cancelled','No Show'
  ) NOT NULL DEFAULT 'Pending';

-- VERIFY
SELECT 'V1 appointments.Status distinct (expect 7 canonical values, 0 legacy)' AS verification;
SELECT Status, COUNT(*) n FROM appointments GROUP BY Status ORDER BY n DESC;

SELECT 'V2 any legacy values remaining (expect 0)' AS verification, COUNT(*) actual
FROM appointments WHERE Status IN ('Scheduled','Confirmed');

SELECT 'V3 column type now 7 values' AS verification, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='appointments' AND COLUMN_NAME='Status';