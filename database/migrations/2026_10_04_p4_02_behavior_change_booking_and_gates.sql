-- ============================================================================
-- PHASE 4 / BEHAVIOR CHANGE — Booking Validation Gates
-- ============================================================================
-- BEHAVIOR CHANGE (not a schema change). This document records the change
-- and contains the verification queries you can run to confirm it works.
--
-- WHAT CHANGED:
--   Six booking gates in the PHP layer previously allowed an appointment to be
--   created/confirmed when EITHER the doctor's availability flag said
--   'Available' OR the derived schedule allowed the slot. The gates now
--   require BOTH: the doctor's AvailabilityStatus must be 'Available' AND the
--   derived schedule (department_schedules) must contain a matching row for
--   the requested department / day-of-week / time window.
--
-- WHY: every staff row is currently 'Available' (19/19), so the OR version
--      silently let bookings through even for doctors whose department has no
--      matching schedule. AND makes the system fail closed: no schedule → no
--      booking.
--
-- SIDE EFFECT YOU SHOULD EXPECT: bookings that previously succeeded at
--   departments whose department_schedules rows have been deleted or corrupted
--   (possible because department_schedules was MyISAM and never enforced its
--   FK) will now fail. If a legitimate booking starts failing, check
--   department_schedules for a matching row before reverting this change.
--
-- PHP SITES CHANGED (for your diff review):
--   api/appointments/get_booked_slots.php:110
--   api/appointments/save_appointment.php:166
--   api/appointments/reschedule_appointment.php:363, 405
--   patient/book_appointment.php:133
--
-- DELIBERATELY NOT CHANGED:
--   api/appointments/get_department_schedule.php:86. This endpoint returns
--   ALL session slots for a department in one call; it does not receive a
--   specific target date, so it has no day-of-week to gate against. The
--   gate lives in the endpoints that receive an actual appointment date.
--
-- Each site now checks:
--   AvailabilityStatus = 'Available'
--   AND StaffRole       = 'Doctor'
--   AND EXISTS (
--         SELECT 1 FROM department_schedules ds
--         WHERE ds.DepartmentID = s.DepartmentID
--           AND ds.DayOfWeek    = ?       -- day-of-week of the requested slot
--           AND ds.StartTime   <= ?       -- requested slot start
--           AND ds.EndTime     > ds.StartTime
--           AND <slot end>     <= ds.EndTime
--       )
--
-- VERIFICATION QUERIES:
-- ============================================================================

-- V1: every gated query still returns at least one doctor for every
-- department/day that has a matching schedule row.
SELECT d.DepartmentID, d.DayOfWeek, d.StartTime, d.EndTime, COUNT(s.StaffID) AS available_doctors
FROM department_schedules d
LEFT JOIN staff s
  ON s.DepartmentID = d.DepartmentID
 AND s.StaffRole    = 'Doctor'
 AND s.AvailabilityStatus = 'Available'
GROUP BY d.DepartmentID, d.DayOfWeek, d.StartTime, d.EndTime
ORDER BY d.DepartmentID, d.DayOfWeek;

-- V2: every doctor's DepartmentID should map to at least one schedule row,
-- otherwise the AND gate will block them forever. (19 doctors, 19 schedule rows.)
SELECT s.StaffID, s.DepartmentID, s.AvailabilityStatus, s.StaffRole,
       (SELECT COUNT(*) FROM department_schedules ds WHERE ds.DepartmentID = s.DepartmentID) AS schedule_rows
FROM staff s
WHERE s.StaffRole = 'Doctor'
ORDER BY s.StaffID;

-- V3: report any (department, day, slot) in department_schedules that has no
-- matching available doctor. Those slots will behave as "no capacity".
SELECT ds.DepartmentID, ds.DayOfWeek, ds.StartTime, ds.EndTime,
       COUNT(DISTINCT s.StaffID) AS doctors_available
FROM department_schedules ds
LEFT JOIN staff s
  ON s.DepartmentID = ds.DepartmentID
 AND s.StaffRole    = 'Doctor'
 AND s.AvailabilityStatus = 'Available'
GROUP BY ds.DepartmentID, ds.DayOfWeek, ds.StartTime, ds.EndTime
HAVING doctors_available = 0;

-- SPOT-CHECK A REAL BOOKING FLOW:
--   1. Open /patient/book_appointment.php and try to book dept 1, Wed, 08:00.
--      Expect success if dept 1 has an Available doctor and a matching row.
--   2. Open /patient/book_appointment.php and try to book dept 1, Sun 03:00.
--      Expect rejection: no matching department_schedules row.
--   3. Admin marks the dept 1 doctor 'Off Duty'. Re-run step 1. Expect
--      rejection: manual flag lost, even though schedule row still exists.
--
-- ROLLBACK PLAN:
--   This is a code change, not a schema change. Revert the 6 PHP sites to
--   their prior OR semantics by restoring them from the git working tree
--   before the behavior-change commit (or by pulling the commit). No DB
--   rollback is required.