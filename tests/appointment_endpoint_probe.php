<?php
/**
 * Endpoint-level probe for the appointment APIs (CLI only).
 *
 * Runs the REAL endpoints (save / reschedule / cancel) in a subprocess with a
 * live session identity and asserts:
 *   A1  an active appointment in the department blocks a new booking
 *       ("One active appointment per department per patient.")
 *   A2  a doctor-suggested follow-up date is derived SERVER-side and skips
 *       the per-department limit (appointments.IsFollowup = 1)
 *   C   cancelling writes an APPOINTMENT_CANCEL audit row
 *   B   a patient AT the active limit can still reschedule their own
 *       appointment (self-exclusion fix + bind fix), with audit row
 *
 * Every fixture row is tagged '[TEST-PROBE]' and removed on shutdown.
 */

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/booking_rules_core.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__probeStart'] = date('Y-m-d H:i:s');
$GLOBALS['__probeTag'] = '[TEST-PROBE]';

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

function probeCleanup(): void
{
    global $conn, $probeTemp;
    if (!isset($conn) || !$conn) return;

    $tag = "'[TEST-PROBE]'";

    // Consultations hold ON DELETE RESTRICT on AppointmentID: remove them
    // before the appointments they reference.
    mysqli_query($conn, "DELETE FROM consultations WHERE ChiefComplaint = $tag");

    $res = mysqli_query($conn, "SELECT AppointmentID FROM appointments WHERE PatientID = 823 AND Purpose = $tag");
    $ids = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $ids[] = (int) $r['AppointmentID'];
    }

    if ($ids) {
        $idList = implode(',', array_map('intval', $ids));
        mysqli_query($conn, "DELETE FROM audit_trail WHERE RecordID IN ($idList) AND Action LIKE 'APPOINTMENT_%'");
        mysqli_query($conn, "DELETE FROM appointment_reminders WHERE AppointmentID IN ($idList)");
        mysqli_query($conn, "DELETE FROM appointment_reschedule_history WHERE AppointmentID IN ($idList)");
        mysqli_query(
            $conn,
            "DELETE FROM notifications WHERE RelatedTable = 'appointments' AND RelatedID IN ($idList)
             AND CreatedAt >= '" . mysqli_real_escape_string($conn, $GLOBALS['__probeStart']) . "'"
        );
        mysqli_query($conn, "DELETE FROM appointments WHERE AppointmentID IN ($idList)");
    }
}

register_shutdown_function('probeCleanup');

function runEndpoint(string $endpoint, array $session, array $post): array
{
    global $probeTemp;
    $inputFile = $probeTemp . '/probe_input_' . getmypid() . '_' . random_int(1000, 9999) . '.json';
    file_put_contents($inputFile, json_encode([
        'session' => $session,
        'post'    => $post,
    ]));
    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/endpoint_runner.php')
        . ' ' . escapeshellarg($endpoint)
        . ' ' . escapeshellarg($inputFile);
    $out = (string) shell_exec($cmd . ' 2>&1');
    @unlink($inputFile);
    $json = json_decode(trim($out), true);
    return ['raw' => $out, 'json' => is_array($json) ? $json : null];
}

function jsonDecodeLoose(?string $s): ?array
{
    if ($s === null) return null;
    $v = json_decode($s, true);
    return is_array($v) ? $v : null;
}

$patientSession = ['UserID' => 823, 'RoleName' => 'Patient'];

// ---------------------------------------------------------------------
// Pick department D + date1 with sessions, an available doctor, no holiday
// ---------------------------------------------------------------------
$deptD = 0;
$date1 = '';
$sessions1 = [];

for ($off = 7; $off <= 28 && $deptD === 0; $off++) {
    $dateStr = (new DateTimeImmutable('today'))->modify("+$off days")->format('Y-m-d');
    if (isHospitalHoliday($conn, $dateStr)) continue;
    $dow = (int) (new DateTimeImmutable($dateStr))->format('N');
    $res = mysqli_query(
        $conn,
        'SELECT ds.DepartmentID FROM department_schedules ds
         JOIN departments d ON d.DepartmentID = ds.DepartmentID
         WHERE ds.DayOfWeek = ' . $dow . ' AND d.MaxAge >= 18
         GROUP BY ds.DepartmentID'
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $cand = (int) $r['DepartmentID'];
        $sess = departmentSessionsForDay($conn, $cand, $dow);
        if (!$sess) continue;
        if (findAssignableDoctor($conn, $cand, $dateStr, $sess[0]['StartTime']) <= 0) continue;
        $deptD = $cand;
        $date1 = $dateStr;
        $sessions1 = $sess;
        break;
    }
}
if ($deptD === 0) {
    echo "FATAL  no department/date combo available\n";
    exit(1);
}
$targetTime = $sessions1[0]['StartTime'];
echo "== dept D=$deptD, date1=$date1, target=$targetTime ==\n";

