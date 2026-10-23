<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/db_pdo.php';
require_once __DIR__ . '/../../includes/audit_helper.php';
require_once __DIR__ . '/../../includes/admin_notifications.php';
require_once __DIR__ . '/../../includes/appointment_assignment.php';
require_once __DIR__ . '/../../includes/booking_rules_core.php';
require_once __DIR__ . '/../../includes/booking_rules_extra.php';

header('Content-Type: application/json');

function respond(bool $success, string $message): void
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit();
}

function sendNotification(mysqli $conn, int $userID, string $title, string $message, int $relatedID): void
{
    $stmt = mysqli_prepare($conn,
        'INSERT INTO notifications (UserID, Title, Message, Type, RelatedID, RelatedTable, PriorityLevel) VALUES (?, ?, ?, "Appointment", ?, "appointments", "Medium")');
    if (!$stmt) return;
    mysqli_stmt_bind_param($stmt, 'issi', $userID, $title, $message, $relatedID);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if (!isset($_SESSION['UserID'])) {
    respond(false, 'Your session has expired. Please log in again.');
}
if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    respond(false, 'Unauthorized access.');
}

$userID = (int) $_SESSION['UserID'];

$departmentID = (int) ($_POST['department_id'] ?? 0);
$appointmentDate = trim($_POST['appointment_date'] ?? '');
$appointmentTime = trim($_POST['appointment_time'] ?? '');
$purpose = trim($_POST['purpose'] ?? '');

if ($departmentID <= 0 || $appointmentDate === '' || $appointmentTime === '' || $purpose === '') {
    respond(false, 'Please complete all appointment details.');
}

if (strpos($appointmentTime, ':') !== false && strlen($appointmentTime) === 5) {
    $appointmentTime .= ':00';
}

$patientStmt = mysqli_prepare($conn, 'SELECT PatientID FROM patients WHERE UserID = ? LIMIT 1');
mysqli_stmt_bind_param($patientStmt, 'i', $userID);
mysqli_stmt_execute($patientStmt);
$patientResult = mysqli_stmt_get_result($patientStmt);
$patient = mysqli_fetch_assoc($patientResult);
mysqli_stmt_close($patientStmt);
if (!$patient) {
    respond(false, 'Patient record not found.');
}
$patientID = (int)$patient['PatientID'];

// Follow-up status is server-derived from the doctor's suggested date
// (consultations.FollowUpDate) — never taken from a client flag.
$isFollowup = hasDoctorSuggestedFollowUpOn($conn, $patientID, $departmentID, $appointmentDate);

list($ok2, $data2) = bookingValidateCore($conn, [
    'patient_id' => $patientID,
    'department_id' => $departmentID,
    'appointment_date' => $appointmentDate,
    'appointment_time' => $appointmentTime,
], null, $isFollowup);
if (!$ok2) {
    respond(false, (string)$data2);
}
$staffID = (int)($data2['doctor_id'] ?? 0);
if ($staffID <= 0) {
    respond(false, 'This session is full.');
}

$deptStmt = mysqli_prepare($conn, 'SELECT DepartmentName FROM departments WHERE DepartmentID = ? LIMIT 1');
mysqli_stmt_bind_param($deptStmt, 'i', $departmentID);
mysqli_stmt_execute($deptStmt);
$deptResult = mysqli_stmt_get_result($deptStmt);
$dept = mysqli_fetch_assoc($deptResult);
mysqli_stmt_close($deptStmt);
$departmentName = $dept['DepartmentName'] ?? '';

$isFollowupFlag = $isFollowup ? 1 : 0;

// Get session name from the validated data window
$sessionName = null;
if (is_array($data2) && isset($data2['session'])) {
    $sessionName = $data2['session'];
} elseif (isset($win) && is_array($win) && isset($win['SessionName'])) {
    $sessionName = $win['SessionName'];
}
if ($sessionName !== null && $sessionName !== '') {
    $sessionName = mb_substr((string) $sessionName, 0, 50);
} else {
    $sessionName = null;
}

$insertStmt = mysqli_prepare($conn, 'INSERT INTO appointments (PatientID, StaffID, DepartmentID, AppointmentDate, AppointmentTime, Session, Purpose, Status, BookedByUserID, RescheduleCount, IsFollowup) VALUES (?, ?, ?, ?, ?, ?, ?, "Pending", ?, 0, ?)');
mysqli_stmt_bind_param($insertStmt, 'iiissssii', $patientID, $staffID, $departmentID, $appointmentDate, $appointmentTime, $sessionName, $purpose, $userID, $isFollowupFlag);
if (!mysqli_stmt_execute($insertStmt)) {
    respond(false, 'Unable to book the appointment. Please try again.');
}
$appointmentID = (int)mysqli_insert_id($conn);
mysqli_stmt_close($insertStmt);

$timeLabel = date('g:i A', strtotime($appointmentTime));
$dateLabel = date('F j, Y', strtotime($appointmentDate));
$confirmationMessage = 'Your appointment for ' . $dateLabel . ' at ' . $timeLabel . ' at the ' . $departmentName . ' Department has been confirmed.';

sendNotification($conn, $userID, 'Appointment Confirmed', $confirmationMessage, $appointmentID);
foreach (adminNotificationAppointmentRecipientUserIds($conn, $appointmentID) as $recipientUserId) {
    if ($recipientUserId !== $userID) {
        adminNotificationCreateForUser($conn, $recipientUserId, 'Appointment Assigned', 'A new appointment has been assigned to you for ' . $dateLabel . ' at ' . $timeLabel . '.', 'Appointment', $appointmentID, 'appointments', 'Medium');
    }
}
adminNotificationCreateForActiveAdmins($conn, 'New Appointment', 'A new appointment was booked for ' . $dateLabel . ' at ' . $timeLabel . '.', 'Appointment', $appointmentID, 'appointments', 'Medium');

$reminderStmt = mysqli_prepare($conn, 'INSERT INTO appointment_reminders (AppointmentID, ReminderType, SentAt, SentVia, Status) VALUES (?, "confirmation", NOW(), "app", "sent")');
if ($reminderStmt) {
    mysqli_stmt_bind_param($reminderStmt, 'i', $appointmentID);
    mysqli_stmt_execute($reminderStmt);
    mysqli_stmt_close($reminderStmt);
}

// Spec 9: every booking is written to the audit trail.
logAudit(db_pdo(), 'APPOINTMENT_CREATE', 'appointments', $appointmentID, null, json_encode([
    'patient_id'     => $patientID,
    'department_id'  => $departmentID,
    'date'           => $appointmentDate,
    'time'           => $appointmentTime,
    'doctor_id'      => $staffID,
    'purpose'        => $purpose,
    'booked_by_user' => $userID,
]));

respond(true, 'Appointment booked successfully.');
