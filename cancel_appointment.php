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

$appointmentID = (int) ($_POST['appointment_id'] ?? 0);
$userID = (int) $_SESSION['UserID'];

if ($appointmentID <= 0) {
    respond(false, 'Invalid appointment.');
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
    respond(false, 'Patient profile not found.');
}

$patientID = (int) $patient['PatientID'];

$cancelStmt = mysqli_prepare(
    $conn,
    'UPDATE appointments
     SET Status = "Cancelled"
     WHERE AppointmentID = ?
       AND PatientID = ?
       AND Status NOT IN ("Cancelled", "Completed")'
);

mysqli_stmt_bind_param(
    $cancelStmt,
    'ii',
    $appointmentID,
    $patientID
);

mysqli_stmt_execute($cancelStmt);

if (mysqli_stmt_affected_rows($cancelStmt) === 0) {
    respond(false, 'This appointment cannot be cancelled.');
}

respond(true, 'Appointment cancelled successfully.');