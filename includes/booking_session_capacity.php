<?php

/**
 * Session windows, breaks and session capacity (spec 1 + 3).
 *
 * A "session" is one department_schedules row (DepartmentID + DayOfWeek +
 * SessionName with a StartTime/EndTime window, optional break, optional
 * per-session patient cap). Capacity is counted across the WHOLE window for
 * the department, not at a single clock time.
 */

require_once __DIR__ . '/status_constants.php';
require_once __DIR__ . '/booking_settings.php';

/** Comma-quoted list of appointment statuses that still occupy a slot. */
function bookingActiveStatuses(): string
{
    return '"' . APPT_STATUS_PENDING . '", "'
        . APPT_STATUS_CHECKED_IN . '", "'
        . APPT_STATUS_CALLED . '", "'
        . APPT_STATUS_IN_CONSULTATION . '"';
}

/**
 * Every session window the department runs on the given weekday.
 *
 * @return array<int, array{SessionName:string,StartTime:string,EndTime:string,
 *                          BreakStart:?string,BreakEnd:?string,
 *                          MaxPatientsPerSession:?int}>
 */
function departmentSessionsForDay(mysqli $conn, int $departmentId, int $dayOfWeek): array
{
    $rows = [];
    if ($departmentId <= 0 || $dayOfWeek < 1 || $dayOfWeek > 7) {
        return $rows;
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT SessionName, StartTime, EndTime, BreakStart, BreakEnd, MaxPatientsPerSession
         FROM department_schedules
         WHERE DepartmentID = ? AND DayOfWeek = ?
         ORDER BY StartTime'
    );
    if (!$stmt) {
        return $rows;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $departmentId, $dayOfWeek);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $rows;
}

/**
 * The session window containing $time on $date, or null when the slot lies
 * outside every window (department closed that day, or after hours).
 * TIME strings compare correctly as 'H:i:s' strings.
 */
function sessionWindowForTime(mysqli $conn, int $departmentId, string $date, string $time): ?array
{
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt) {
        return null;
    }

    foreach (departmentSessionsForDay($conn, $departmentId, (int) $dt->format('N')) as $session) {
        if ($time >= $session['StartTime'] && $time < $session['EndTime']) {
            return $session;
        }
    }

    return null;
}

/** Does $time fall inside this session's break? */
function timeInsideBreak(array $session, string $time): bool
{
    if (($session['BreakStart'] ?? null) === null || ($session['BreakEnd'] ?? null) === null) {
        return false;
    }
    return $time >= $session['BreakStart'] && $time < $session['BreakEnd'];
}

/**
 * Active appointments already inside an explicit session window
 * (department-wide, every doctor). Uses [start, end).
 * $excludeAppointmentId keeps an appointment that is being rescheduled
 * from counting against itself.
 */
function sessionWindowBookedCount(
    mysqli $conn,
    int $departmentId,
    string $date,
    string $start,
    string $end,
    int $excludeAppointmentId = 0
): int {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS c
         FROM appointments
         WHERE DepartmentID = ?
           AND AppointmentDate = ?
           AND AppointmentTime >= ?
           AND AppointmentTime < ?
           AND (? = 0 OR AppointmentID <> ?)
           AND Status IN (' . bookingActiveStatuses() . ')'
    );
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'isssii', $departmentId, $date, $start, $end, $excludeAppointmentId, $excludeAppointmentId);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return (int) ($row['c'] ?? 0);
}

/**
 * Active appointments inside the session window that contains $time.
 * Returns 0 when no window contains the time (caller should already have
 * rejected that slot via sessionWindowForTime()).
 */
function sessionBookedCount(mysqli $conn, int $departmentId, string $date, string $time, int $excludeAppointmentId = 0): int
{
    $session = sessionWindowForTime($conn, $departmentId, $date, $time);
    if (!$session) {
        return 0;
    }

    return sessionWindowBookedCount(
        $conn,
        $departmentId,
        $date,
        $session['StartTime'],
        $session['EndTime'],
        $excludeAppointmentId
    );
}

/**
 * Capacity for one session: the row's MaxPatientsPerSession override when
 * set, otherwise the max_patients_per_session setting. Never hard-coded.
 */
function sessionMaxCapacity(mysqli $conn, int $departmentId, int $dayOfWeek, string $sessionName = ''): int
{
    if ($sessionName !== '') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT MaxPatientsPerSession
             FROM department_schedules
             WHERE DepartmentID = ? AND DayOfWeek = ? AND SessionName = ?
             LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iis', $departmentId, $dayOfWeek, $sessionName);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if ($row && $row['MaxPatientsPerSession'] !== null) {
                $n = (int) $row['MaxPatientsPerSession'];
                if ($n > 0) {
                    return $n;
                }
            }
        }
    }

    return bookingSettingInt($conn, 'max_patients_per_session', 20);
}
