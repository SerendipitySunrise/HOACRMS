<?php
session_start();

require_once __DIR__ . '/includes/db.php';


// ======================================================
// PATIENT ACCESS CHECK
// ======================================================

if (!isset($_SESSION['UserID'])) {
    header('Location: login.php?portal=patient');
    exit();
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    header('Location: portal-select.php?action=login');
    exit();
}


// ======================================================
// BASIC VALUES
// ======================================================

$userID = (int) $_SESSION['UserID'];
$today = date('Y-m-d');


// ======================================================
// GET PATIENT
// ======================================================

$patientStmt = mysqli_prepare(
    $conn,
    'SELECT
        p.PatientID,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.ProfilePhoto
     FROM patients p
     INNER JOIN users u
        ON p.UserID = u.UserID
     WHERE p.UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param(
    $patientStmt,
    'i',
    $userID
);

mysqli_stmt_execute($patientStmt);

$patientResult = mysqli_stmt_get_result($patientStmt);
$patient = mysqli_fetch_assoc($patientResult);

if (!$patient) {
    die('Patient profile not found.');
}

$patientID = (int) $patient['PatientID'];


// ======================================================
// PATIENT NAME
// ======================================================

$patientName = trim(
    $patient['FirstName'] . ' ' .
    ($patient['MiddleName']
        ? $patient['MiddleName'] . ' '
        : '') .
    $patient['LastName']
);


// ======================================================
// PATIENT INITIALS
// ======================================================

$initials =
    strtoupper(
        substr($patient['FirstName'], 0, 1) .
        substr($patient['LastName'], 0, 1)
    );


// ======================================================
// GET TODAY'S QUEUE FOR THIS PATIENT
// ======================================================

$queueStmt = mysqli_prepare(
    $conn,
    'SELECT
        q.QueueID,
        q.QueueNumber,
        q.PriorityLevel,
        q.QueueDate,
        q.QueueTime,
        q.Status AS QueueStatus,

        a.AppointmentID,
        a.AppointmentTime,
        a.Status AS AppointmentStatus,

        d.DepartmentName

     FROM queue q

     INNER JOIN appointments a
        ON q.AppointmentID = a.AppointmentID

     INNER JOIN departments d
        ON a.DepartmentID = d.DepartmentID

     WHERE a.PatientID = ?
       AND q.QueueDate = ?

     ORDER BY q.QueueID DESC

     LIMIT 1'
);

mysqli_stmt_bind_param(
    $queueStmt,
    'is',
    $patientID,
    $today
);

mysqli_stmt_execute($queueStmt);

$queueResult = mysqli_stmt_get_result($queueStmt);
$queue = mysqli_fetch_assoc($queueResult);


// ======================================================
// DEFAULT VALUES
// ======================================================

$hasQueue = $queue !== null;

$queueNumber = $hasQueue
    ? (int) $queue['QueueNumber']
    : null;

$queueStatus = $hasQueue
    ? $queue['QueueStatus']
    : null;

$departmentName = $hasQueue
    ? $queue['DepartmentName']
    : null;


// ======================================================
// GET CURRENTLY SERVING PATIENT
// ======================================================

$nowServingStmt = mysqli_prepare(
    $conn,
    'SELECT
        q.QueueNumber,
        q.QueueTime,
        q.Status

     FROM queue q

     WHERE q.QueueDate = ?

       AND q.Status IN ("Serving", "In Progress")

     ORDER BY q.QueueNumber ASC

     LIMIT 1'
);

mysqli_stmt_bind_param(
    $nowServingStmt,
    's',
    $today
);

mysqli_stmt_execute($nowServingStmt);

$nowServingResult = mysqli_stmt_get_result($nowServingStmt);
$nowServing = mysqli_fetch_assoc($nowServingResult);


// ======================================================
// CURRENT QUEUE NUMBER
// ======================================================

$currentNumber = $nowServing
    ? (int) $nowServing['QueueNumber']
    : 0;


// ======================================================
// ESTIMATED WAIT
// ======================================================

$estimatedWait = 0;

if ($hasQueue && $queueNumber !== null) {

    if ($queueStatus === 'Waiting' && $currentNumber > 0) {

        $peopleAhead = $queueNumber - $currentNumber - 1;

        if ($peopleAhead < 0) {
            $peopleAhead = 0;
        }

        // Temporary estimate:
        // 10 minutes per patient ahead
        $estimatedWait = $peopleAhead * 10;

    } elseif ($queueStatus === 'Called') {

        $estimatedWait = 0;

    } elseif (
        $queueStatus === 'Serving' ||
        $queueStatus === 'In Progress'
    ) {

        $estimatedWait = 0;

    } elseif (
        $queueStatus === 'Completed' ||
        $queueStatus === 'Done'
    ) {

        $estimatedWait = 0;
    }
}


// ======================================================
// QUEUE PROGRESS STEP
// ======================================================

$step = 0;

if ($hasQueue) {

    switch ($queueStatus) {

        case 'Waiting':
            $step = 1;
            break;

        case 'Called':
            $step = 2;
            break;

        case 'Serving':
        case 'In Progress':
            $step = 3;
            break;

        case 'Completed':
        case 'Done':
            $step = 4;
            break;

        default:
            $step = 0;
    }
}


// ======================================================
// CURRENT PATIENT SESSION TIMER
// ======================================================

$elapsedSeconds = 0;

if (
    $hasQueue &&
    $queueStatus === 'In Progress' &&
    !empty($queue['QueueTime'])
) {

    $queueTimestamp = strtotime(
        $today . ' ' . $queue['QueueTime']
    );

    $elapsedSeconds = max(
        0,
        time() - $queueTimestamp
    );
}


// ======================================================
// DISPLAY QUEUE STATUS
// ======================================================

$displayStatus = 'Waiting';

if ($hasQueue) {

    switch ($queueStatus) {

        case 'Serving':
        case 'In Progress':
            $displayStatus = 'Now Serving';
            break;

        case 'Called':
            $displayStatus = 'Called';
            break;

        case 'Completed':
        case 'Done':
            $displayStatus = 'Completed';
            break;

        case 'Waiting':
        default:
            $displayStatus = 'Waiting';
            break;
    }
}


// ======================================================
// HERO LABEL
// ======================================================

$heroLabel = 'Queue Status';

if ($hasQueue && $queueStatus === 'In Progress') {
    $heroLabel = 'In Session';
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Queue Status — MediCare Patient Portal</title>

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
    href="assets/css/patient_dashboard.css"
>

</head>

<body>

<div class="app">


    <!-- ==================================================
         SIDEBAR
    =================================================== -->

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
                    Patient Portal
                </div>

            </div>

        </div>


        <ul class="nav-list">
        <li class="nav-item">
            <a href="patient_dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
            Dashboard
        </li>
        <li class="nav-item">
            <a href="patient_appointment.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            Appointments
        </li>
        <li class="nav-item active">
            <a href="queue_status.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
            Queue Status
        </li>
        <li class="nav-item">
            <a href="view_results.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
            View Results
        </li>
        <li class="nav-item">
            <a href="consultation_history.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            Consultation History
        </li>
        <li class="nav-item">
            <a href="notifications.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            Notifications
        </li>
        <li class="nav-item">
            <a href="patient_profile.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profile
        </li>
        </ul>


        <!-- SIDEBAR USER -->

        <div class="sidebar-footer">

            <div class="sidebar-user">

                <?php if (!empty($patient['ProfilePhoto'])): ?>
                <div class="user-avatar"><img src="<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>
                <?php else: ?>
                <div class="user-avatar">

                    <?php echo htmlspecialchars($initials); ?>

                </div>
                <?php endif; ?>

                <div>

                    <div class="user-name">

                        <?php echo htmlspecialchars($patientName); ?>

                    </div>

                    <div class="user-role">
                        Patient
                    </div>

                </div>

            </div>


            <a
                class="sign-out"
                href="logout.php"
                onclick="return confirm('Are you sure you want to sign out?');"
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line
                        x1="21"
                        y1="12"
                        x2="9"
                        y2="12"
                    />
                </svg>

                Sign Out

            </a>

        </div>

    </aside>


    <!-- ==================================================
         MAIN
    =================================================== -->

    <main class="main">


        <div class="page-header">

            <h1>
                Queue Status
            </h1>

            <p>
                Track your position in real-time
            </p>

        </div>


        <?php if (!$hasQueue): ?>


            <!-- ==========================================
                 NO QUEUE
            =========================================== -->

            <div
                class="panel"
                style="margin-top:20px;"
            >

                <div
                    style="
                        text-align:center;
                        padding:40px 30px;
                    "
                >

                    <h2 style="margin-bottom:10px;">
                        You're not in the queue yet
                    </h2>

                    <p style="color:#6b7280;">
                        Your queue number will appear here after
                        the clinic staff checks you in.
                    </p>

                </div>

            </div>


        <?php else: ?>


            <!-- ==========================================
                 NOW SERVING HERO
            =========================================== -->

            <div class="qs-hero">

                <div class="qs-hero-left">

                    <div class="qs-hero-avatar">

                        <?php echo $queueNumber; ?>

                    </div>


                    <div>

                        <div class="qs-hero-name">

                            <?php
                            echo htmlspecialchars($patientName);
                            ?>

                        </div>


                        <span class="qs-hero-pill">

                            <span class="dot"></span>

                            <?php
                            echo htmlspecialchars($displayStatus);
                            ?>

                        </span>

                    </div>

                </div>


                <div class="qs-hero-right">

                    <div class="qs-hero-label">

                        <?php
                        echo htmlspecialchars($heroLabel);
                        ?>

                    </div>


                    <div
                        class="qs-hero-timer"
                        id="sessionTimer"
                        data-elapsed="<?php echo $elapsedSeconds; ?>"
                    >

                        00m 00s

                    </div>

                </div>

            </div>


            <!-- ==========================================
                 STATS
            =========================================== -->

            <div
                class="panel"
                style="margin-top:20px;"
            >

                <div class="qs-stats">


                    <!-- NOW SERVING -->

                    <div class="qs-stat-card">

                        <div class="qs-stat-label">
                            Now Serving
                        </div>

                        <div class="qs-stat-value">

                            <?php

                            if ($nowServing) {

                                echo 'Q-' . str_pad(
                                    (int)$nowServing['QueueNumber'],
                                    2,
                                    '0',
                                    STR_PAD_LEFT
                                );

                            } else {

                                echo '--';

                            }

                            ?>

                        </div>

                    </div>


                    <!-- YOUR NUMBER -->

                    <div class="qs-stat-card highlight">

                        <div class="qs-stat-label">
                            Your Number
                        </div>

                        <div class="qs-stat-value">

                            <?php

                            echo 'Q-' . str_pad(
                                $queueNumber,
                                2,
                                '0',
                                STR_PAD_LEFT
                            );

                            ?>

                        </div>

                    </div>


                    <!-- ESTIMATED WAIT -->

                    <div class="qs-stat-card">

                        <div class="qs-stat-label">
                            Est. Wait
                        </div>

                        <div class="qs-stat-value">

                            <?php

                            if (
                                $queueStatus === 'Completed' ||
                                $queueStatus === 'Done'
                            ) {

                                echo 'Done';

                            } elseif (
                                $queueStatus === 'Serving' ||
                                $queueStatus === 'In Progress'
                            ) {

                                echo 'Now';

                            } else {

                                echo $estimatedWait . ' min';

                            }

                            ?>

                        </div>

                    </div>

                </div>


                <!-- ======================================
                     QUEUE PROGRESS
                ======================================= -->

                <div
                    class="qs-progress-title"
                    style="margin-top:25px;"
                >

                    Queue Progress

                </div>


                <div
                    class="stepper"
                    style="
                        padding-left:0;
                        padding-right:0;
                    "
                >


                    <!-- STEP 1 -->

                    <div
                        class="step
                        <?php echo $step >= 1 ? 'active' : ''; ?>"
                    >

                        <div class="step-circle">
                            <span>1</span>
                        </div>

                        <div class="step-label">
                            Waiting
                        </div>

                    </div>


                    <div class="step-line"></div>


                    <!-- STEP 2 -->

                    <div
                        class="step
                        <?php echo $step >= 2 ? 'active' : ''; ?>"
                    >

                        <div class="step-circle">
                            <span>2</span>
                        </div>

                        <div class="step-label">
                            Called
                        </div>

                    </div>


                    <div class="step-line"></div>


                    <!-- STEP 3 -->

                    <div
                        class="step
                        <?php echo $step >= 3 ? 'active' : ''; ?>"
                    >

                        <div class="step-circle">
                            <span>3</span>
                        </div>

                        <div class="step-label">
                            In Progress
                        </div>

                    </div>


                    <div class="step-line"></div>


                    <!-- STEP 4 -->

                    <div
                        class="step
                        <?php echo $step >= 4 ? 'active' : ''; ?>"
                    >

                        <div class="step-circle">
                            <span>4</span>
                        </div>

                        <div class="step-label">
                            Done
                        </div>

                    </div>

                </div>


                <!-- ======================================
                     LOCATION
                ======================================= -->

                <div class="qs-location">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >

                        <path
                            d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"
                        />

                        <circle
                            cx="12"
                            cy="10"
                            r="3"
                        />

                    </svg>


                    <?php

                    echo htmlspecialchars(
                        $departmentName
                    );

                    ?>

                </div>

            </div>


        <?php endif; ?>


    </main>

</div>


<!-- ==================================================
     SESSION TIMER
=================================================== -->

<script>

(function () {

    const timerEl =
        document.getElementById('sessionTimer');

    if (!timerEl) {
        return;
    }


    let elapsed =
        parseInt(
            timerEl.dataset.elapsed,
            10
        ) || 0;


    function render() {

        const minutes =
            Math.floor(elapsed / 60);

        const seconds =
            elapsed % 60;


        timerEl.textContent =
            String(minutes).padStart(2, '0')
            + 'm '
            + String(seconds).padStart(2, '0')
            + 's';

    }


    render();


    setInterval(function () {

        elapsed += 1;

        render();

    }, 1000);

})();

</script>


</body>
</html>