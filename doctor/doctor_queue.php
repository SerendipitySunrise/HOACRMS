<?php
/**
 * doctor_queue.php
 *
 * Doctor Live Queue + Consultation
 *
 * Uses the real MySQL database.
 *
 * Database relationship:
 *
 * users
 *   ↓ UserID
 * staff
 *   ↓ StaffID
 * appointments
 *   ↓ PatientID
 * patients
 *
 * consultations are stored when the doctor saves a consultation.
 */

session_start();

require_once __DIR__ . '/../includes/db.php';


/* ================================================================
   CHECK DATABASE CONNECTION
================================================================ */

if (!isset($conn) || !$conn) {
    die('Database connection is not available.');
}


/* ================================================================
   CHECK DOCTOR LOGIN
================================================================ */

if (!isset($_SESSION['UserID'])) {
    header('Location: ../login.php');
    exit;
}

$userID = (int)$_SESSION['UserID'];


/* ================================================================
   GET LOGGED-IN DOCTOR
================================================================ */

$doctorSql = "
    SELECT
        s.StaffID,
        s.UserID,
        s.StaffRole,
        s.Specialization,
        s.DepartmentID,
        d.DepartmentName,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Sex,
        u.ProfilePhoto
    FROM staff s
    INNER JOIN users u
        ON s.UserID = u.UserID
    LEFT JOIN departments d
        ON s.DepartmentID = d.DepartmentID
    WHERE s.UserID = ?
    LIMIT 1
";

$doctorStmt = mysqli_prepare($conn, $doctorSql);

if (!$doctorStmt) {
    die('Failed to prepare doctor query: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($doctorStmt, 'i', $userID);
mysqli_stmt_execute($doctorStmt);

$doctorResult = mysqli_stmt_get_result($doctorStmt);
$doctor = mysqli_fetch_assoc($doctorResult);

mysqli_stmt_close($doctorStmt);


if (!$doctor) {
    die('Doctor account was not found in the staff table.');
}


$staffID = (int)$doctor['StaffID'];

$doctorFirstName = $doctor['FirstName'] ?? '';
$doctorMiddleName = $doctor['MiddleName'] ?? '';
$doctorLastName = $doctor['LastName'] ?? '';

$doctorName = trim(
    'Dr. ' .
    $doctorFirstName . ' ' .
    ($doctorMiddleName ? $doctorMiddleName . ' ' : '') .
    $doctorLastName
);

$doctorSpecialization =
    $doctor['Specialization']
    ?: 'Doctor';

$doctorDepartment =
    $doctor['DepartmentName']
    ?: 'General Medicine';


/* ================================================================
   HELPER FUNCTIONS
================================================================ */

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));

    if (!$parts) {
        return '';
    }

    $first = $parts[0][0] ?? '';

    $last = '';

    if (count($parts) > 1) {
        $last = $parts[count($parts) - 1][0] ?? '';
    }

    return strtoupper($first . $last);
}


function format_patient_name(array $patient): string
{
    $first = $patient['FirstName'] ?? '';
    $middle = $patient['MiddleName'] ?? '';
    $last = $patient['LastName'] ?? '';

    return trim(
        $first .
        ($middle ? ' ' . $middle : '') .
        ' ' .
        $last
    );
}


function waiting_minutes(array $patient): int
{
    if (empty($patient['AppointmentDate']) || empty($patient['AppointmentTime'])) {
        return 0;
    }

    $appointmentTimestamp = strtotime(
        $patient['AppointmentDate'] . ' ' . $patient['AppointmentTime']
    );

    if (!$appointmentTimestamp) {
        return 0;
    }

    return max(
        0,
        (int)floor((time() - $appointmentTimestamp) / 60)
    );
}


