<?php
/**
 * queue.php
 * Staff Queue Management
 *
 * Uses the real MySQL database.
 * Workflow:
 *
 * Waiting → Called → In Progress → Completed
 *
 * Queue table:
 * QueueID
 * AppointmentID
 * QueueNumber
 * PriorityLevel
 * QueueDate
 * QueueTime
 * Status
 */

session_start();

require_once __DIR__ . '/../includes/db.php';


// ======================================================
// STAFF SECURITY CHECK
// ======================================================

if (!isset($_SESSION['UserID'])) {
    header('Location: ../login.php?portal=staff');
    exit();
}

if (
    !isset($_SESSION['RoleName']) ||
    $_SESSION['RoleName'] !== 'Staff'
) {
    header('Location: ../login.php?portal=staff');
    exit();
}


// ======================================================
// DATE
// ======================================================

$today = date('Y-m-d');

$message = '';
$messageType = '';


// ======================================================
// CURRENT STAFF
// ======================================================

$currentUserID = (int) $_SESSION['UserID'];

$staffStmt = mysqli_prepare(
    $conn,
    'SELECT StaffID
     FROM staff
     WHERE UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param(
    $staffStmt,
    'i',
    $currentUserID
);

mysqli_stmt_execute($staffStmt);

$staffResult = mysqli_stmt_get_result($staffStmt);

$currentStaff = mysqli_fetch_assoc($staffResult);

if (!$currentStaff) {
    die('Staff profile not found. Please contact the administrator.');
}

$currentStaffID = (int) $currentStaff['StaffID'];


// ======================================================
// HANDLE QUEUE ACTIONS
// ======================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $queueID = (int) ($_POST['queue_id'] ?? 0);


    // ==================================================
    // CALL NEXT PATIENT
    // ==================================================

    if ($action === 'call_next') {

        /*
         * Priority order:
         *
         * Urgent
         * Priority
         * Normal
         *
         * If same priority:
         * oldest QueueTime first
         */

        $nextStmt = mysqli_prepare(
            $conn,
            'SELECT QueueID
             FROM queue
             WHERE QueueDate = ?
             AND Status = "Waiting"
             ORDER BY
                CASE
                    WHEN PriorityLevel = "Urgent" THEN 1
                    WHEN PriorityLevel = "Priority" THEN 2
                    ELSE 3
                END,
                QueueTime ASC,
                QueueID ASC
             LIMIT 1'
        );

        mysqli_stmt_bind_param(
            $nextStmt,
            's',
            $today
        );

        mysqli_stmt_execute($nextStmt);

        $nextResult = mysqli_stmt_get_result($nextStmt);

        $nextPatient = mysqli_fetch_assoc($nextResult);


        if (!$nextPatient) {

            $message = 'There are no patients waiting.';
            $messageType = 'error';

        } else {

            $nextQueueID = (int) $nextPatient['QueueID'];

            mysqli_begin_transaction($conn);

            try {

                // Update queue
                $updateQueueStmt = mysqli_prepare(
                    $conn,
                    'UPDATE queue
                     SET Status = "Called"
                     WHERE QueueID = ?
                     AND Status = "Waiting"'
                );

                mysqli_stmt_bind_param(
                    $updateQueueStmt,
                    'i',
                    $nextQueueID
                );

                if (!mysqli_stmt_execute($updateQueueStmt)) {
                    throw new Exception('Failed to call patient.');
                }


                // Get appointment ID
                $appointmentStmt = mysqli_prepare(
                    $conn,
                    'SELECT AppointmentID
                     FROM queue
                     WHERE QueueID = ?
                     LIMIT 1'
                );

                mysqli_stmt_bind_param(
                    $appointmentStmt,
                    'i',
                    $nextQueueID
                );

                mysqli_stmt_execute($appointmentStmt);

                $appointmentResult =
                    mysqli_stmt_get_result($appointmentStmt);

                $appointmentData =
                    mysqli_fetch_assoc($appointmentResult);


                if ($appointmentData) {

                    $appointmentID =
                        (int) $appointmentData['AppointmentID'];

                    $appointmentUpdateStmt = mysqli_prepare(
                        $conn,
                        'UPDATE appointments
                         SET Status = "Called"
                         WHERE AppointmentID = ?'
                    );

                    mysqli_stmt_bind_param(
                        $appointmentUpdateStmt,
                        'i',
                        $appointmentID
                    );

                    mysqli_stmt_execute(
                        $appointmentUpdateStmt
                    );
                }


                mysqli_commit($conn);

                $message =
                    'Patient called successfully.';

                $messageType = 'success';

            } catch (Exception $e) {

                mysqli_rollback($conn);

                $message =
                    'Unable to call the next patient.';

                $messageType = 'error';
            }
        }
    }


    // ==================================================
    // INDIVIDUAL QUEUE ACTION
    // ==================================================

    elseif ($queueID > 0) {

        if ($action === 'call') {

            $queueStatus = 'Called';

            $stmt = mysqli_prepare(
                $conn,
                'UPDATE queue
                 SET Status = ?
                 WHERE QueueID = ?
                 AND Status = "Waiting"'
            );

            mysqli_stmt_bind_param(
                $stmt,
                'si',
                $queueStatus,
                $queueID
            );

            if (mysqli_stmt_execute($stmt)) {

                // Get appointment ID
                $getAppointmentStmt = mysqli_prepare(
                    $conn,
                    'SELECT AppointmentID
                     FROM queue
                     WHERE QueueID = ?
                     LIMIT 1'
                );

                mysqli_stmt_bind_param(
                    $getAppointmentStmt,
                    'i',
                    $queueID
                );

                mysqli_stmt_execute(
                    $getAppointmentStmt
                );

                $result =
                    mysqli_stmt_get_result(
                        $getAppointmentStmt
                    );

                $data =
                    mysqli_fetch_assoc($result);

                if ($data) {

                    $appointmentID =
                        (int) $data['AppointmentID'];

                    $appointmentUpdateStmt =
                        mysqli_prepare(
                            $conn,
                            'UPDATE appointments
                             SET Status = "Called"
                             WHERE AppointmentID = ?'
                        );

                    mysqli_stmt_bind_param(
                        $appointmentUpdateStmt,
                        'i',
                        $appointmentID
                    );

                    mysqli_stmt_execute(
                        $appointmentUpdateStmt
                    );
                }

                $message =
                    'Patient called successfully.';

                $messageType = 'success';

            } else {

                $message =
                    'Unable to call patient.';

                $messageType = 'error';
            }
        }


        // ==================================================
        // START CONSULTATION
        // ==================================================

        elseif ($action === 'start') {

            $queueStatus = 'In Progress';

            $stmt = mysqli_prepare(
                $conn,
                'UPDATE queue
                 SET Status = ?
                 WHERE QueueID = ?
                 AND Status = "Called"'
            );

            mysqli_stmt_bind_param(
                $stmt,
                'si',
                $queueStatus,
                $queueID
            );

            if (mysqli_stmt_execute($stmt)) {

                // Get appointment ID
                $getAppointmentStmt = mysqli_prepare(
                    $conn,
                    'SELECT AppointmentID
                     FROM queue
                     WHERE QueueID = ?
                     LIMIT 1'
                );

                mysqli_stmt_bind_param(
                    $getAppointmentStmt,
                    'i',
                    $queueID
                );

                mysqli_stmt_execute(
                    $getAppointmentStmt
                );

                $result =
                    mysqli_stmt_get_result(
                        $getAppointmentStmt
                    );

                $data =
                    mysqli_fetch_assoc($result);

                if ($data) {

                    $appointmentID =
                        (int) $data['AppointmentID'];

                    $appointmentUpdateStmt =
                        mysqli_prepare(
                            $conn,
                            'UPDATE appointments
                             SET Status = "In Consultation"
                             WHERE AppointmentID = ?'
                        );

                    mysqli_stmt_bind_param(
                        $appointmentUpdateStmt,
                        'i',
                        $appointmentID
                    );

                    mysqli_stmt_execute(
                        $appointmentUpdateStmt
                    );
                }

                $message =
                    'Consultation started.';

                $messageType = 'success';

            } else {

                $message =
                    'Unable to start consultation.';

                $messageType = 'error';
            }
        }


        // ==================================================
        // COMPLETE CONSULTATION
        // ==================================================

        elseif ($action === 'complete') {

            $queueStatus = 'Completed';

            $stmt = mysqli_prepare(
                $conn,
                'UPDATE queue
                 SET Status = ?
                 WHERE QueueID = ?
                 AND Status = "In Progress"'
            );

            mysqli_stmt_bind_param(
                $stmt,
                'si',
                $queueStatus,
                $queueID
            );

            if (mysqli_stmt_execute($stmt)) {

                // Get appointment ID
                $getAppointmentStmt = mysqli_prepare(
                    $conn,
                    'SELECT AppointmentID
                     FROM queue
                     WHERE QueueID = ?
                     LIMIT 1'
                );

                mysqli_stmt_bind_param(
                    $getAppointmentStmt,
                    'i',
                    $queueID
                );

                mysqli_stmt_execute(
                    $getAppointmentStmt
                );

                $result =
                    mysqli_stmt_get_result(
                        $getAppointmentStmt
                    );

                $data =
                    mysqli_fetch_assoc($result);

                if ($data) {

                    $appointmentID =
                        (int) $data['AppointmentID'];

                    $appointmentUpdateStmt =
                        mysqli_prepare(
                            $conn,
                            'UPDATE appointments
                             SET Status = "Completed"
                             WHERE AppointmentID = ?'
                        );

                    mysqli_stmt_bind_param(
                        $appointmentUpdateStmt,
                        'i',
                        $appointmentID
                    );

                    mysqli_stmt_execute(
                        $appointmentUpdateStmt
                    );
                }

                $message =
                    'Consultation completed.';

                $messageType = 'success';

            } else {

                $message =
                    'Unable to complete consultation.';

                $messageType = 'error';
            }
        }
    }


    // Prevent duplicate form submission
    header(
        'Location: queue.php?message=' .
        urlencode($message) .
        '&type=' .
        urlencode($messageType)
    );

    exit();
}


