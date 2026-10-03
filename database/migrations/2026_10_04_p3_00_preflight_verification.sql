-- ============================================================================
-- PHASE 3 - PREFLIGHT VERIFICATION (read-only, changes nothing)
-- Run this FIRST. Every SELECT must return the expected value before you
-- proceed to 01. If any row is unexpected, STOP and report it.
-- ============================================================================

SELECT '=== 1. Row-count baseline (must match Phase 1: 861 total) ===' AS check_name;
SELECT 'users' t, COUNT(*) n FROM users
UNION ALL SELECT 'patients', COUNT(*) FROM patients
UNION ALL SELECT 'staff', COUNT(*) FROM staff
UNION ALL SELECT 'admin', COUNT(*) FROM admin
UNION ALL SELECT 'departments', COUNT(*) FROM departments
UNION ALL SELECT 'department_schedules', COUNT(*) FROM department_schedules
UNION ALL SELECT 'appointments', COUNT(*) FROM appointments
UNION ALL SELECT 'queue', COUNT(*) FROM queue
UNION ALL SELECT 'consultations', COUNT(*) FROM consultations
UNION ALL SELECT 'vitals', COUNT(*) FROM vitals
UNION ALL SELECT 'no_shows', COUNT(*) FROM no_shows
UNION ALL SELECT 'prescriptions', COUNT(*) FROM prescriptions
UNION ALL SELECT 'prescription_items', COUNT(*) FROM prescription_items
UNION ALL SELECT 'appointment_reminders', COUNT(*) FROM appointment_reminders
UNION ALL SELECT 'appointment_reschedule_history', COUNT(*) FROM appointment_reschedule_history
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'announcements', COUNT(*) FROM announcements
UNION ALL SELECT 'announcement_recipients', COUNT(*) FROM announcementrecipients
UNION ALL SELECT 'announcement_reads', COUNT(*) FROM announcement_reads
UNION ALL SELECT 'doctorunavailability', COUNT(*) FROM doctorunavailability
UNION ALL SELECT 'reports', COUNT(*) FROM reports
UNION ALL SELECT 'audit_trail', COUNT(*) FROM audit_trail
UNION ALL SELECT 'system_settings', COUNT(*) FROM system_settings
UNION ALL SELECT 'roles', COUNT(*) FROM roles
UNION ALL SELECT 'role_permissions', COUNT(*) FROM role_permissions;


SELECT '=== 2. Orphan check - ALL must be 0 ===' AS check_name;
SELECT 'queue -> appointments' chk, COUNT(*) orphans FROM queue q
  LEFT JOIN appointments a ON a.AppointmentID=q.AppointmentID WHERE a.AppointmentID IS NULL
UNION ALL SELECT 'vitals -> appointments', COUNT(*) FROM vitals v
  LEFT JOIN appointments a ON a.AppointmentID=v.AppointmentID WHERE a.AppointmentID IS NULL
UNION ALL SELECT 'vitals -> patients', COUNT(*) FROM vitals v
  LEFT JOIN patients p ON p.PatientID=v.PatientID WHERE p.PatientID IS NULL
UNION ALL SELECT 'vitals -> staff', COUNT(*) FROM vitals v
  LEFT JOIN staff s ON s.StaffID=v.StaffID WHERE s.StaffID IS NULL
UNION ALL SELECT 'consultations -> appointments', COUNT(*) FROM consultations c
  LEFT JOIN appointments a ON a.AppointmentID=c.AppointmentID WHERE a.AppointmentID IS NULL
UNION ALL SELECT 'consultations -> staff', COUNT(*) FROM consultations c
  LEFT JOIN staff s ON s.StaffID=c.StaffID WHERE s.StaffID IS NULL
UNION ALL SELECT 'no_shows -> appointments', COUNT(*) FROM no_shows n
  LEFT JOIN appointments a ON a.AppointmentID=n.AppointmentID WHERE a.AppointmentID IS NULL
UNION ALL SELECT 'no_shows -> queue', COUNT(*) FROM no_shows n
  LEFT JOIN queue q ON q.QueueID=n.QueueID WHERE q.QueueID IS NULL
UNION ALL SELECT 'prescriptions -> consultations', COUNT(*) FROM prescriptions p
  LEFT JOIN consultations c ON c.ConsultationID=p.ConsultationID WHERE c.ConsultationID IS NULL
UNION ALL SELECT 'staff -> users', COUNT(*) FROM staff s
  LEFT JOIN users u ON u.UserID=s.UserID WHERE u.UserID IS NULL
UNION ALL SELECT 'staff -> departments', COUNT(*) FROM staff s
  LEFT JOIN departments d ON d.DepartmentID=s.DepartmentID WHERE d.DepartmentID IS NULL
UNION ALL SELECT 'patients -> users', COUNT(*) FROM patients p
  LEFT JOIN users u ON u.UserID=p.UserID WHERE u.UserID IS NULL
UNION ALL SELECT 'admin -> users', COUNT(*) FROM admin a
  LEFT JOIN users u ON u.UserID=a.UserID WHERE u.UserID IS NULL
