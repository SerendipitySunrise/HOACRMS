<?php

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/db_pdo.php';
require_once __DIR__ . '/../../includes/audit_helper.php';
require_once __DIR__ . '/../../includes/admin_notifications.php';
require_once __DIR__ . '/../../includes/appointment_assignment.php';
require_once __DIR__ . '/../../includes/booking_rules_core.php';
require_once __DIR__ . '/../../includes/booking_rules_extra.php';
require_once __DIR__ . '/../../includes/booking_settings.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}

function sendNotification(mysqli $conn, int $userID, string $title, string $message, string $type, int $relatedID, string $relatedTable): void
{
    $stmt = mysqli_prepare($conn, 'INSERT INTO notifications (UserID, Title, Message, Type, RelatedID, RelatedTable, PriorityLevel) VALUES (?, ?, ?, ?, ?, ?, "Medium")');
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'isssis', $userID, $title, $message, $type, $relatedID, $relatedTable);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, 'Invalid request method.');
if (!isset($_SESSION['UserID'])) respond(false, 'Your session has expired. Please log in again.');
if (($_SESSION['RoleName'] ?? '') !== 'Patient') respond(false, 'Unauthorized access.');

try {
    $userID = (int)$_SESSION['UserID'];
    $appointmentID = (int)($_POST['appointment_id'] ?? 0);
    $rescheduleToken = trim((string)($_POST['reschedule_token'] ?? ''));
    $appointmentDate = trim($_POST['appointment_date'] ?? '');
    $appointmentTime = trim($_POST['appointment_time'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    if ($appointmentID <= 0) respond(false, 'Invalid appointment ID.');
    if ($appointmentDate === '' || $appointmentTime === '') respond(false, 'Please select a new appointment date and time.');
    if (strpos($appointmentTime, ':') !== false && strlen($appointmentTime) === 5) $appointmentTime .= ':00';

    $patientStmt = mysqli_prepare($conn, 'SELECT p.PatientID, u.FirstName, u.LastName FROM patients p INNER JOIN users u ON p.UserID = u.UserID WHERE p.UserID = ? LIMIT 1');
    if (!$patientStmt) respond(false, 'Unable to prepare patient query.');
    mysqli_stmt_bind_param($patientStmt, 'i', $userID);
    mysqli_stmt_execute($patientStmt);
    $patientResult = mysqli_stmt_get_result($patientStmt);
    $patient = mysqli_fetch_assoc($patientResult);
    mysqli_stmt_close($patientStmt);
    if (!$patient) respond(false, 'Patient profile not found.');
    $patientID = (int)$patient['PatientID'];

    $apptStmt = mysqli_prepare($conn, 'SELECT a.AppointmentID,a.PatientID,a.StaffID,a.DepartmentID,a.AppointmentDate,a.AppointmentTime,a.Purpose,a.Status,d.DepartmentName FROM appointments a INNER JOIN departments d ON d.DepartmentID=a.DepartmentID WHERE a.AppointmentID = ? AND a.PatientID = ? LIMIT 1');
    if (!$apptStmt) respond(false, 'Unable to prepare appointment query.');
    mysqli_stmt_bind_param($apptStmt, 'ii', $appointmentID, $patientID);
    mysqli_stmt_execute($apptStmt);
    $apptResult = mysqli_stmt_get_result($apptStmt);
    $appointment = $apptResult ? mysqli_fetch_assoc($apptResult) : null;
    mysqli_stmt_close($apptStmt);
    if (!$appointment) respond(false, 'Appointment not found or you do not have permission to reschedule it.');
    if (!in_array($appointment['Status'], ['Pending','Checked In','Called','In Consultation'], true)) {
        respond(false, 'This appointment cannot be rescheduled.');
    }

    $isEmergencyDisruption = ($rescheduleToken !== '' && hash_equals($appointment['reschedule_token'] ?? '', $rescheduleToken));

    $limitStmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM appointment_reschedule_history WHERE AppointmentID = ?');
    mysqli_stmt_bind_param($limitStmt, 'i', $appointmentID);
    mysqli_stmt_execute($limitStmt);
    $limitResult = mysqli_stmt_get_result($limitStmt);
    $limitRow = mysqli_fetch_assoc($limitResult);
    mysqli_stmt_close($limitStmt);
    $rescheduleCount = (int)($limitRow['total'] ?? 0);
    $resLimit = bookingSettingInt($conn, 'reschedule_limit', 2);
    if (!$isEmergencyDisruption && $rescheduleCount >= $resLimit) {
        respond(false, 'This appointment has already been rescheduled the maximum number of times (' . $resLimit . ').');
    }

    $currentApptStart = DateTime::createFromFormat('!Y-m-d H:i:s', $appointment['AppointmentDate'] . ' ' . $appointment['AppointmentTime']);
    if (!$currentApptStart) $currentApptStart = DateTime::createFromFormat('!Y-m-d H:i', $appointment['AppointmentDate'] . ' ' . $appointment['AppointmentTime']);
    $cutoffH = bookingSettingInt($conn, 'cancel_cutoff_hours', 4);
    if (!$isEmergencyDisruption && $currentApptStart && $currentApptStart < (new DateTime())->modify('+' . $cutoffH . ' hours')) {
        respond(false, 'This appointment can only be rescheduled at least ' . $cutoffH . ' hours before it starts.');
    }

    // Follow-up status is server-derived from the doctor's suggested date
    // (never a client flag), and the appointment being moved must not count
    // against itself in the active/per-department/overlap limits.
    $isFollowupNow = hasDoctorSuggestedFollowUpOn(
        $conn,
        $patientID,
        (int) $appointment['DepartmentID'],
        $appointmentDate
    );

    list($okv, $dv) = bookingValidateCore($conn, [
        'patient_id' => $patientID,
        'department_id' => (int)$appointment['DepartmentID'],
        'appointment_date' => $appointmentDate,
        'appointment_time' => $appointmentTime,
    ], null, $isFollowupNow, (int) $appointmentID);
    if (!$okv) respond(false, (string)$dv);
    $newStaffID = (int)($dv['doctor_id'] ?? 0);
    if ($newStaffID <= 0) respond(false, 'This time slot is fully booked. Please select another time.');
    $newSessionName = isset($dv['session']) && $dv['session'] !== '' ? mb_substr((string) $dv['session'], 0, 50) : null;

    $oldDate = $appointment['AppointmentDate'];
    $oldTime = $appointment['AppointmentTime'];
    $departmentName = $appointment['DepartmentName'];
    $oldPurpose = $appointment['Purpose'];
    if ($purpose === '') $purpose = $oldPurpose ?? '';

    mysqli_begin_transaction($conn);
    try {
        $isFollowupFlag = $isFollowupNow ? 1 : 0;
        $updateStmt = mysqli_prepare($conn, 'UPDATE appointments SET AppointmentDate=?, AppointmentTime=?, Session=?, StaffID=?, Status="Pending", Purpose=?, OriginalAppointmentDate=COALESCE(OriginalAppointmentDate,?), OriginalAppointmentTime=COALESCE(OriginalAppointmentTime,?), RescheduledAt=NOW(), RescheduleReason=?, IsFollowup=IF(?, 1, IsFollowup), disruption_reason=NULL, is_emergency_disruption=0, reschedule_token=NULL, RescheduleCount = RescheduleCount+1 WHERE AppointmentID=? AND PatientID=?');
        if (!$updateStmt) throw new Exception('Unable to prepare appointment update.');
        $reasonForBind = $isEmergencyDisruption ? ($reason ?: 'Emergency disruption reschedule') : ($reason ?: 'Patient reschedule');
        // 11 params: date, time, session, doctor, purpose, orig-date, orig-time, reason, is-followup, appointment id, patient id.
        mysqli_stmt_bind_param($updateStmt, 'ssssisssiii', $appointmentDate, $appointmentTime, $newSessionName, $newStaffID, $purpose, $oldDate, $oldTime, $reasonForBind, $isFollowupFlag, $appointmentID, $patientID);
        if (!mysqli_stmt_execute($updateStmt)) throw new Exception('Unable to update appointment.');
        mysqli_stmt_close($updateStmt);

        // History table columns: OriginalDate / OriginalTime / NewDate /
        // NewTime / Reason (no RescheduledBy — the audit trail records who).
        $historyStmt = mysqli_prepare($conn, 'INSERT INTO appointment_reschedule_history (AppointmentID, OriginalDate, OriginalTime, NewDate, NewTime, Reason) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$historyStmt) throw new Exception('Unable to prepare reschedule history.');
        mysqli_stmt_bind_param($historyStmt, 'isssss', $appointmentID, $oldDate, $oldTime, $appointmentDate, $appointmentTime, $reasonForBind);
        if (!mysqli_stmt_execute($historyStmt)) throw new Exception('Unable to record reschedule history.');
        mysqli_stmt_close($historyStmt);

        $patientName = trim($patient['FirstName'] . ' ' . $patient['LastName']);
        $oldDateLabel = date('F j, Y', strtotime($oldDate));
        $oldTimeLabel = date('g:i A', strtotime($oldTime));
        $newDateLabel = date('F j, Y', strtotime($appointmentDate));
        $newTimeLabel = date('g:i A', strtotime($appointmentTime));
        $reschedMsg = 'Your appointment with ' . $patientName . ' has been rescheduled from ' . $oldDateLabel . ' at ' . $oldTimeLabel . ' to ' . $newDateLabel . ' at ' . $newTimeLabel . '.';
        sendNotification($conn, $userID, 'Appointment Rescheduled', $reschedMsg, 'Appointment', $appointmentID, 'appointments');
        foreach (adminNotificationAppointmentRecipientUserIds($conn, $appointmentID) as $recipientUserId) {
            if ($recipientUserId !== $userID) {
                adminNotificationCreateForUser($conn, $recipientUserId, 'Appointment Rescheduled', $reschedMsg, 'Appointment', $appointmentID, 'appointments', 'Medium');
            }
        }
        adminNotificationCreateForActiveAdmins($conn, 'Appointment Rescheduled', $reschedMsg, 'Appointment', $appointmentID, 'appointments', 'Medium');

        mysqli_commit($conn);

        // Spec 9: reschedules are written to the audit trail.
        logAudit(db_pdo(), 'APPOINTMENT_RESCHEDULE', 'appointments', $appointmentID,
            json_encode([
                'date'      => $oldDate,
                'time'      => $oldTime,
                'doctor_id' => (int) $appointment['StaffID'],
                'purpose'   => $oldPurpose,
            ]),
            json_encode([
                'date'            => $appointmentDate,
                'time'            => $appointmentTime,
                'doctor_id'       => $newStaffID,
                'purpose'         => $purpose,
                'reason'          => $reasonForBind,
                'reschedule_no'   => $rescheduleCount + 1,
            ]));

        respond(true, 'Appointment rescheduled successfully.');
    } catch (Exception $e) {
        mysqli_rollback($conn);
        respond(false, $e->getMessage());
    }
} catch (Exception $e) {
    respond(false, $e->getMessage());
}
