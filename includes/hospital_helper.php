<?php

require_once __DIR__ . '/db.php';

function isHospitalHoliday(mysqli $conn, string $date): bool
{
    if ($date === '') return false;
    $stmt = mysqli_prepare($conn, 'SELECT HolidayID FROM hospital_holidays WHERE HolidayDate = ? LIMIT 1');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $date);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $found = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $found;
}

/**
 * Does this doctor have a leave/unavailability row covering the date?
 *
 * Matches the doctor's own rows, plus department-wide closures
 * (DoctorID IS NULL with the doctor's DepartmentID) — never leave rows
 * belonging to a different department.
 */
function isDoctorOnLeave(mysqli $conn, int $staffId, string $date): bool
{
    if ($staffId <= 0 || $date === '') return false;
    $stmt = mysqli_prepare($conn,
        'SELECT du.UnavailabilityID
         FROM doctorunavailability du
         WHERE du.StartDate <= ? AND du.EndDate >= ?
           AND (
               du.DoctorID = ?
               OR (du.DoctorID IS NULL
                   AND du.DepartmentID = (SELECT DepartmentID FROM staff WHERE StaffID = ?))
           )
         LIMIT 1');
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ssii', $date, $date, $staffId, $staffId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $found = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $found;
}
