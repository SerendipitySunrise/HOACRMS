-- ============================================================================
-- PHASE 3 / MIGRATION 08 ROLLBACK - drop every FK added in 08
-- ============================================================================
ALTER TABLE users       DROP FOREIGN KEY fk_users_roles;
ALTER TABLE patients    DROP FOREIGN KEY fk_patients_users;
ALTER TABLE admin       DROP FOREIGN KEY fk_admin_users;
ALTER TABLE staff       DROP FOREIGN KEY fk_staff_users,
                        DROP FOREIGN KEY fk_staff_departments;
ALTER TABLE vitals      DROP FOREIGN KEY fk_vitals_appointment,
                        DROP FOREIGN KEY fk_vitals_patient,
                        DROP FOREIGN KEY fk_vitals_staff,
                        DROP INDEX idx_vitals_staff;
ALTER TABLE no_shows    DROP FOREIGN KEY fk_noshow_queue,
                        DROP FOREIGN KEY fk_noshow_appointment,
                        DROP FOREIGN KEY fk_noshow_patient,
                        DROP FOREIGN KEY fk_noshow_department,
                        DROP FOREIGN KEY fk_noshow_marked_by,
                        DROP INDEX idx_noshow_marked_by;

-- VERIFY - every table back to its pre-08 FK count: admin 0, patients 0,
-- staff 0, users 0, vitals 0, no_shows 0.
SELECT TABLE_NAME, COUNT(*) fk_count
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'hoacrms' AND REFERENCED_TABLE_NAME IS NOT NULL
  AND TABLE_NAME IN ('admin','patients','staff','users','vitals','no_shows')
GROUP BY TABLE_NAME ORDER BY TABLE_NAME;