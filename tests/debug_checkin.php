<?php
// One-off debug: what does the page say when checking in with a name?
if (php_sapi_name() !== 'cli') exit(1);
require __DIR__ . '/../includes/db.php';

$minorId = 0;
$r = mysqli_query($conn, "SELECT p.PatientID FROM patients p LEFT JOIN users u ON u.UserID=p.UserID
    WHERE COALESCE(u.DateOfBirth,p.DateOfBirth) IS NOT NULL
      AND TIMESTAMPDIFF(YEAR,COALESCE(u.DateOfBirth,p.DateOfBirth),CURDATE()) < 18
    ORDER BY p.PatientID LIMIT 1");
if ($r && ($row = mysqli_fetch_assoc($r))) $minorId = (int)$row['PatientID'];

mysqli_query($conn, "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
    VALUES ($minorId, 1, CURDATE(), '11:30:00', 'Pending', '[TEST-DEBUG-CI]')");
$apptId = (int) mysqli_insert_id($conn);

$inputFile = sys_get_temp_dir() . '/dbg_' . getmypid() . '.json';
file_put_contents($inputFile, json_encode([
    'session' => ['UserID' => 7, 'RoleName' => 'Staff'],
    'post' => ['action' => 'checkin', 'appointment_id' => $apptId, 'accompanied_by' => 'Maria dela Cruz'],
]));
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/endpoint_runner.php')
     . ' ' . escapeshellarg('staff/checkIn_patient.php') . ' ' . escapeshellarg($inputFile);
$out = (string) shell_exec($cmd . ' 2>&1');
@unlink($inputFile);

// Pull out anything message-like from the rendered page.
foreach ([
    'A minor must be accompanied',
    'already been checked in',
    'Appointment not found',
    'Unable to check in patient',
    'checkin failed',
    'Failed to create queue record',
    'Failed to update appointment status',
    'checked in successfully',
    'Checked in successfully',
    'check-in',
    'Warning',
    'Fatal error',
    'Uncaught',
] as $needle) {
    $pos = strpos($out, $needle);
    if ($pos !== false) {
        echo "FOUND [$needle] at $pos: " . trim(substr($out, max(0, $pos - 80), 260)) . "\n---\n";
    }
}

echo "== DB state ==\n";
$st = $conn->query("SELECT a.Status, a.CheckInTime, a.AccompaniedBy,
    (SELECT COUNT(*) FROM queue WHERE AppointmentID=$apptId) q
    FROM appointments a WHERE AppointmentID=$apptId")->fetch_assoc();
echo json_encode($st, JSON_PRETTY_PRINT) . "\n";

// cleanup
$conn->query("DELETE FROM notifications WHERE RelatedID=$apptId AND SentAt >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
$conn->query("DELETE FROM queue WHERE AppointmentID=$apptId");
$conn->query("DELETE FROM appointments WHERE AppointmentID=$apptId");