/* ================================================================
   SAVE CONSULTATION
================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save_consultation'
) {

    $appointmentID = (int)($_POST['appointment_id'] ?? 0);
    $patientID = (int)($_POST['patient_id'] ?? 0);

    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $clinicalNotes = trim($_POST['clinical_notes'] ?? '');
    $treatmentPlan = trim($_POST['treatment_plan'] ?? '');

    $bloodPressure = trim($_POST['vital_bp'] ?? '');
    $temperature = trim($_POST['vital_temp'] ?? '');
    $pulseRate = trim($_POST['vital_pulse'] ?? '');

    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');

    $followUpDate = trim($_POST['follow_up_date'] ?? '');

    if ($followUpDate === '') {
        $followUpDate = null;
    }


    /* ------------------------------------------------------------
       PRESCRIPTIONS
    ------------------------------------------------------------ */

    $rxNames = $_POST['rx_name'] ?? [];
    $rxDosages = $_POST['rx_dosage'] ?? [];
    $rxInstructions = $_POST['rx_instructions'] ?? [];

    $prescriptions = [];

    foreach ($rxNames as $i => $name) {

        $name = trim($name);

        if ($name === '') {
            continue;
        }

        $dosage = trim($rxDosages[$i] ?? '');
        $instructions = trim($rxInstructions[$i] ?? '');

        $prescription = $name;

        if ($dosage !== '') {
            $prescription .= ' — ' . $dosage;
        }

        if ($instructions !== '') {
            $prescription .= ' — ' . $instructions;
        }

        $prescriptions[] = $prescription;
    }


    /* ------------------------------------------------------------
       COMBINE TREATMENT + PRESCRIPTIONS
    ------------------------------------------------------------ */

    $treatmentParts = [];

    if ($treatmentPlan !== '') {
        $treatmentParts[] =
            "Treatment Plan:\n" . $treatmentPlan;
    }

    if (!empty($prescriptions)) {
        $treatmentParts[] =
            "Prescriptions:\n- " .
            implode("\n- ", $prescriptions);
    }

    $finalTreatment = implode("\n\n", $treatmentParts);


    /* ------------------------------------------------------------
       VALIDATE APPOINTMENT
    ------------------------------------------------------------ */

    if ($appointmentID <= 0 || $patientID <= 0) {

        header('Location: doctor_queue.php?error=invalid');
        exit;
    }


    /* ------------------------------------------------------------
       VERIFY APPOINTMENT BELONGS TO THIS DOCTOR
    ------------------------------------------------------------ */

    $verifySql = "
        SELECT
            AppointmentID,
            PatientID
        FROM appointments
        WHERE AppointmentID = ?
          AND PatientID = ?
          AND StaffID = ?
        LIMIT 1
    ";

    $verifyStmt = mysqli_prepare($conn, $verifySql);

    if (!$verifyStmt) {
        die('Failed to prepare appointment verification.');
    }

    mysqli_stmt_bind_param(
        $verifyStmt,
        'iii',
        $appointmentID,
        $patientID,
        $staffID
    );

    mysqli_stmt_execute($verifyStmt);

    $verifyResult = mysqli_stmt_get_result($verifyStmt);

    $verifiedAppointment = mysqli_fetch_assoc($verifyResult);

    mysqli_stmt_close($verifyStmt);


    if (!$verifiedAppointment) {

        header('Location: doctor_queue.php?error=unauthorized');
        exit;
    }


    /* ------------------------------------------------------------
       GET APPOINTMENT DATE/TIME
    ------------------------------------------------------------ */

    $appointmentSql = "
        SELECT
            AppointmentDate,
            AppointmentTime
        FROM appointments
        WHERE AppointmentID = ?
        LIMIT 1
    ";

    $appointmentStmt = mysqli_prepare($conn, $appointmentSql);

    mysqli_stmt_bind_param(
        $appointmentStmt,
        'i',
        $appointmentID
    );

    mysqli_stmt_execute($appointmentStmt);

    $appointmentResult =
        mysqli_stmt_get_result($appointmentStmt);

    $appointmentData =
        mysqli_fetch_assoc($appointmentResult);

    mysqli_stmt_close($appointmentStmt);


    if (!$appointmentData) {

        header('Location: doctor_queue.php?error=appointment');
        exit;
    }


    $consultationDate =
        $appointmentData['AppointmentDate'];

    $consultationTime =
        $appointmentData['AppointmentTime'];


    /* ------------------------------------------------------------
       CHECK IF CONSULTATION ALREADY EXISTS
    ------------------------------------------------------------ */

    $checkConsultSql = "
        SELECT ConsultationID
        FROM consultations
        WHERE AppointmentID = ?
        LIMIT 1
    ";

    $checkConsultStmt =
        mysqli_prepare($conn, $checkConsultSql);

    mysqli_stmt_bind_param(
        $checkConsultStmt,
        'i',
        $appointmentID
    );

    mysqli_stmt_execute($checkConsultStmt);

    $checkConsultResult =
        mysqli_stmt_get_result($checkConsultStmt);

    $existingConsultation =
        mysqli_fetch_assoc($checkConsultResult);

    mysqli_stmt_close($checkConsultStmt);


    /* ============================================================
       UPDATE EXISTING CONSULTATION
    ============================================================ */

    if ($existingConsultation) {

        $consultationID =
            (int)$existingConsultation['ConsultationID'];


        $updateSql = "
            UPDATE consultations
            SET
                ChiefComplaint = ?,
                Diagnosis = ?,
                Treatment = ?,
                Notes = ?,
                FollowUpDate = ?,
                Status = 'Completed',
                BloodPressure = ?,
                Temperature = NULLIF(?, ''),
                PulseRate = NULLIF(?, ''),
                ConsultationDate = ?,
                ConsultationTime = ?
            WHERE ConsultationID = ?
              AND StaffID = ?
        ";

        $updateStmt =
            mysqli_prepare($conn, $updateSql);

        if (!$updateStmt) {
            die(
                'Failed to prepare consultation update: ' .
                mysqli_error($conn)
            );
        }


        mysqli_stmt_bind_param(
            $updateStmt,
            'ssssssssssii',
            $chiefComplaint,
            $diagnosis,
            $finalTreatment,
            $clinicalNotes,
            $followUpDate,
            $bloodPressure,
            $temperature,
            $pulseRate,
            $consultationDate,
            $consultationTime,
            $consultationID,
            $staffID
        );

        mysqli_stmt_execute($updateStmt);

        mysqli_stmt_close($updateStmt);

    }


    /* ============================================================
       CREATE NEW CONSULTATION
    ============================================================ */

    else {

        $insertSql = "
            INSERT INTO consultations
            (
                AppointmentID,
                PatientID,
                StaffID,
                ConsultationDate,
                ConsultationTime,
                ChiefComplaint,
                Diagnosis,
                Treatment,
                Notes,
                FollowUpDate,
                Status,
                BloodPressure,
                Temperature,
                PulseRate
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                'Completed',
                ?,
                NULLIF(?, ''),
                NULLIF(?, '')
            )
        ";

        $insertStmt =
            mysqli_prepare($conn, $insertSql);

        if (!$insertStmt) {
            die(
                'Failed to prepare consultation insert: ' .
                mysqli_error($conn)
            );
        }


        mysqli_stmt_bind_param(
            $insertStmt,
            'iiissssssssss',
            $appointmentID,
            $patientID,
            $staffID,
            $consultationDate,
            $consultationTime,
            $chiefComplaint,
            $diagnosis,
            $finalTreatment,
            $clinicalNotes,
            $followUpDate,
            $bloodPressure,
            $temperature,
            $pulseRate
        );

        mysqli_stmt_execute($insertStmt);

        mysqli_stmt_close($insertStmt);
    }


    /* ------------------------------------------------------------
       MARK APPOINTMENT COMPLETED
    ------------------------------------------------------------ */

    $updateAppointmentSql = "
        UPDATE appointments
        SET Status = 'Completed'
        WHERE AppointmentID = ?
          AND StaffID = ?
    ";

    $updateAppointmentStmt =
        mysqli_prepare(
            $conn,
            $updateAppointmentSql
        );

    mysqli_stmt_bind_param(
        $updateAppointmentStmt,
        'ii',
        $appointmentID,
        $staffID
    );

    mysqli_stmt_execute(
        $updateAppointmentStmt
    );

    mysqli_stmt_close(
        $updateAppointmentStmt
    );


    /* ------------------------------------------------------------
       RETURN TO QUEUE
    ------------------------------------------------------------ */

    header('Location: doctor_queue.php?saved=1');
    exit;
}


