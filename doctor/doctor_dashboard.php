<?php
/**
 * doctor_dashboard.php
 * MediCare - Doctor Dashboard
 *
 * REAL DATABASE DATA
 *
 * Database relationship:
 *
 * users
 *   ├── staff
 *   │     └── departments
 *   │
 *   └── patients
 *         └── appointments
 *               └── consultations
 *
 * Uses mysqli connection from ../includes/db.php
 */

session_start();

require_once __DIR__ . '/../includes/db.php';

/*
|--------------------------------------------------------------------------
| CHECK DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !$conn) {
    die('Database connection is not available.');
}


/*
|--------------------------------------------------------------------------
| GET LOGGED-IN USER ID
|--------------------------------------------------------------------------
|
| Your login system should store the UserID in the session.
|
*/

$userID = $_SESSION['UserID']
    ?? $_SESSION['user_id']
    ?? $_SESSION['userid']
    ?? null;

if (!$userID) {
    header('Location: ../login.php?portal=doctor');
    exit;
}

$userID = (int)$userID;

/*
|--------------------------------------------------------------------------
| CHECK USER ROLE
|--------------------------------------------------------------------------
|
| A patient or another account type must not be able to open the doctor
| portal by typing its URL directly into the browser.
|
| Some login versions store the role under a different session key, so
| support the common variants while keeping the database staff check below
| as the final authorization check.
*/

$roleName = $_SESSION['RoleName']
    ?? $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? null;

