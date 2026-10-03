-- ============================================================================
-- PHASE 3 / MIGRATION 07 - department_schedules: MyISAM -> InnoDB, add FK
-- ============================================================================
-- WHAT:  Convert the only MyISAM table to InnoDB and add the FK that could
--        never exist while it was MyISAM.
-- WHY:   MyISAM does not enforce foreign keys. department_schedules is the
--        one table whose referential link to departments was silently ignored.
--        Converting to InnoDB makes every other migration's integrity
--        guarantees true for this table too.
-- DATA:  Preflight check 2 returned 0 orphan department_schedules -> departments,
--        so the FK conversion loses nothing.
-- NOTE ON TRANSACTIONS: ENGINE change is DDL and implicitly commits.
-- ============================================================================

SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');


-- ---------------------------------------------------------------------------
-- STEP 7.1 - Convert engine.
-- ---------------------------------------------------------------------------
ALTER TABLE department_schedules ENGINE = InnoDB;

-- VERIFY: must now read 'InnoDB'.
SELECT 'V1 engine is InnoDB' AS verification, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='department_schedules';


-- ---------------------------------------------------------------------------
-- STEP 7.2 - Add the FK to departments.
-- ---------------------------------------------------------------------------
ALTER TABLE department_schedules
  ADD CONSTRAINT fk_department_schedules_department
  FOREIGN KEY (DepartmentID) REFERENCES departments (DepartmentID)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- VERIFY: must be 1.
SELECT 'V2 FK present' AS verification, COUNT(*) actual
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='department_schedules'
  AND CONSTRAINT_NAME='fk_department_schedules_department';

-- VERIFY: data intact.
SELECT 'V3 rows (expect 19)' AS verification, COUNT(*) actual FROM department_schedules;