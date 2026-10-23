<?php

/**
 * Shared doctor auto-assignment for appointments.
 *
 * Single source of truth for "which doctor is in charge of this
 * appointment". It is intentionally logic-only: no schema, no UI, no new
 * fields. Used by:
 *
 *   - api/appointments/save_appointment.php      (new booking)
 *   - api/appointments/reschedule_appointment.php (reschedule fallback)
 *   - patient/book_appointment.php               (legacy no-JS booking block)
 *
 * The rule:
 *   1. Prefer the doctor the patient saw most recently. On a reschedule the
 *      caller passes the appointment's current doctor instead. This doctor
 *      is used only when they are actually assignable for the slot.
 *   2. Otherwise pick the assignable doctor with the fewest active
 *      appointments on that date.
 *   3. Ties are broken by the lowest StaffID.
 *   4. If nobody is assignable, return 0 and let the caller show its
 *      existing "slot is fully booked" message.
 *
 * "Assignable" means, at the requested slot: same department,
 * AvailabilityStatus = 'Available', StaffRole = 'Doctor', covered by the
 * department_schedules window for that weekday and time, and no other
 * active appointment at the exact date and time.
 *
 * "Active" appointment means Status is neither Cancelled nor Completed,
 * matching the status values used elsewhere in the codebase.
 *
 * Cancelled and No Show appointments never decide the "previous doctor".
 */

require_once __DIR__ . '/status_constants.php';

/**
 * SQL fragment listing the appointment statuses that are NOT active.
 */
function appointmentAssignmentActiveExclusion(): string
{
    return '"' . APPT_STATUS_CANCELLED . '", "' . APPT_STATUS_COMPLETED . '"';
}

/**
 * The doctor the patient saw most recently, within one department.
 *
 * Scoped to $departmentID so a visit to a different department does not
 * cancel out continuity: when the patient returns to this department, the
 * doctor they saw here is still preferred.
 *
 * Only appointments up to today are considered, and Cancelled / No Show
 * appointments are ignored, so a future or abandoned booking cannot be
 * mistaken for the doctor the patient actually saw.
 *
 * Returns 0 when the patient has no usable history in this department.
 */
function findPatientPreviousDoctor(mysqli $conn, int $patientID, int $departmentID): int
{
    if ($patientID <= 0 || $departmentID <= 0) {
        return 0;
    }

    $excludedStatuses = '"' . APPT_STATUS_CANCELLED . '", "' . APPT_STATUS_NO_SHOW . '"';

    $stmt = mysqli_prepare(
        $conn,
        'SELECT a.StaffID
         FROM appointments a
         WHERE a.PatientID = ?
           AND a.DepartmentID = ?
           AND a.StaffID IS NOT NULL
           AND a.StaffID > 0
           AND a.Status NOT IN (' . $excludedStatuses . ')
           AND a.AppointmentDate <= CURDATE()
         ORDER BY a.AppointmentDate DESC, a.AppointmentTime DESC, a.AppointmentID DESC
         LIMIT 1'
    );

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $patientID, $departmentID);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return (int) ($row['StaffID'] ?? 0);
}

/**
 * Is the department staffed for this slot at all?
 *
 * Ignores how busy the doctors are, so callers can tell "no doctor is
 * scheduled for this department/time" apart from "every doctor is already
 * booked". Used to preserve the original booking-page error messages.
 */
function departmentHasDoctorForSlot(
    mysqli $conn,
    int $departmentID,
    string $appointmentDate,
    string $appointmentTime
): bool {
    if ($departmentID <= 0 || $appointmentDate === '' || $appointmentTime === '') {
        return false;
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $appointmentDate);

    if (!$dateObject || $dateObject->format('Y-m-d') !== $appointmentDate) {
        return false;
    }

    $dayOfWeek = (int) $dateObject->format('N');

    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1
         FROM staff s
         WHERE s.DepartmentID = ?
           AND s.AvailabilityStatus = "Available"
           AND s.StaffRole = "Doctor"
           AND EXISTS (
               SELECT 1 FROM department_schedules ds
               WHERE ds.DepartmentID = s.DepartmentID
                 AND ds.DayOfWeek = ?
                 AND ds.StartTime <= ?
                 AND ds.EndTime > ?
           )
           AND NOT EXISTS (
               SELECT 1 FROM doctorunavailability du
               WHERE du.StartDate <= ?
                 AND du.EndDate >= ?
                 AND (du.DoctorID = s.StaffID
                      OR (du.DepartmentID = s.DepartmentID AND du.DoctorID IS NULL))
           )
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'iissss',
        $departmentID,
        $dayOfWeek,
        $appointmentTime,
        $appointmentTime,
        $appointmentDate,
        $appointmentDate
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $found = $result ? mysqli_num_rows($result) > 0 : false;

    mysqli_stmt_close($stmt);

    return $found;
}

/**
 * Resolve the doctor to assign to an appointment.
 *
 * @param int $preferStaffID        Doctor to try first (patient's previous
 *                                  doctor, or current doctor on reschedule).
 *                                  Pass 0 to skip straight to step 2.
 * @param int $excludeAppointmentID Appointment being moved; ignored in the
 *                                  collision/load checks (reschedule only).
 *
 * @return int StaffID, or 0 when no doctor is assignable.
 */
