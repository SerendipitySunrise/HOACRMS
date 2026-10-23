<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/booking_session_capacity.php';
require_once __DIR__ . '/../../includes/booking_settings.php';
require_once __DIR__ . '/../../includes/appointment_assignment.php';

header('Content-Type: application/json');
function respond(bool $s, string $m, array $e=[]){ echo json_encode(array_merge(['success'=>$s,'message'=>$m],$e)); exit; }
if (!isset($_SESSION['UserID'])) respond(false,'Session expired');
if (($_SESSION['RoleName'] ?? '') !== 'Patient') respond(false,'Unauthorized');

$departmentID = (int)($_POST['department_id'] ?? 0);
$appointmentDate = trim($_POST['appointment_date'] ?? '');
if ($departmentID <= 0 || $appointmentDate === '') respond(false,'Missing params');

$dt = DateTime::createFromFormat('Y-m-d', $appointmentDate);
if (!$dt || $dt->format('Y-m-d') !== $appointmentDate) respond(false,'Invalid date');
$dow = (int)$dt->format('N');

// Same-day slots earlier than the booking window (min hours ahead) are shown disabled.
$minHours = bookingSettingInt($conn, 'booking_window_min_hours', 2);
$earliest = (new DateTimeImmutable('now'))->modify('+' . $minHours . ' hours');

/*
 * Build slots per session window. Capacity and "booked" are counted across
 * the WHOLE session window (department-wide), never at a single clock time.
 * Break slots are not offered. A slot is available only when the session is
 * not full, the time honours the booking window, and at least one doctor
 * (Available, not on leave, not already taken at that exact time) can take it.
 */
$sessions = departmentSessionsForDay($conn, $departmentID, $dow);
$reportedCapacity = bookingSettingInt($conn, 'max_patients_per_session', 20);
$out = [];

foreach ($sessions as $session) {
    $sessionName = (string)$session['SessionName'];
    $cap = sessionMaxCapacity($conn, $departmentID, $dow, $sessionName);
    $sessionBooked = sessionWindowBookedCount(
        $conn,
        $departmentID,
        $appointmentDate,
        $session['StartTime'],
        $session['EndTime']
    );
    $sessionFull = $sessionBooked >= $cap;
    if ($cap > $reportedCapacity) {
        $reportedCapacity = $cap;
    }

    $start = new DateTime($session['StartTime']);
    $end = new DateTime($session['EndTime']);
    while ($start < $end) {
        $t = $start->format('H:i:s');
        $start->modify('+30 minutes');

        if (timeInsideBreak($session, $t)) {
            continue; // breaks are simply not offered
        }

        $slotMoment = new DateTimeImmutable($appointmentDate . ' ' . $t);
        $inWindow = $slotMoment >= $earliest;
        $assignable = !$sessionFull && $inWindow
            && findAssignableDoctor($conn, $departmentID, $appointmentDate, $t) > 0;

        $out[] = [
            'time' => $t,
            'label' => date('g:i A', strtotime($t)),
            'session' => $sessionName,
            'booked' => $sessionBooked,
            'capacity' => $cap,
            'available' => $assignable,
        ];
    }
}

usort($out, function ($a, $b) {
    return strcmp($a['time'], $b['time']);
});

echo json_encode(['success' => true, 'slots' => $out, 'capacity' => $reportedCapacity]);
exit;