/* ================================================================
   GET TODAY'S QUEUE
================================================================ */

$today = date('Y-m-d');


$queueSql = "
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
        p.Allergies,
        p.PastMedicalCondition,
        p.CurrentMedication,

        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Sex,
        u.DateOfBirth,

        d.DepartmentName,

        c.ConsultationID,
        c.Diagnosis,
        c.Treatment,
        c.Notes,
        c.ChiefComplaint,
        c.BloodPressure,
        c.Temperature,
        c.PulseRate,
        c.ConsultationDate,
        c.ConsultationTime,
        c.Status AS ConsultationStatus

    FROM appointments a

    INNER JOIN patients p
        ON a.PatientID = p.PatientID

    INNER JOIN users u
        ON p.UserID = u.UserID

    LEFT JOIN departments d
        ON a.DepartmentID = d.DepartmentID

    LEFT JOIN consultations c
        ON a.AppointmentID = c.AppointmentID

    WHERE a.StaffID = ?
      AND a.AppointmentDate = ?

    ORDER BY
        a.AppointmentTime ASC,
        a.AppointmentID ASC
";


$queueStmt = mysqli_prepare(
    $conn,
    $queueSql
);

if (!$queueStmt) {
    die(
        'Failed to prepare queue query: ' .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $queueStmt,
    'is',
    $staffID,
    $today
);

mysqli_stmt_execute($queueStmt);

$queueResult =
    mysqli_stmt_get_result($queueStmt);


$queue = [];


while ($row = mysqli_fetch_assoc($queueResult)) {

    /* ------------------------------------------------------------
       CALCULATE PATIENT AGE
    ------------------------------------------------------------ */

    $age = '';

    if (!empty($row['DateOfBirth'])) {

        $birthDate =
            new DateTime($row['DateOfBirth']);

        $todayDate =
            new DateTime($today);

        $age =
            $birthDate->diff($todayDate)->y;
    }


    /* ------------------------------------------------------------
       DETERMINE QUEUE STATUS
    ------------------------------------------------------------ */

    $appointmentStatus =
        strtolower(
            trim($row['AppointmentStatus'] ?? '')
        );

    $consultationStatus =
        strtolower(
            trim($row['ConsultationStatus'] ?? '')
        );


    if (
        $appointmentStatus === 'completed' ||
        $consultationStatus === 'completed'
    ) {

        $queueStatus = 'completed';

    } elseif (
        $consultationStatus === 'ongoing'
    ) {

        $queueStatus = 'in_progress';

    } elseif (
        $appointmentStatus === 'called'
    ) {

        $queueStatus = 'called';

    } else {

        $queueStatus = 'waiting';
    }


    /* ------------------------------------------------------------
       ALLERGIES
    ------------------------------------------------------------ */

    $allergies = [];

    if (!empty($row['Allergies'])) {

        $allergyText =
            trim($row['Allergies']);

        if ($allergyText !== '') {

            /*
             * Supports comma-separated allergies.
             *
             * Example:
             * Penicillin, Seafood
             */

            $allergies =
                array_map(
                    'trim',
                    explode(',', $allergyText)
                );
        }
    }


    /* ------------------------------------------------------------
       PATIENT NAME
    ------------------------------------------------------------ */

    $patientName =
        format_patient_name($row);


    /* ------------------------------------------------------------
       QUEUE NUMBER
    ------------------------------------------------------------ */

    /*
     * Your appointments table currently does not contain
     * a QueueNumber column.
     *
     * Therefore we temporarily generate one from AppointmentID.
     *
     * Example:
     * AppointmentID 25 → A-025
     */

    $queueNumber =
        'A-' .
        str_pad(
            (string)$row['AppointmentID'],
            3,
            '0',
            STR_PAD_LEFT
        );


    /* ------------------------------------------------------------
       HISTORY
    ------------------------------------------------------------ */

    $history = [];


    if (!empty($row['ConsultationID'])) {

        $history[] = [
            'date' =>
                $row['ConsultationDate'],

            'doctor' =>
                $doctorName,

            'diagnosis' =>
                $row['Diagnosis'] ?? '',

            'note' =>
                $row['Notes'] ?? '',

            'tag' =>
                $row['Treatment'] ?? '',

            'blood_pressure' =>
                $row['BloodPressure'] ?? '',

            'temperature' =>
                $row['Temperature'] ?? '',

            'pulse_rate' =>
                $row['PulseRate'] ?? ''
        ];
    }


    /* ------------------------------------------------------------
       BUILD QUEUE PATIENT
    ------------------------------------------------------------ */

    $queue[] = [

        'appointment_id' =>
            (int)$row['AppointmentID'],

        'patient_id' =>
            (int)$row['PatientID'],

        'staff_id' =>
            (int)$row['StaffID'],

        'queue_number' =>
            $queueNumber,

        'name' =>
            $patientName,

        'age' =>
            $age,

        'sex' =>
            $row['Sex'] ?? '',

        'blood' =>
            $row['BloodType'] ?? '',

        'allergies' =>
            $allergies,

        'status' =>
            $queueStatus,

        'appointment_date' =>
            $row['AppointmentDate'],

        'appointment_time' =>
            $row['AppointmentTime'],

        'purpose' =>
            $row['Purpose'] ?? '',

        'checkin_at' =>
            strtotime(
                $row['AppointmentDate'] .
                ' ' .
                $row['AppointmentTime']
            ),

        'consultation_id' =>
            !empty($row['ConsultationID'])
                ? (int)$row['ConsultationID']
                : null,

        'history' =>
            $history
    ];
}


mysqli_stmt_close($queueStmt);


/* ================================================================
   QUEUE COUNTS
================================================================ */

$counts = [
    'waiting' => 0,
    'called' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'total' => count($queue)
];


foreach ($queue as $p) {

    if (isset($counts[$p['status']])) {
        $counts[$p['status']]++;
    }
}


$progressPct =
    $counts['total'] > 0
        ? round(
            ($counts['completed'] /
            $counts['total']) * 100
        )
        : 0;


/* ================================================================
   CURRENTLY IN CONSULTATION
================================================================ */

$nowServing = null;


foreach ($queue as $p) {

    if ($p['status'] === 'in_progress') {

        $nowServing = $p;

        break;
    }
}


/* ================================================================
   WAITING RANK
================================================================ */

$waitingRank = [];

$rankCounter = 0;


foreach ($queue as $p) {

    if ($p['status'] === 'waiting') {

        $rankCounter++;

        $waitingRank[
            $p['appointment_id']
        ] = $rankCounter;
    }
}


/* ================================================================
   CONSULTATION VIEW
================================================================ */

$consultPatient = null;

$queueMessage = '';


if (isset($_GET['consult'])) {

    $appointmentID =
        (int)$_GET['consult'];


    /* ------------------------------------------------------------
       FIND REQUESTED PATIENT
    ------------------------------------------------------------ */

    foreach ($queue as $p) {

        if (
            $p['appointment_id'] ===
            $appointmentID
        ) {

            $consultPatient = $p;

            break;
        }
    }


    /* ------------------------------------------------------------
       CHECK IF ANOTHER PATIENT IS ALREADY IN CONSULTATION
    ------------------------------------------------------------ */

    if ($consultPatient) {

        $activePatient = null;


        foreach ($queue as $p) {

            if ($p['status'] === 'in_progress') {

                $activePatient = $p;

                break;
            }
        }


        if (
            $activePatient &&
            $activePatient['appointment_id']
            !== $appointmentID
        ) {

            $consultPatient = null;

            $queueMessage =
                'A patient is already in consultation. ' .
                'Please complete the current consultation first.';
        }
    }


    /* ------------------------------------------------------------
       START CONSULTATION
    ------------------------------------------------------------ */

    if ($consultPatient) {

        if (
            $consultPatient['status'] === 'waiting' ||
            $consultPatient['status'] === 'called'
        ) {

            /*
             * Create an ongoing consultation record.
             *
             * We do this when the doctor clicks Consult.
             */

            $existingConsultID =
                $consultPatient['consultation_id'];


            if (!$existingConsultID) {

                $startDate =
                    $today;

                $startTime =
                    date('H:i:s');


                $startSql = "
                    INSERT INTO consultations
                    (
                        AppointmentID,
                        PatientID,
                        StaffID,
                        ConsultationDate,
                        ConsultationTime,
                        ChiefComplaint,
                        Status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'Ongoing'
                    )
                ";

                $startStmt =
                    mysqli_prepare(
                        $conn,
                        $startSql
                    );


                $chiefComplaint =
                    $consultPatient['purpose'];


                mysqli_stmt_bind_param(
                    $startStmt,
                    'iiisss',
                    $consultPatient['appointment_id'],
                    $consultPatient['patient_id'],
                    $staffID,
                    $startDate,
                    $startTime,
                    $chiefComplaint
                );


                mysqli_stmt_execute(
                    $startStmt
                );


                mysqli_stmt_close(
                    $startStmt
                );


                /*
                 * Mark appointment as Called.
                 */

                $calledSql = "
                    UPDATE appointments
                    SET Status = 'Called'
                    WHERE AppointmentID = ?
                      AND StaffID = ?
                ";

                $calledStmt =
                    mysqli_prepare(
                        $conn,
                        $calledSql
                    );


                mysqli_stmt_bind_param(
                    $calledStmt,
                    'ii',
                    $appointmentID,
                    $staffID
                );


                mysqli_stmt_execute(
                    $calledStmt
                );


                mysqli_stmt_close(
                    $calledStmt
                );


                /*
                 * Reload page so the newly created
                 * consultation appears as Ongoing.
                 */

                header(
                    'Location: doctor_queue.php?consult=' .
                    $appointmentID
                );

                exit;
            }
        }


        /*
         * Reload consultation information from database.
         */

        $consultSql = "
            SELECT
                c.*,
                a.Purpose
            FROM consultations c
            INNER JOIN appointments a
                ON c.AppointmentID = a.AppointmentID
            WHERE c.AppointmentID = ?
              AND c.StaffID = ?
            LIMIT 1
        ";


        $consultStmt =
            mysqli_prepare(
                $conn,
                $consultSql
            );


        mysqli_stmt_bind_param(
            $consultStmt,
            'ii',
            $appointmentID,
            $staffID
        );


        mysqli_stmt_execute(
            $consultStmt
        );


        $consultResult =
            mysqli_stmt_get_result(
                $consultStmt
            );


        $consultation =
            mysqli_fetch_assoc(
                $consultResult
            );


        mysqli_stmt_close(
            $consultStmt
        );


        if ($consultation) {

            $consultPatient['consultation_id'] =
                (int)$consultation['ConsultationID'];

            $consultPatient['purpose'] =
                $consultation['Purpose'] ?? '';

            $consultPatient['chief_complaint'] =
                $consultation['ChiefComplaint'] ?? '';

            $consultPatient['diagnosis'] =
                $consultation['Diagnosis'] ?? '';

            $consultPatient['treatment'] =
                $consultation['Treatment'] ?? '';

            $consultPatient['notes'] =
                $consultation['Notes'] ?? '';

            $consultPatient['blood_pressure'] =
                $consultation['BloodPressure'] ?? '';

            $consultPatient['temperature'] =
                $consultation['Temperature'] ?? '';

            $consultPatient['pulse_rate'] =
                $consultation['PulseRate'] ?? '';

            $consultPatient['follow_up_date'] =
                $consultation['FollowUpDate'] ?? '';
        }
    }
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

<title>
    <?= $consultPatient ? 'Consultation' : 'Live Queue' ?>
    — Doctor Portal
</title>

<link
    rel="stylesheet"
    href="../assets/css/doctor/doctor_dashboard.css"
>

</head>


<body>

<div class="app">


<!-- =============================================================
     SIDEBAR
============================================================== -->

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

                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>

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
      <li class="nav-item">
        <a href="doctor_dashboard.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
          Dashboard
        </a>
      </li>
      <li class="nav-item active">
        <a href="doctor_queue.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          Queue
        </a>
      </li>
      <li class="nav-item">
        <a href="records.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Records
        </a>
      </li>
      <li class="nav-item">
        <a href="search_patient.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Search Patient
        </a>
      </li>
      <li class="nav-item">
        <a href="doctor_profile.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
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
                <?= htmlspecialchars(initials($doctorName)) ?>
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


<!-- =============================================================
     MAIN
============================================================== -->

<main class="main">


<?php if ($consultPatient): ?>


<!-- =============================================================
     CONSULTATION VIEW
============================================================== -->

<div class="doctor-topbar">

    <div class="page-header">

        <h1>
            Consultation
        </h1>

        <p>
            Record patient diagnosis and treatment
        </p>

    </div>


    <a
        class="back-link"
        href="doctor_queue.php"
    >

        ← Back To Queue

    </a>

</div>


<?php if ($queueMessage): ?>

<div class="queue-alert">

    <?= htmlspecialchars($queueMessage) ?>

</div>

<?php endif; ?>


<!-- PATIENT BANNER -->

<div class="consult-patient-banner">

    <div class="cpb-top">


        <div class="cpb-avatar">

            <?= htmlspecialchars(
                initials($consultPatient['name'])
            ) ?>

        </div>


        <div>

            <div class="cpb-name">

                <?= htmlspecialchars(
                    $consultPatient['name']
                ) ?>

            </div>


            <div class="cpb-meta">

                <span>

                    <?= (int)$consultPatient['age'] ?>
                    years

                    &middot;

                    <?= htmlspecialchars(
                        $consultPatient['sex']
                    ) ?>

                </span>


                <span class="sep">
                    |
                </span>


                <span>

                    Queue:
                    <?= htmlspecialchars(
                        $consultPatient['queue_number']
                    ) ?>

                </span>


                <span class="sep">
                    |
                </span>


                <span>

                    Blood:
                    <?= htmlspecialchars(
                        $consultPatient['blood'] ?: 'N/A'
                    ) ?>

                </span>

            </div>

        </div>


        <?php if (!empty($consultPatient['allergies'])): ?>

        <div class="cpb-allergy">

            ⚠ Allergies:

            <?= htmlspecialchars(
                implode(
                    ', ',
                    $consultPatient['allergies']
                )
            ) ?>

        </div>

        <?php endif; ?>


    </div>

</div>


<!-- CONSULTATION FORM -->

<form method="post">

<input
    type="hidden"
    name="action"
    value="save_consultation"
>


<input
    type="hidden"
    name="appointment_id"
    value="<?= (int)$consultPatient['appointment_id'] ?>"
>


<input
    type="hidden"
    name="patient_id"
    value="<?= (int)$consultPatient['patient_id'] ?>"
>


<div class="consult-grid">


<!-- ============================================================
     LEFT COLUMN
============================================================= -->

<div>


<div class="consult-panel">

    <div class="consult-panel-head">

        <div class="consult-panel-title">

            Diagnosis &amp; Notes

        </div>

    </div>


    <div class="form-field">

        <label for="chief-complaint">

            Chief Complaint

        </label>

        <input
            type="text"
            id="chief-complaint"
            name="chief_complaint"
            value="<?= htmlspecialchars(
                $consultPatient['chief_complaint'] ?? ''
            ) ?>"
            placeholder="Patient's main complaint"
        >

    </div>


    <div class="form-field">

        <label for="diagnosis">

            Diagnosis

        </label>

        <input
            type="text"
            id="diagnosis"
            name="diagnosis"
            value="<?= htmlspecialchars(
                $consultPatient['diagnosis'] ?? ''
            ) ?>"
            placeholder="Enter diagnosis"
        >

    </div>


    <div class="form-field">

        <label for="clinical-notes">

            Clinical Notes

        </label>

        <textarea
            id="clinical-notes"
            name="clinical_notes"
            placeholder="Patient symptoms, observations, history..."
        ><?= htmlspecialchars(
            $consultPatient['notes'] ?? ''
        ) ?></textarea>

    </div>


    <div class="form-field">

        <label for="treatment-plan">

            Treatment Plan

        </label>

        <textarea
            id="treatment-plan"
            name="treatment_plan"
            placeholder="Recommended treatment, follow-up, instructions..."
        ><?= htmlspecialchars(
            $consultPatient['treatment'] ?? ''
        ) ?></textarea>

    </div>


    <div class="form-field">

        <label for="follow-up-date">

            Follow-up Date

        </label>

        <input
            type="date"
            id="follow-up-date"
            name="follow_up_date"
            value="<?= htmlspecialchars(
                $consultPatient['follow_up_date'] ?? ''
            ) ?>"
        >

    </div>


