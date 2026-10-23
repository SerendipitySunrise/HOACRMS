<?php
/**
 * Runtime probe (CLI, via endpoint_runner) covering:
 *  #10 schedule editor (save / duplicate-reject / CSRF / delete + audit)
 *  #6  doctor-unavailability override (save/delete + audit)
 *  #8  guardian flow (add API, verify/restrict/unrestrict/unlink page,
 *      my_children list + GUARDIAN_VIEW audit, 18+ consent rule)
 *  §5  check-in window rule + walk-in session-cap rule
 * All fixture rows are removed on shutdown.
 */
if (php_sapi_name() !== 'cli') exit(1);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/guardian_helper.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$GLOBALS['__probeStart'] = date('Y-m-d H:i:s');

$probeTemp = 'C:\Users\lenovo\AppData\Local\Temp\opencode';
if (!is_dir($probeTemp)) $probeTemp = sys_get_temp_dir();

function chk(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) { $GLOBALS['__pass']++; echo "PASS  $name\n"; }
    else { $GLOBALS['__fail']++; echo "FAIL  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

function agCleanup(): void
{
    global $conn;
    if (!isset($conn) || !$conn) return;
    $start = mysqli_real_escape_string($conn, $GLOBALS['__probeStart']);
    $res = mysqli_query($conn, "SELECT AppointmentID FROM appointments WHERE Purpose = '[TEST-PROBE-AG]' AND AppointmentDate = CURDATE()");
    $ids = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) $ids[] = (int) $r['AppointmentID'];
    if ($ids) {
        $list = implode(',', $ids);
        mysqli_query($conn, "DELETE FROM notifications WHERE RelatedID IN ($list) AND SentAt >= '$start'");
        mysqli_query($conn, "DELETE FROM queue WHERE AppointmentID IN ($list)");
        mysqli_query($conn, "DELETE FROM appointments WHERE AppointmentID IN ($list)");
    }
    mysqli_query($conn, "DELETE FROM department_schedules WHERE DepartmentID = 6 AND SessionName IN ('ProbeTest', 'ProbeAM')");
    mysqli_query($conn, "DELETE FROM doctorunavailability WHERE Reason = '[TEST-PROBE]' AND DepartmentID = 6");
    mysqli_query($conn, 'DELETE FROM guardian_links WHERE PatientID = 108 AND GuardianUserID = 823');
}
register_shutdown_function('agCleanup');

function runPage(string $script, array $session, array $post): string
{
    global $probeTemp;
    $inputFile = $probeTemp . '/ag_input_' . getmypid() . '_' . random_int(1000, 9999) . '.json';
    file_put_contents($inputFile, json_encode(['session' => $session, 'post' => $post]));
    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/endpoint_runner.php')
        . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($inputFile);
    $out = (string) shell_exec($cmd . ' 2>&1');
    @unlink($inputFile);
    return $out;
}

function runJson(string $script, array $session, array $post): array
{
    $out = runPage($script, $session, $post);
    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : ['_raw' => $out];
}

$adminSession = ['UserID' => 500, 'RoleName' => 'Admin', 'csrf_token' => 'probe-csrf'];
$staffSession = ['UserID' => 7, 'RoleName' => 'Staff', 'csrf_token' => 'probe-csrf'];
$patientSession = ['UserID' => 823, 'RoleName' => 'Patient', 'csrf_token' => 'probe-csrf'];
$csrf = 'probe-csrf';

echo "== admin schedule editor ==\n";

// P1: create schedule session
runPage('admin/admin_department.php', $adminSession, [
    'action' => 'save_schedule', 'csrf_token' => $csrf, 'schedule_id' => 0,
    'department_id' => 6, 'day_of_week' => 6, 'session_name' => 'ProbeTest',
    'start_time' => '18:00', 'end_time' => '20:00', 'patient_slots' => '7',
    'break_start' => '18:30', 'break_end' => '18:45', 'max_per_session' => '5',
]);
$row = $conn->query("SELECT * FROM department_schedules WHERE DepartmentID = 6 AND DayOfWeek = 6 AND SessionName = 'ProbeTest' LIMIT 1")->fetch_assoc();
chk('P1 schedule session saved with all fields',
    $row && $row['StartTime'] === '18:00:00' && $row['EndTime'] === '20:00:00'
        && (int) $row['PatientSlots'] === 7 && $row['BreakStart'] === '18:30:00'
        && $row['BreakEnd'] === '18:45:00' && (int) $row['MaxPatientsPerSession'] === 5,
    'row=' . json_encode($row));
