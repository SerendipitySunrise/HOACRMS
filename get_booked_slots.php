<?php
session_start();

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

function respond(bool $success, array $slots = [], string $message = ''): void
{
    echo json_encode([
        'success' => $success,
        'slots' => $slots,
        'message' => $message
    ]);

    exit();
}

if (!isset($_SESSION['UserID']) || ($_SESSION['RoleName'] ?? '') !== 'Patient') {
    respond(false, [], 'Unauthorized access.');
}

$departmentID = (int) ($_POST['department_id'] ?? 0);
$appointmentDate = trim($_POST['appointment_date'] ?? '');

if ($departmentID <= 0 || $appointmentDate === '') {
    respond(false, [], 'Missing appointment details.');
}

$staffStmt = mysqli_prepare(
    $conn,
    'SELECT StaffID
     FROM staff
     WHERE DepartmentID = ?
       AND AvailabilityStatus = "Available"
       AND StaffRole = "Doctor"
     ORDER BY StaffID ASC
     LIMIT 1'
);

mysqli_stmt_bind_param($staffStmt, 'i', $departmentID);
mysqli_stmt_execute($staffStmt);

$staffResult = mysqli_stmt_get_result($staffStmt);
$staff = mysqli_fetch_assoc($staffResult);

if (!$staff) {
    respond(false, [], 'No available doctor is assigned to this department.');
}

$staffID = (int) $staff['StaffID'];

$slotStmt = mysqli_prepare(
    $conn,
    'SELECT AppointmentTime
     FROM appointments
     WHERE StaffID = ?
       AND AppointmentDate = ?
       AND Status NOT IN ("Cancelled", "Completed")'
);

mysqli_stmt_bind_param($slotStmt, 'is', $staffID, $appointmentDate);
mysqli_stmt_execute($slotStmt);

$slotResult = mysqli_stmt_get_result($slotStmt);

$bookedSlots = [];

while ($slot = mysqli_fetch_assoc($slotResult)) {
    $bookedSlots[] = $slot['AppointmentTime'];
}

respond(true, $bookedSlots);