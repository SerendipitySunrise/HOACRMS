<?php

require_once __DIR__ . '/status_constants.php';
require_once __DIR__ . '/booking_settings.php';

function buildQueueOrderSql(int $departmentId, string $date): string
{
    return 'SELECT q.QueueID, q.AppointmentID, q.QueueNumber, q.PriorityLevel, q.QueueTime, q.Status,
                   a.Urgent, a.AccompaniedBy, a.CheckInTime,
                   p.PatientID, CONCAT(u.FirstName, " ", u.LastName) AS PatientName, u.DateOfBirth,
                   a.AppointmentDate, a.AppointmentTime
            FROM queue q
            JOIN appointments a ON a.AppointmentID = q.AppointmentID
            JOIN patients p ON p.PatientID = a.PatientID
            JOIN users u ON u.UserID = p.UserID
            WHERE q.DepartmentID = ? AND q.QueueDate = ?
            ORDER BY (CASE WHEN q.Status="In Consultation" THEN 0 WHEN q.Status="Called" THEN 1 WHEN q.Status="Waiting" THEN 2 ELSE 3 END),
                     (CASE WHEN a.Urgent=1 THEN 0 ELSE 1 END),
                     q.QueueTime ASC, q.QueueNumber ASC';
}
