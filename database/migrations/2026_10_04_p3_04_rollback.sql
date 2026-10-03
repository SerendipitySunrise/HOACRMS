-- ============================================================================
-- PHASE 3 / MIGRATION 04 ROLLBACK - restore varchar types and the two columns
-- ============================================================================
-- Reverts the ENUM narrowing back to varchar(50) and restores the dropped
-- columns. The two dropped columns come back EMPTY - their original values
-- are not preserved. That is why the preflight script captured them and why
-- the mysqldump before Phase 3 is the authoritative restore source.
--
-- If you cannot accept losing reports.FormatStatus or staff.AssignedDays
-- values, restore from the mysqldump instead of running this.
-- ============================================================================

-- appointments.Status -> varchar(50)
ALTER TABLE appointments
  MODIFY COLUMN Status varchar(50) NOT NULL DEFAULT 'Pending';

-- queue.Status -> varchar(50)
ALTER TABLE queue
  MODIFY COLUMN Status varchar(50) NOT NULL DEFAULT 'Waiting';

-- consultations.Status -> varchar(50)
ALTER TABLE consultations
  MODIFY COLUMN Status varchar(50) NOT NULL DEFAULT 'Ongoing';

-- staff.AvailabilityStatus -> varchar(50)
ALTER TABLE staff
  MODIFY COLUMN AvailabilityStatus varchar(50) NOT NULL DEFAULT 'Available';

-- queue.PriorityLevel -> varchar(50)
ALTER TABLE queue
  MODIFY COLUMN PriorityLevel varchar(50) NOT NULL DEFAULT 'Normal';

-- Re-create the dropped columns. They are empty after this.
ALTER TABLE reports ADD COLUMN FormatStatus varchar(50) DEFAULT NULL AFTER FilePath;
ALTER TABLE staff  ADD COLUMN AssignedDays varchar(100) DEFAULT NULL AFTER ScheduleEnd;

-- VERIFY ---------------------------------------------------------------------
SELECT 'V1 appointments.Status now varchar' AS verification, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='appointments' AND COLUMN_NAME='Status';
SELECT 'V2 FormatStatus restored (empty)' AS verification, COUNT(*) total, SUM(FormatStatus IS NOT NULL) filled FROM reports;
SELECT 'V3 AssignedDays restored (empty)' AS verification, COUNT(*) total, SUM(AssignedDays IS NOT NULL) filled FROM staff;