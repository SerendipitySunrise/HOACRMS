-- ============================================================================
-- PHASE 3 / MIGRATION 08 - add the 11 missing foreign keys
-- ============================================================================
-- Tables with zero FK enforcement despite having foreign-key-looking columns:
--   admin, patients, staff, users, vitals, no_shows (and department_schedules,
--   handled in 07). Preflight check 2 confirmed every orphan count is 0, so
--   adding them enforces integrity without any data repair.
--
-- ON DELETE choices mirror the existing app behavior for equivalent links:
--   RESTRICT    - the parent sticks around; deleting it is an error.
--   SET NULL    - parent deletion orphans the link, not the child row.
--   CASCADE     - child row is meaningless without its parent.
--
-- NOTE ON TRANSACTIONS: ALTER TABLE implicitly commits; not rollback-able.
-- ============================================================================

SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');


-- ---------------------------------------------------------------------------
-- users -> roles: an account cannot exist without its role.
-- ---------------------------------------------------------------------------
ALTER TABLE users
  ADD CONSTRAINT fk_users_roles
  FOREIGN KEY (RoleID) REFERENCES roles (RoleID)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- patients -> users
ALTER TABLE patients
  ADD CONSTRAINT fk_patients_users
  FOREIGN KEY (UserID) REFERENCES users (UserID)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- admin -> users
ALTER TABLE admin
  ADD CONSTRAINT fk_admin_users
  FOREIGN KEY (UserID) REFERENCES users (UserID)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- staff -> users, staff -> departments
ALTER TABLE staff
  ADD CONSTRAINT fk_staff_users
    FOREIGN KEY (UserID) REFERENCES users (UserID)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_staff_departments
    FOREIGN KEY (DepartmentID) REFERENCES departments (DepartmentID)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- vitals -> appointments, patients, staff
-- StaffID on vitals has no index yet, so create one as part of the FK.
ALTER TABLE vitals
  ADD CONSTRAINT fk_vitals_appointment
    FOREIGN KEY (AppointmentID) REFERENCES appointments (AppointmentID)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_vitals_patient
    FOREIGN KEY (PatientID) REFERENCES patients (PatientID)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD KEY idx_vitals_staff (StaffID),
  ADD CONSTRAINT fk_vitals_staff
    FOREIGN KEY (StaffID) REFERENCES staff (StaffID)
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- no_shows -> queue, appointments, patients, departments, users(MarkedBy)
ALTER TABLE no_shows
  ADD CONSTRAINT fk_noshow_queue
    FOREIGN KEY (QueueID) REFERENCES queue (QueueID)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT fk_noshow_appointment
    FOREIGN KEY (AppointmentID) REFERENCES appointments (AppointmentID)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_noshow_patient
    FOREIGN KEY (PatientID) REFERENCES patients (PatientID)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT fk_noshow_department
    FOREIGN KEY (DepartmentID) REFERENCES departments (DepartmentID)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD KEY idx_noshow_marked_by (MarkedBy),
  ADD CONSTRAINT fk_noshow_marked_by
    FOREIGN KEY (MarkedBy) REFERENCES users (UserID)
    ON DELETE RESTRICT ON UPDATE CASCADE;


-- ---------------------------------------------------------------------------
-- VERIFY - count FKs to confirm they all landed.
-- ---------------------------------------------------------------------------
SELECT TABLE_NAME, COUNT(*) fk_count
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'hoacrms'
  AND REFERENCED_TABLE_NAME IS NOT NULL
GROUP BY TABLE_NAME
ORDER BY TABLE_NAME;

-- Expected after 08: admin 1, announcement_reads 2, announcementrecipients 2,
-- appointment_reminders 1, appointment_reschedule_history 1, appointments 3,
-- audit_trail 1, consultations 3, department_schedules 1, doctorunavailability 3,
-- no_shows 5, notifications 1, patients 1, prescription_items 1, prescriptions 1,
-- queue 2, reports 1, role_permissions 1, staff 2, users 1, vitals 3.