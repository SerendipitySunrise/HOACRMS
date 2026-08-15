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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
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

if ($departmentID <= 0 || $appointmentDate === '' ||
    $appointmentTime === '' || $purpose === '') {
    respond(false, 'Please complete all appointment details.');
}

$dateObject = DateTime::createFromFormat('Y-m-d', $appointmentDate);
$timeObject = DateTime::createFromFormat('H:i:s', $appointmentTime);

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $appointmentDate
) {
    respond(false, 'Please select a valid appointment date.');
}



if (
    !$timeObject ||
    $timeObject->format('H:i:s') !== $appointmentTime
) {
    respond(false, 'Please select a valid appointment time.');
}

$manilaTimezone = new DateTimeZone('Asia/Manila');
$today = new DateTime('today', $manilaTimezone);

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

if (mysqli_num_rows($patientResult) === 0) {
    respond(false, 'Patient record not found.');
}

$patient = mysqli_fetch_assoc($patientResult);
$patientID = (int) $patient['PatientID'];

$departmentStmt = mysqli_prepare(
    $conn,
    'SELECT DepartmentID
     FROM departments
     WHERE DepartmentID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param($departmentStmt, 'i', $departmentID);
mysqli_stmt_execute($departmentStmt);

$departmentResult = mysqli_stmt_get_result($departmentStmt);

if (mysqli_num_rows($departmentResult) === 0) {
    respond(false, 'The selected department does not exist.');
}

$staffStmt = mysqli_prepare(
    $conn,
    'SELECT StaffID
     FROM staff
     WHERE DepartmentID = ?
       AND AvailabilityStatus = "Available"
       AND StaffRole = "Doctor"
     LIMIT 1'
);

mysqli_stmt_bind_param($staffStmt, 'i', $departmentID);
mysqli_stmt_execute($staffStmt);

$staffResult = mysqli_stmt_get_result($staffStmt);

if (mysqli_num_rows($staffResult) === 0) {
    respond(false, 'No available doctor is assigned to this department.');
}

$staff = mysqli_fetch_assoc($staffResult);
$staffID = (int) $staff['StaffID'];

$checkStmt = mysqli_prepare(
    $conn,
    'SELECT AppointmentID
     FROM appointments
     WHERE StaffID = ?
       AND AppointmentDate = ?
       AND AppointmentTime = ?
       AND Status NOT IN ("Cancelled", "Completed")
     LIMIT 1'
);

mysqli_stmt_bind_param(
    $checkStmt,
    'iss',
    $staffID,
    $appointmentDate,
    $appointmentTime
);

mysqli_stmt_execute($checkStmt);

$checkResult = mysqli_stmt_get_result($checkStmt);

if (mysqli_num_rows($checkResult) > 0) {
    respond(false, 'This time slot is already booked. Please choose another time.');
}

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