<?php
session_start();

require_once __DIR__ . '/includes/db.php';

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

if (!isset($_SESSION['UserID'])) {
    respond(false, [], 'Your session has expired. Please log in again.');
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    respond(false, [], 'Unauthorized access.');
}

$departmentID = (int) ($_POST['department_id'] ?? 0);

if ($departmentID <= 0) {
    respond(false, [], 'Please select a department.');
}

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
    respond(false, [], 'The selected department does not exist.');
}

$scheduleStmt = mysqli_prepare(
    $conn,
    'SELECT
        DayOfWeek,
        SessionName,
        StartTime,
        EndTime
     FROM department_schedules
     WHERE DepartmentID = ?
     ORDER BY DayOfWeek ASC, StartTime ASC'
);

mysqli_stmt_bind_param($scheduleStmt, 'i', $departmentID);
mysqli_stmt_execute($scheduleStmt);

$scheduleResult = mysqli_stmt_get_result($scheduleStmt);

$schedules = [];

while ($schedule = mysqli_fetch_assoc($scheduleResult)) {
    $schedules[] = $schedule;
}

if (count($schedules) === 0) {
    respond(false, [], 'This department has no configured appointment schedule.');
}

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

$activeDoctors = (int) $doctor['ActiveDoctorCount'];

respond(true, [
    'schedules' => $schedules,
    'active_doctors' => $activeDoctors
]);