</div>


<!-- ============================================================
     PRESCRIPTIONS
============================================================= -->

<div class="consult-panel">

    <div class="consult-panel-head">

        <div class="consult-panel-title">

            Prescriptions

        </div>


        <button
            type="button"
            class="btn-add-sm"
            onclick="addPrescriptionRow()"
        >

            + Add

        </button>

    </div>


    <div id="prescription-list">

        <div class="prescription-entry">

            <div class="prescription-row">

                <input
                    type="text"
                    name="rx_name[]"
                    placeholder="Medicine name & strength"
                >


                <input
                    type="text"
                    name="rx_dosage[]"
                    placeholder="Dosage"
                >

            </div>


            <div class="prescription-row">

                <input
                    type="text"
                    name="rx_instructions[]"
                    class="full"
                    placeholder="Instructions"
                >

            </div>

        </div>

    </div>

</div>


</div>


<!-- ============================================================
     RIGHT COLUMN
============================================================= -->

<div>


<!-- PAST CONSULTATIONS -->

<div class="consult-panel">

    <div class="consult-panel-head">

        <div class="consult-panel-title">

            Past Consultations

        </div>

    </div>


    <?php if (empty($consultPatient['history'])): ?>

        <div class="records-empty">

            No past consultations on file.

        </div>

    <?php else: ?>


        <?php foreach (
            array_reverse($consultPatient['history'])
            as $c
        ): ?>


        <div class="past-consult-item">

            <div class="pc-head">

                <span class="pc-date">

                    <?= htmlspecialchars(
                        $c['date']
                    ) ?>

                </span>


                <span>

                    <?= htmlspecialchars(
                        $c['doctor']
                    ) ?>

                </span>

            </div>


            <?php if (!empty($c['diagnosis'])): ?>

            <div class="pc-diagnosis">

                <?= htmlspecialchars(
                    $c['diagnosis']
                ) ?>

            </div>

            <?php endif; ?>


            <?php if (!empty($c['note'])): ?>

            <div class="pc-note">

                <?= nl2br(
                    htmlspecialchars(
                        $c['note']
                    )
                ) ?>

            </div>

            <?php endif; ?>


            <?php if (!empty($c['tag'])): ?>

            <span class="pc-tag">

                <?= htmlspecialchars(
                    $c['tag']
                ) ?>

            </span>

            <?php endif; ?>

        </div>


        <?php endforeach; ?>


    <?php endif; ?>

