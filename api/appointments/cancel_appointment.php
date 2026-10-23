<?php
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/db_pdo.php';
require_once __DIR__ . '/../../includes/audit_helper.php';
require_once __DIR__ . '/../../includes/admin_notifications.php';
require_once __DIR__ . '/../../includes/booking_settings.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message): void
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit();
}

if (!isset($_SESSION['UserID'])) respond(false, 'Your session has expired. Please log in again.');
$role = $_SESSION['RoleName'] ?? '';
$userID = (int)$_SESSION['UserID'];

if ($role !== 'Patient' && $role !== 'Staff' && $role !== 'Admin') {
    respond(false, 'Unauthorized access.');
}

$appointmentID = (int)($_POST['appointment_id'] ?? 0);
if ($appointmentID <= 0) respond(false, 'Invalid appointment ID.');

$apptStmt = mysqli_prepare($conn, 'SELECT a.AppointmentID,a.PatientID,a.AppointmentDate,a.AppointmentTime,a.Status,p.UserID as PatientUserID FROM appointments a INNER JOIN patients p ON p.PatientID=a.PatientID WHERE a.AppointmentID=? LIMIT 1');
if (!$apptStmt) respond(false, 'Unable to load appointment.');
mysqli_stmt_bind_param($apptStmt, 'i', $appointmentID);
mysqli_stmt_execute($apptStmt);
$res = mysqli_stmt_get_result($apptStmt);
$app = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($apptStmt);
if (!$app) respond(false, 'Appointment not found.');

if ($role === 'Patient' && (int)$app['PatientUserID'] !== $userID) {
    respond(false, 'You are not allowed to cancel this appointment.');
}
if (in_array($app['Status'], ['Cancelled','Completed','Checked In'], true)) {
    respond(false, 'This appointment cannot be cancelled.');
}

$cutoffH = bookingSettingInt($conn, 'cancel_cutoff_hours', 4);
$start = DateTime::createFromFormat('!Y-m-d H:i:s', $app['AppointmentDate'].' '.$app['AppointmentTime']);
if (!$start) $start = DateTime::createFromFormat('!Y-m-d H:i', $app['AppointmentDate'].' '.$app['AppointmentTime']);
if ($role !== 'Staff' && $role !== 'Admin' && $start && $start < (new DateTime())->modify('+'.$cutoffH.' hours')) {
    respond(false, 'This appointment can only be cancelled at least '.$cutoffH.' hours before it starts.');
}

mysqli_begin_transaction($conn);
try {
    $upd = mysqli_prepare($conn, 'UPDATE appointments SET Status="Cancelled" WHERE AppointmentID=?');
    if (!$upd) throw new Exception('Failed');
    mysqli_stmt_bind_param($upd, 'i', $appointmentID);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    if ((int)$app['PatientUserID'] > 0) {
        adminNotificationCreateForUser($conn, (int)$app['PatientUserID'], 'Appointment Cancelled', 'Your appointment was cancelled.', 'Appointment', $appointmentID, 'appointments', 'Medium');
    }
    adminNotificationCreateForActiveAdmins($conn, 'Appointment Cancelled', 'An appointment was cancelled.', 'Appointment', $appointmentID, 'appointments', 'Medium');
    mysqli_commit($conn);

    // Spec 9: cancellations are written to the audit trail.
    logAudit(db_pdo(), 'APPOINTMENT_CANCEL', 'appointments', $appointmentID,
        json_encode([
            'status' => $app['Status'],
            'date'   => $app['AppointmentDate'],
            'time'   => $app['AppointmentTime'],
        ]),
        json_encode([
            'status'        => 'Cancelled',
            'cancelled_by'  => $userID,
            'role'          => $role,
            'reason'        => (string) ($_POST['reason'] ?? ''),
        ]));

    respond(true, 'Appointment cancelled successfully.');
} catch (Exception $e) {
    mysqli_rollback($conn);
    respond(false, 'Unable to cancel appointment.');
}