UNION ALL SELECT 'department_schedules -> departments', COUNT(*) FROM department_schedules ds
  LEFT JOIN departments d ON d.DepartmentID=ds.DepartmentID WHERE d.DepartmentID IS NULL;


SELECT '=== 3. New UNIQUE constraints must apply cleanly (ALL must be 0) ===' AS check_name;
-- 3a. P3-05 uq_appointments_slot
SELECT 'appt slot collisions (StaffID+Date+Time, not Cancelled/Completed)' chk, COUNT(*) collision_groups FROM (
  SELECT StaffID, AppointmentDate, AppointmentTime
  FROM appointments
  WHERE StaffID IS NOT NULL AND Status NOT IN ('Cancelled','Completed')
  GROUP BY StaffID, AppointmentDate, AppointmentTime HAVING COUNT(*) > 1
) t;

-- 3b. P3-06 uq_queue_dept_date_number
SELECT 'queue (DepartmentID,QueueDate,QueueNumber) collisions' chk, COUNT(*) collision_groups FROM (
  SELECT a.DepartmentID, q.QueueDate, q.QueueNumber
  FROM queue q JOIN appointments a ON a.AppointmentID = q.AppointmentID
  GROUP BY a.DepartmentID, q.QueueDate, q.QueueNumber HAVING COUNT(*) > 1
) t;


SELECT '=== 4. P3-02 BP split - every value must parse as NNN/NNN ===' AS check_name;
SELECT 'vitals' tbl, COUNT(*) malformed FROM vitals
  WHERE BloodPressure IS NOT NULL AND BloodPressure NOT REGEXP '^[0-9]{1,3}/[0-9]{1,3}$'
UNION ALL SELECT 'consultations', COUNT(*) FROM consultations
  WHERE BloodPressure IS NOT NULL AND BloodPressure <> '' AND BloodPressure NOT REGEXP '^[0-9]{1,3}/[0-9]{1,3}$';


SELECT '=== 5. P3-03 VitalID backfill must be unambiguous (must be 0) ===' AS check_name;
SELECT 'consultations with >1 vitals from consulting staff' chk, COUNT(*) ambiguous FROM (
  SELECT c.ConsultationID
  FROM consultations c JOIN vitals v
    ON v.AppointmentID=c.AppointmentID AND v.StaffID=c.StaffID
  GROUP BY c.ConsultationID HAVING COUNT(v.VitalID) > 1
) t;


SELECT '=== 6. P3-04 every status value must exist in its planned ENUM ===' AS check_name;
SELECT 'appointments.Status (needs 9: Pending,Checked In,Called,In Consultation,Completed,Cancelled,No Show,Scheduled,Confirmed)' chk,
       GROUP_CONCAT(DISTINCT Status ORDER BY Status) vals, COUNT(DISTINCT Status) distinct_vals FROM appointments
UNION ALL SELECT 'queue.Status (needs 7: Waiting,Called,In Consultation,In Progress,Completed,Cancelled,No Show)',
       GROUP_CONCAT(DISTINCT Status ORDER BY Status), COUNT(DISTINCT Status) FROM queue
UNION ALL SELECT 'consultations.Status (needs 4: Ongoing,In Progress,Completed,Cancelled)',
       GROUP_CONCAT(DISTINCT Status ORDER BY Status), COUNT(DISTINCT Status) FROM consultations
UNION ALL SELECT 'staff.AvailabilityStatus (needs 3: Available,Off Duty,On Leave)',
       GROUP_CONCAT(DISTINCT AvailabilityStatus ORDER BY AvailabilityStatus), COUNT(DISTINCT AvailabilityStatus) FROM staff
UNION ALL SELECT 'queue.PriorityLevel (needs 2: Normal,Priority)',
       GROUP_CONCAT(DISTINCT PriorityLevel ORDER BY PriorityLevel), COUNT(DISTINCT PriorityLevel) FROM queue;


SELECT '=== 7. P3-04 dead columns - record values before dropping ===' AS check_name;
SELECT 'reports.FormatStatus' col, IFNULL(FormatStatus,'(NULL)') val, COUNT(*) n
  FROM reports GROUP BY FormatStatus;
SELECT 'staff.AssignedDays' col, IFNULL(AssignedDays,'(NULL)') val, COUNT(*) n
  FROM staff GROUP BY AssignedDays ORDER BY n DESC;


SELECT '=== 8. Known data-quality issue (NOT touched by Phase 3) ===' AS check_name;
SELECT 'duplicate vitals rows - same appointment, same staff, identical values' chk;
SELECT v.AppointmentID, v.StaffID, COUNT(*) n,
       COUNT(DISTINCT CONCAT_WS('|',v.BloodPressure,v.Temperature,v.PulseRate,v.Weight,v.Height)) distinct_value_sets,
       MIN(v.RecordedAt) first_rec, MAX(v.RecordedAt) last_rec
FROM vitals v
GROUP BY v.AppointmentID, v.StaffID
HAVING n > 1 ORDER BY n DESC;
-- Expect 2 groups: appt 2046 (7 identical rows) and appt 37 (2 identical rows).
-- Reported to user; NOT deleted by any migration.