<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));

    exit();
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

$appointmentID = (int) ($_POST['appointment_id'] ?? 0);
$userID = (int) $_SESSION['UserID'];

if ($appointmentID <= 0) {
    respond(false, 'Invalid appointment ID.');
}

try {

    // -------------------------------------------------
    // GET PATIENT ID
    // -------------------------------------------------

    $patientStmt = mysqli_prepare(
        $conn,
        'SELECT PatientID
         FROM patients
         WHERE UserID = ?
         LIMIT 1'
    );

    if (!$patientStmt) {
        respond(false, 'Unable to prepare patient query.');
    }

    mysqli_stmt_bind_param(
        $patientStmt,
        'i',
        $userID
    );

    mysqli_stmt_execute($patientStmt);

    $patientResult = mysqli_stmt_get_result($patientStmt);
    $patient = mysqli_fetch_assoc($patientResult);

    mysqli_stmt_close($patientStmt);

    if (!$patient) {
        respond(false, 'Patient profile not found.');
    }

    $patientID = (int) $patient['PatientID'];


    // -------------------------------------------------
    // CHECK APPOINTMENT
    // -------------------------------------------------

    $checkStmt = mysqli_prepare(
        $conn,
        'SELECT AppointmentID, Status
         FROM appointments
         WHERE AppointmentID = ?
           AND PatientID = ?
         LIMIT 1'
    );

    if (!$checkStmt) {
        respond(false, 'Unable to check appointment.');
    }

    mysqli_stmt_bind_param(
        $checkStmt,
        'ii',
        $appointmentID,
        $patientID
    );

    mysqli_stmt_execute($checkStmt);

    $checkResult = mysqli_stmt_get_result($checkStmt);
    $appointment = mysqli_fetch_assoc($checkResult);

    mysqli_stmt_close($checkStmt);

    if (!$appointment) {
        respond(false, 'Appointment not found or does not belong to you.');
    }


    // -------------------------------------------------
    // CHECK STATUS
    // -------------------------------------------------

    $currentStatus = $appointment['Status'];

    if ($currentStatus === 'Cancelled') {
        respond(false, 'This appointment has already been cancelled.');
    }

    if ($currentStatus === 'Completed') {
        respond(false, 'Completed appointments cannot be cancelled.');
    }

    if ($currentStatus === 'Checked In') {
        respond(false, 'A checked-in appointment cannot be cancelled.');
    }


    // -------------------------------------------------
    // CANCEL APPOINTMENT
    // -------------------------------------------------

    $cancelStmt = mysqli_prepare(
        $conn,
        'UPDATE appointments
         SET Status = "Cancelled"
         WHERE AppointmentID = ?
           AND PatientID = ?'
    );

    if (!$cancelStmt) {
        respond(false, 'Unable to prepare cancellation request.');
    }

    mysqli_stmt_bind_param(
        $cancelStmt,
        'ii',
        $appointmentID,
        $patientID
    );

    mysqli_stmt_execute($cancelStmt);

    $affectedRows = mysqli_stmt_affected_rows($cancelStmt);

    mysqli_stmt_close($cancelStmt);

    if ($affectedRows !== 1) {
        respond(false, 'The appointment could not be cancelled.');
    }

    respond(true, 'Appointment cancelled successfully.');

} catch (Throwable $e) {

    // Log the real error
    error_log(
        'Cancel appointment error: ' .
        $e->getMessage()
    );

    // Return JSON instead of PHP/HTML error output
    respond(
        false,
        'A database error occurred while cancelling the appointment.'
    );
}