// ======================================================
// DISPLAY MESSAGE AFTER REDIRECT
// ======================================================

if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'] ?? 'success';
}


// ======================================================
// GET TODAY'S QUEUE
// ======================================================

$queue = [];

$queueStmt = mysqli_prepare(
    $conn,
    'SELECT
        q.QueueID,
        q.AppointmentID,
        q.QueueNumber,
        q.PriorityLevel,
        q.QueueDate,
        q.QueueTime,
        q.Status,

        a.AppointmentDate,
        a.AppointmentTime,
        a.Purpose,

        p.PatientID,

        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.ContactNumber,

        d.DepartmentName

     FROM queue q

     INNER JOIN appointments a
        ON q.AppointmentID = a.AppointmentID

     INNER JOIN patients p
        ON a.PatientID = p.PatientID

     INNER JOIN users u
        ON p.UserID = u.UserID

     INNER JOIN departments d
        ON a.DepartmentID = d.DepartmentID

     WHERE q.QueueDate = ?

     ORDER BY
        CASE
            WHEN q.Status = "In Progress" THEN 1
            WHEN q.Status = "Called" THEN 2
            WHEN q.Status = "Waiting" THEN 3
            WHEN q.Status = "Completed" THEN 4
            ELSE 5
        END,
        q.QueueTime ASC,
        q.QueueID ASC'
);