</div>


<!-- VITALS -->

<div class="consult-panel">

    <div class="consult-panel-head">

        <div class="consult-panel-title">

            Vitals

        </div>

    </div>


    <div class="vitals-grid">


        <div class="form-field">

            <label for="vital-bp">

                Blood Pressure

            </label>

            <input
                type="text"
                id="vital-bp"
                name="vital_bp"
                value="<?= htmlspecialchars(
                    $consultPatient['blood_pressure'] ?? ''
                ) ?>"
                placeholder="120/80"
            >

        </div>


        <div class="form-field">

            <label for="vital-temp">

                Temperature

            </label>

            <input
                type="text"
                id="vital-temp"
                name="vital_temp"
                value="<?= htmlspecialchars(
                    $consultPatient['temperature'] ?? ''
                ) ?>"
                placeholder="36.8"
            >

        </div>


        <div class="form-field full">

            <label for="vital-pulse">

                Pulse

            </label>

            <input
                type="number"
                id="vital-pulse"
                name="vital_pulse"
                value="<?= htmlspecialchars(
                    $consultPatient['pulse_rate'] ?? ''
                ) ?>"
                placeholder="72"
            >

        </div>


    </div>

</div>


<div class="consult-save-wrap">

    <button
        type="submit"
        class="btn-save-consult"
    >

        Save Consultation

    </button>

