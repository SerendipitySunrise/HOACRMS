-- ============================================================================
-- PHASE 3 / MIGRATION 06 ROLLBACK - remove queue.DepartmentID and its UNIQUE
-- ============================================================================
ALTER TABLE queue
  DROP FOREIGN KEY fk_queue_department,
  DROP INDEX uq_queue_dept_date_number,
  DROP INDEX idx_queue_department,
  DROP COLUMN DepartmentID;

-- VERIFY ---------------------------------------------------------------------
SELECT 'V1 DepartmentID removed (expect 0)' AS verification, COUNT(*) actual
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='queue' AND COLUMN_NAME='DepartmentID';

SELECT 'V2 queue rows intact (expect 90)' AS verification, COUNT(*) actual FROM queue;