mysqli_stmt_bind_param(
    $queueStmt,
    's',
    $today
);

mysqli_stmt_execute($queueStmt);

$queueResult =
    mysqli_stmt_get_result($queueStmt);

while ($row = mysqli_fetch_assoc($queueResult)) {
    $queue[] = $row;
}


// ======================================================
// GROUP QUEUE BY STATUS
// ======================================================

$waiting = [];
$called = [];
$inProgress = [];
$completed = [];

foreach ($queue as $patient) {

    switch (strtolower($patient['Status'])) {

        case 'waiting':
            $waiting[] = $patient;
            break;

        case 'called':
            $called[] = $patient;
            break;

        case 'in progress':
            $inProgress[] = $patient;
            break;

        case 'completed':
            $completed[] = $patient;
            break;
    }
}


// ======================================================
// COUNTS
// ======================================================

$waitingCount = count($waiting);
$calledCount = count($called);
$inProgressCount = count($inProgress);
$completedCount = count($completed);


// ======================================================
// NOW SERVING
// ======================================================

$nowServing = $inProgress[0] ?? null;


// ======================================================
// STAFF INFO
// ======================================================

$staffInfoStmt = mysqli_prepare(
    $conn,
    "SELECT s.StaffRole, u.FirstName, u.LastName, u.ProfilePhoto
     FROM staff s
     INNER JOIN users u ON s.UserID = u.UserID
     WHERE s.UserID = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($staffInfoStmt, 'i', $currentUserID);
mysqli_stmt_execute($staffInfoStmt);
$staffInfoRow = mysqli_fetch_assoc(mysqli_stmt_get_result($staffInfoStmt));

$staffFirstName = $staffInfoRow['FirstName'] ?? ($_SESSION['FirstName'] ?? '');
$staffLastName = $staffInfoRow['LastName'] ?? ($_SESSION['LastName'] ?? '');
$staffRole = $staffInfoRow['StaffRole'] ?? 'Staff';
$staffProfilePhoto = $staffInfoRow['ProfilePhoto'] ?? null;
$staffInitials = strtoupper(substr($staffFirstName, 0, 1) . substr($staffLastName, 0, 1));
$staffName = htmlspecialchars($staffFirstName . ' ' . $staffLastName);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Queue Management — Staff Portal</title>

<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="../assets/css/staff/staff_dashboard.css"
>

<link
    rel="stylesheet"
    href="../assets/css/staff/queue.css"
>

</head>

<body>

<?php if ($message !== ''): ?>

<div
    style="
        margin:16px 36px 0;
        padding:12px 16px;
        border-radius:8px;

        background:
        <?php
        echo $messageType === 'success'
            ? '#dcfce7'
            : '#fee2e2';
        ?>;

        color:
        <?php
        echo $messageType === 'success'
            ? '#166534'
            : '#991b1b';
        ?>;

        font-size:14px;
        font-weight:500;
    "
>

<?= htmlspecialchars($message) ?>

</div>

<?php endif; ?>


<div class="app">


<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>

        </div>

        <div class="brand-text">

            <div class="brand-title">
                MediCare
            </div>

            <div class="brand-sub">
                Staff Portal
            </div>

        </div>

    </div>


    <ul class="nav-list">

        <li class="nav-item">

            <a
                href="../staff/staff_dashboard.php"
                style="
                    text-decoration:none;
                    color:inherit;
                    display:flex;
                    align-items:center;
                    gap:12px;
                "
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <rect x="3" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"/>
                </svg>

                Dashboard

            </a>

        </li>


        <li class="nav-item">

            <a
                href="../staff/checkin_patient.php"
                style="
                    text-decoration:none;
                    color:inherit;
                    display:flex;
                    align-items:center;
                    gap:12px;
                "
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <line x1="19" y1="8" x2="19" y2="14"/>
                    <line x1="16" y1="11" x2="22" y2="11"/>
                </svg>

                Patient Check-in

            </a>

        </li>


        <li class="nav-item active">

            <a
                href="../staff/queue.php"
                style="
                    text-decoration:none;
                    color:inherit;
                    display:flex;
                    align-items:center;
                    gap:12px;
                "
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <path d="M8 6h13"/>
                    <path d="M8 12h13"/>
                    <path d="M8 18h13"/>
                    <path d="M3 6h.01"/>
                    <path d="M3 12h.01"/>
                    <path d="M3 18h.01"/>
                </svg>

                Queue

            </a>

        </li>

        <li class="nav-item">

            <a
                href="staff_profile.php"
                style="
                    text-decoration:none;
                    color:inherit;
                    display:flex;
                    align-items:center;
                    gap:12px;
                "
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                >
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>

                Profile

            </a>

        </li>

    </ul>


    <div class="sidebar-footer">

        <div class="sidebar-user">

            <div class="user-avatar">
                <?php if (!empty($staffProfilePhoto)): ?>
                <img src="../<?php echo htmlspecialchars($staffProfilePhoto); ?>" alt="Photo">
                <?php else: ?>
                <?= $staffInitials ?>
                <?php endif; ?>
            </div>

            <div>

                <div class="user-name">
                    <?= $staffName ?>
                </div>

                <div class="user-role">
                    <?= htmlspecialchars($staffRole) ?>
                </div>

            </div>

        </div>


        <a
            href="../logout.php"
            class="sign-out"
            onclick="return confirm('Are you sure you want to sign out?');"
        >

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>

            Sign Out

        </a>

    </div>

</aside>


<!-- =====================================================
     MAIN
====================================================== -->

<main class="main">


<div class="staff-topbar">

    <div class="page-header">

        <h1>
            Queue Management
        </h1>

        <p>
            <?= date('l, F j, Y') ?>
        </p>

    </div>

</div>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="staff-stats">

    <div class="staff-stat-card">

        <div class="staff-stat-label">
            Waiting
        </div>

        <div class="staff-stat-value orange">
            <?= $waitingCount ?>
        </div>

    </div>


    <div class="staff-stat-card">

        <div class="staff-stat-label">
            Called
        </div>

        <div class="staff-stat-value blue">
            <?= $calledCount ?>
        </div>

    </div>


    <div class="staff-stat-card">

        <div class="staff-stat-label">
            In Progress
        </div>

        <div class="staff-stat-value teal">
            <?= $inProgressCount ?>
        </div>

    </div>


    <div class="staff-stat-card">

        <div class="staff-stat-label">
            Completed Today
        </div>

        <div class="staff-stat-value green">
            <?= $completedCount ?>
        </div>

    </div>

</div>


<!-- =====================================================
     NOW SERVING
====================================================== -->

<?php if ($nowServing): ?>

<div class="now-serving-hero">

    <div class="ns-left">

        <div class="ns-avatar">

            <?= strtoupper(
                substr($nowServing['FirstName'], 0, 1) .
                substr($nowServing['LastName'], 0, 1)
            ) ?>

        </div>


        <div>

            <div class="ns-label">
                Now Serving
            </div>

            <div class="ns-name">

                <?= htmlspecialchars(
                    $nowServing['FirstName'] .
                    ' ' .
                    $nowServing['LastName']
                ) ?>

            </div>


            <div class="ns-meta">

                <span class="ns-queue-num">

                    Q<?= str_pad(
                        $nowServing['QueueNumber'],
                        3,
                        '0',
                        STR_PAD_LEFT
                    ) ?>

                </span>


                <span>

                    <?= htmlspecialchars(
                        $nowServing['DepartmentName']
                    ) ?>

                </span>


                <span class="sep">
                    &bull;
                </span>


                <span>

                    Appt
                    <?= date(
                        'h:i A',
                        strtotime(
                            $nowServing['AppointmentTime']
                        )
                    ) ?>

                </span>

            </div>

        </div>

    </div>


    <div class="ns-right">

        <form method="POST">

            <input
                type="hidden"
                name="action"
                value="complete"
            >

            <input
                type="hidden"
                name="queue_id"
                value="<?= (int)$nowServing['QueueID'] ?>"
            >

            <button
                class="btn-ns-complete"
                type="submit"
            >

                ✓ Complete

            </button>

        </form>

    </div>

</div>

<?php else: ?>

<div class="now-serving-empty">

    No patient currently in consultation.

</div>

<?php endif; ?>


<!-- =====================================================
     CALL NEXT
====================================================== -->

<div class="staff-quick-actions">

    <form method="POST">

        <input
            type="hidden"
            name="action"
            value="call_next"
        >

        <button
            class="btn-quick blue"
            type="submit"
            <?= $waitingCount === 0 ? 'disabled' : '' ?>
        >

            ☎ Call Next

        </button>

    </form>

    <span class="call-next-hint">

        Calls the next patient by priority,
        then check-in time.

    </span>

</div>


<!-- =====================================================
     WAITING + CALLED
====================================================== -->

<div class="staff-grid">


<!-- WAITING -->

<div class="panel">

    <div class="panel-head">

        <div class="panel-head-title orange">

            Waiting Queue

        </div>

        <span class="panel-head-meta">

            <?= $waitingCount ?> waiting

        </span>

    </div>


    <?php if (empty($waiting)): ?>

        <div class="empty-state">
            No patients waiting.
        </div>

    <?php else: ?>

        <div class="queue-list">

        <?php foreach ($waiting as $p): ?>

            <div class="queue-list-row">

                <div class="queue-badge">

                    Q<?= str_pad(
                        $p['QueueNumber'],
                        3,
                        '0',
                        STR_PAD_LEFT
                    ) ?>

                </div>


                <div class="queue-info">

                    <div class="queue-name">

                        <?= htmlspecialchars(
                            $p['FirstName'] .
                            ' ' .
                            $p['LastName']
                        ) ?>


                        <?php if (
                            strtolower(
                                $p['PriorityLevel']
                            ) === 'urgent'
                        ): ?>

                            <span class="urgent-badge">
                                Urgent
                            </span>

                        <?php elseif (
                            strtolower(
                                $p['PriorityLevel']
                            ) === 'priority'
                        ): ?>

                            <span class="priority-badge">
                                Priority
                            </span>

                        <?php endif; ?>

                    </div>


                    <div class="queue-sub">

                        <?= htmlspecialchars(
                            $p['DepartmentName']
                        ) ?>

                        &bull;

                        Appt

                        <?= date(
                            'h:i A',
                            strtotime(
                                $p['AppointmentTime']
                            )
                        ) ?>

                    </div>

                </div>


                <div class="queue-actions">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="call"
                        >

                        <input
                            type="hidden"
                            name="queue_id"
                            value="<?= (int)$p['QueueID'] ?>"
                        >

                        <button
                            class="btn-call"
                            type="submit"
                        >
                            Call
                        </button>

                    </form>

                </div>

            </div>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>


<!-- CALLED -->

<div class="panel">

    <div class="panel-head">

        <div class="panel-head-title">
            Called
        </div>

        <span class="panel-head-meta">

            <?= $calledCount ?> called

        </span>

    </div>


    <?php if (empty($called)): ?>

        <div class="empty-state">

            No patients called yet.

        </div>

    <?php else: ?>

        <div class="queue-list">

        <?php foreach ($called as $p): ?>

            <div class="queue-list-row highlight">

                <div class="queue-badge">

                    Q<?= str_pad(
                        $p['QueueNumber'],
                        3,
                        '0',
                        STR_PAD_LEFT
                    ) ?>

                </div>


                <div class="queue-info">

                    <div class="queue-name">

                        <?= htmlspecialchars(
                            $p['FirstName'] .
                            ' ' .
                            $p['LastName']
                        ) ?>

                    </div>


                    <div class="queue-sub">

                        <?= htmlspecialchars(
                            $p['DepartmentName']
                        ) ?>

                        &bull;

                        Called

                    </div>

                </div>


                <div class="queue-actions">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="start"
                        >

                        <input
                            type="hidden"
                            name="queue_id"
                            value="<?= (int)$p['QueueID'] ?>"
                        >

                        <button
                            class="btn-start"
                            type="submit"
                        >

                            Start Consultation

                        </button>

                    </form>

                </div>

            </div>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

</div>


<!-- =====================================================
     IN PROGRESS + COMPLETED
====================================================== -->

<div class="staff-grid">


<!-- IN PROGRESS -->

<div class="panel">

    <div class="panel-head">

        <div class="panel-head-title purple">

            In Progress

        </div>

        <span class="panel-head-meta">

            <?= $inProgressCount ?>
            in consultation

        </span>

    </div>


    <?php if (empty($inProgress)): ?>

        <div class="empty-state">

            No patients in consultation.

        </div>

    <?php else: ?>

        <div class="queue-list">

        <?php foreach ($inProgress as $p): ?>

            <div class="queue-list-row">

                <div class="queue-badge">

                    Q<?= str_pad(
                        $p['QueueNumber'],
                        3,
                        '0',
                        STR_PAD_LEFT
                    ) ?>

                </div>


                <div class="queue-info">

                    <div class="queue-name">

                        <?= htmlspecialchars(
                            $p['FirstName'] .
                            ' ' .
                            $p['LastName']
                        ) ?>

                    </div>


                    <div class="queue-sub">

                        <?= htmlspecialchars(
                            $p['DepartmentName']
                        ) ?>

                        &bull;

                        In consultation

                    </div>

                </div>


                <div class="queue-actions">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="action"
                            value="complete"
                        >

                        <input
                            type="hidden"
                            name="queue_id"
                            value="<?= (int)$p['QueueID'] ?>"
                        >

                        <button
                            class="btn-complete"
                            type="submit"
                        >

                            ✓ Complete

                        </button>

                    </form>

                </div>

            </div>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>


<!-- COMPLETED -->

<div class="panel">

    <div class="panel-head">

        <div class="panel-head-title green">

            Completed

        </div>

        <span class="panel-head-meta">

            <?= $completedCount ?> today

        </span>

    </div>


    <?php if (empty($completed)): ?>

        <div class="empty-state">

            No completed patients yet.

        </div>

    <?php else: ?>

        <div class="queue-list">

        <?php foreach ($completed as $p): ?>

            <div class="completed-row">

                <div class="completed-check">

                    ✓

                </div>


                <div class="queue-info">

                    <div class="queue-name">

                        <?= htmlspecialchars(
                            $p['FirstName'] .
                            ' ' .
                            $p['LastName']
                        ) ?>

                    </div>


                    <div class="queue-sub">

                        Q<?= str_pad(
                            $p['QueueNumber'],
                            3,
                            '0',
                            STR_PAD_LEFT
                        ) ?>

                        &bull;

                        <?= htmlspecialchars(
                            $p['DepartmentName']
                        ) ?>

                        &bull;

                        Completed

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

</div>

</main>

</div>

</body>

</html>