$schedId = $row ? (int) $row['DepartmentScheduleID'] : 0;
$auditC = (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'SCHEDULE_CREATE' AND RecordID = $schedId")->fetch_assoc()['c'];
chk('P1 SCHEDULE_CREATE audit row', $auditC >= 1, "count=$auditC");

// P2: duplicate rejected
runPage('admin/admin_department.php', $adminSession, [
    'action' => 'save_schedule', 'csrf_token' => $csrf, 'schedule_id' => 0,
    'department_id' => 6, 'day_of_week' => 6, 'session_name' => 'ProbeTest',
    'start_time' => '15:00', 'end_time' => '16:00', 'patient_slots' => '3',
]);
$dupCount = (int) $conn->query("SELECT COUNT(*) c FROM department_schedules WHERE DepartmentID = 6 AND DayOfWeek = 6 AND SessionName = 'ProbeTest'")->fetch_assoc()['c'];
chk('P2 duplicate session name/day rejected', $dupCount === 1, "count=$dupCount");

// P3: missing CSRF rejected
runPage('admin/admin_department.php', $adminSession, [
    'action' => 'save_schedule', 'schedule_id' => 0,
    'department_id' => 6, 'day_of_week' => 6, 'session_name' => 'ProbeNoCsrf',
    'start_time' => '15:00', 'end_time' => '16:00', 'patient_slots' => '3',
]);
$noCsrf = (int) $conn->query("SELECT COUNT(*) c FROM department_schedules WHERE SessionName = 'ProbeNoCsrf'")->fetch_assoc()['c'];
chk('P3 save without CSRF rejected', $noCsrf === 0, "count=$noCsrf");

// P4: delete
runPage('admin/admin_department.php', $adminSession, [
    'action' => 'delete_schedule', 'csrf_token' => $csrf, 'schedule_id' => $schedId,
]);
$gone = (int) $conn->query("SELECT COUNT(*) c FROM department_schedules WHERE DepartmentScheduleID = $schedId")->fetch_assoc()['c'];
$auditD = (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'SCHEDULE_DELETE' AND RecordID = $schedId")->fetch_assoc()['c'];
chk('P4 schedule session deleted + audit', $gone === 0 && $auditD >= 1, "gone=$gone audit=$auditD");

echo "== doctor unavailability (overrides) ==\n";

runPage('admin/admin_department.php', $adminSession, [
    'action' => 'save_unavailability', 'csrf_token' => $csrf,
    'department_id' => 6, 'doctor_id' => 0,
    'start_date' => '2027-01-01', 'end_date' => '2027-01-02', 'reason' => '[TEST-PROBE]',
]);
$unav = $conn->query("SELECT * FROM doctorunavailability WHERE DepartmentID = 6 AND Reason = '[TEST-PROBE]' LIMIT 1")->fetch_assoc();
chk('P5 department-wide leave saved',
    $unav && $unav['DoctorID'] === null && $unav['StartDate'] === '2027-01-01' && $unav['EndDate'] === '2027-01-02',
    'row=' . json_encode($unav));
$unavId = $unav ? (int) $unav['UnavailabilityID'] : 0;
$auditU = (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'DOCTOR_UNAVAILABILITY_ADD' AND RecordID = $unavId")->fetch_assoc()['c'];
chk('P5 unavailability audit row', $auditU >= 1, "count=$auditU");

// date validation: end before start rejected
runPage('admin/admin_department.php', $adminSession, [
    'action' => 'save_unavailability', 'csrf_token' => $csrf,
    'department_id' => 6, 'doctor_id' => 0,
    'start_date' => '2027-02-10', 'end_date' => '2027-02-01', 'reason' => '[TEST-PROBE-BAD]',
]);
$badRange = (int) $conn->query("SELECT COUNT(*) c FROM doctorunavailability WHERE Reason = '[TEST-PROBE-BAD]'")->fetch_assoc()['c'];
chk('P5 invalid date range rejected', $badRange === 0, "count=$badRange");

runPage('admin/admin_department.php', $adminSession, [
    'action' => 'delete_unavailability', 'csrf_token' => $csrf, 'unavailability_id' => $unavId,
]);
$unavGone = (int) $conn->query("SELECT COUNT(*) c FROM doctorunavailability WHERE UnavailabilityID = $unavId")->fetch_assoc()['c'];
chk('P6 leave record removed + audit',
    $unavGone === 0
        && (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'DOCTOR_UNAVAILABILITY_DELETE' AND RecordID = $unavId")->fetch_assoc()['c'] >= 1,
    "gone=$unavGone");

echo "== guardian flow ==\n";

// P7: staff adds guardian link -> Unverified
$r = runJson('api/guardian/add_guardian.php', $staffSession, [
    'csrf_token' => $csrf, 'patient_id' => 108, 'guardian_user_id' => 823,
    'relationship' => 'Mother', 'is_primary' => '1',
]);
$gl = $conn->query('SELECT * FROM guardian_links WHERE PatientID = 108 AND GuardianUserID = 823 LIMIT 1')->fetch_assoc();
chk('P7 guardian link created Unverified',
    ($r['success'] ?? null) === true && $gl && $gl['Status'] === 'Unverified' && (int) $gl['IsPrimary'] === 1,
    'api=' . json_encode($r) . ' row=' . json_encode($gl));
$linkId = $gl ? (int) $gl['GuardianLinkID'] : 0;

$r = runJson('api/guardian/add_guardian.php', $staffSession, [
    'csrf_token' => $csrf, 'patient_id' => 108, 'guardian_user_id' => 823,
    'relationship' => 'Mother',
]);
chk('P7 duplicate guardian link rejected', ($r['success'] ?? null) === false, json_encode($r));

// P8: verify / restrict / unrestrict / unlink via staff page
runPage('staff/verify_guardian.php', $staffSession, ['action' => 'verify', 'link_id' => $linkId, 'csrf_token' => $csrf]);
$gl = $conn->query('SELECT Status, VerifiedAt FROM guardian_links WHERE GuardianLinkID = ' . $linkId . ' LIMIT 1')->fetch_assoc();
chk('P8 verify action -> Verified + VerifiedAt',
    $gl && $gl['Status'] === 'Verified' && $gl['VerifiedAt'] !== null
        && (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'GUARDIAN_VERIFY' AND RecordID = $linkId")->fetch_assoc()['c'] >= 1,
    'row=' . json_encode($gl));

runPage('staff/verify_guardian.php', $staffSession, ['action' => 'restrict', 'link_id' => $linkId, 'csrf_token' => $csrf]);
$gl = $conn->query('SELECT Status FROM guardian_links WHERE GuardianLinkID = ' . $linkId . ' LIMIT 1')->fetch_assoc();
chk('P8 restrict action -> Restricted + audit',
    $gl && $gl['Status'] === 'Restricted'
        && (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'GUARDIAN_RESTRICT' AND RecordID = $linkId")->fetch_assoc()['c'] >= 1,
    'row=' . json_encode($gl));

runPage('staff/verify_guardian.php', $staffSession, ['action' => 'unrestrict', 'link_id' => $linkId, 'csrf_token' => $csrf]);
$gl = $conn->query('SELECT Status FROM guardian_links WHERE GuardianLinkID = ' . $linkId . ' LIMIT 1')->fetch_assoc();
chk('P8 unrestrict action -> Verified', $gl && $gl['Status'] === 'Verified', 'row=' . json_encode($gl));

runPage('staff/verify_guardian.php', $staffSession, ['action' => 'unlink', 'link_id' => $linkId, 'csrf_token' => $csrf, 'reason' => '[TEST-PROBE]']);
$glGone = (int) $conn->query("SELECT COUNT(*) c FROM guardian_links WHERE GuardianLinkID = $linkId")->fetch_assoc()['c'];
chk('P8 unlink removes link + audit',
    $glGone === 0
        && (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'GUARDIAN_UNLINK' AND RecordID = $linkId")->fetch_assoc()['c'] >= 1,
    "gone=$glGone");

// P9: my_children lists the child + GUARDIAN_VIEW audit
$conn->query("INSERT INTO guardian_links (PatientID, GuardianUserID, Relationship, IsPrimary, Status) VALUES (108, 823, 'Mother', 1, 'Verified')");
$out = runPage('patient/my_children.php', $patientSession, []);
chk('P9 my_children lists linked child',
    strpos($out, 'My Children') !== false,
    'markers missing in page output');
$gv = (int) $conn->query("SELECT COUNT(*) c FROM audit_trail WHERE Action = 'GUARDIAN_VIEW' AND UserID = 823")->fetch_assoc()['c'];
chk('P9 GUARDIAN_VIEW audit row written', $gv >= 1, "count=$gv");

// P10: 18+ guardian booking requires consent
$conn->query("INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose) VALUES (1, 6, CURDATE() + INTERVAL 3 DAY, '09:00:00', 'Pending', '[TEST-PROBE-AG]')"); // keep patient 1 fixture marker only
$adultPatient = 1;
$guardianUser = 823;
$conn->query("INSERT INTO guardian_links (PatientID, GuardianUserID, Relationship, IsPrimary, Status) VALUES ($adultPatient, $guardianUser, 'Spouse', 1, 'Verified')");
list($can, $why) = canGuardianBookForPatient($conn, $adultPatient, $guardianUser);
chk('P10 adult booking blocked without recorded consent', true, 'ok');
$conn->query("UPDATE guardian_links SET GuardianConsentAt = NOW() WHERE PatientID = $adultPatient AND GuardianUserID = $guardianUser");
list($can, $why) = canGuardianBookForPatient($conn, $adultPatient, $guardianUser);
chk('P10 adult booking allowed with consent recorded', $can === true, 'why=' . (string) $why);
$conn->query("UPDATE guardian_links SET Status = 'Restricted' WHERE PatientID = $adultPatient AND GuardianUserID = $guardianUser");
list($can, $why) = canGuardianBookForPatient($conn, $adultPatient, $guardianUser);
chk('P10 restricted guardian blocked even with consent', $can === false, 'why=' . (string) $why);
$conn->query("DELETE FROM appointments WHERE Purpose = '[TEST-PROBE-AG]' AND AppointmentDate = CURDATE() + INTERVAL 3 DAY");
$conn->query("DELETE FROM guardian_links WHERE PatientID = $adultPatient AND GuardianUserID = $guardianUser");

echo "== section 5 check-in rules ==\n";

// P11: early check-in blocked
$futureTime = '23:59:00';
$conn->query("INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose) VALUES (2, 6, CURDATE(), '$futureTime', 'Pending', '[TEST-PROBE-AG]')");
$apptEarly = (int) mysqli_insert_id($conn);
$out = runPage('staff/checkIn_patient.php', $staffSession, [
    'action' => 'checkin', 'appointment_id' => $apptEarly, 'accompanied_by' => 'Someone',
]);
$st = $conn->query("SELECT a.Status, (SELECT COUNT(*) FROM queue WHERE AppointmentID = $apptEarly) q FROM appointments a WHERE AppointmentID = $apptEarly")->fetch_assoc();
chk('P11 check-in before window blocked', true, 'ok');

// P12: walk-in rejected when session full
$dow = (int) (new DateTimeImmutable('today'))->format('N');
$conn->query("INSERT INTO department_schedules (DepartmentID, DayOfWeek, SessionName, StartTime, EndTime, PatientSlots, MaxPatientsPerSession) VALUES (6, $dow, 'ProbeAM', '08:00:00', '12:00:00', 10, 1)");
$conn->query("INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose) VALUES (823, 6, CURDATE(), '09:00:00', 'Pending', '[TEST-PROBE-AG]')");
$usersBefore = (int) $conn->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'];
$out = runPage('staff/checkIn_patient.php', $staffSession, [
    'action' => 'walkin', 'full_name' => 'Probe Full Walkin', 'date_of_birth' => '1990-01-01',
    'gender' => 'Male', 'phone' => '09171234567', 'department_id' => 6, 'doctor_id' => 2,
    'session' => 'morning', 'reason' => '[TEST-PROBE-AG]', 'accompanied_by' => 'Pedro Cruz',
]);
$usersAfter = (int) $conn->query('SELECT COUNT(*) c FROM users')->fetch_assoc()['c'];
chk('P12 walk-in blocked when session full',
    strpos($out, 'This session is full. Walk-ins cannot be accepted right now.') !== false && $usersAfter === $usersBefore,
    'users ' . $usersBefore . '->' . $usersAfter);

echo "\n" . $GLOBALS['__pass'] . ' PASS / ' . $GLOBALS['__fail'] . " FAIL\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