</div>


</div>


</div>

</form>


<script>

function addPrescriptionRow()
{
    const list =
        document.getElementById(
            'prescription-list'
        );


    const entry =
        document.createElement(
            'div'
        );


    entry.className =
        'prescription-entry';


    entry.innerHTML = `

        <div class="prescription-row">

            <input
                type="text"
                name="rx_name[]"
                placeholder="Medicine name & strength"
            >

            <input
                type="text"
                name="rx_dosage[]"
                placeholder="Dosage"
            >

        </div>

        <div class="prescription-row">

            <input
                type="text"
                name="rx_instructions[]"
                class="full"
                placeholder="Instructions"
            >

        </div>

        <button
            type="button"
            class="btn-remove-rx"
            onclick="this.closest('.prescription-entry').remove()"
        >

            Remove

        </button>
    `;


    list.appendChild(entry);
}

</script>


<?php else: ?>


<!-- =============================================================
     QUEUE VIEW
============================================================== -->

<div class="doctor-topbar">

    <div class="page-header">

        <h1>
            Live Queue
        </h1>


        <div class="queue-live-status">

            <span class="queue-live-dot"></span>

            Live · <?= htmlspecialchars($today) ?>

        </div>

    </div>

</div>


<?php if (isset($_GET['saved'])): ?>

