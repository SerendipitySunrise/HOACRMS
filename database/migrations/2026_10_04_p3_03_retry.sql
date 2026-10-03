SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', '');

INSERT INTO vitals
  (AppointmentID, PatientID, StaffID, BloodPressure, Temperature, PulseRate,
   Weight, Height, RecordedAt, Source)
SELECT
  c.AppointmentID, c.PatientID, c.StaffID, c.BloodPressure, c.Temperature,
  c.PulseRate, c.Weight, c.Height,
  TIMESTAMP(CONCAT(c.ConsultationDate, ' ', c.ConsultationTime)),
  'Consultation'
FROM consultations c
WHERE c.ConsultationID IN (7, 4503)
  AND c.BloodPressure IS NOT NULL AND c.BloodPressure <> ''
  AND NOT EXISTS (
    SELECT 1 FROM (SELECT AppointmentID, StaffID, Source FROM vitals) v
    WHERE v.Source = 'Consultation'
      AND v.AppointmentID = c.AppointmentID
      AND v.StaffID = c.StaffID
  );
SELECT ROW_COUNT() AS vitals_inserted;

UPDATE consultations c
SET c.VitalID = (
  SELECT MAX(v.VitalID)
  FROM vitals v
  WHERE v.AppointmentID = c.AppointmentID
    AND v.StaffID       = c.StaffID
)
WHERE c.VitalID IS NULL
  AND EXISTS (
    SELECT 1 FROM vitals v
    WHERE v.AppointmentID = c.AppointmentID
      AND v.StaffID       = c.StaffID
  );
SELECT ROW_COUNT() AS vitalids_backfilled;

ALTER TABLE consultations
  ADD CONSTRAINT fk_consultations_vital
  FOREIGN KEY (VitalID) REFERENCES vitals (VitalID)
  ON DELETE SET NULL ON UPDATE CASCADE;

SELECT 'V1' AS v, COUNT(*) total, SUM(VitalID IS NOT NULL) linked, SUM(VitalID IS NULL) unlinked FROM consultations;
SELECT 'V2' AS v, COUNT(*) total FROM vitals;
SELECT 'V2b' AS v, Source, COUNT(*) n FROM vitals GROUP BY Source;
SELECT 'V4' AS v, COUNT(*) mismatched FROM consultations c JOIN vitals v ON v.VitalID=c.VitalID WHERE v.StaffID<>c.StaffID OR v.AppointmentID<>c.AppointmentID;
SELECT 'V5' AS v, COUNT(*) fk_present FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='hoacrms' AND CONSTRAINT_NAME='fk_consultations_vital';
SELECT 'V6' AS v, c.Status, COUNT(*) n FROM consultations c WHERE c.VitalID IS NULL GROUP BY c.Status;
