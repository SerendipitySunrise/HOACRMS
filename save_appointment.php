<?php
session_start();

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

function respond(bool $success, string $message): void
{
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);

    exit();
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

if ($departmentID <= 0 || !$appointmentDate || !$appointmentTime || !$purpose) {
    respond(false, 'Please complete all appointment details.');
}

$dateObject = DateTime::createFromFormat('Y-m-d', $appointmentDate);
$timeObject = DateTime::createFromFormat('H:i:s', $appointmentTime);

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $appointmentDate
) {
    respond(false, 'Invalid appointment date.');
}

if (
    !$timeObject ||
    $timeObject->format('H:i:s') !== $appointmentTime
) {
    respond(false, 'Invalid appointment time.');
}

$today = new DateTime('today');

if ($dateObject < $today) {
    respond(false, 'Appointments cannot be booked for a past date.');
}

$patientStmt = mysqli_prepare(
    $conn,
    'SELECT PatientID
     FROM patients
     WHERE UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param($patientStmt, 'i', $userID);
mysqli_stmt_execute($patientStmt);

$patientResult = mysqli_stmt_get_result($patientStmt);
$patient = mysqli_fetch_assoc($patientResult);

if (!$patient) {
    respond(false, 'Patient record not found.');
}

$patientID = (int) $patient['PatientID'];
$dayOfWeek = (int) $dateObject->format('N');

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

if (!in_array($appointmentTime, $allowedTimes, true)) {
    respond(false, 'This appointment time is not available for the selected department.');
}

$patientConflictStmt = mysqli_prepare(
    $conn,
    'SELECT AppointmentID
     FROM appointments
     WHERE PatientID = ?
       AND AppointmentDate = ?
       AND AppointmentTime = ?
       AND Status NOT IN ("Cancelled", "Completed")
     LIMIT 1'
);

mysqli_stmt_bind_param(
    $patientConflictStmt,
    'iss',
    $patientID,
    $appointmentDate,
    $appointmentTime
);

mysqli_stmt_execute($patientConflictStmt);

$patientConflictResult = mysqli_stmt_get_result($patientConflictStmt);

if (mysqli_num_rows($patientConflictResult) > 0) {
    respond(false, 'You already have an appointment at this date and time.');
}

$staffStmt = mysqli_prepare(
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
       )
     ORDER BY s.StaffID ASC
     LIMIT 1'
);

mysqli_stmt_bind_param(
    $staffStmt,
    'iss',
    $departmentID,
    $appointmentDate,
    $appointmentTime
);

mysqli_stmt_execute($staffStmt);

$staffResult = mysqli_stmt_get_result($staffStmt);
$staff = mysqli_fetch_assoc($staffResult);

if (!$staff) {
    respond(false, 'This time slot is fully booked. Please select another time.');
}

$staffID = (int) $staff['StaffID'];

$insertStmt = mysqli_prepare(
    $conn,
    'INSERT INTO appointments
    (
        PatientID,
        StaffID,
        DepartmentID,
        AppointmentDate,
        AppointmentTime,
        Purpose,
        Status
    )
    VALUES (?, ?, ?, ?, ?, ?, "Pending")'
);

mysqli_stmt_bind_param(
    $insertStmt,
    'iiisss',
    $patientID,
    $staffID,
    $departmentID,
    $appointmentDate,
    $appointmentTime,
    $purpose
);

if (!mysqli_stmt_execute($insertStmt)) {
    respond(false, 'Unable to book the appointment. Please try again.');
}

respond(true, 'Appointment booked successfully.');