<div class="queue-alert">

    Consultation saved successfully.

</div>

<?php endif; ?>


<?php if ($queueMessage): ?>

<div class="queue-alert">

    <?= htmlspecialchars($queueMessage) ?>

</div>

<?php endif; ?>


<!-- =============================================================
     NOW SERVING
============================================================== -->

<?php if ($nowServing): ?>

<div class="queue-hero">

    <div class="queue-hero-left">

        <div class="queue-hero-pill">

            <span class="dot"></span>

            In Consultation

        </div>


        <div class="queue-hero-main">

            <div class="queue-hero-avatar">

                <?= htmlspecialchars(
                    initials(
                        $nowServing['name']
                    )
                ) ?>

            </div>


            <div>

                <div class="queue-hero-name">

                    <?= htmlspecialchars(
                        $nowServing['name']
                    ) ?>

                </div>


                <div class="queue-hero-sub">

                    <?= htmlspecialchars(
                        $nowServing['queue_number']
                    ) ?>

                    ·

                    <?= htmlspecialchars(
                        date(
                            'h:i A',
                            strtotime(
                                $nowServing['appointment_time']
                            )
                        )
                    ) ?>

                </div>

            </div>

        </div>

    </div>


    <div class="queue-hero-right">

        <div class="queue-hero-label">

            Actions

        </div>


        <a
            class="btn-quick teal"
            href="doctor_queue.php?consult=<?= (int)$nowServing['appointment_id'] ?>"
        >

            Resume Consultation

        </a>

    </div>

</div>

<?php endif; ?>


<!-- =============================================================
     STAT CARDS
============================================================== -->

<div class="queue-stats">


<div class="queue-stat-card teal">

    <div class="queue-stat-left">

        <div class="queue-stat-label">

            In Consultation

        </div>

    </div>

    <div class="queue-stat-value">

        <?= $counts['in_progress'] ?>

    </div>

</div>


<div class="queue-stat-card amber">

    <div class="queue-stat-left">

        <div class="queue-stat-label">

            Waiting

        </div>

    </div>

    <div class="queue-stat-value">

        <?= $counts['waiting'] ?>

    </div>

</div>


<div class="queue-stat-card slate">

    <div class="queue-stat-left">

        <div class="queue-stat-label">

            Total Patients Today

        </div>

    </div>

    <div class="queue-stat-value">

        <?= $counts['total'] ?>

    </div>

</div>


<div class="queue-stat-card green">

    <div class="queue-stat-left">

        <div class="queue-stat-label">

            Completed

        </div>

    </div>

    <div class="queue-stat-value">

        <?= $counts['completed'] ?>

    </div>

</div>


</div>


<!-- =============================================================
     PROGRESS
============================================================== -->

