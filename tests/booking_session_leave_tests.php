<?php
/**
 * §1 data-model fixes: session-window capacity, breaks, doctor leave.
 *
 * Covers:
 *  - #2 sessionBookedCount counts the WHOLE session window (not one clock time)
 *  - #1 BreakStart/BreakEnd are enforced by bookingValidateCore
 *  - sessionMaxCapacity honours the per-row override, falls back to the setting
 *  - #3 DoctorUnavailability excludes doctors from assignment + correct messages
 *
 * All fixture rows are tagged '[TEST-SESSION]' and removed on shutdown.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/booking_rules_core.php';

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

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

function cleanupFixtures(): void
{
    global $conn, $createdAppts, $fixtureDept, $fixtureDow, $fixtureSession;
    if (!isset($conn) || !$conn) return;

    foreach (($createdAppts ?? []) as $id) {
        mysqli_query($conn, 'DELETE FROM appointments WHERE AppointmentID = ' . (int) $id);
    }
    mysqli_query($conn, "DELETE FROM doctorunavailability WHERE Reason = '[TEST-SESSION]'");

    if (!empty($fixtureDept)) {
        $sql = 'UPDATE department_schedules
                SET BreakStart = NULL, BreakEnd = NULL, MaxPatientsPerSession = NULL
                WHERE DepartmentID = ' . (int) $fixtureDept
               . ' AND DayOfWeek = ' . (int) $fixtureDow
               . " AND SessionName = '" . mysqli_real_escape_string($conn, $fixtureSession) . "'";
        mysqli_query($conn, $sql);
    }
}

$createdAppts = [];
$fixtureDept = 0;
$fixtureDow = 0;
$fixtureSession = '';
register_shutdown_function('cleanupFixtures');

function fatal(string $msg): void
{
    echo "FATAL  $msg\n";
    exit(1);
}

// ---------------------------------------------------------------------
// Pick a test date (Sundays ahead, within the 30-day booking window)
// and a department open that day with an adult age band and a doctor.
// ---------------------------------------------------------------------
$testDate = '';
$deptId = 0;
$sessions = [];

for ($off = 7; $off <= 28; $off += 7) {
    $dateStr = (new DateTimeImmutable('today'))->modify("+$off days")->format('Y-m-d');
    if (isHospitalHoliday($conn, $dateStr)) continue;
    $dow = (int) (new DateTimeImmutable($dateStr))->format('N');

    $res = mysqli_query(
        $conn,
        'SELECT ds.DepartmentID, COUNT(*) AS c
         FROM department_schedules ds
         JOIN departments d ON d.DepartmentID = ds.DepartmentID
         WHERE ds.DayOfWeek = ' . $dow . ' AND d.MaxAge >= 18
         GROUP BY ds.DepartmentID
         ORDER BY c DESC, ds.DepartmentID'
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $cand = (int) $r['DepartmentID'];
        $sess = departmentSessionsForDay($conn, $cand, $dow);
        if (!$sess) continue;
        if (findAssignableDoctor($conn, $cand, $dateStr, $sess[0]['StartTime']) <= 0) continue;
        $testDate = $dateStr;
        $deptId = $cand;
        $sessions = $sess;
        break 2;
    }
}

if ($testDate === '') fatal('no department/date with sessions + available doctor inside booking window');
$dow = (int) (new DateTimeImmutable($testDate))->format('N');
$fixtureDept = $deptId;
$fixtureDow = $dow;
$fixtureSession = (string) $sessions[0]['SessionName'];

echo "== dept $deptId, $testDate (dow $dow), session '$fixtureSession' ("
    . count($sessions) . " session(s)) ==\n";

check('department has at least one session on the test date', count($sessions) >= 1);

// ---------------------------------------------------------------------
// Phase A: two fixture appointments inside session 1 -> window counts
// ---------------------------------------------------------------------
$amStart = $sessions[0]['StartTime'];
$amEnd = $sessions[0]['EndTime'];
$t1 = $amStart;
$t2 = (new DateTime($amEnd))->modify('-60 minutes')->format('H:i:s');
if ($t2 <= $t1) $t2 = (new DateTime($t1))->modify('+30 minutes')->format('H:i:s');

$pids = [];
$res = mysqli_query($conn, 'SELECT PatientID FROM patients ORDER BY PatientID LIMIT 10');
while ($res && ($r = mysqli_fetch_assoc($res))) $pids[] = (int) $r['PatientID'];
if (count($pids) < 2) fatal('need at least 2 patient rows for fixtures');

foreach ([$t1, $t2] as $i => $t) {
    $sql = "INSERT INTO appointments (PatientID, DepartmentID, AppointmentDate, AppointmentTime, Status, Purpose)
            VALUES (" . (int) $pids[$i] . ", " . (int) $deptId . ", '$testDate', '$t', 'Pending', '[TEST-SESSION]')";
    if (!mysqli_query($conn, $sql)) fatal('fixture insert failed: ' . mysqli_error($conn));
    $createdAppts[] = (int) mysqli_insert_id($conn);
}

$winAtStart = sessionWindowForTime($conn, $deptId, $testDate, $t1);
check('sessionWindowForTime finds the session at its start', $winAtStart !== null);
check(
    'session name comes from the schedule row (no time heuristic)',
    $winAtStart !== null && (string) $winAtStart['SessionName'] === $fixtureSession,
    $winAtStart['SessionName'] ?? 'null'
);

$gapTime = $amEnd;
$hasGap = count($sessions) < 2 || $sessions[0]['EndTime'] < $sessions[1]['StartTime'];
if ($hasGap) {
    check(
        'slot at the session end is outside every window (end exclusive)',
        sessionWindowForTime($conn, $deptId, $testDate, $gapTime) === null,
        $gapTime
    );
}

$bookedAtT1 = sessionBookedCount($conn, $deptId, $testDate, $t1);
$bookedAtT2 = sessionBookedCount($conn, $deptId, $testDate, $t2);
check('window count at first slot = 2 (whole window, not exact time)', $bookedAtT1 === 2, "got $bookedAtT1");
check('window count at other slot = 2 (same window)', $bookedAtT2 === 2, "got $bookedAtT2");

if (count($sessions) >= 2) {
    $pmStart = $sessions[1]['StartTime'];
    check(
        'session 2 window exists at its start',
        sessionWindowForTime($conn, $deptId, $testDate, $pmStart) !== null
    );
    check(
        'session 2 window has 0 booked (session 1 bookings do not leak)',
        sessionBookedCount($conn, $deptId, $testDate, $pmStart) === 0
    );
}

// ---------------------------------------------------------------------
// Candidate patient: adult, complete, zero active appointments
// ---------------------------------------------------------------------
function findCandidate(mysqli $conn, int $deptId, string $date, string $time): int
{
    $res = mysqli_query(
        $conn,
        "SELECT p.PatientID
         FROM patients p
         LEFT JOIN users u ON u.UserID = p.UserID
         WHERE COALESCE(u.DateOfBirth, p.DateOfBirth) IS NOT NULL
           AND TIMESTAMPDIFF(YEAR, COALESCE(u.DateOfBirth, p.DateOfBirth), CURDATE()) >= 18
           AND p.EmergencyContactName IS NOT NULL AND p.EmergencyContactName <> ''
           AND p.EmergencyContactNo IS NOT NULL AND p.EmergencyContactNo <> ''
         ORDER BY p.PatientID"
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $pid = (int) $r['PatientID'];
        if (countActiveAppointmentsForPatient($conn, $pid) > 0) continue;
        if (countActivePerDepartment($conn, $pid, $deptId) > 0) continue;
        list($ok, $msg) = bookingValidateCore($conn, [
            'patient_id' => $pid,
            'department_id' => $deptId,
            'appointment_date' => $date,
            'appointment_time' => $time,
        ]);
        if ($ok) return $pid;
    }
    return 0;
}

$candidate = findCandidate($conn, $deptId, $testDate, $t1);
check('candidate patient passes bookingValidateCore (happy path baseline)', $candidate > 0);
if ($candidate <= 0) fatal('no clean candidate patient available');

// A probe time inside session 1, outside any break.
$probe = (new DateTime($t1))->modify('+30 minutes')->format('H:i:s');

// ---------------------------------------------------------------------
// Phase B: enforce the break
// ---------------------------------------------------------------------
$breakStartObj = (new DateTime($amEnd))->modify('-60 minutes');
if ($breakStartObj <= (new DateTime($amStart))->modify('+15 minutes')) {
    $breakStartObj = (new DateTime($amStart))->modify('+15 minutes');
}
$breakStart = $breakStartObj->format('H:i:s');
$breakEnd = (clone $breakStartObj)->modify('+30 minutes')->format('H:i:s');

$sql = "UPDATE department_schedules
        SET BreakStart = '$breakStart', BreakEnd = '$breakEnd'
        WHERE DepartmentID = " . (int) $deptId . ' AND DayOfWeek = ' . (int) $dow
        . " AND SessionName = '" . mysqli_real_escape_string($conn, $fixtureSession) . "'";
if (!mysqli_query($conn, $sql)) fatal('break update failed: ' . mysqli_error($conn));

$winWithBreak = sessionWindowForTime($conn, $deptId, $testDate, $breakStart);
check('window row carries BreakStart/BreakEnd', $winWithBreak !== null && $winWithBreak['BreakStart'] === $breakStart);
check('timeInsideBreak true inside the break', timeInsideBreak($winWithBreak, $breakStart));
check('timeInsideBreak false before the break', !timeInsideBreak($winWithBreak, $t1));

list($okBreak, $msgBreak) = bookingValidateCore($conn, [
    'patient_id' => $candidate,
    'department_id' => $deptId,
    'appointment_date' => $testDate,
    'appointment_time' => $breakStart,
]);
check(
    'bookingValidateCore rejects a slot inside the break',
    $okBreak === false && $msgBreak === "That time is during the doctor's break. Please choose another time.",
    (string) $msgBreak
);

// ---------------------------------------------------------------------
// Phase C: session capacity (override + setting fallback + window count)
// ---------------------------------------------------------------------
$sql = "UPDATE department_schedules SET MaxPatientsPerSession = 2
        WHERE DepartmentID = " . (int) $deptId . ' AND DayOfWeek = ' . (int) $dow
        . " AND SessionName = '" . mysqli_real_escape_string($conn, $fixtureSession) . "'";
if (!mysqli_query($conn, $sql)) fatal('capacity update failed: ' . mysqli_error($conn));

check(
    'sessionMaxCapacity uses the per-row override',
    sessionMaxCapacity($conn, $deptId, $dow, $fixtureSession) === 2
);

// $probe has zero bookings at its exact clock time, but the window holds 2.
$exactCount = 0;
$stmt = mysqli_prepare($conn, 'SELECT COUNT(*) c FROM appointments WHERE DepartmentID=? AND AppointmentDate=? AND AppointmentTime=? AND Status = "Pending"');
mysqli_stmt_bind_param($stmt, 'iss', $deptId, $testDate, $probe);
mysqli_stmt_execute($stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
$exactCount = (int) ($row['c'] ?? 0);
mysqli_stmt_close($stmt);

list($okFull, $msgFull) = bookingValidateCore($conn, [
    'patient_id' => $candidate,
    'department_id' => $deptId,
    'appointment_date' => $testDate,
    'appointment_time' => $probe,
]);
check(
    'session full: probe (0 exact-time bookings, 2/2 in window) rejected',
    $okFull === false && $msgFull === 'This session is full.',
    (string) $msgFull
);
check(
    'window counting is what rejected it (exact-time count is ' . $exactCount . ')',
    $exactCount === 0
);

$sql = "UPDATE department_schedules SET MaxPatientsPerSession = NULL
        WHERE DepartmentID = " . (int) $deptId . ' AND DayOfWeek = ' . (int) $dow
        . " AND SessionName = '" . mysqli_real_escape_string($conn, $fixtureSession) . "'";
mysqli_query($conn, $sql);
check(
    'sessionMaxCapacity falls back to the max_patients_per_session setting',
    sessionMaxCapacity($conn, $deptId, $dow, $fixtureSession) === bookingSettingInt($conn, 'max_patients_per_session', 20)
);

// ---------------------------------------------------------------------
// Phase D: doctor leave (department-wide, then doctor-specific)
// ---------------------------------------------------------------------
$sql = "INSERT INTO doctorunavailability (DoctorID, DepartmentID, StartDate, EndDate, Reason, Priority, CreatedBy)
        VALUES (NULL, " . (int) $deptId . ", '$testDate', '$testDate', '[TEST-SESSION]', 'NORMAL', 500)";
if (!mysqli_query($conn, $sql)) fatal('leave insert failed: ' . mysqli_error($conn));

check('department-wide closure: findAssignableDoctor returns 0', findAssignableDoctor($conn, $deptId, $testDate, $probe) === 0);
check('department-wide closure: departmentHasDoctorForSlot false', !departmentHasDoctorForSlot($conn, $deptId, $testDate, $probe));

list($okLeave, $msgLeave) = bookingValidateCore($conn, [
    'patient_id' => $candidate,
    'department_id' => $deptId,
    'appointment_date' => $testDate,
    'appointment_time' => $probe,
]);
check(
    'bookingValidateCore: "This doctor is on leave on that date."',
    $okLeave === false && $msgLeave === 'This doctor is on leave on that date.',
    (string) $msgLeave
);

// Regression: isDoctorOnLeave must not leak across departments (old query
// matched ANY department-wide row for ANY doctor).
$stmt = mysqli_prepare($conn, 'SELECT StaffID FROM staff WHERE DepartmentID=? AND StaffRole="Doctor" AND AvailabilityStatus="Available" LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $deptId);
mysqli_stmt_execute($stmt);
$deptDoc = (int) ((mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?? ['StaffID' => 0])['StaffID']);
mysqli_stmt_close($stmt);

$otherDoc = 0;
$res = mysqli_query($conn, 'SELECT StaffID FROM staff WHERE DepartmentID <> ' . (int) $deptId . ' AND StaffRole = "Doctor" LIMIT 1');
if ($res && ($r = mysqli_fetch_assoc($res))) $otherDoc = (int) $r['StaffID'];

if ($deptDoc > 0) {
    check('isDoctorOnLeave true for doctor inside the closed department', isDoctorOnLeave($conn, $deptDoc, $testDate));
}
if ($otherDoc > 0) {
    check('isDoctorOnLeave false for doctor in ANOTHER department (old leak)', !isDoctorOnLeave($conn, $otherDoc, $testDate));
}

// Doctor-specific leave: other doctors stay assignable, the named one does not.
mysqli_query($conn, "DELETE FROM doctorunavailability WHERE Reason = '[TEST-SESSION]'");
if ($deptDoc > 0) {
    $sql = "INSERT INTO doctorunavailability (DoctorID, DepartmentID, StartDate, EndDate, Reason, Priority, CreatedBy)
            VALUES (" . (int) $deptDoc . ", NULL, '$testDate', '$testDate', '[TEST-SESSION]', 'NORMAL', 500)";
    if (!mysqli_query($conn, $sql)) fatal('doctor leave insert failed: ' . mysqli_error($conn));
    check('isDoctorOnLeave true for the named doctor', isDoctorOnLeave($conn, $deptDoc, $testDate));

    $assign = findAssignableDoctor($conn, $deptId, $testDate, $probe);
    check('named doctor is never assigned', $assign !== $deptDoc, "assigned $assign");

    list($okDoc, $msgDoc) = bookingValidateCore($conn, [
        'patient_id' => $candidate,
        'department_id' => $deptId,
        'appointment_date' => $testDate,
        'appointment_time' => $probe,
    ]);
    if ($assign > 0) {
        check(
            'another doctor takes the slot when one is on leave',
            $okDoc === true,
            is_array($msgDoc) ? json_encode($msgDoc) : (string) $msgDoc
        );
    } else {
        check(
            'no other doctor: leave message returned',
            $okDoc === false && $msgDoc === 'This doctor is on leave on that date.',
            (string) $msgDoc
        );
    }
} else {
    echo "SKIP  doctor-specific leave tests (no available doctor in dept $deptId)\n";
}

// ---------------------------------------------------------------------
// Phase E: restore everything, happy path passes
// ---------------------------------------------------------------------
mysqli_query($conn, "DELETE FROM doctorunavailability WHERE Reason = '[TEST-SESSION]'");
mysqli_query($conn, $sql = "UPDATE department_schedules
        SET BreakStart = NULL, BreakEnd = NULL, MaxPatientsPerSession = NULL
        WHERE DepartmentID = " . (int) $deptId . ' AND DayOfWeek = ' . (int) $dow
        . " AND SessionName = '" . mysqli_real_escape_string($conn, $fixtureSession) . "'");

list($okEnd, $msgEnd) = bookingValidateCore($conn, [
    'patient_id' => $candidate,
    'department_id' => $deptId,
    'appointment_date' => $testDate,
    'appointment_time' => $probe,
]);
check(
    'happy path validates after cleanup',
    $okEnd === true,
    is_array($msgEnd) ? json_encode($msgEnd) : (string) $msgEnd
);

echo "\n" . $GLOBALS['__pass'] . ' PASS / ' . $GLOBALS['__fail'] . " FAIL\n";
exit($GLOBALS['__fail'] > 0 ? 1 : 0);
