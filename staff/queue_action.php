<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
require_once __DIR__ . '/../includes/status_constants.php';

if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php?portal=staff');
    exit();
}

$sessionRole = strtolower(trim((string) ($_SESSION['RoleName'] ?? '')));
if (!in_array($sessionRole, ['staff', 'nurse'], true)) {
    header('Location: ../auth/login.php?portal=staff');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff_dashboard.php');
    exit();
}

$action = $_POST['action'] ?? '';
$queueID = (int) ($_POST['queue_id'] ?? 0);

if ($queueID <= 0 || !in_array($action, ['call', 'complete'], true)) {
    header('Location: staff_dashboard.php');
    exit();
}

$message = '';
$messageType = 'error';

if ($action === 'call') {

    mysqli_begin_transaction($conn);

    try {

        $stmt = mysqli_prepare(
            $conn,
            'UPDATE queue
             SET Status = ?
             WHERE QueueID = ?
               AND Status = ?'
        );

        $queueStatus = QUEUE_STATUS_CALLED;
        $queueFrom   = QUEUE_STATUS_WAITING;

        mysqli_stmt_bind_param(
            $stmt,
            'sis',
            $queueStatus,
            $queueID,
            $queueFrom
        );

        if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
            throw new Exception('Failed to call patient.');
        }

        $getAppt = mysqli_prepare(
            $conn,
            'SELECT AppointmentID FROM queue WHERE QueueID = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($getAppt, 'i', $queueID);
        if (!mysqli_stmt_execute($getAppt)) {
            throw new Exception('Failed to read queue entry.');
        }
        $apptRow = mysqli_fetch_assoc(mysqli_stmt_get_result($getAppt));

        $apptID = 0;
        if ($apptRow) {
            $apptID = (int) $apptRow['AppointmentID'];
            $updAppt = mysqli_prepare(
                $conn,
                'UPDATE appointments SET Status = ? WHERE AppointmentID = ?'
            );
            $apptStatus = APPT_STATUS_CALLED;

            mysqli_stmt_bind_param(
                $updAppt,
                'si',
                $apptStatus,
                $apptID
            );
            if (!mysqli_stmt_execute($updAppt)) {
                throw new Exception('Failed to update appointment.');
            }
        }

        mysqli_commit($conn);

        if ($apptID > 0) {
            adminNotificationNotifyAppointmentStatus($conn, $apptID, APPT_STATUS_CALLED);
        }

        $message = 'Patient called successfully.';
        $messageType = 'success';

    } catch (Exception $e) {

        mysqli_rollback($conn);

        $message = 'Unable to call patient.';
    }

} elseif ($action === 'complete') {

    mysqli_begin_transaction($conn);

    try {

        $stmt = mysqli_prepare(
            $conn,
            'UPDATE queue
             SET Status = ?
             WHERE QueueID = ?
               AND Status = ?'
        );

        $queueStatus = QUEUE_STATUS_COMPLETED;
        $queueFrom   = QUEUE_STATUS_IN_CONSULTATION;

        mysqli_stmt_bind_param(
            $stmt,
            'sis',
            $queueStatus,
            $queueID,
            $queueFrom
        );

        if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) < 1) {
            throw new Exception('Failed to complete consultation.');
        }

        $getAppt = mysqli_prepare(
            $conn,
            'SELECT AppointmentID FROM queue WHERE QueueID = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($getAppt, 'i', $queueID);
        if (!mysqli_stmt_execute($getAppt)) {
            throw new Exception('Failed to read queue entry.');
        }
        $apptRow = mysqli_fetch_assoc(mysqli_stmt_get_result($getAppt));

        $apptID = 0;
        if ($apptRow) {
            $apptID = (int) $apptRow['AppointmentID'];
            $updAppt = mysqli_prepare(
                $conn,
                'UPDATE appointments SET Status = ? WHERE AppointmentID = ?'
            );
            $apptStatus = APPT_STATUS_COMPLETED;

            mysqli_stmt_bind_param(
                $updAppt,
                'si',
                $apptStatus,
                $apptID
            );
            if (!mysqli_stmt_execute($updAppt)) {
                throw new Exception('Failed to update appointment.');
            }
        }

        mysqli_commit($conn);

        if ($apptID > 0) {
            adminNotificationNotifyAppointmentStatus($conn, $apptID, APPT_STATUS_COMPLETED);
        }

        $message = 'Consultation completed.';
        $messageType = 'success';

    } catch (Exception $e) {

        mysqli_rollback($conn);

        $message = 'Unable to complete consultation.';
    }
}

header('Location: staff_dashboard.php?message=' . urlencode($message) . '&type=' . urlencode($messageType));
exit();
