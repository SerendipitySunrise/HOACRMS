<?php

/**
 * Single source of truth for department / doctor appointment capacity.
 *
 * Definition (the same one the patient booking page already uses, via
 * api/appointments/get_booked_slots.php):
 *
 *     one patient per doctor per 30-minute time slot
 *
 * For a session:
 *     capacity = (30-minute slots in the session) x (available doctors)
 *
 * For a whole weekday:
 *     capacity = sum over the weekday's sessions of the above
 *
 * "Available doctors" means the same thing as the booking page: a staff
 * member in the department with StaffRole = 'Doctor', AvailabilityStatus =
 * 'Available', for a department that holds consultations that weekday.
 *
 * department_schedules.PatientSlots is deliberately NOT used any more. The
 * column and its data are left untouched; it just no longer drives capacity,
 * so the doctor, admin and patient screens can never disagree again.
 */

const CAPACITY_SLOT_MINUTES = 30;

/**
 * Number of 30-minute appointment slots between two clock times.
 *
 * 08:00:00 -> 12:00:00 = 8 slots. Returns 0 for an invalid or empty window.
 */
function capacitySlotCount(string $startTime, string $endTime): int
{
    $start = strtotime($startTime);
    $end   = strtotime($endTime);

    if ($start === false || $end === false || $end <= $start) {
        return 0;
    }

    return (int) floor(($end - $start) / (CAPACITY_SLOT_MINUTES * 60));
}

/**
 * Number of available doctors in a department on an ISO weekday (1=Mon..7=Sun).
 *
 * Mirrors the capacity rule in api/appointments/get_booked_slots.php, so the
 * number here is exactly the per-slot capacity the patient booking page shows.
 */
function capacityAvailableDoctors(mysqli $conn, int $departmentID, int $dayOfWeek): int
{
    if ($departmentID <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7) {
        return 0;
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS DoctorCount
         FROM staff
         WHERE DepartmentID = ?
           AND AvailabilityStatus = "Available"
           AND StaffRole = "Doctor"
           AND EXISTS (
               SELECT 1 FROM department_schedules ds
               WHERE ds.DepartmentID = staff.DepartmentID
                 AND ds.DayOfWeek = ?
           )'
    );

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $departmentID, $dayOfWeek);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return (int) ($row['DoctorCount'] ?? 0);
}

/**
 * Capacity for one department on one weekday.
 *
 * @param string|null $sessionName Limit to a single session ('Morning',
 *                                 'Afternoon'), or null for the whole day.
 *
 * @return array{capacity:int, slots:int, doctors:int}
 */
function departmentCapacityForDay(
    mysqli $conn,
    int $departmentID,
    int $dayOfWeek,
    ?string $sessionName = null
): array {
    $summary = ['capacity' => 0, 'slots' => 0, 'doctors' => 0];

    if ($departmentID <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7) {
        return $summary;
    }

    $sql = 'SELECT StartTime, EndTime
            FROM department_schedules
            WHERE DepartmentID = ?
              AND DayOfWeek = ?';

    if ($sessionName !== null && $sessionName !== '') {
        $sql .= ' AND SessionName = ?';
    }

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return $summary;
    }

    if ($sessionName !== null && $sessionName !== '') {
        mysqli_stmt_bind_param($stmt, 'iis', $departmentID, $dayOfWeek, $sessionName);
    } else {
        mysqli_stmt_bind_param($stmt, 'ii', $departmentID, $dayOfWeek);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $slots = 0;
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $slots += capacitySlotCount($row['StartTime'], $row['EndTime']);
    }

    mysqli_stmt_close($stmt);

    // Doctors are counted once for the day; the same doctors staff every
    // session, so each session can hold one patient per doctor per slot.
    $doctors = capacityAvailableDoctors($conn, $departmentID, $dayOfWeek);

    $summary['slots']    = $slots;
    $summary['doctors']  = $doctors;
    $summary['capacity'] = $slots * $doctors;

    return $summary;
}

/**
 * Capacity summed across every department for one weekday.
 *
 * Used by the admin dashboard, which shows totalled morning / afternoon
 * capacity rather than a single department.
 *
 * @return array{capacity:int, slots:int, doctors:int}
 */
function allDepartmentsCapacityForDay(
    mysqli $conn,
    int $dayOfWeek,
    ?string $sessionName = null
): array {
    $total = ['capacity' => 0, 'slots' => 0, 'doctors' => 0];

    if ($dayOfWeek < 1 || $dayOfWeek > 7) {
        return $total;
    }

    $sql = 'SELECT DepartmentID, StartTime, EndTime
            FROM department_schedules
            WHERE DayOfWeek = ?';

    if ($sessionName !== null && $sessionName !== '') {
        $sql .= ' AND SessionName = ?';
    }

    $sql .= ' ORDER BY DepartmentID';

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return $total;
    }

    if ($sessionName !== null && $sessionName !== '') {
        mysqli_stmt_bind_param($stmt, 'is', $dayOfWeek, $sessionName);
    } else {
        mysqli_stmt_bind_param($stmt, 'i', $dayOfWeek);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $slotsByDepartment = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $deptID = (int) $row['DepartmentID'];
        if (!isset($slotsByDepartment[$deptID])) {
            $slotsByDepartment[$deptID] = 0;
        }
        $slotsByDepartment[$deptID] += capacitySlotCount($row['StartTime'], $row['EndTime']);
    }

    mysqli_stmt_close($stmt);

    foreach ($slotsByDepartment as $deptID => $slots) {
        $doctors = capacityAvailableDoctors($conn, $deptID, $dayOfWeek);

        $total['slots']    += $slots;
        $total['doctors']  += $doctors;
        $total['capacity'] += $slots * $doctors;
    }

    return $total;
}
