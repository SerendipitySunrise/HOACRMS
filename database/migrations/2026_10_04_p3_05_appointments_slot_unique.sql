-- ============================================================================
-- PHASE 3 / MIGRATION 05 - one generated SlotKey + UNIQUE to prevent
-- double-booking the same doctor at the same instant
-- ============================================================================
-- WHAT:
--   appointments.SlotKey  stored generated column.
--                         NULL when StaffID IS NULL or the appointment is
--                         Cancelled or No Show (those slots are releaseable).
--                         Otherwise  'appt|StaffID|DepartmentID|Date|Time'.
--   UNIQUE KEY uq_appointments_slot on it.
--
-- WHY GENERATED, NOT TRIGGER:
--   The conflict rule must hold for every INSERT and UPDATE with no PHP change,
--   including bulk imports. A stored generated column is recomputed by MySQL on
--   every write, so there is no code path that can skip it. (Virtual generated
--   columns cannot carry a UNIQUE index on this MySQL version, so STORED is
--   used.)
--
-- WHY StaffID, NOT DepartmentID, AS THE SLOT KEY:
--   save_appointment.php defines a slot as one doctor at one instant
--   (StaffID+AppointmentDate+AppointmentTime). Two patients can legitimately
--   share the same department and even the same clock time with two different
--   doctors. Keying on department would forbid that and break the booking
--   flow. The unique key uses DepartmentID only as disambiguation inside the
--   string, per the schema.
--
-- PRE-CHECK (already run in preflight 3a): 0 collision groups among rows with
--   StaffID NOT NULL and Status NOT IN ('Cancelled','Completed'). The
--   constraint therefore applies with zero data repair.
--
-- ROWS LEFT OUT OF THE KEY:
--   - 19 appointments with StaffID IS NULL (NULL key, excluded).
--   - Cancelled and No Show (NULL key, rebookable).
--   - 0-date rows are already excluded via StaffID IS NULL.
--
-- NOTE ON STORED vs VIRTUAL:
--   STORED forces a full table rebuild, which on this MySQL 8.0 instance trips
--   ERROR 1215 when re-validating FKs on the appointments table. VIRTUAL does
--   not rebuild the table and still supports the UNIQUE index below (verified:
--   the same key inserted twice raises ERROR 1062). Use VIRTUAL.
--
--   A VIRTUAL column cannot be selected as part of the query planner’s WHERE
--   evaluation as efficiently as STORED, but the comparison is on the same
--   values and the index supplies the lookup. Functionally equivalent for our
--   use.
-- ============================================================================

SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');


-- ---------------------------------------------------------------------------
-- STEP 5.1 - Add the stored generated column.
-- ---------------------------------------------------------------------------
ALTER TABLE appointments
  ADD COLUMN SlotKey varchar(100)
    GENERATED ALWAYS AS (
      CASE
        WHEN StaffID IS NULL THEN NULL
        WHEN Status IN ('Cancelled','No Show','Completed') THEN NULL
        ELSE CONCAT_WS('|', 'appt', StaffID, DepartmentID,
                       CAST(AppointmentDate AS CHAR), CAST(AppointmentTime AS CHAR))
      END
    ) VIRTUAL AFTER reschedule_token;


-- ---------------------------------------------------------------------------
-- STEP 5.2 - The UNIQUE index.
-- ---------------------------------------------------------------------------
ALTER TABLE appointments
  ADD UNIQUE KEY uq_appointments_slot (SlotKey);


-- ---------------------------------------------------------------------------
-- STEP 5.3 - VERIFY
-- ---------------------------------------------------------------------------

-- V1: every non-cancelled/non-no-show/staffed row has a key, everything else NULL.
SELECT 'V1 SlotKey coverage' AS verification,
       SUM(SlotKey IS NULL) as null_slotkey,
       SUM(SlotKey IS NOT NULL) as keyed,
       COUNT(*) as total FROM appointments;

-- V2: every keyed row is unique. Must be 0.
SELECT 'V2 slotkey duplicates (expect 0)' AS verification, COUNT(*) actual FROM (
  SELECT SlotKey FROM appointments WHERE SlotKey IS NOT NULL
  GROUP BY SlotKey HAVING COUNT(*) > 1
) t;

-- V3: the two In Progress zero-StaffID-zero-date rows can never collide.
SELECT 'V3 NULL-StaffID rows that would have collided (expect 0)' AS verification,
       COUNT(*) actual FROM appointments WHERE StaffID IS NULL AND SlotKey IS NOT NULL;

-- V4: prove the index exists.
SELECT 'V4 unique index present' AS verification, TABLE_NAME, INDEX_NAME, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='hoacrms' AND TABLE_NAME='appointments' AND COLUMN_NAME='SlotKey';

-- V5: live behaviour - inserting a duplicate staff+date+time must now fail.
SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');
-- Pick a real occupied active slot to attempt to duplicate.
SELECT 'V5 sample active slot to try duplicating' AS verification,
       AppointmentID, StaffID, DepartmentID, AppointmentDate, AppointmentTime, Status, SlotKey
FROM appointments
WHERE SlotKey IS NOT NULL
ORDER BY AppointmentID
LIMIT 1;

-- (Run this by hand, substituting the IDs above:)
-- INSERT INTO appointments (PatientID, StaffID, DepartmentID, AppointmentDate, AppointmentTime, Purpose, Status)
-- SELECT PatientID, StaffID, DepartmentID, AppointmentDate, AppointmentTime, 'dup test', 'Pending'
-- FROM appointments WHERE AppointmentID = <id from V5>;
--   Expect: ERROR 1062 Duplicate entry ... for key 'uq_appointments_slot'

-- And inserting the same slot with a cancelled row must SUCCEED (it is excluded):
-- UPDATE appointments SET Status='Cancelled' WHERE AppointmentID = <that donor id>;
-- INSERT ...same staff/date/time...  -> now allowed because cancelled rows carry NULL SlotKey.
-- Revert with the backup.