<div class="queue-progress-panel">

    <div class="queue-progress-head">

        <span>
            Today's Progress
        </span>


        <span class="count">

            <?= $counts['completed'] ?>
            /
            <?= $counts['total'] ?>
            seen

        </span>

    </div>


    <div class="queue-progress-bar">

        <div
            class="queue-progress-fill"
            style="width: <?= $progressPct ?>%;"
        ></div>

    </div>


    <div class="queue-progress-sub">

        <?= $progressPct ?>%
        of today's patients seen

    </div>

</div>


<!-- =============================================================
     QUEUE TABLE
============================================================== -->

<div class="queue-table-panel">


<div class="queue-filter-bar">

    <button
        class="queue-filter-btn active"
        onclick="filterQueue('all', this)"
    >
        All
    </button>


    <button
        class="queue-filter-btn"
        onclick="filterQueue('waiting', this)"
    >
        Waiting
    </button>


    <button
        class="queue-filter-btn"
        onclick="filterQueue('called', this)"
    >
        Called
    </button>


    <button
        class="queue-filter-btn"
        onclick="filterQueue('in_progress', this)"
    >
        In Consultation
    </button>


    <button
        class="queue-filter-btn"
        onclick="filterQueue('completed', this)"
    >
        Completed
    </button>

</div>


<div class="queue-table-wrap">

<table class="queue-table">

<thead>

<tr>

    <th>
        Queue #
    </th>

    <th>
        Patient
    </th>

    <th>
        Appointment
    </th>

    <th>
        Status
    </th>

    <th>
        Est. Wait
    </th>

    <th>
        Actions
    </th>

</tr>

</thead>


<tbody id="queue-tbody">


<?php if (empty($queue)): ?>


<tr>

    <td
        colspan="6"
        style="text-align:center;padding:40px;"
    >

        No patients are scheduled for you today.

    </td>

</tr>


<?php else: ?>


<?php foreach ($queue as $p): ?>


<?php

$badgeClass =
    $p['status'] === 'in_progress'
        ? 'serving'
        : $p['status'];


$statusLabels = [

    'waiting' =>
        'Waiting',

    'called' =>
        'Called',

    'in_progress' =>
        'In Consultation',

    'completed' =>
        'Completed'

];


$statusLabel =
    $statusLabels[
        $p['status']
    ] ?? $p['status'];

?>


<tr

    class="<?= $p['status'] === 'in_progress'
        ? 'highlight'
        : '' ?>"

    data-status="<?= htmlspecialchars(
        $p['status']
    ) ?>"
>


<td>

    <span
        class="queue-num-badge <?= htmlspecialchars(
            $badgeClass
        ) ?>"
    >

        <?= htmlspecialchars(
            $p['queue_number']
        ) ?>

    </span>

</td>


<td>

    <div class="queue-patient-name">

        <?= htmlspecialchars(
            $p['name']
        ) ?>

    </div>


    <div class="queue-patient-meta">

        <?= (int)$p['age'] ?>y

        &middot;

        <?= htmlspecialchars(
            $p['sex']
        ) ?>

    </div>

</td>


<td>

    <?= htmlspecialchars(
        date(
            'h:i A',
            strtotime(
                $p['appointment_time']
            )
        )
    ) ?>

</td>


<td>

    <span
        class="queue-status-text <?= htmlspecialchars(
            $p['status']
        ) ?>"
    >

        <?= htmlspecialchars(
            $statusLabel
        ) ?>

    </span>

</td>


<td>

<?php if ($p['status'] === 'waiting'): ?>


<span class="queue-est-wait">

    ~<?= 8 *
        ($waitingRank[
            $p['appointment_id']
        ] ?? 1) ?>

    min

</span>


<?php else: ?>


<span class="queue-est-wait none">

    &mdash;

</span>


<?php endif; ?>

</td>


<td class="queue-actions-cell">


<?php if (
    $p['status'] === 'waiting' ||
    $p['status'] === 'called'
): ?>


<a
    class="btn-teal-solid"
    href="doctor_queue.php?consult=<?= (int)$p['appointment_id'] ?>"
>

    Consult

</a>


<?php elseif (
    $p['status'] === 'in_progress'
): ?>


<a
    class="btn-teal-solid"
    href="doctor_queue.php?consult=<?= (int)$p['appointment_id'] ?>"
>

    Resume

</a>


<?php else: ?>


<a
    class="btn-done"
    href="doctor_queue.php?consult=<?= (int)$p['appointment_id'] ?>"
>

    View

</a>


<?php endif; ?>


</td>


</tr>


<?php endforeach; ?>


<?php endif; ?>


</tbody>

</table>

</div>

</div>


<script>

function filterQueue(status, btn)
{
    document
        .querySelectorAll(
            '.queue-filter-btn'
        )
        .forEach(
            function(button)
            {
                button.classList.remove(
                    'active'
                );
            }
        );


    btn.classList.add(
        'active'
    );


    document
        .querySelectorAll(
            '#queue-tbody tr'
        )
        .forEach(
            function(row)
            {
                if (
                    status === 'all' ||
                    row.dataset.status === status
                )
                {
                    row.style.display = '';
                }
                else
                {
                    row.style.display = 'none';
                }
            }
        );
}

</script>


<?php endif; ?>


</main>

</div>

</body>

</html>