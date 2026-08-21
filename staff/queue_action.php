<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header('Location: ../login.php?portal=staff');
    exit();
}

$sessionRole = strtolower(trim((string) ($_SESSION['RoleName'] ?? '')));
if (!in_array($sessionRole, ['staff', 'nurse'], true)) {
    header('Location: ../login.php?portal=staff');
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

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE queue
         SET Status = "Called"
         WHERE QueueID = ?
           AND Status = "Waiting"'
    );

    mysqli_stmt_bind_param($stmt, 'i', $queueID);

    if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {

        $getAppt = mysqli_prepare(
            $conn,
            'SELECT AppointmentID FROM queue WHERE QueueID = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($getAppt, 'i', $queueID);
        mysqli_stmt_execute($getAppt);
        $apptRow = mysqli_fetch_assoc(mysqli_stmt_get_result($getAppt));

        if ($apptRow) {
            $apptID = (int) $apptRow['AppointmentID'];
            $updAppt = mysqli_prepare(
                $conn,
                'UPDATE appointments SET Status = "Called" WHERE AppointmentID = ?'
            );
            mysqli_stmt_bind_param($updAppt, 'i', $apptID);
            mysqli_stmt_execute($updAppt);
        }

        $message = 'Patient called successfully.';
        $messageType = 'success';

    } else {

        $message = 'Unable to call patient.';
    }

} elseif ($action === 'complete') {

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE queue
         SET Status = "Completed"
         WHERE QueueID = ?
           AND Status = "In Progress"'
    );

    mysqli_stmt_bind_param($stmt, 'i', $queueID);

    if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) > 0) {

        $getAppt = mysqli_prepare(
            $conn,
            'SELECT AppointmentID FROM queue WHERE QueueID = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($getAppt, 'i', $queueID);
        mysqli_stmt_execute($getAppt);
        $apptRow = mysqli_fetch_assoc(mysqli_stmt_get_result($getAppt));

        if ($apptRow) {
            $apptID = (int) $apptRow['AppointmentID'];
            $updAppt = mysqli_prepare(
                $conn,
                'UPDATE appointments SET Status = "Completed" WHERE AppointmentID = ?'
            );
            mysqli_stmt_bind_param($updAppt, 'i', $apptID);
            mysqli_stmt_execute($updAppt);
        }

        $message = 'Consultation completed.';
        $messageType = 'success';

    } else {

        $message = 'Unable to complete consultation.';
    }
}

header('Location: staff_dashboard.php?message=' . urlencode($message) . '&type=' . urlencode($messageType));
exit();
