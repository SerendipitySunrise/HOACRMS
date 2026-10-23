<?php

require_once __DIR__ . '/booking_settings.php';
require_once __DIR__ . '/status_constants.php';
require_once __DIR__ . '/db.php';

function noShowCountForPatient(mysqli $conn, int $patientId, int $daysWindow = 0): int
{
    if ($daysWindow <= 0) {
        $daysWindow = bookingSettingInt($conn, 'no_show_window_days', 90);
    }
    $since = (new DateTimeImmutable("-$daysWindow days"))->format('Y-m-d');
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM no_shows WHERE PatientID = ? AND NoShowDate >= ?');
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'is', $patientId, $since);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['c'] ?? 0);
}

function markNoShow(mysqli $conn, int $appointmentId, int $markedBy, string $reason = ''): bool
{
    mysqli_begin_transaction($conn);
    try {
        // fetch
        $stmt = mysqli_prepare($conn, 'SELECT a.AppointmentID, a.PatientID, a.DepartmentID, q.QueueID FROM appointments a LEFT JOIN queue q ON q.AppointmentID=a.AppointmentID AND q.Status!="Completed" WHERE a.AppointmentID=? LIMIT 1');
        if (!$stmt) throw new Exception('prep');
        mysqli_stmt_bind_param($stmt, 'i', $appointmentId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $app = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!$app) throw new Exception('not found');

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        // insert no_shows
        $stmt = mysqli_prepare($conn, 'INSERT INTO no_shows (QueueID, AppointmentID, PatientID, DepartmentID, MarkedBy, NoShowReason, NoShowDate) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new Exception('prep2');
        $qv = $app['QueueID'] ?: 0;
        mysqli_stmt_bind_param($stmt, 'iiiiiss', $qv, $app['AppointmentID'], $app['PatientID'], $app['DepartmentID'], $markedBy, $reason, $today);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // update patient NoShowCount
        mysqli_query($conn, 'UPDATE patients SET NoShowCount = NoShowCount+1 WHERE PatientID='.(int)$app['PatientID']);

        // update appointment
        $sNo = APPT_STATUS_NO_SHOW;
        $stmt = mysqli_prepare($conn, 'UPDATE appointments SET Status=?, CheckInTime=NULL WHERE AppointmentID=?');
        if (!$stmt) throw new Exception('prep3');
        mysqli_stmt_bind_param($stmt, 'si', $sNo, $appointmentId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // queue
        if ($qv) {
            $stmt = mysqli_prepare($conn, 'UPDATE queue SET Status="No Show" WHERE QueueID=?');
            if ($stmt) { mysqli_stmt_bind_param($stmt, 'i', $qv); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); }
        }
        mysqli_commit($conn);
        return true;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return false;
    }
}
