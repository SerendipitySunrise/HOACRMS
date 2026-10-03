-- ============================================================================
-- PHASE 3 / MIGRATION 04 - tighten status vocabularies to ENUMs,
-- drop reports.FormatStatus and staff.AssignedDays
-- ============================================================================
-- WHAT:
--   1. appointments.Status      varchar(50) -> ENUM (same 9 values, no merge yet)
--   2. queue.Status             varchar(50) -> ENUM (adds 'In Progress' already used)
--   3. consultations.Status     varchar(50) -> ENUM
--   4. staff.AvailabilityStatus varchar(50) -> ENUM('Available','Off Duty','On Leave')
--   5. queue.PriorityLevel      varchar(50) -> ENUM('Normal','Priority')
--   6. reports.FormatStatus     dropped (leftover, 0 PHP references)
--   7. staff.AssignedDays       dropped (dead, 0 PHP references)
--
-- WHY ENUMs instead of the Phase 4 merge:
--   Appointments: 'Checked In', 'Called' and 'In Consultation' are live states
--   read by cancel_appointment.php, reschedule_appointment.php,
--   patient_appointment.php and admin_reports.php. Reducing the column to
--   outcomes would break those guards. The ENUM tightens the vocabulary to the
--   9 values that actually occur without removing any of them; the
--   Scheduled/Confirmed merge happens in Phase 4 together with the 8 PHP sites
--   that read those literals.
--
--   'In Progress' on queue.Status: 2 rows already carry it, so the column
--   currently violates its own status_constants.php header. The ENUM formalises
--   the de facto value and makes that class of bug impossible going forward.
--
--   Case-insensitivity: all columns inherit utf8mb4_0900_ai_ci, so ENUM
--   matching is case-insensitive. A lowercase 'ongoing' write will still match
--   the ENUM member 'Ongoing' - this cannot fail the way a strict binary
--   comparison would.
--
-- DEAD-COLUMN SAFETY: before dropping, the values were captured in
-- 2026_10_04_p3_00_preflight_verification.sql (output of check 7):
--   reports.FormatStatus - all 4 rows are 'Completed'.
--   staff.AssignedDays   - 11 of 19 rows have real text values such as
--                          'Mon,Tue,Wed,Thu,Fri'. That scheduling data will be
--                          lost. It is recoverable only from the mysqldump.
--   If you want to keep AssignedDays, comment out the DROP below and skip it.
--
-- NOTE ON TRANSACTIONS: ALTER TABLE is DDL and implicitly commits. The
-- rollback is a forward-compensating script, not ROLLBACK.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- STEP 4.1 - appointments.Status. All 9 currently-occurring values are kept.
-- ---------------------------------------------------------------------------
ALTER TABLE appointments
  MODIFY COLUMN Status enum(
    'Pending','Checked In','Called','In Consultation','Completed',
    'Cancelled','No Show','Scheduled','Confirmed'
  ) NOT NULL DEFAULT 'Pending';

-- ---------------------------------------------------------------------------
-- STEP 4.2 - queue.Status. Adds 'In Progress' which 2 rows already hold.
-- ---------------------------------------------------------------------------
ALTER TABLE queue
  MODIFY COLUMN Status enum(
    'Waiting','Called','In Consultation','In Progress','Completed',
    'Cancelled','No Show'
  ) NOT NULL DEFAULT 'Waiting';

-- ---------------------------------------------------------------------------
-- STEP 4.3 - consultations.Status. 'In Progress' is legal for PHP
-- (records.php allows Ongoing/Completed/In Progress/Cancelled) even though
-- 0 rows currently use it.
-- ---------------------------------------------------------------------------
ALTER TABLE consultations
  MODIFY COLUMN Status enum(
    'Ongoing','In Progress','Completed','Cancelled'
  ) NOT NULL DEFAULT 'Ongoing';

-- ---------------------------------------------------------------------------
-- STEP 4.4 - staff.AvailabilityStatus. Three values, matching exactly the
-- whitelist in admin_doctor_management.php:116 and the radios in
-- admin_staff_management.php:539. All 19 rows are 'Available'.
-- ---------------------------------------------------------------------------
ALTER TABLE staff
  MODIFY COLUMN AvailabilityStatus enum('Available','Off Duty','On Leave')
  NOT NULL DEFAULT 'Available';

-- ---------------------------------------------------------------------------
-- STEP 4.5 - queue.PriorityLevel. PHP writes only 'Normal' and 'Priority'.
-- ---------------------------------------------------------------------------
ALTER TABLE queue
  MODIFY COLUMN PriorityLevel enum('Normal','Priority')
  NOT NULL DEFAULT 'Normal';

-- ---------------------------------------------------------------------------
-- STEP 4.6 - Drop reports.FormatStatus (leftover; 0 PHP references).
-- ---------------------------------------------------------------------------
ALTER TABLE reports DROP COLUMN FormatStatus;

-- ---------------------------------------------------------------------------
-- STEP 4.7 - Drop staff.AssignedDays (dead; 0 PHP references).
-- ---------------------------------------------------------------------------
ALTER TABLE staff DROP COLUMN AssignedDays;


-- ---------------------------------------------------------------------------
-- STEP 4.8 - VERIFY
-- ---------------------------------------------------------------------------

-- V1: every appointments.Status value is now a legal ENUM member (no NULLs, no surprises).
SELECT 'V1 appointments.Status distinct values' AS verification, Status, COUNT(*) n
FROM appointments GROUP BY Status ORDER BY n DESC;

-- V2: includes the 2 In Progress rows that were previously invalid.
SELECT 'V2 queue.Status distinct values' AS verification, Status, COUNT(*) n
FROM queue GROUP BY Status ORDER BY n DESC;

-- V3: consultations.
SELECT 'V3 consultations.Status distinct values' AS verification, Status, COUNT(*) n
FROM consultations GROUP BY Status ORDER BY n DESC;

-- V4: all staff one value, which is still 'Available'.
SELECT 'V4 staff.AvailabilityStatus' AS verification, AvailabilityStatus, COUNT(*) n
FROM staff GROUP BY AvailabilityStatus;

-- V5: prove the two columns are now ENUMs.
SELECT 'V5 appointments.Status type' AS verification, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='appointments' AND COLUMN_NAME='Status';
SELECT 'V5 queue.Status type' AS verification, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='queue' AND COLUMN_NAME='Status';

-- V6: both columns are gone.
SELECT 'V6 dropped columns (expect 0 for both rows)' AS verification, COUNT(*) actual
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='hoacrms'
  AND ((TABLE_NAME='reports' AND COLUMN_NAME='FormatStatus')
    OR (TABLE_NAME='staff' AND COLUMN_NAME='AssignedDays'));

-- V7: data intact.
SELECT 'V7 row counts' AS verification;
SELECT 'appointments' t, COUNT(*) n FROM appointments
UNION ALL SELECT 'queue', COUNT(*) FROM queue
UNION ALL SELECT 'consultations', COUNT(*) FROM consultations
UNION ALL SELECT 'staff', COUNT(*) FROM staff
UNION ALL SELECT 'reports', COUNT(*) FROM reports;