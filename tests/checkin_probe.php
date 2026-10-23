<?php
/**
 * Runtime probe for staff/checkIn_patient.php (CLI only, via endpoint_runner).
 *
 * Covers spec test 11 + the check-in field writes (#4/#5):
 *   1  minor check-in WITHOUT accompanying name -> blocked, nothing written
 *   2  same appointment WITH accompanying name -> checked in, AccompaniedBy +
 *      CheckInTime stored, queue row created
 *   3  adult check-in without accompanying name -> allowed, AccompaniedBy NULL
 *   4  minor walk-in WITHOUT accompanying name -> blocked, no user created
 *   5  adult walk-in WITH accompanying name -> created checked-in with
 *      CheckInTime, AccompaniedBy, BookedByUserID (proves the new INSERT bind)
 *
 * All fixture rows are removed on shutdown.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/booking_rules.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__probeStart'] = date('Y-m-d H:i:s');
$GLOBALS['__probeAppts'] = [];

$probeTemp = 'C:\Users\lenovo\AppData\Local\Temp\opencode';
if (!is_dir($probeTemp)) {
    @mkdir($probeTemp, 0777, true);
}
if (!is_dir($probeTemp)) {
    $probeTemp = sys_get_temp_dir();
}

function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['__pass']++;
        echo "PASS  $name\n";
    } else {
        $GLOBALS['__fail']++;
        echo "FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

function ciCleanup(): void
{
    global $conn;
    if (!isset($conn) || !$conn) return;

    $start = mysqli_real_escape_string($conn, $GLOBALS['__probeStart']);

    $apptIds = [];
    foreach ($GLOBALS['__probeAppts'] as $id) {
        $apptIds[] = (int) $id;
    }

    // Fixture appointments recorded by the probes themselves.
    $res = mysqli_query(
        $conn,
        "SELECT AppointmentID FROM appointments
         WHERE Purpose IN ('[TEST-PROBE-CI]', '[TEST-PROBE-WI]')
           AND AppointmentDate = CURDATE()
           AND AppointmentTime IN ('09:00:00', '10:00:00')"
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $apptIds[] = (int) $r['AppointmentID'];
    }
    $apptIds = array_values(array_unique($apptIds));

    // Walk-in probes CREATE user + patient rows; those are safe to delete.
    // Scheduled fixtures use pre-existing patients — never delete those.
    $patientIds = [];
    $userIds = [];
    if ($apptIds) {
        $res = mysqli_query(
            $conn,
            "SELECT a.PatientID, p.UserID
             FROM appointments a
             JOIN patients p ON p.PatientID = a.PatientID
             WHERE a.AppointmentID IN (" . implode(',', $apptIds) . ")
               AND a.Purpose = '[TEST-PROBE-WI]'"
        );
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $patientIds[] = (int) $r['PatientID'];
            if (!empty($r['UserID'])) {
                $userIds[] = (int) $r['UserID'];
            }
        }
    }
    $patientIds = array_values(array_unique($patientIds));
    $userIds = array_values(array_unique($userIds));

    if ($apptIds) {
        $list = implode(',', $apptIds);
        // notifications.SentAt (no CreatedAt column); RelatedTable varies by caller.
        mysqli_query($conn, "DELETE FROM notifications WHERE RelatedID IN ($list) AND SentAt >= '$start'");
        mysqli_query($conn, "DELETE FROM queue WHERE AppointmentID IN ($list)");
        mysqli_query($conn, "DELETE FROM appointments WHERE AppointmentID IN ($list)");
    }
    if ($patientIds) {
        mysqli_query($conn, 'DELETE FROM patients WHERE PatientID IN (' . implode(',', $patientIds) . ')');
    }
    if ($userIds) {
        mysqli_query($conn, 'DELETE FROM users WHERE UserID IN (' . implode(',', $userIds) . ')');
    }
}

register_shutdown_function('ciCleanup');

function runStaffPage(array $session, array $post): string
{
    global $probeTemp;
    $inputFile = $probeTemp . '/ci_input_' . getmypid() . '_' . random_int(1000, 9999) . '.json';
    file_put_contents($inputFile, json_encode([
        'session' => $session,
        'post'    => $post,
    ]));
    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/endpoint_runner.php')
        . ' ' . escapeshellarg('staff/checkIn_patient.php')
        . ' ' . escapeshellarg($inputFile);
    $out = (string) shell_exec($cmd . ' 2>&1');
    @unlink($inputFile);
    return $out;
}

$staffSession = ['UserID' => 7, 'RoleName' => 'Staff'];

// Confirm the minor fixture really is a minor (same COALESCE the page uses).
$minorId = 0;
$r = mysqli_query(
    $conn,
    "SELECT p.PatientID
     FROM patients p LEFT JOIN users u ON u.UserID = p.UserID
     WHERE COALESCE(u.DateOfBirth, p.DateOfBirth) IS NOT NULL
       AND TIMESTAMPDIFF(YEAR, COALESCE(u.DateOfBirth, p.DateOfBirth), CURDATE()) < 18
     ORDER BY p.PatientID LIMIT 1"
);
if ($r && ($row = mysqli_fetch_assoc($r))) $minorId = (int) $row['PatientID'];
$adultId = 0;
$r = mysqli_query(
    $conn,
    "SELECT p.PatientID
     FROM patients p LEFT JOIN users u ON u.UserID = p.UserID
     WHERE COALESCE(u.DateOfBirth, p.DateOfBirth) IS NOT NULL
       AND TIMESTAMPDIFF(YEAR, COALESCE(u.DateOfBirth, p.DateOfBirth), CURDATE()) >= 18
       AND NOT EXISTS (
           SELECT 1 FROM appointments a
           WHERE a.PatientID = p.PatientID
             AND a.AppointmentDate = CURDATE()
             AND a.Status NOT IN ('Cancelled', 'Completed')
       )
     ORDER BY p.PatientID LIMIT 1"
);
if ($r && ($row = mysqli_fetch_assoc($r))) $adultId = (int) $row['PatientID'];
if ($minorId === 0 || $adultId === 0) {
    echo "FATAL  need one minor and one adult fixture patient\n";
    exit(1);
}
echo "== minor=$minorId adult=$adultId ==\n";

// =====================================================================
// 1: minor, no accompanying name -> blocked
// =====================================================================
mysqli_query(
    $conn,
    "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
     VALUES ($minorId, 1, CURDATE(), '09:00:00', 'Pending', '[TEST-PROBE-CI]')"
);
$apptMinor = (int) mysqli_insert_id($conn);
$GLOBALS['__probeAppts'][] = $apptMinor;

$out1 = runStaffPage($staffSession, ['action' => 'checkin', 'appointment_id' => $apptMinor]);
check(
    '1 minor check-in without accompanying name is blocked',
    strpos($out1, 'A minor must be accompanied by a parent or guardian.') !== false,
    'message not in page output'
);

$state = $conn->query("SELECT a.Status, a.CheckInTime, a.AccompaniedBy, (SELECT COUNT(*) FROM queue WHERE AppointmentID = $apptMinor) q FROM appointments a WHERE AppointmentID = $apptMinor")->fetch_assoc();
check(
    '1 nothing written on blocked check-in',
    $state['Status'] === 'Pending' && $state['CheckInTime'] === null && $state['AccompaniedBy'] === null && (int) $state['q'] === 0,
    'status=' . $state['Status'] . ' queue=' . $state['q']
);

// =====================================================================
// 2: same appointment, WITH accompanying name -> checked in
// =====================================================================
$out2 = runStaffPage($staffSession, [
    'action'         => 'checkin',
    'appointment_id' => $apptMinor,
    'accompanied_by' => 'Maria dela Cruz',
]);
check(
    '2 minor check-in with accompanying name succeeds',
    strpos($out2, 'Patient checked in successfully') !== false,
    'success message not in output'
);

$state = $conn->query("SELECT Status, CheckInTime, AccompaniedBy, (SELECT COUNT(*) FROM queue WHERE AppointmentID = $apptMinor) q FROM appointments WHERE AppointmentID = $apptMinor")->fetch_assoc();
check(
    '2 CheckInTime + AccompaniedBy stored, queue row created',
    $state['Status'] === 'Checked In'
        && $state['CheckInTime'] !== null
        && $state['AccompaniedBy'] === 'Maria dela Cruz'
        && (int) $state['q'] === 1,
    'status=' . $state['Status'] . ' acc=' . var_export($state['AccompaniedBy'], true) . ' queue=' . $state['q']
);

// =====================================================================
// 3: adult, no accompanying name -> allowed
// =====================================================================
mysqli_query(
    $conn,
    "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
     VALUES ($adultId, 1, CURDATE(), '10:00:00', 'Pending', '[TEST-PROBE-CI]')"
);
$apptAdult = (int) mysqli_insert_id($conn);
$GLOBALS['__probeAppts'][] = $apptAdult;

$out3 = runStaffPage($staffSession, ['action' => 'checkin', 'appointment_id' => $apptAdult]);
check(
    '3 adult check-in without accompanying name succeeds',
    strpos($out3, 'Patient checked in successfully') !== false,
    'success message not in output'
);
$state = $conn->query("SELECT Status, CheckInTime, AccompaniedBy FROM appointments WHERE AppointmentID = $apptAdult")->fetch_assoc();
check(
    '3 adult CheckInTime set, AccompaniedBy NULL',
    $state['Status'] === 'Checked In' && $state['CheckInTime'] !== null && $state['AccompaniedBy'] === null,
    'acc=' . var_export($state['AccompaniedBy'], true)
);

// =====================================================================
// 4: minor walk-in, no accompanying name -> blocked, no account created
// =====================================================================
$userCountBefore = (int) $conn->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'];

$out4 = runStaffPage($staffSession, [
    'action'        => 'walkin',
    'full_name'     => 'Probe Minor Walkin',
    'date_of_birth' => '2019-05-05',
    'gender'        => 'Male',
    'phone'         => '09171234567',
    'department_id' => 1,
    'doctor_id'     => 1,
    'session'       => 'morning',
    'reason'        => '[TEST-PROBE-WI]',
]);
check(
    '4 minor walk-in without accompanying name is blocked',
    strpos($out4, 'A minor must be accompanied by a parent or guardian.') !== false,
    'message not in page output'
);
$userCountAfter = (int) $conn->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'];
check('4 no account created for blocked walk-in', $userCountAfter === $userCountBefore);

// =====================================================================
// 5: adult walk-in WITH accompanying name -> created checked-in
// =====================================================================
$walkinDoc = 0;
$r = $conn->query("SELECT StaffID FROM staff WHERE StaffRole = 'Doctor' AND DepartmentID = 1 LIMIT 1");
if ($r && ($row = $r->fetch_assoc())) $walkinDoc = (int) $row['StaffID'];
if ($walkinDoc === 0) {
    echo "SKIP  5 adult walk-in (no doctor in department 1)\n";
} else {
    $out5 = runStaffPage($staffSession, [
        'action'        => 'walkin',
        'full_name'     => 'Probe Walkin Adult',
        'date_of_birth' => '1990-01-01',
        'gender'        => 'Male',
        'phone'         => '09171234567',
        'department_id' => 1,
        'doctor_id'     => $walkinDoc,
        'session'       => 'morning',
        'reason'        => '[TEST-PROBE-WI]',
        'accompanied_by'=> 'Pedro Cruz',
    ]);

    $wi = $conn->query(
        "SELECT a.AppointmentID, a.Status, a.CheckInTime, a.AccompaniedBy, a.StaffID, a.BookedByUserID,
                (SELECT COUNT(*) FROM queue WHERE AppointmentID = a.AppointmentID) q
         FROM appointments a
         WHERE a.Purpose = '[TEST-PROBE-WI]'
         ORDER BY a.AppointmentID DESC LIMIT 1"
    )->fetch_assoc();

    check(
        '5 adult walk-in created as checked-in appointment',
        $wi !== false && $wi['Status'] === 'Checked In' && $wi['CheckInTime'] !== null,
        'walk-in row not found or wrong state'
    );
    if ($wi) {
        check(
            '5 walk-in AccompaniedBy + BookedByUserID + doctor recorded',
            $wi['AccompaniedBy'] === 'Pedro Cruz'
                && (int) $wi['BookedByUserID'] === 7
                && (int) $wi['StaffID'] === $walkinDoc
                && (int) $wi['q'] === 1,
            'acc=' . var_export($wi['AccompaniedBy'], true) . ' booked=' . $wi['BookedByUserID'] . ' staff=' . $wi['StaffID'] . ' queue=' . $wi['q']
        );
    }
}

echo "\n" . $GLOBALS['__pass'] . ' PASS / ' . $GLOBALS['__fail'] . " FAIL\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