// =====================================================================
// A1: without a follow-up suggestion, one per department blocks the booking
// =====================================================================
$fixtureTime = date('H:i:s', max(0, strtotime($targetTime) - 3600));
mysqli_query(
    $conn,
    "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
     VALUES (823, $deptD, '$date1', '$fixtureTime', 'Pending', '[TEST-PROBE]')"
);
$fixtureId = (int) mysqli_insert_id($conn);

$createAuditBefore = (int) ((function () use ($conn) {
    $r = mysqli_query($conn, "SELECT COUNT(*) c FROM audit_trail WHERE UserID = 823 AND Action = 'APPOINTMENT_CREATE'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row['c'] ?? 0;
})());

$createPost = [
    'department_id'    => $deptD,
    'appointment_date' => $date1,
    'appointment_time' => $targetTime,
    'purpose'          => '[TEST-PROBE]',
];

$r1 = runEndpoint('api/appointments/save_appointment.php', $patientSession, $createPost);
$msg1 = (string) ($r1['json']['message'] ?? $r1['raw']);
check(
    'A1 per-department limit blocks second booking in department',
    ($r1['json']['success'] ?? null) === false && $msg1 === 'One active appointment per department per patient.',
    $msg1
);

$createAuditAfter1 = (int) ((function () use ($conn) {
    $r = mysqli_query($conn, "SELECT COUNT(*) c FROM audit_trail WHERE UserID = 823 AND Action = 'APPOINTMENT_CREATE'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row['c'] ?? 0;
})());
check('A1 failed booking writes no audit row', $createAuditAfter1 === $createAuditBefore);

// =====================================================================
// A2: doctor-suggested follow-up on this date skips the limit; IsFollowup=1
// =====================================================================
$docForConsult = (int) ((function () use ($conn, $deptD, $date1, $targetTime) {
    $id = findAssignableDoctor($conn, $deptD, $date1, $targetTime);
    if ($id > 0) return $id;
    $r = mysqli_query($conn, "SELECT StaffID FROM staff WHERE DepartmentID = $deptD AND StaffRole = 'Doctor' LIMIT 1");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return (int) ($row['StaffID'] ?? 0);
})());

// consultations.AppointmentID is NOT NULL (FK RESTRICT): link the
// suggestion to the fixture appointment in the same department.
$now = date('Y-m-d H:i:s');
$consultOk = mysqli_query(
    $conn,
    "INSERT INTO consultations (AppointmentID, PatientID, StaffID, ConsultationDate, ConsultationTime, ChiefComplaint, Diagnosis, Treatment, Notes, FollowUpDate, Status)
     VALUES ($fixtureId, 823, $docForConsult, CURDATE(), CURTIME(), '[TEST-PROBE]', '', '', '', '$date1', 'Completed')"
);
if (!$consultOk) {
    echo 'FATAL  consult insert failed: ' . mysqli_error($conn) . "\n";
    exit(1);
}
$consultId = (int) mysqli_insert_id($conn);

$r2 = runEndpoint('api/appointments/save_appointment.php', $patientSession, $createPost);
check('A2 follow-up booking succeeds on the suggested date', ($r2['json']['success'] ?? null) === true, (string) ($r2['json']['message'] ?? $r2['raw']));

$apptA = 0;
$isFollowupSeen = -1;
$bookedBySeen = 0;
$sessionSeen = null;
$r = mysqli_query($conn, "SELECT AppointmentID, IsFollowup, BookedByUserID, Session FROM appointments WHERE PatientID = 823 AND Purpose = '[TEST-PROBE]' AND Status = 'Pending' AND AppointmentDate = '$date1' AND AppointmentTime = '$targetTime' ORDER BY AppointmentID DESC LIMIT 1");
if ($r && ($row = mysqli_fetch_assoc($r))) {
    $apptA = (int) $row['AppointmentID'];
    $isFollowupSeen = (int) $row['IsFollowup'];
    $bookedBySeen = (int) $row['BookedByUserID'];
    $sessionSeen = $row['Session'];
}
check('A2 appointment stored with IsFollowup = 1 (server-derived)', $apptA > 0 && $isFollowupSeen === 1, "IsFollowup=$isFollowupSeen id=$apptA");
check('A2 BookedByUserID recorded', $bookedBySeen === 823, "got $bookedBySeen");
check('A2 Session stored on booking', $sessionSeen !== null && $sessionSeen !== '', 'Session=' . var_export($sessionSeen, true));

$createAuditAfter2 = (int) ((function () use ($conn) {
    $r = mysqli_query($conn, "SELECT COUNT(*) c FROM audit_trail WHERE UserID = 823 AND Action = 'APPOINTMENT_CREATE'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row['c'] ?? 0;
})());
check('A2 APPOINTMENT_CREATE audit row written', $createAuditAfter2 === $createAuditBefore + 1, "before=$createAuditBefore after=$createAuditAfter2");

// =====================================================================
// C: cancel the appointment -> APPOINTMENT_CANCEL audit, status Cancelled
// =====================================================================
$rC = runEndpoint('api/appointments/cancel_appointment.php', $patientSession, ['appointment_id' => $apptA]);
check('C cancel succeeds', ($rC['json']['success'] ?? null) === true, (string) ($rC['json']['message'] ?? $rC['raw']));

$statusA = '';
$r = mysqli_query($conn, "SELECT Status FROM appointments WHERE AppointmentID = $apptA");
if ($r && ($row = mysqli_fetch_assoc($r))) $statusA = (string) $row['Status'];
check('C appointment Status = Cancelled', $statusA === 'Cancelled', $statusA);

$cancelAudit = 0;
$r = mysqli_query($conn, "SELECT COUNT(*) c FROM audit_trail WHERE Action = 'APPOINTMENT_CANCEL' AND TableName = 'appointments' AND RecordID = $apptA");
if ($r && ($row = mysqli_fetch_assoc($r))) $cancelAudit = (int) $row['c'];
$cancelOld = '';
$r = mysqli_query($conn, "SELECT OldValue FROM audit_trail WHERE Action = 'APPOINTMENT_CANCEL' AND RecordID = $apptA ORDER BY AuditID DESC LIMIT 1");
if ($r && ($row = mysqli_fetch_assoc($r))) $cancelOld = (string) $row['OldValue'];
check('C APPOINTMENT_CANCEL audit row (old status Pending)', $cancelAudit === 1 && strpos($cancelOld, 'Pending') !== false, "rows=$cancelAudit old=$cancelOld");

// =====================================================================
// B: patient AT the active limit reschedules their own appointment
//     (3 active: subject + 2 fillers; without self-exclusion this is
//      blocked by "Maximum active appointments reached.")
// =====================================================================
// The consult references the fixture appointment (FK RESTRICT): drop the
// consult first, then the fixture, so phase B starts from a clean slate.
mysqli_query($conn, "DELETE FROM consultations WHERE ChiefComplaint = '[TEST-PROBE]'");
mysqli_query($conn, "DELETE FROM appointments WHERE AppointmentID = $fixtureId");

$otherDepts = [];
$r = mysqli_query($conn, "SELECT DepartmentID FROM departments WHERE DepartmentID <> $deptD ORDER BY DepartmentID LIMIT 2");
while ($r && ($row = mysqli_fetch_assoc($r))) $otherDepts[] = (int) $row['DepartmentID'];
if (count($otherDepts) < 2) {
    echo "FATAL  need two other departments\n";
    exit(1);
}

// Find date2 inside the booking window: dept D open, non-holiday, patient free
$date2 = '';
$targetTime2 = '';
$sessions2 = [];
for ($off = 7; $off <= 28 && $date2 === ''; $off++) {
    $dateStr = (new DateTimeImmutable('today'))->modify("+$off days")->format('Y-m-d');
    if (isHospitalHoliday($conn, $dateStr)) continue;
    $dow = (int) (new DateTimeImmutable($dateStr))->format('N');
    $sess = departmentSessionsForDay($conn, $deptD, $dow);
    if (!$sess) continue;
    $t = $sess[0]['StartTime'];
    // patient must be free that day (no active appointment that date)
    $r = mysqli_query(
        $conn,
        "SELECT COUNT(*) c FROM appointments WHERE PatientID = 823 AND AppointmentDate = '$dateStr'
         AND Status NOT IN ('Cancelled','Completed','No Show')"
    );
    $row = $r ? mysqli_fetch_assoc($r) : null;
    if ((int) ($row['c'] ?? 0) > 0) continue;
    if (findAssignableDoctor($conn, $deptD, $dateStr, $t) <= 0) continue;
    $win = sessionWindowForTime($conn, $deptD, $dateStr, $t);
    if (!$win || timeInsideBreak($win, $t)) continue;
    if (sessionBookedCount($conn, $deptD, $dateStr, $t) >= sessionMaxCapacity($conn, $deptD, $dow, (string) $win['SessionName'])) continue;
    $date2 = $dateStr;
    $targetTime2 = $t;
    $sessions2 = $sess;
}
if ($date2 === '') {
    echo "FATAL  no reschedule target slot found\n";
    exit(1);
}
echo "== reschedule target: $date2 $targetTime2 ==\n";

$subjectDate = (new DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d');
mysqli_query(
    $conn,
    "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
     VALUES (823, $otherDepts[0], '$subjectDate', '10:00:00', 'Pending', '[TEST-PROBE]')"
); // filler 1 (other department)
mysqli_query(
    $conn,
    "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
     VALUES (823, $otherDepts[1], '$subjectDate', '11:00:00', 'Pending', '[TEST-PROBE]')"
); // filler 2 (other department)
mysqli_query(
    $conn,
    "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
     VALUES (823, $deptD, '$subjectDate', '09:00:00', 'Pending', '[TEST-PROBE]')"
); // the appointment being moved (department D)
$subjectId = (int) mysqli_insert_id($conn);

// Exactly 3 active: 2 fillers + subject.
$activeNow = countActiveAppointmentsForPatient($conn, 823);
check('B setup: patient has exactly 3 active appointments', $activeNow === 3, "got $activeNow");

$snapshot = null;
$r = mysqli_query($conn, "SELECT * FROM appointments WHERE AppointmentID = $subjectId");
if ($r) $snapshot = mysqli_fetch_assoc($r);

$rB = runEndpoint('api/appointments/reschedule_appointment.php', $patientSession, [
    'appointment_id'    => $subjectId,
    'appointment_date'  => $date2,
    'appointment_time'  => $targetTime2,
    'reason'            => '[TEST-PROBE] reschedule',
    'purpose'           => 'probe purpose',
]);
check(
    'B at-limit patient can reschedule own appointment (self-exclusion)',
    ($rB['json']['success'] ?? null) === true,
    (string) ($rB['json']['message'] ?? $rB['raw'])
);

$newDate = $newTime = '';
$newStaff = 0;
$newReschedCount = -1;
$newSession = null;
$r = mysqli_query($conn, "SELECT AppointmentDate, AppointmentTime, StaffID, RescheduleCount, Session FROM appointments WHERE AppointmentID = $subjectId");
if ($r && ($row = mysqli_fetch_assoc($r))) {
    $newDate = (string) $row['AppointmentDate'];
    $newTime = (string) $row['AppointmentTime'];
    $newStaff = (int) $row['StaffID'];
    $newReschedCount = (int) $row['RescheduleCount'];
    $newSession = $row['Session'];
}
check('B appointment moved to target slot', $newDate === $date2 && $newTime === $targetTime2, "$newDate $newTime");
check('B doctor assigned on reschedule', $newStaff > 0, "staff=$newStaff");
check('B RescheduleCount incremented', $newReschedCount === 1, "got $newReschedCount");
check('B Session stored on reschedule', $newSession !== null && $newSession !== '', 'Session=' . var_export($newSession, true));

$reschedAudit = 0;
$reschedOld = '';
$r = mysqli_query($conn, "SELECT COUNT(*) c FROM audit_trail WHERE Action = 'APPOINTMENT_RESCHEDULE' AND RecordID = $subjectId");
if ($r && ($row = mysqli_fetch_assoc($r))) $reschedAudit = (int) $row['c'];
$r = mysqli_query($conn, "SELECT OldValue FROM audit_trail WHERE Action = 'APPOINTMENT_RESCHEDULE' AND RecordID = $subjectId ORDER BY AuditID DESC LIMIT 1");
if ($r && ($row = mysqli_fetch_assoc($r))) $reschedOld = (string) $row['OldValue'];
check(
    'B APPOINTMENT_RESCHEDULE audit row with original date',
    $reschedAudit === 1 && strpos($reschedOld, $subjectDate) !== false,
    "rows=$reschedAudit old=$reschedOld"
);

// Restore the subject row exactly as it was (fixture hygiene).
if ($snapshot) {
    $sets = [];
    foreach ($snapshot as $col => $val) {
        if (in_array($col, ['AppointmentID', 'CreatedAt', 'UpdatedAt', 'SlotKey'], true)) continue;
        $sets[] = "`$col` = " . ($val === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, (string) $val) . "'");
    }
    mysqli_query($conn, 'UPDATE appointments SET ' . implode(', ', $sets) . " WHERE AppointmentID = $subjectId");
}

echo "\n" . $GLOBALS['__pass'] . ' PASS / ' . $GLOBALS['__fail'] . " FAIL\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
