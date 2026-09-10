-- ============================================================
-- TEST DATA: Same-day appointment for the staff dashboard
-- Scope: Surgery dept (3) = Jonah Santos's dashboard (StaffID 4)
--
-- Creates:
--   1) A today's appointment for Jedrick Versoza (PatientID 1)
--      with Dr. Ana Luiz (StaffID 8)
--   2) A queue entry (checked in) -> shows in ACTIVE QUEUE (%s- / %s)
--   3) A vitals record -> shows the %s indicator + "Vitals Done" card
--
-- Log in as Jonah Santos on the staff portal to see the effect.
-- ============================================================

USE hoacrms;

-- 1) Appointment today in Surgery
INSERT INTO appointments
    (PatientID, StaffID, DepartmentID, AppointmentDate, AppointmentTime, Purpose, Status)
VALUES
    (1, 8, 3, CURDATE(), '10:00:00', 'Follow-up check-up', 'Scheduled');

SET @appt_id = LAST_INSERT_ID();

-- 2) Check in -> creates the Active Queue row
SET @max_queue_num = IFNULL(
    (SELECT MAX(QueueNumber) FROM queue WHERE QueueDate = CURDATE()),
    0
);

INSERT INTO queue
    (AppointmentID, QueueNumber, PriorityLevel, QueueDate, QueueTime, Status, CreatedAt)
VALUES
    (@appt_id, @max_queue_num + 1, 'Normal', CURDATE(), CURTIME(), 'Waiting', NOW());

-- 3) Record vitals (Jonah Santos, Nurse) -> flips the indicator to checked
INSERT INTO vitals
    (AppointmentID, PatientID, StaffID, BloodPressure, Temperature, PulseRate,
     Weight, Height, RecordedAt)
VALUES
    (@appt_id, 1, 4, '120/80', 36.8, 72, 68.50, 165.00, NOW());

-- Confirm what was created
SELECT a.AppointmentID, a.AppointmentDate, a.AppointmentTime, a.Purpose,
       a.Status, CONCAT(u.FirstName,' ',u.LastName) AS Patient
FROM appointments a
INNER JOIN patients p ON a.PatientID = p.PatientID
INNER JOIN users u ON p.UserID = u.UserID
WHERE a.AppointmentID = @appt_id;

SELECT q.QueueID, q.QueueNumber, q.Status AS QueueStatus,
       (SELECT COUNT(*) FROM vitals v
        WHERE v.PatientID = 1
          AND v.RecordedAt >= CURDATE()
          AND v.RecordedAt < CURDATE() + INTERVAL 1 DAY) AS vitals_today
FROM queue q
WHERE q.AppointmentID = @appt_id;