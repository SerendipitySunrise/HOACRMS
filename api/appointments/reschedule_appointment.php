<?php

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_notifications.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));

    exit();
}

function sendNotification(
    mysqli $conn,
    int $userID,
    string $title,
    string $message,
    string $type,
    int $relatedID,
    string $relatedTable
): void {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO notifications
            (UserID, Title, Message, Type, RelatedID, RelatedTable, PriorityLevel)
         VALUES (?, ?, ?, ?, ?, ?, "Medium")'
    );

    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'isssis',
        $userID,
        $title,
        $message,
        $type,
        $relatedID,
        $relatedTable
    );

    mysqli_stmt_execute($stmt);

    mysqli_stmt_close($stmt);
}

// Only POST requests are allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

// Check login
if (!isset($_SESSION['UserID'])) {
    respond(false, 'Your session has expired. Please log in again.');
}

// Check patient role
if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    respond(false, 'Unauthorized access.');
}

try {

    $userID = (int) $_SESSION['UserID'];
    $appointmentID = (int) ($_POST['appointment_id'] ?? 0);
    $rescheduleToken = trim((string) ($_POST['reschedule_token'] ?? ''));
    $appointmentDate = trim($_POST['appointment_date'] ?? '');
    $appointmentTime = trim($_POST['appointment_time'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');

    if ($appointmentID <= 0) {
        respond(false, 'Invalid appointment ID.');
    }

    if ($appointmentDate === '' || $appointmentTime === '') {
        respond(false, 'Please select a new appointment date and time.');
    }


    // -------------------------------------------------
    // GET PATIENT ID
    // -------------------------------------------------

    $patientStmt = mysqli_prepare(
        $conn,
        'SELECT
            p.PatientID,
            u.FirstName,
            u.LastName
         FROM patients p
         INNER JOIN users u ON p.UserID = u.UserID
         WHERE p.UserID = ?
         LIMIT 1'
    );

    if (!$patientStmt) {
        respond(false, 'Unable to prepare patient query.');
    }

    mysqli_stmt_bind_param($patientStmt, 'i', $userID);
    mysqli_stmt_execute($patientStmt);

    $patientResult = mysqli_stmt_get_result($patientStmt);
    $patient = mysqli_fetch_assoc($patientResult);

    mysqli_stmt_close($patientStmt);

    if (!$patient) {
        respond(false, 'Patient profile not found.');
    }

    $patientID = (int) $patient['PatientID'];


    // -------------------------------------------------
    // LOAD APPOINTMENT
    // -------------------------------------------------

    $apptStmt = mysqli_prepare(
        $conn,
        'SELECT
            a.AppointmentID,
            a.PatientID,
            a.StaffID,
            a.DepartmentID,
            a.AppointmentDate,
            a.AppointmentTime,
            a.Purpose,
            a.Status,
            a.disruption_reason,
            a.is_emergency_disruption,
            a.reschedule_token,
            d.DepartmentName
         FROM appointments a
         INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
         WHERE a.AppointmentID = ?
           AND a.PatientID = ?
         LIMIT 1'
    );

    if (!$apptStmt) {
        respond(false, 'Unable to prepare appointment query.');
    }

    mysqli_stmt_bind_param($apptStmt, 'ii', $appointmentID, $patientID);
    mysqli_stmt_execute($apptStmt);

    $apptResult = mysqli_stmt_get_result($apptStmt);
    $appointment = mysqli_fetch_assoc($apptResult);

    mysqli_stmt_close($apptStmt);

    if (!$appointment) {
        respond(false, 'Appointment not found or does not belong to you.');
    }


    // -------------------------------------------------
    // BUSINESS RULES (status gate)
    // -------------------------------------------------

    $currentStatus = $appointment['Status'];
    $hasDisruptionAccess = $currentStatus === 'Cancelled'
        && $appointment['disruption_reason'] !== null
        && $appointment['disruption_reason'] !== ''
        && $rescheduleToken !== ''
        && hash_equals((string) $appointment['reschedule_token'], $rescheduleToken);
    $isEmergencyDisruption = $hasDisruptionAccess && (int) $appointment['is_emergency_disruption'] === 1;

    if (in_array($currentStatus, ['Checked In', 'Called', 'In Consultation'], true)) {
        respond(false, 'This appointment cannot be rescheduled because you have already arrived at the clinic.');
    }

    if ($currentStatus === 'Completed') {
        respond(false, 'Completed appointments cannot be rescheduled.');
    }

    if ($currentStatus === 'Cancelled' && !$hasDisruptionAccess) {
        respond(false, 'Cancelled appointments cannot be rescheduled. Please book a new appointment.');
    }

    if ($currentStatus === 'No Show') {
        respond(false, 'This appointment was marked as a no-show and cannot be rescheduled.');
    }

    // Max 3 reschedules per appointment
    $limitStmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS total
         FROM appointment_reschedule_history
         WHERE AppointmentID = ?'
    );

    mysqli_stmt_bind_param($limitStmt, 'i', $appointmentID);
    mysqli_stmt_execute($limitStmt);

    $limitResult = mysqli_stmt_get_result($limitStmt);
    $limitRow = mysqli_fetch_assoc($limitResult);

    mysqli_stmt_close($limitStmt);

    $rescheduleCount = (int) ($limitRow['total'] ?? 0);

    if (!$isEmergencyDisruption && $rescheduleCount >= 3) {
        respond(
            false,
            'This appointment has already been rescheduled the maximum number of times (3).'
        );
    }

    // Rescheduling is only allowed up to 24 hours before the appointment
    $currentApptStart = DateTime::createFromFormat(
        '!Y-m-d H:i:s',
        $appointment['AppointmentDate'] . ' ' . $appointment['AppointmentTime']
    );

    if (!$currentApptStart) {
        $currentApptStart = DateTime::createFromFormat(
            '!Y-m-d H:i',
            $appointment['AppointmentDate'] . ' ' . $appointment['AppointmentTime']
        );
    }

    if (!$isEmergencyDisruption && $currentApptStart && $currentApptStart < (new DateTime())->modify('+24 hours')) {
        respond(
            false,
            'This appointment can only be rescheduled at least 24 hours before it starts.'
        );
    }


    // -------------------------------------------------
    // VALIDATE NEW DATE / TIME
    // -------------------------------------------------

    $dateObject = DateTime::createFromFormat('!Y-m-d', $appointmentDate);

    if (!$dateObject || $dateObject->format('Y-m-d') !== $appointmentDate) {
        respond(false, 'Invalid appointment date.');
    }

    $timeObject = DateTime::createFromFormat('!H:i', $appointmentTime);

    if (!$timeObject) {
        $timeObject = DateTime::createFromFormat('!H:i:s', $appointmentTime);
    }

    if (!$timeObject) {
        respond(false, 'Invalid appointment time.');
    }

    $appointmentDate = $dateObject->format('Y-m-d');
    $appointmentTime = $timeObject->format('H:i:s');

    $today = new DateTime('today');

    if ($dateObject < $today) {
        respond(false, 'Appointments cannot be rescheduled to a past date.');
    }

    if ($dateObject == $today && $timeObject <= new DateTime('now')) {
        respond(false, 'Please select a time that has not already passed.');
    }

    $departmentID = (int) $appointment['DepartmentID'];
    $dayOfWeek = (int) $dateObject->format('N');

    // Check that the new slot falls within the department schedule
    $scheduleStmt = mysqli_prepare(
        $conn,
        'SELECT StartTime, EndTime
         FROM department_schedules
         WHERE DepartmentID = ?
           AND DayOfWeek = ?'
    );

    mysqli_stmt_bind_param($scheduleStmt, 'ii', $departmentID, $dayOfWeek);
    mysqli_stmt_execute($scheduleStmt);

    $scheduleResult = mysqli_stmt_get_result($scheduleStmt);

    $allowedTimes = [];

    while ($schedule = mysqli_fetch_assoc($scheduleResult)) {
        $start = new DateTime($schedule['StartTime']);
        $end = new DateTime($schedule['EndTime']);

        while ($start < $end) {
            $allowedTimes[] = $start->format('H:i:s');
            $start->modify('+30 minutes');
        }
    }

    mysqli_stmt_close($scheduleStmt);

    if (!in_array($appointmentTime, $allowedTimes, true)) {
        respond(false, 'This appointment time is not available for the selected department.');
    }


    // -------------------------------------------------
    // PATIENT CONFLICT CHECK (excludes this appointment)
    // -------------------------------------------------

    $conflictStmt = mysqli_prepare(
        $conn,
        'SELECT AppointmentID
         FROM appointments
         WHERE PatientID = ?
           AND AppointmentDate = ?
           AND AppointmentTime = ?
           AND AppointmentID <> ?
           AND Status NOT IN ("Cancelled", "Completed")
         LIMIT 1'
    );

    mysqli_stmt_bind_param(
        $conflictStmt,
        'issi',
        $patientID,
        $appointmentDate,
        $appointmentTime,
        $appointmentID
    );

    mysqli_stmt_execute($conflictStmt);

    $conflictResult = mysqli_stmt_get_result($conflictStmt);

    mysqli_stmt_close($conflictStmt);

    if (mysqli_num_rows($conflictResult) > 0) {
        respond(false, 'You already have another appointment at this date and time.');
    }


    // -------------------------------------------------
    // STAFF / DOCTOR ASSIGNMENT
    // Prefer the current doctor if available at the new slot,
    // otherwise pick any available doctor in the department.
    // -------------------------------------------------

    $oldStaffID = (int) ($appointment['StaffID'] ?? 0);
    $newStaffID = 0;

    if ($oldStaffID > 0) {
        $keepStmt = mysqli_prepare(
            $conn,
            'SELECT s.StaffID
             FROM staff s
             WHERE s.StaffID = ?
               AND s.DepartmentID = ?
               AND s.AvailabilityStatus = "Available"
               AND s.StaffRole = "Doctor"
               AND NOT EXISTS (
                   SELECT 1
                   FROM appointments a
                   WHERE a.StaffID = s.StaffID
                     AND a.AppointmentDate = ?
                     AND a.AppointmentTime = ?
                     AND a.Status NOT IN ("Cancelled", "Completed")
                     AND a.AppointmentID <> ?
               )
             LIMIT 1'
        );

        mysqli_stmt_bind_param(
            $keepStmt,
            'iissi',
            $oldStaffID,
            $departmentID,
            $appointmentDate,
            $appointmentTime,
            $appointmentID
        );

        mysqli_stmt_execute($keepStmt);

        $keepResult = mysqli_stmt_get_result($keepStmt);
        $keep = mysqli_fetch_assoc($keepResult);

        mysqli_stmt_close($keepStmt);

        if ($keep) {
            $newStaffID = (int) $keep['StaffID'];
        }
    }

    if ($newStaffID === 0) {
        $assignStmt = mysqli_prepare(
            $conn,
            'SELECT s.StaffID
             FROM staff s
             WHERE s.DepartmentID = ?
               AND s.AvailabilityStatus = "Available"
               AND s.StaffRole = "Doctor"
               AND NOT EXISTS (
                   SELECT 1
                   FROM appointments a
                   WHERE a.StaffID = s.StaffID
                     AND a.AppointmentDate = ?
                     AND a.AppointmentTime = ?
                     AND a.Status NOT IN ("Cancelled", "Completed")
                     AND a.AppointmentID <> ?
               )
             ORDER BY s.StaffID ASC
             LIMIT 1'
        );

        mysqli_stmt_bind_param(
            $assignStmt,
            'issi',
            $departmentID,
            $appointmentDate,
            $appointmentTime,
            $appointmentID
        );

        mysqli_stmt_execute($assignStmt);

        $assignResult = mysqli_stmt_get_result($assignStmt);
        $assign = mysqli_fetch_assoc($assignResult);

        mysqli_stmt_close($assignStmt);

        if ($assign) {
            $newStaffID = (int) $assign['StaffID'];
        }
    }

    if ($newStaffID === 0) {
        respond(false, 'This time slot is fully booked. Please select another time.');
    }


    // -------------------------------------------------
    // RESULT DETAILS (for notification + response)
    // -------------------------------------------------

    $oldDate = $appointment['AppointmentDate'];
    $oldTime = $appointment['AppointmentTime'];
    $departmentName = $appointment['DepartmentName'];
    $oldPurpose = $appointment['Purpose'];

    if ($purpose === '') {
        $purpose = $oldPurpose ?? '';
    }

    $newDateTime = new DateTime($appointmentDate . ' ' . $appointmentTime);
    $within24h = $newDateTime < (new DateTime())->modify('+24 hours');


    // -------------------------------------------------
    // APPLY CHANGES (transaction)
    // -------------------------------------------------

    mysqli_begin_transaction($conn);

    try {

        // Re-open the appointment for booking flow.
        $updateStmt = mysqli_prepare(
            $conn,
            'UPDATE appointments
             SET AppointmentDate = ?,
                 AppointmentTime = ?,
                 StaffID = ?,
                 Status = "Pending",
                 Purpose = ?,
                 OriginalAppointmentDate =
                     COALESCE(OriginalAppointmentDate, ?),
                 OriginalAppointmentTime =
                     COALESCE(OriginalAppointmentTime, ?),
                     RescheduledAt = NOW(),
                     RescheduleReason = ?,
                     disruption_reason = NULL,
                     is_emergency_disruption = 0,
                     reschedule_token = NULL
             WHERE AppointmentID = ?
               AND PatientID = ?'
        );

        if (!$updateStmt) {
            throw new Exception('Unable to prepare appointment update.');
        }

        $newStaffIDForBind = $newStaffID > 0 ? $newStaffID : null;

        mysqli_stmt_bind_param(
            $updateStmt,
            'ssissssii',
            $appointmentDate,
            $appointmentTime,
            $newStaffIDForBind,
            $purpose,
            $oldDate,
            $oldTime,
            $reason,
            $appointmentID,
            $patientID
        );

        if (!mysqli_stmt_execute($updateStmt)) {
            throw new Exception('Unable to update the appointment.');
        }

        $affected = mysqli_stmt_affected_rows($updateStmt);

        mysqli_stmt_close($updateStmt);

        if ($affected !== 1) {
            throw new Exception(
                'The appointment could not be rescheduled. Please try again.'
            );
        }

        // Audit trail entry for this reschedule.
        $historyStmt = mysqli_prepare(
            $conn,
            'INSERT INTO appointment_reschedule_history
                (AppointmentID, OriginalDate, OriginalTime,
                 NewDate, NewTime, Reason)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        if (!$historyStmt) {
            throw new Exception('Unable to prepare reschedule history query.');
        }

        $reasonForBind = $reason === '' ? null : $reason;

        mysqli_stmt_bind_param(
            $historyStmt,
            'isssss',
            $appointmentID,
            $oldDate,
            $oldTime,
            $appointmentDate,
            $appointmentTime,
            $reasonForBind
        );

        if (!mysqli_stmt_execute($historyStmt)) {
            throw new Exception('Unable to record reschedule history.');
        }

        mysqli_stmt_close($historyStmt);


        // -------------------------------------------------
        // NOTIFY PATIENT
        // -------------------------------------------------

        $patientName = trim($patient['FirstName'] . ' ' . $patient['LastName']);

        $oldDateLabel = date('F j, Y', strtotime($oldDate));
        $oldTimeLabel = date('g:i A', strtotime($oldTime));
        $newDateLabel = date('F j, Y', strtotime($appointmentDate));
        $newTimeLabel = date('g:i A', strtotime($appointmentTime));

        sendNotification(
            $conn,
            $userID,
            'Appointment Rescheduled',
            "Your appointment for {$departmentName} has been rescheduled from " .
            "{$oldDateLabel} at {$oldTimeLabel} to {$newDateLabel} at {$newTimeLabel}.",
            'Appointment',
            $appointmentID,
            'appointments'
        );


        // -------------------------------------------------
        // NOTIFY ASSIGNED DOCTOR/STAFF
        // -------------------------------------------------

        $staffNotify = function (int $staffID, string $prefixMsg) use (
            $conn,
            $appointmentID,
            $patientName,
            $departmentName,
            $newDateLabel,
            $newTimeLabel,
            $oldDateLabel,
            $oldTimeLabel
        ): void {
            $staffStmt = mysqli_prepare(
                $conn,
                'SELECT u.UserID, u.FirstName, u.LastName, u.Email
                 FROM staff s
                 INNER JOIN users u ON s.UserID = u.UserID
                 WHERE s.StaffID = ?
                 LIMIT 1'
            );

            if (!$staffStmt) {
                return;
            }

            mysqli_stmt_bind_param($staffStmt, 'i', $staffID);
            mysqli_stmt_execute($staffStmt);

            $staffResult = mysqli_stmt_get_result($staffStmt);
            $staffRow = mysqli_fetch_assoc($staffResult);

            mysqli_stmt_close($staffStmt);

            if (!$staffRow) {
                return;
            }

            $msg = '' === $prefixMsg
                ? "{$patientName}'s appointment for {$departmentName} has been " .
                  "rescheduled to {$newDateLabel} at {$newTimeLabel} " .
                  "(previously {$oldDateLabel} at {$oldTimeLabel})."
                : $prefixMsg;

            sendNotification(
                $conn,
                (int) $staffRow['UserID'],
                'Appointment Rescheduled',
                $msg,
                'Appointment',
                $appointmentID,
                'appointments'
            );

            // Email notification to the doctor (best effort; never blocks the reschedule)
            if (!empty($staffRow['Email'])) {
                $mailerPath = __DIR__ . '/../../includes/mailer.php';

                if (is_file($mailerPath) && !function_exists('sendCuroraEmail')) {
                    require_once $mailerPath;
                }

                if (function_exists('sendCuroraEmail')) {
                    $doctorName = trim(($staffRow['FirstName'] ?? '') . ' ' . ($staffRow['LastName'] ?? ''));
                    $subject = 'Appointment Rescheduled - ' . $departmentName;

                    $emailBody =
                        '<p>Hello' . ($doctorName !== '' ? ' ' . htmlspecialchars($doctorName) : '') . ',</p>' .
                        '<p>' . htmlspecialchars($msg) . '</p>' .
                        '<p>Patient: <strong>' . htmlspecialchars($patientName) . '</strong><br>' .
                        'Department: ' . htmlspecialchars($departmentName) . '</p>';

                    sendCuroraEmail(
                        $staffRow['Email'],
                        $subject,
                        $emailBody
                    );
                }
            }
        };

        if ($newStaffID === $oldStaffID) {
            if ($oldStaffID > 0) {
                $staffNotify($oldStaffID, '');
            }
        } else {
            if ($oldStaffID > 0) {
                $staffNotify(
                    $oldStaffID,
                    "{$patientName} has rescheduled their appointment and is no " .
                    "longer assigned to you ({$departmentName}, previously " .
                    "{$oldDateLabel} at {$oldTimeLabel})."
                );
            }

            if ($newStaffID > 0) {
                $staffNotify($newStaffID, '');
            }
        }


        mysqli_commit($conn);

        adminNotificationCreateForActiveAdmins(
            $conn,
            'Appointment Rescheduled',
            $patientName . "'s appointment for " . $departmentName . ' was rescheduled to '
            . $newDateLabel . ' at ' . $newTimeLabel . '.',
            'Appointment',
            $appointmentID,
            'appointments',
            'Medium'
        );

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        error_log(
            'Reschedule appointment error: ' .
            $e->getMessage()
        );

        respond(
            false,
            'A database error occurred while rescheduling the appointment.'
        );
    }

    $extra = [];

    if ($within24h) {
        $extra['within_24h'] = true;
    }

    respond(
        true,
        'Appointment rescheduled successfully.',
        $extra
    );

} catch (Throwable $e) {

    error_log('Reschedule appointment error: ' . $e->getMessage());

    respond(
        false,
        'A database error occurred while rescheduling the appointment.'
    );
}