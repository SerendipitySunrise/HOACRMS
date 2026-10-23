<?php

require_once __DIR__ . '/status_constants.php';
require_once __DIR__ . '/booking_settings.php';
require_once __DIR__ . '/appointment_assignment.php';
require_once __DIR__ . '/hospital_helper.php';
require_once __DIR__ . '/db.php';

function isNoShowBlocked(mysqli $conn, int $patientId): array
{
    $limit = bookingSettingInt($conn, 'no_show_limit', 3);
    $days  = bookingSettingInt($conn, 'no_show_window_days', 90);
    if ($limit <= 0) {
        return [false, null];
    }
    $since = (new DateTimeImmutable("-$days days"))->format('Y-m-d');
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM no_shows WHERE PatientID = ? AND NoShowDate >= ?');
    if (!$stmt) return [false, null];
    mysqli_stmt_bind_param($stmt, 'is', $patientId, $since);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $c = (int)($row['c'] ?? 0);
    if ($c >= $limit) {
        return [true, '3 no-shows in 90 days (settings): online booking blocked, message to call the front desk.'];
    }
    return [false, null];
}

/**
 * $excludeAppointmentId: an appointment that is being MOVED (reschedule).
 * It must not count against itself — otherwise a patient at the active
 * limit could never reschedule any of their own appointments.
 */
function countActiveAppointmentsForPatient(mysqli $conn, int $patientId, int $excludeAppointmentId = 0): int
{
    $active = '"' . APPT_STATUS_CANCELLED . '", "' . APPT_STATUS_COMPLETED . '", "' . APPT_STATUS_NO_SHOW . '"';
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM appointments WHERE PatientID = ? AND Status NOT IN (' . $active . ') AND (? = 0 OR AppointmentID <> ?)');
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'iii', $patientId, $excludeAppointmentId, $excludeAppointmentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['c'] ?? 0);
}

function countActivePerDepartment(mysqli $conn, int $patientId, int $departmentId, int $excludeAppointmentId = 0): int
{
    $active = '"' . APPT_STATUS_CANCELLED . '", "' . APPT_STATUS_COMPLETED . '", "' . APPT_STATUS_NO_SHOW . '"';
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM appointments WHERE PatientID = ? AND DepartmentID = ? AND Status NOT IN (' . $active . ') AND (? = 0 OR AppointmentID <> ?)');
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'iiii', $patientId, $departmentId, $excludeAppointmentId, $excludeAppointmentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['c'] ?? 0);
}

function hasActiveAppointmentSameDoctorDay(mysqli $conn, int $patientId, int $staffId, string $date, int $excludeAppointmentId = 0): bool
{
    if ($staffId <= 0) return false;
    $active = '"' . APPT_STATUS_CANCELLED . '", "' . APPT_STATUS_COMPLETED . '", "' . APPT_STATUS_NO_SHOW . '"';
    $stmt = mysqli_prepare($conn, 'SELECT AppointmentID FROM appointments WHERE PatientID = ? AND StaffID = ? AND AppointmentDate = ? AND Status NOT IN (' . $active . ') AND (? = 0 OR AppointmentID <> ?) LIMIT 1');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'iisii', $patientId, $staffId, $date, $excludeAppointmentId, $excludeAppointmentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $found = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $found;
}

function hasOverlappingForPatient(mysqli $conn, int $patientId, string $date, string $time, int $excludeAppointmentId = 0): bool
{
    $active = '"' . APPT_STATUS_CANCELLED . '", "' . APPT_STATUS_COMPLETED . '", "' . APPT_STATUS_NO_SHOW . '"';
    $stmt = mysqli_prepare($conn, 'SELECT AppointmentID FROM appointments WHERE PatientID = ? AND AppointmentDate = ? AND AppointmentTime = ? AND Status NOT IN (' . $active . ') AND (? = 0 OR AppointmentID <> ?) LIMIT 1');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'issii', $patientId, $date, $time, $excludeAppointmentId, $excludeAppointmentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $found = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $found;
}

/**
 * Did a doctor suggest a follow-up (consultations.FollowUpDate) on this
 * exact date for this department? Server-derived so a client cannot claim
 * follow-up status for a slot the doctor never suggested; a follow-up
 * booking skips the per-department limit (spec 3).
 *
 * Consultations not linked to an appointment count for any department
 * (walk-in consultations have no appointment to scope to).
 */
function hasDoctorSuggestedFollowUpOn(mysqli $conn, int $patientId, int $departmentId, string $date): bool
{
    if ($patientId <= 0 || $departmentId <= 0 || $date === '') return false;
    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1
         FROM consultations c
         LEFT JOIN appointments a ON a.AppointmentID = c.AppointmentID
         WHERE c.PatientID = ?
           AND c.FollowUpDate = ?
           AND (a.AppointmentID IS NULL OR a.DepartmentID = ?)
         LIMIT 1'
    );
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'isi', $patientId, $date, $departmentId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $found = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $found;
}

function inBookingWindow(mysqli $conn, string $date, string $time): array
{
    $minH = bookingSettingInt($conn, 'booking_window_min_hours', 2);
    $maxD = bookingSettingInt($conn, 'booking_window_max_days', 30);
    $dtAppt = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . substr($time, 0, 5));
    if (!$dtAppt) return [false, 'Invalid date/time.'];
    $now = new DateTimeImmutable('now');
    $min = $now->add(new DateInterval('PT'.$minH.'H'));
    if ($dtAppt < $min) {
        return [false, 'At least ' . $minH . ' hours ahead required.'];
    }
    $max = $now->add(new DateInterval('P'.$maxD.'D'));
    if ($dtAppt > $max) {
        return [false, 'Booking is limited to ' . $maxD . ' days ahead.'];
    }
    return [true, null];
}