function findAssignableDoctor(
    mysqli $conn,
    int $departmentID,
    string $appointmentDate,
    string $appointmentTime,
    int $preferStaffID = 0,
    int $excludeAppointmentID = 0
): int {
    if ($departmentID <= 0 || $appointmentDate === '' || $appointmentTime === '') {
        return 0;
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $appointmentDate);

    if (!$dateObject || $dateObject->format('Y-m-d') !== $appointmentDate) {
        return 0;
    }

    $dayOfWeek = (int) $dateObject->format('N');
    $active = appointmentAssignmentActiveExclusion();

    /*
     * Conditions every candidate doctor must satisfy for this slot. The
     * placeholder order is: DepartmentID, DayOfWeek, StartTime, EndTime,
     * leave range (StartDate, EndDate). Doctors with a DoctorUnavailability
     * row covering the date (their own, or a department-wide closure) are
     * excluded so an on-leave doctor is never assigned.
     */
    $assignable = 's.DepartmentID = ?
                   AND s.AvailabilityStatus = "Available"
                   AND s.StaffRole = "Doctor"
                   AND EXISTS (
                       SELECT 1 FROM department_schedules ds
                       WHERE ds.DepartmentID = s.DepartmentID
                         AND ds.DayOfWeek = ?
                         AND ds.StartTime <= ?
                         AND ds.EndTime > ?
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM doctorunavailability du
                       WHERE du.StartDate <= ?
                         AND du.EndDate >= ?
                         AND (du.DoctorID = s.StaffID
                              OR (du.DepartmentID = s.DepartmentID AND du.DoctorID IS NULL))
                   )';

    // ------------------------------------------------------------------
    // Step 1: the preferred doctor, when assignable.
    // ------------------------------------------------------------------

    if ($preferStaffID > 0) {
        $preferStmt = mysqli_prepare(
            $conn,
            'SELECT s.StaffID
             FROM staff s
             WHERE s.StaffID = ?
               AND ' . $assignable . '
               AND NOT EXISTS (
                   SELECT 1 FROM appointments a
                   WHERE a.StaffID = s.StaffID
                     AND a.AppointmentDate = ?
                     AND a.AppointmentTime = ?
                     AND a.Status NOT IN (' . $active . ')
                     AND (? = 0 OR a.AppointmentID <> ?)
               )
             LIMIT 1'
        );

        if ($preferStmt) {
            mysqli_stmt_bind_param(
                $preferStmt,
                'iiisssssssii',
                $preferStaffID,
                $departmentID,
                $dayOfWeek,
                $appointmentTime,
                $appointmentTime,
                $appointmentDate,
                $appointmentDate,
                $appointmentDate,
                $appointmentTime,
                $excludeAppointmentID,
                $excludeAppointmentID
            );

            mysqli_stmt_execute($preferStmt);

            $preferResult = mysqli_stmt_get_result($preferStmt);
            $preferRow = $preferResult ? mysqli_fetch_assoc($preferResult) : null;

            mysqli_stmt_close($preferStmt);

            if ($preferRow) {
                return (int) $preferRow['StaffID'];
            }
        }
    }

    // ------------------------------------------------------------------
    // Step 2 + 3: least-loaded assignable doctor, tie -> lowest StaffID.
    // ------------------------------------------------------------------

    $assignStmt = mysqli_prepare(
        $conn,
        'SELECT s.StaffID,
                (
                    SELECT COUNT(*)
                    FROM appointments a
                    WHERE a.StaffID = s.StaffID
                      AND a.AppointmentDate = ?
                      AND a.Status NOT IN (' . $active . ')
                      AND (? = 0 OR a.AppointmentID <> ?)
                ) AS ActiveCount
         FROM staff s
         WHERE ' . $assignable . '
           AND NOT EXISTS (
               SELECT 1 FROM appointments a
               WHERE a.StaffID = s.StaffID
                 AND a.AppointmentDate = ?
                 AND a.AppointmentTime = ?
                 AND a.Status NOT IN (' . $active . ')
                 AND (? = 0 OR a.AppointmentID <> ?)
           )
         ORDER BY ActiveCount ASC, s.StaffID ASC
         LIMIT 1'
    );

    if (!$assignStmt) {
        return 0;
    }

    mysqli_stmt_bind_param(
        $assignStmt,
        'siiiissssssii',
        $appointmentDate,
        $excludeAppointmentID,
        $excludeAppointmentID,
        $departmentID,
        $dayOfWeek,
        $appointmentTime,
        $appointmentTime,
        $appointmentDate,
        $appointmentDate,
        $appointmentDate,
        $appointmentTime,
        $excludeAppointmentID,
        $excludeAppointmentID
    );

    mysqli_stmt_execute($assignStmt);

    $assignResult = mysqli_stmt_get_result($assignStmt);
    $assignRow = $assignResult ? mysqli_fetch_assoc($assignResult) : null;

    mysqli_stmt_close($assignStmt);

    return (int) ($assignRow['StaffID'] ?? 0);
}
