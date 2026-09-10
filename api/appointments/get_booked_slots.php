<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json');

function respond(bool $success, array $data = [], string $message = ''): void
{
    $response = [
        'success' => $success,
        'message' => $message
    ];

    foreach ($data as $key => $value) {
        $response[$key] = $value;
    }

    echo json_encode($response);
    exit();
}

function timeLabel(string $time): string
{
    return date('g:i A', strtotime($time));
}

if (!isset($_SESSION['UserID'])) {
    respond(false, [], 'Your session has expired. Please log in again.');
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    respond(false, [], 'Unauthorized access.');
}

$departmentID = (int) ($_POST['department_id'] ?? 0);
$appointmentDate = trim($_POST['appointment_date'] ?? '');
$excludeAppointmentID = (int) ($_POST['exclude_appointment_id'] ?? 0);

if ($departmentID <= 0 || $appointmentDate === '') {
    respond(false, [], 'Please select a department and appointment date.');
}

// When rescheduling, the patient's own appointment must not count
// against the slot it was originally booked for.
if ($excludeAppointmentID > 0) {
    $patientStmt = mysqli_prepare(
        $conn,
        'SELECT PatientID
         FROM patients
         WHERE UserID = ?
         LIMIT 1'
    );

    mysqli_stmt_bind_param($patientStmt, 'i', $_SESSION['UserID']);
    mysqli_stmt_execute($patientStmt);

    $patientResult = mysqli_stmt_get_result($patientStmt);
    $patient = mysqli_fetch_assoc($patientResult);

    mysqli_stmt_close($patientStmt);

    if (!$patient) {
        respond(false, [], 'Patient record not found.');
    }

    $verifyStmt = mysqli_prepare(
        $conn,
        'SELECT AppointmentID
         FROM appointments
         WHERE AppointmentID = ?
           AND PatientID = ?
         LIMIT 1'
    );

    mysqli_stmt_bind_param(
        $verifyStmt,
        'ii',
        $excludeAppointmentID,
        $patient['PatientID']
    );

    mysqli_stmt_execute($verifyStmt);

    $verifyResult = mysqli_stmt_get_result($verifyStmt);

    mysqli_stmt_close($verifyStmt);

    if (mysqli_num_rows($verifyResult) === 0) {
        respond(false, [], 'Appointment not found or does not belong to you.');
    }
}

$dateObject = DateTime::createFromFormat('Y-m-d', $appointmentDate);

if (
    !$dateObject ||
    $dateObject->format('Y-m-d') !== $appointmentDate
) {
    respond(false, [], 'Invalid appointment date.');
}

$dayOfWeek = (int) $dateObject->format('N');

$doctorStmt = mysqli_prepare(
    $conn,
    'SELECT COUNT(*) AS ActiveDoctorCount
     FROM staff
     WHERE DepartmentID = ?
       AND AvailabilityStatus = "Available"
       AND StaffRole = "Doctor"'
);

mysqli_stmt_bind_param($doctorStmt, 'i', $departmentID);
mysqli_stmt_execute($doctorStmt);

$doctorResult = mysqli_stmt_get_result($doctorStmt);
$doctor = mysqli_fetch_assoc($doctorResult);

$capacity = (int) $doctor['ActiveDoctorCount'];

if ($capacity === 0) {
    respond(false, [], 'No active doctors are currently assigned to this department.');
}

$scheduleStmt = mysqli_prepare(
    $conn,
    'SELECT StartTime, EndTime
     FROM department_schedules
     WHERE DepartmentID = ?
       AND DayOfWeek = ?
     ORDER BY StartTime ASC'
);

mysqli_stmt_bind_param($scheduleStmt, 'ii', $departmentID, $dayOfWeek);
mysqli_stmt_execute($scheduleStmt);

$scheduleResult = mysqli_stmt_get_result($scheduleStmt);

$slotTimes = [];

while ($schedule = mysqli_fetch_assoc($scheduleResult)) {
    $start = new DateTime($schedule['StartTime']);
    $end = new DateTime($schedule['EndTime']);

    while ($start < $end) {
        $slotTimes[] = $start->format('H:i:s');
        $start->modify('+30 minutes');
    }
}

if (count($slotTimes) === 0) {
    respond(false, [], 'This department has no service scheduled on the selected date.');
}

$bookedSql = '
    SELECT
        AppointmentTime,
        COUNT(*) AS BookedCount
     FROM appointments
     WHERE DepartmentID = ?
       AND AppointmentDate = ?
       AND Status NOT IN ("Cancelled", "Completed")
 ';

$bookedParams = [$departmentID, $appointmentDate];

if ($excludeAppointmentID > 0) {
    $bookedSql .= ' AND AppointmentID <> ?';
    $bookedParams[] = $excludeAppointmentID;
}

$bookedSql .= ' GROUP BY AppointmentTime';

$bookedStmt = mysqli_prepare($conn, $bookedSql);

if (!$bookedStmt) {
    respond(false, [], 'Unable to prepare booked slots query.');
}

if ($excludeAppointmentID > 0) {
    mysqli_stmt_bind_param(
        $bookedStmt,
        'isi',
        $bookedParams[0],
        $bookedParams[1],
        $bookedParams[2]
    );
} else {
    mysqli_stmt_bind_param(
        $bookedStmt,
        'is',
        $bookedParams[0],
        $bookedParams[1]
    );
}

mysqli_stmt_execute($bookedStmt);

$bookedResult = mysqli_stmt_get_result($bookedStmt);

$bookedCounts = [];

while ($booked = mysqli_fetch_assoc($bookedResult)) {
    $bookedCounts[$booked['AppointmentTime']] =
        (int) $booked['BookedCount'];
}

$slots = [];

foreach ($slotTimes as $time) {
    $bookedCount = $bookedCounts[$time] ?? 0;

    $slots[] = [
        'time' => $time,
        'label' => timeLabel($time),
        'booked' => $bookedCount,
        'capacity' => $capacity,
        'available' => $bookedCount < $capacity
    ];
}

respond(true, [
    'slots' => $slots,
    'capacity' => $capacity
]);