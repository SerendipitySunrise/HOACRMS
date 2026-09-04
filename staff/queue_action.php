<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
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

        mysqli_stmt_bind_param(
            $stmt,
            'sis',
            $queueStatus = QUEUE_STATUS_CALLED,
            $queueID,
            $queueFrom = QUEUE_STATUS_WAITING
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

        if ($apptRow) {
            $apptID = (int) $apptRow['AppointmentID'];
            $updAppt = mysqli_prepare(
                $conn,
                'UPDATE appointments SET Status = ? WHERE AppointmentID = ?'
            );
            mysqli_stmt_bind_param(
                $updAppt,
                'si',
                $apptStatus = APPT_STATUS_CALLED,
                $apptID
            );
            if (!mysqli_stmt_execute($updAppt)) {
                throw new Exception('Failed to update appointment.');
            }
        }

        mysqli_commit($conn);

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

        mysqli_stmt_bind_param(
            $stmt,
            'sis',
            $queueStatus = QUEUE_STATUS_COMPLETED,
            $queueID,
            $queueFrom = QUEUE_STATUS_IN_CONSULTATION
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

        if ($apptRow) {
            $apptID = (int) $apptRow['AppointmentID'];
            $updAppt = mysqli_prepare(
                $conn,
                'UPDATE appointments SET Status = ? WHERE AppointmentID = ?'
            );
            mysqli_stmt_bind_param(
                $updAppt,
                'si',
                $apptStatus = APPT_STATUS_COMPLETED,
                $apptID
            );
            if (!mysqli_stmt_execute($updAppt)) {
                throw new Exception('Failed to update appointment.');
            }
        }

        mysqli_commit($conn);

        $message = 'Consultation completed.';
        $messageType = 'success';

    } catch (Exception $e) {

        mysqli_rollback($conn);

        $message = 'Unable to complete consultation.';
    }
}

header('Location: staff_dashboard.php?message=' . urlencode($message) . '&type=' . urlencode($messageType));
exit();
