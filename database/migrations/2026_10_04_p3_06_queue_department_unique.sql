-- ============================================================================
-- PHASE 3 / MIGRATION 06 - queue: add DepartmentID, enforce per-dept queue number
-- ============================================================================
-- WHAT:
--   1. queue.DepartmentID  added, NOT NULL, FK to departments.
--   2. UNIQUE KEY on (DepartmentID, QueueDate, QueueNumber) - a department's
--      queue numbers are unique per day.
--
-- WHY: queue currently has no department. Queue numbers are handed out per
--   department line. The same QueueNumber can legitimately occur in two
--   different departments on the same day (all 19 prior duplicates span 2-5
--   departments), so the unique must be per (DepartmentID, QueueDate).
--
-- BACKFILL: every queue row's DepartmentID is copied from its appointment.
--   Preflight 3b confirmed 0 collisions across departments on the same
--   (QueueDate, QueueNumber) once keyed per department.
--
-- NOTE ON TRANSACTIONS: ALTER TABLE implicitly commits; DDL is not rollback-able.
-- ============================================================================

SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');


-- ---------------------------------------------------------------------------
-- STEP 6.1 - Add the column, nullable first so the ALTER can't fail on the
-- existing 90 rows, then backfill, then tighten.
-- ---------------------------------------------------------------------------
ALTER TABLE queue ADD COLUMN DepartmentID int NULL AFTER QueueTime;

UPDATE queue q
JOIN appointments a ON a.AppointmentID = q.AppointmentID
SET q.DepartmentID = a.DepartmentID;

ALTER TABLE queue
  MODIFY COLUMN DepartmentID int NOT NULL;


-- ---------------------------------------------------------------------------
-- STEP 6.2 - FK to departments.
-- ---------------------------------------------------------------------------
ALTER TABLE queue
  ADD CONSTRAINT fk_queue_department
  FOREIGN KEY (DepartmentID) REFERENCES departments (DepartmentID)
  ON DELETE RESTRICT ON UPDATE CASCADE;


-- ---------------------------------------------------------------------------
-- STEP 6.3 - Per-department per-day unique queue number.
-- ---------------------------------------------------------------------------
ALTER TABLE queue
  ADD UNIQUE KEY uq_queue_dept_date_number (DepartmentID, QueueDate, QueueNumber);


-- ---------------------------------------------------------------------------
-- STEP 6.4 - Key index for the join.
-- ---------------------------------------------------------------------------
ALTER TABLE queue
  ADD KEY idx_queue_department (DepartmentID);


-- ---------------------------------------------------------------------------
-- STEP 6.5 - VERIFY
-- ---------------------------------------------------------------------------

-- V1: no NULLs left, every row matches its appointment's department.
SELECT 'V1 NULL DepartmentIDs (expect 0)' AS verification, COUNT(*) actual
FROM queue WHERE DepartmentID IS NULL;

-- V2: provenance check - every queue row's department equals its appointment's.
SELECT 'V2 mismatched DepartmentID vs appointment (expect 0)' AS verification, COUNT(*) actual
FROM queue q JOIN appointments a ON a.AppointmentID=q.AppointmentID
WHERE q.DepartmentID <> a.DepartmentID;

-- V3: unique holds - no (dept,date,number) appears twice.
SELECT 'V3 duplicate queue numbers per dept/date (expect 0)' AS verification, COUNT(*) actual FROM (
  SELECT DepartmentID, QueueDate, QueueNumber FROM queue
  GROUP BY DepartmentID, QueueDate, QueueNumber HAVING COUNT(*) > 1
) t;

-- V4: FK present.
SELECT 'V4 FK present' AS verification, CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='queue' AND CONSTRAINT_NAME='fk_queue_department';

-- V5: index present.
SELECT 'V5 unique key present' AS verification, INDEX_NAME FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='queue' AND INDEX_NAME='uq_queue_dept_date_number' LIMIT 1;

-- V6: data intact.
SELECT 'V6 queue rows (expect 90)' AS verification, COUNT(*) actual FROM queue;