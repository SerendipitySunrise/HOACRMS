<?php

require_once __DIR__ . '/booking_rules.php';
require_once __DIR__ . '/booking_rules_extra.php';
require_once __DIR__ . '/booking_session_capacity.php';
require_once __DIR__ . '/hospital_helper.php';
require_once __DIR__ . '/guardian_helper.php';
require_once __DIR__ . '/appointment_assignment.php';

function bookingValidateCore(mysqli $conn, array $input, ?int $guardianUserId = null, bool $isFollowup = false, int $excludeAppointmentId = 0): array
{
    $errors = [];
    $patientId = (int)($input['patient_id'] ?? 0);
    $deptId    = (int)($input['department_id'] ?? 0);
    $date      = trim((string)($input['appointment_date'] ?? ''));
    $time      = trim((string)($input['appointment_time'] ?? ''));
    if (strpos($time, ':') !== false && strlen($time) === 5) {
        $time .= ':00';
    }

    if ($patientId <= 0) $errors[] = 'Patient must have a valid record.';
    if ($deptId <= 0) $errors[] = 'Department required.';
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Invalid appointment date.';
    if ($time === '' || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) $errors[] = 'Invalid appointment time.';
    if (!empty($errors)) return [false, $errors[0]];

    $patient = null;
    // DateOfBirth lives in users for account-linked patients (profile edits write
    // there); child records created without a login keep it on patients only.
    // COALESCE mirrors account_service.php so both sources work.
    $stmt = mysqli_prepare(
        $conn,
        'SELECT p.PatientID, p.UserID,
                COALESCE(u.DateOfBirth, p.DateOfBirth) AS DateOfBirth,
                p.EmergencyContactName, p.EmergencyContactNo
         FROM patients p
         LEFT JOIN users u ON u.UserID = p.UserID
         WHERE p.PatientID = ? LIMIT 1'
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $patientId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $patient = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }
    if (!$patient) return [false, 'Patient not found.'];
    if (empty($patient['DateOfBirth']) || empty($patient['EmergencyContactName']) || empty($patient['EmergencyContactNo'])) {
        return [false, 'Patient must have date of birth and emergency contact.'];
    }

    list($okAge, $mAge) = departmentFitsAge($conn, $deptId, $patient['DateOfBirth']);
    if (!$okAge) return [false, $mAge ?? 'Age does not match department.'];

    if ($guardianUserId && $guardianUserId > 0) {
        list($okG, $mG) = canGuardianBookForPatient($conn, $patientId, $guardianUserId);
        if (!$okG) return [false, $mG];
    }

    if (isHospitalHoliday($conn, $date)) {
        return [false, 'This doctor is on leave on that date.'];
    }
    list($okW, $mW) = inBookingWindow($conn, $date, $time);
    if (!$okW) return [false, $mW];

    list($blk, $mBlk) = isNoShowBlocked($conn, $patientId);
    if ($blk && !$isFollowup) return [false, $mBlk ?? 'Online booking blocked.'];

    $act = countActiveAppointmentsForPatient($conn, $patientId, $excludeAppointmentId);
    $actLim = bookingSettingInt($conn, 'active_appointment_limit', 3);
    if ($act >= $actLim && !$isFollowup) {
        return [false, 'Maximum active appointments reached.'];
    }
    $actDept = countActivePerDepartment($conn, $patientId, $deptId, $excludeAppointmentId);
    $deptLim = bookingSettingInt($conn, 'per_department_limit', 1);
    if ($actDept >= $deptLim && !$isFollowup) {
        return [false, 'One active appointment per department per patient.'];
    }
    if (hasOverlappingForPatient($conn, $patientId, $date, $time, $excludeAppointmentId)) {
        return [false, 'You already have an appointment at this time.'];
    }

    // Working days + session hours + breaks (spec 3): the slot must sit
    // inside a session window for that weekday and outside the break.
    $win = sessionWindowForTime($conn, $deptId, $date, $time);
    if (!$win) {
        return [false, 'This appointment time is not available for the selected department.'];
    }
    if (timeInsideBreak($win, $time)) {
        return [false, 'That time is during the doctor\'s break. Please choose another time.'];
    }
    $sess = (string) $win['SessionName'];

    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    $dow = $dt ? (int)$dt->format('N') : 0;

    // Session capacity counts the whole session window (spec 3:
    // "This session is full." once max_patients_per_session is reached).
    $maxSess = sessionMaxCapacity($conn, $deptId, $dow, $sess);
    $booked  = sessionBookedCount($conn, $deptId, $date, $time, $excludeAppointmentId);
    if ($booked >= $maxSess) {
        return [false, 'This session is full.'];
    }

    // Leave / Off Duty (spec 3). findAssignableDoctor now excludes doctors
    // with a DoctorUnavailability row covering the date; this final check
    // makes the helper's verdict authoritative for the assigned doctor.
    $doc = findAssignableDoctor($conn, $deptId, $date, $time, 0, $excludeAppointmentId);
    if ($doc <= 0) {
        if (!departmentHasDoctorForSlot($conn, $deptId, $date, $time)) {
            return [false, 'This doctor is on leave on that date.'];
        }
        return [false, 'This session is full.'];
    }
    if (isDoctorOnLeave($conn, $doc, $date)) {
        return [false, 'This doctor is on leave on that date.'];
    }
    if (hasActiveAppointmentSameDoctorDay($conn, $patientId, $doc, $date, $excludeAppointmentId)) {
        return [false, 'Same doctor, same day, same patient twice: rejected.'];
    }

    return [true, ['doctor_id' => $doc, 'session' => $sess]];
}
