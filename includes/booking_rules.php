<?php

/**
 * Server-side booking rules.
 * Every check enforced server-side; messages match spec exactly.
 */

require_once __DIR__ . '/status_constants.php';
require_once __DIR__ . '/booking_settings.php';
require_once __DIR__ . '/appointment_assignment.php';

function patientIsMinor(DateTimeInterface|string|null $dob): bool
{
    if ($dob === null || $dob === '') {
        return false;
    }
    if ($dob instanceof DateTimeInterface) {
        $dt = clone $dob;
    } else {
        $dt = DateTime::createFromFormat('!Y-m-d', (string) $dob);
        if (!$dt) {
            return false;
        }
    }
    $now = new DateTimeImmutable('today');
    return $now->diff($dt)->y < 18;
}

function patientAgeYears(DateTimeInterface|string|null $dob): int
{
    if ($dob === null || $dob === '') {
        return 999;
    }
    if ($dob instanceof DateTimeInterface) {
        $dt = clone $dob;
    } else {
        $dt = DateTime::createFromFormat('!Y-m-d', (string) $dob);
        if (!$dt) {
            return 999;
        }
    }
    $now = new DateTimeImmutable('today');
    return (int) $now->diff($dt)->y;
}

function patientIsUnder1Year(DateTimeInterface|string|null $dob): bool
{
    if ($dob === null || $dob === '') {
        return false;
    }
    if ($dob instanceof DateTimeInterface) {
        $dt = clone $dob;
    } else {
        $dt = DateTime::createFromFormat('!Y-m-d', (string) $dob);
        if (!$dt) {
            return false;
        }
    }
    $now = new DateTimeImmutable('today');
    $diff = $now->diff($dt);
    return $diff->y === 0 && $diff->m < 12;
}

function departmentFitsAge(mysqli $conn, int $departmentId, DateTimeInterface|string|null $dob): array
{
    $dep = null;
    $stmt = mysqli_prepare($conn, 'SELECT DepartmentID, DepartmentName, MinAge, MaxAge FROM departments WHERE DepartmentID = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $departmentId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $dep = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    if (!$dep) {
        return [false, 'Invalid department.'];
    }

    $deptName  = $dep['DepartmentName'] ?? '';
    $age       = patientAgeYears($dob);
    $pedsLimit = bookingSettingInt($conn, 'pediatrics_age_limit', 18);

    // Effective band is derived from the pediatrics_age_limit setting so an
    // admin edit takes effect immediately (spec 1: Pediatrics = under the
    // limit; adult departments = limit and over; infants under 1 -> the
    // pediatrician, which the minor rule already covers).
    $isPediatrics = (stripos($deptName, 'Pedia') !== false || stripos($deptName, 'Child') !== false);

    if ($isPediatrics) {
        if ($age >= $pedsLimit) {
            return [false, 'Pediatrics sees patients under ' . $pedsLimit . '.'];
        }
    } else {
        if ($age < $pedsLimit) {
            return [false, 'This department treats patients ' . $pedsLimit . ' and over. Please choose Pediatrics.'];
        }
    }

    return [true, null];
}
