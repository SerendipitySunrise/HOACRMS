-- ============================================================================
-- PHASE 3 / MIGRATION 07 ROLLBACK - revert department_schedules to MyISAM
-- ============================================================================
ALTER TABLE department_schedules DROP FOREIGN KEY fk_department_schedules_department;
ALTER TABLE department_schedules ENGINE = MyISAM;

-- VERIFY ---------------------------------------------------------------------
SELECT 'V1 engine is MyISAM' AS verification, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='department_schedules';

SELECT 'V2 rows intact (expect 19)' AS verification, COUNT(*) actual FROM department_schedules;