if ($roleName !== null && strcasecmp(trim((string)$roleName), 'Doctor') !== 0) {
    header('Location: ../portal-select.php?action=login');
    exit;
}


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    header('Location: ../logout.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| GET DOCTOR INFORMATION
|--------------------------------------------------------------------------
|
| users → staff → departments
|
*/

$doctorName = '';
$doctorDepartment = '';
$staffID = 0;
$departmentID = 0;

$doctorSQL = "
    SELECT
        s.StaffID,
        s.DepartmentID,
        s.StaffRole,
        s.Specialization,
        u.UserID,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.ProfilePhoto,
        d.DepartmentName
    FROM staff s
    INNER JOIN users u
        ON s.UserID = u.UserID
    INNER JOIN departments d
        ON s.DepartmentID = d.DepartmentID
    WHERE s.UserID = ?
    LIMIT 1
";

$stmtDoctor = $conn->prepare($doctorSQL);

if (!$stmtDoctor) {
    die('Failed to prepare doctor query: ' . $conn->error);
}

$stmtDoctor->bind_param('i', $userID);
$stmtDoctor->execute();

$doctorResult = $stmtDoctor->get_result();

if ($doctorResult->num_rows === 0) {
    header('Location: ../portal-select.php?action=login');
    exit;
}

$doctor = $doctorResult->fetch_assoc();

$stmtDoctor->close();


/*
|--------------------------------------------------------------------------
| BUILD DOCTOR NAME
|--------------------------------------------------------------------------
*/

$doctorNameParts = [];

if (!empty($doctor['FirstName'])) {
    $doctorNameParts[] = $doctor['FirstName'];
}

if (!empty($doctor['MiddleName'])) {
    $doctorNameParts[] = $doctor['MiddleName'];
}

if (!empty($doctor['LastName'])) {
    $doctorNameParts[] = $doctor['LastName'];
}

$doctorFullName = trim(implode(' ', $doctorNameParts));

if ($doctorFullName === '') {
    $doctorFullName = 'Doctor';
}


/*
|--------------------------------------------------------------------------
| ADD "DR." ONLY FOR DISPLAY
|--------------------------------------------------------------------------
|
| This does NOT change the name in the database.
|
*/

$doctorName = 'Dr. ' . $doctorFullName;

$staffID = (int)$doctor['StaffID'];
$departmentID = (int)$doctor['DepartmentID'];

$doctorDepartment = $doctor['DepartmentName'];


/*
|--------------------------------------------------------------------------
| GET TODAY'S DATE
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$currentDate = date('l, F j, Y');


/*
|--------------------------------------------------------------------------
| GET TODAY'S APPOINTMENTS
|--------------------------------------------------------------------------
|
| Appointment data:
|
| appointments
|   PatientID
|   StaffID
|   DepartmentID
|   AppointmentDate
|   AppointmentTime
|   Purpose
|   Status
|
| patients
|   BloodType
|   UserID
|
| users
|   FirstName
|   MiddleName
|   LastName
|   Sex
|   DateOfBirth
|
| consultations
|   Status
|
*/

$appointments = [];

$appointmentSQL = "
    SELECT
        a.AppointmentID,
        a.PatientID,
        a.StaffID,
        a.DepartmentID,
        a.AppointmentDate,
        a.AppointmentTime,
        a.Purpose,
        a.Status AS AppointmentStatus,

        p.BloodType,

        pu.UserID AS PatientUserID,
        pu.FirstName AS PatientFirstName,
        pu.MiddleName AS PatientMiddleName,
        pu.LastName AS PatientLastName,
        pu.Sex AS PatientSex,
        pu.DateOfBirth AS PatientDateOfBirth,

        c.ConsultationID,
        c.Status AS ConsultationStatus

    FROM appointments a

    INNER JOIN patients p
        ON a.PatientID = p.PatientID

    INNER JOIN users pu
        ON p.UserID = pu.UserID

    LEFT JOIN consultations c
        ON a.AppointmentID = c.AppointmentID

    WHERE a.StaffID = ?
      AND a.AppointmentDate = ?

    ORDER BY a.AppointmentTime ASC, a.AppointmentID ASC
";

$stmtAppointments = $conn->prepare($appointmentSQL);

if (!$stmtAppointments) {
    die('Failed to prepare appointments query: ' . $conn->error);
}

$stmtAppointments->bind_param(
    'is',
    $staffID,
    $today
);

$stmtAppointments->execute();

$appointmentResult = $stmtAppointments->get_result();


/*
|--------------------------------------------------------------------------
| HELPER: CALCULATE AGE
|--------------------------------------------------------------------------
*/

function calculateAge($dateOfBirth)
{
    if (empty($dateOfBirth)) {
        return 'N/A';
    }

    try {
        $birthDate = new DateTime($dateOfBirth);
        $todayDate = new DateTime();

        return $birthDate->diff($todayDate)->y;
    } catch (Exception $e) {
        return 'N/A';
    }
}


/*
|--------------------------------------------------------------------------
| HELPER: GET PATIENT FULL NAME
|--------------------------------------------------------------------------
*/

function getPatientFullName($row)
{
    $parts = [];

    if (!empty($row['PatientFirstName'])) {
        $parts[] = $row['PatientFirstName'];
    }

    if (!empty($row['PatientMiddleName'])) {
        $parts[] = $row['PatientMiddleName'];
    }

    if (!empty($row['PatientLastName'])) {
        $parts[] = $row['PatientLastName'];
    }

    return trim(implode(' ', $parts));
}


/*
|--------------------------------------------------------------------------
| HELPER: GET INITIALS
|--------------------------------------------------------------------------
*/

function getInitials($name)
{
    $name = trim($name);

    if ($name === '') {
        return 'P';
    }

    $parts = preg_split('/\s+/', $name);

    $initials = '';

    if (isset($parts[0])) {
        $initials .= strtoupper(substr($parts[0], 0, 1));
    }

    if (isset($parts[1])) {
        $initials .= strtoupper(substr($parts[count($parts) - 1], 0, 1));
    }

    return $initials ?: 'P';
}


/*
|--------------------------------------------------------------------------
| HELPER: DETERMINE PATIENT STATUS
|--------------------------------------------------------------------------
|
| Priority:
|
| 1. Consultation status
| 2. Appointment status
|
*/

function getPatientStatus($appointmentStatus, $consultationStatus)
{
    $consultationStatus = strtolower(trim((string)$consultationStatus));
    $appointmentStatus = strtolower(trim((string)$appointmentStatus));

    /*
    | Completed consultation
    */

    if (
        $consultationStatus === 'completed' ||
        $appointmentStatus === 'completed'
    ) {
        return 'completed';
    }

    /*
    | Ongoing consultation
    */

    if (
        $consultationStatus === 'ongoing' ||
        $consultationStatus === 'in progress' ||
        $consultationStatus === 'in_progress'
    ) {
        return 'in_progress';
    }

    /*
    | Cancelled appointments are not part of active queue.
    | We will still display them, but mark completed/closed.
    */

    if (
        $appointmentStatus === 'cancelled' ||
        $appointmentStatus === 'canceled'
    ) {
        return 'completed';
    }

    /*
    | Otherwise waiting
    */

    return 'waiting';
}


/*
|--------------------------------------------------------------------------
| BUILD TODAY'S PATIENT QUEUE
|--------------------------------------------------------------------------
*/

$doctorQueue = [];

$queueNumber = 1;

while ($row = $appointmentResult->fetch_assoc()) {

    $patientName = getPatientFullName($row);

    $age = calculateAge($row['PatientDateOfBirth']);

    $status = getPatientStatus(
        $row['AppointmentStatus'],
        $row['ConsultationStatus']
    );

    /*
    | Calculate estimated wait.
    |
    | This is only an approximate display based on queue position.
    | It does NOT modify database data.
    */

    $estimatedWait = null;

    if ($status === 'waiting') {

        $waitingPosition = 0;

        foreach ($doctorQueue as $existingPatient) {

            if ($existingPatient['status'] === 'waiting') {
                $waitingPosition++;
            }
        }

        $estimatedWait = ($waitingPosition + 1) * 10;
    }

    /*
    | Current consultation does not need wait time.
    */

    if ($status === 'in_progress') {
        $estimatedWait = null;
    }

    $doctorQueue[] = [
        'id' => (int)$row['PatientID'],
        'appointment_id' => (int)$row['AppointmentID'],

        'name' => $patientName !== ''
            ? $patientName
            : 'Unnamed Patient',

        'age' => $age,

        'sex' => $row['PatientSex'] ?? 'N/A',

        'blood_type' => !empty($row['BloodType'])
            ? $row['BloodType']
            : 'N/A',

        'queue_number' => $queueNumber,

        'status' => $status,

        'department' => $doctorDepartment,

        'reason' => !empty($row['Purpose'])
            ? $row['Purpose']
            : 'General consultation',

        'appointment_time' => $row['AppointmentTime'],

        'appointment_status' => $row['AppointmentStatus'],

        'consultation_status' => $row['ConsultationStatus'],

        'consultation_id' => $row['ConsultationID'],

        'est_wait' => $estimatedWait
    ];

    $queueNumber++;
}

$stmtAppointments->close();


/*
|--------------------------------------------------------------------------
| DASHBOARD COUNTS
|--------------------------------------------------------------------------
*/

$counts = [
    'today' => count($doctorQueue),
    'waiting' => 0,
    'in_progress' => 0,
    'completed' => 0
];

foreach ($doctorQueue as $patient) {

    switch ($patient['status']) {

        case 'waiting':
            $counts['waiting']++;
            break;

        case 'in_progress':
            $counts['in_progress']++;
            break;

        case 'completed':
            $counts['completed']++;
            break;
    }
}


/*
|--------------------------------------------------------------------------
| CURRENT PATIENT
|--------------------------------------------------------------------------
*/

$nowServing = null;

foreach ($doctorQueue as $patient) {

    if ($patient['status'] === 'in_progress') {

        $nowServing = $patient;

        break;
    }
}


/*
|--------------------------------------------------------------------------
| WAITING PATIENTS
|--------------------------------------------------------------------------
*/

$waitingPatients = array_filter(
    $doctorQueue,
    function ($patient) {
        return $patient['status'] === 'waiting';
    }
);


/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
|
| There is currently no notifications table in the database structure
| you provided.
|
| Therefore, DO NOT create fake notifications.
|
*/

$notifications = [];

$notificationCount = 0;


/*
|--------------------------------------------------------------------------
| STATUS LABEL
|--------------------------------------------------------------------------
*/

function getStatusLabel($status)
{
    $labels = [
        'waiting' => 'Waiting',
        'called' => 'Called',
        'in_progress' => 'In Progress',
        'completed' => 'Completed'
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}


/*
|--------------------------------------------------------------------------
| DISPLAY TIME
|--------------------------------------------------------------------------
*/

function formatAppointmentTime($time)
{
    if (empty($time)) {
        return 'No time';
    }

    return date(
        'g:i A',
        strtotime($time)
    );
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

<title>Doctor Dashboard — MediCare</title>

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
    href="../assets/css/doctor/doctor_dashboard.css"
>

</head>

<body>

<div class="app">


<!-- ==========================================================
     SIDEBAR
=========================================================== -->

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
                Doctor Portal
            </div>

        </div>

    </div>


    <ul class="nav-list">

        <li class="nav-item active">

            <a href="doctor_dashboard.php">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <path d="M9 22V12h6v10"/>
                </svg>

                Dashboard

            </a>

        </li>


        <li class="nav-item">

            <a href="doctor_queue.php">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
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

            <a href="records.php">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <polyline points="14 2 14 8 20 8"/>
                    <line x1="16" y1="13" x2="8" y2="13"/>
                    <line x1="16" y1="17" x2="8" y2="17"/>
                </svg>

                Records

            </a>

        </li>


        <li class="nav-item">

            <a href="search_patient.php">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <circle cx="11" cy="11" r="8"/>
                    <line
                        x1="21"
                        y1="21"
                        x2="16.65"
                        y2="16.65"
                    />
                </svg>

                Search Patient

            </a>

        </li>

        <li class="nav-item">

            <a href="doctor_profile.php">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>

                Profile

            </a>

        </li>

    </ul>


    <!-- SIDEBAR FOOTER -->

    <div class="sidebar-footer">

        <div class="sidebar-user">

            <div class="user-avatar">
                <?php if (!empty($doctor['ProfilePhoto'])): ?>
                <img src="../<?php echo htmlspecialchars($doctor['ProfilePhoto']); ?>" alt="Photo" style="width:36px;height:36px;border-radius:50%;object-fit:cover;">
                <?php else: ?>
                <?= htmlspecialchars(getInitials($doctorFullName)) ?>
                <?php endif; ?>
            </div>

            <div>

                <div class="user-name">
                    <?= htmlspecialchars($doctorName) ?>
                </div>

                <div class="user-role">
                    <?= htmlspecialchars($doctorDepartment) ?>
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



<!-- ==========================================================
     MAIN CONTENT
=========================================================== -->

<main class="main">


    <!-- TOP BAR -->

    <div class="doctor-topbar">

        <div class="page-header">

            <div class="page-greeting">

                Good morning,

                <span class="page-greeting-name">

                    <?= htmlspecialchars($doctorName) ?>

                </span>

            </div>

            <p>

                <?= htmlspecialchars($doctorDepartment) ?>

                &nbsp;&middot;&nbsp;

                <?= htmlspecialchars($currentDate) ?>

            </p>

        </div>


        <div class="notif-bell">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >

                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>

                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>

            </svg>


            <?php if ($notificationCount > 0): ?>

                <span class="notif-badge">

                    <?= $notificationCount ?>

                </span>

            <?php endif; ?>

        </div>

    </div>



    <!-- ======================================================
         STATISTICS
    ======================================================= -->

    <div class="doctor-stats">


        <div class="doctor-stat-card">

            <div class="doctor-stat-label">
                Today's Appointments
            </div>

            <div class="doctor-stat-value">
                <?= $counts['today'] ?>
            </div>

        </div>


        <div class="doctor-stat-card">

            <div class="doctor-stat-label">
                Waiting
            </div>

            <div class="doctor-stat-value orange">
                <?= $counts['waiting'] ?>
            </div>

        </div>


        <div class="doctor-stat-card">

            <div class="doctor-stat-label">
                In Progress
            </div>

            <div class="doctor-stat-value purple">
                <?= $counts['in_progress'] ?>
            </div>

        </div>


        <div class="doctor-stat-card">

            <div class="doctor-stat-label">
                Completed
            </div>

            <div class="doctor-stat-value green">
                <?= $counts['completed'] ?>
            </div>

        </div>

    </div>



    <!-- ======================================================
         QUICK ACTIONS
    ======================================================= -->

    <div class="doctor-quick-actions">


        <a
            href="doctor_queue.php"
            class="btn-quick blue"
        >

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >

                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>

                <circle
                    cx="9"
                    cy="7"
                    r="4"
                />

                <line
                    x1="19"
                    y1="8"
                    x2="19"
                    y2="14"
                />

                <line
                    x1="16"
                    y1="11"
                    x2="22"
                    y2="11"
                />

            </svg>

            View Queue

        </a>



        <?php if ($nowServing): ?>

            <a
                href="consultation.php?patient_id=<?= $nowServing['id'] ?>&appointment_id=<?= $nowServing['appointment_id'] ?>"
                class="btn-quick teal"
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >

                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>

                </svg>

                Continue Consultation

            </a>

        <?php else: ?>

            <a
                href="doctor_queue.php"
                class="btn-quick teal"
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >

                    <path d="M12 5v14"/>
                    <path d="M5 12h14"/>

                </svg>

                Select Patient

            </a>

        <?php endif; ?>


    </div>



    <!-- ======================================================
         TWO COLUMN GRID
    ======================================================= -->

    <div class="doctor-grid">


        <!-- ==================================================
             TODAY'S PATIENTS
        =================================================== -->

        <div class="panel">


            <div class="panel-head">

                <div class="panel-head-title">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >

                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>

                        <circle
                            cx="9"
                            cy="7"
                            r="4"
                        />

                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>

                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>

                    </svg>

                    Today's Patients

                </div>


                <span class="panel-head-meta">

                    <?= count($doctorQueue) ?> patients

                </span>

            </div>



            <div class="patient-list">


                <?php if (empty($doctorQueue)): ?>


                    <div class="notification-empty">

                        No appointments scheduled for today.

                    </div>


                <?php else: ?>


                    <?php foreach ($doctorQueue as $patient): ?>


                        <div
                            class="patient-row <?= $patient['status'] === 'in_progress' ? 'highlight' : '' ?>"
                        >


                            <!-- PATIENT AVATAR -->

                            <div class="patient-avatar">

                                <?= htmlspecialchars(
                                    getInitials($patient['name'])
                                ) ?>

                            </div>



                            <!-- PATIENT INFORMATION -->

                            <div class="patient-info">


                                <div class="patient-name">

                                    <?= htmlspecialchars(
                                        $patient['name']
                                    ) ?>

                                </div>


                                <div class="patient-meta">


                                    <span>
                                        Age <?= htmlspecialchars(
                                            $patient['age']
                                        ) ?>
                                    </span>


                                    <span>|</span>


                                    <span>

                                        <?= htmlspecialchars(
                                            $patient['sex']
                                        ) ?>

                                    </span>


                                    <span>|</span>


                                    <span>

                                        Queue #<?= htmlspecialchars(
                                            $patient['queue_number']
                                        ) ?>

                                    </span>


                                    <span class="patient-status <?= htmlspecialchars(
                                        $patient['status']
                                    ) ?>">

                                        <?= htmlspecialchars(
                                            getStatusLabel(
                                                $patient['status']
                                            )
                                        ) ?>

                                    </span>


                                </div>


                                <div class="patient-meta">


                                    <span>

                                        <?= htmlspecialchars(
                                            formatAppointmentTime(
                                                $patient['appointment_time']
                                            )
                                        ) ?>

                                    </span>


                                    <?php if (!empty($patient['reason'])): ?>

                                        <span>|</span>

                                        <span>

                                            <?= htmlspecialchars(
                                                $patient['reason']
                                            ) ?>

                                        </span>

                                    <?php endif; ?>


                                </div>


                            </div>



                            <!-- ACTION -->

                            <?php if (
                                $patient['status'] === 'in_progress'
                            ): ?>


                                <a
                                    href="consultation.php?patient_id=<?= $patient['id'] ?>&appointment_id=<?= $patient['appointment_id'] ?>"
                                    class="queue-badge-sm"
                                    onclick="event.stopPropagation();"
                                >

                                    Consult

                                </a>


                            <?php elseif (
                                $patient['status'] === 'waiting'
                            ): ?>


                                <a
                                    href="consultation.php?patient_id=<?= $patient['id'] ?>&appointment_id=<?= $patient['appointment_id'] ?>"
                                    class="queue-badge-sm"
                                    onclick="event.stopPropagation();"
                                >

                                    Start

                                </a>


                            <?php elseif (
                                $patient['status'] === 'completed'
                            ): ?>


                                <span class="queue-badge-sm">

                                    Completed

                                </span>


                            <?php endif; ?>


                        </div>


                    <?php endforeach; ?>


                <?php endif; ?>


            </div>


        </div>



        <!-- ==================================================
             NOTIFICATIONS
        =================================================== -->

        <div class="panel">


            <div class="panel-head">


                <div class="panel-head-title">

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >

                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>

                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>

                    </svg>

                    Notifications

                </div>


                <div class="notification-actions">

                    <span class="panel-head-meta">

                        <?= $notificationCount ?> new

                    </span>

                </div>


            </div>



            <div class="notification-list">


                <?php if (empty($notifications)): ?>


                    <div class="notification-empty">

                        No notifications.

                    </div>


                <?php else: ?>


                    <?php foreach ($notifications as $notification): ?>


                        <div class="notification-item">


                            <div class="notification-icon">

                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >

                                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>

                                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>

                                </svg>

                            </div>


                            <div class="notification-content">

                                <div class="notification-text">

                                    <?= htmlspecialchars(
                                        $notification['message']
                                    ) ?>

                                </div>


                                <div class="notification-time">

                                    <?= htmlspecialchars(
                                        $notification['time']
                                    ) ?>

                                </div>

                            </div>


                        </div>


                    <?php endforeach; ?>


                <?php endif; ?>


            </div>


        </div>


    </div>


</main>

</div>

</body>

</html>