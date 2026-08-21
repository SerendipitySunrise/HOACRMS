<?php
/**
 * records.php — Medical Records
 * Doctor Portal
 *
 * Displays REAL consultation records from the HOACRMS database.
 *
 * Database relationship:
 *
 * users
 *   ↓ UserID
 * staff
 *   ↓ StaffID
 * consultations
 *   ↓ PatientID
 * patients
 *   ↓ UserID
 * users
 *
 * Uses mysqli because includes/db.php provides $conn.
 */

session_start();

require_once __DIR__ . '/../includes/db.php';


/*
|--------------------------------------------------------------------------
| CHECK DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !$conn) {
    die('Database connection failed.');
}


/*
|--------------------------------------------------------------------------
| GET LOGGED-IN USER ID
|--------------------------------------------------------------------------
|
| Your users table uses:
|
| UserID
| FirstName
| MiddleName
| LastName
| RoleID
|
| The login system should normally store UserID in the session.
|
*/

$userID = $_SESSION['UserID']
       ?? $_SESSION['user_id']
       ?? $_SESSION['userid']
       ?? null;


/*
|--------------------------------------------------------------------------
| STOP IF USER IS NOT LOGGED IN
|--------------------------------------------------------------------------
*/

if (!$userID) {
    header('Location: ../auth/login.php');
    exit;
}

$userID = (int) $userID;


/*
|--------------------------------------------------------------------------
| GET DOCTOR INFORMATION
|--------------------------------------------------------------------------
|
| staff.UserID connects the logged-in user to their staff account.
|
*/

$doctorStmt = $conn->prepare("
    SELECT
        s.StaffID,
        s.UserID,
        s.DepartmentID,
        s.StaffRole,
        s.Specialization,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.ProfilePhoto,
        d.DepartmentName
    FROM staff s
    INNER JOIN users u
        ON s.UserID = u.UserID
    LEFT JOIN departments d
        ON s.DepartmentID = d.DepartmentID
    WHERE s.UserID = ?
    LIMIT 1
");

if (!$doctorStmt) {
    die('Doctor query failed: ' . $conn->error);
}

$doctorStmt->bind_param("i", $userID);
$doctorStmt->execute();

$doctorResult = $doctorStmt->get_result();
$doctor = $doctorResult->fetch_assoc();

$doctorStmt->close();


/*
|--------------------------------------------------------------------------
| VERIFY THAT USER IS A DOCTOR / STAFF ACCOUNT
|--------------------------------------------------------------------------
*/

if (!$doctor) {
    die('Doctor account information could not be found.');
}

$staffID = (int) $doctor['StaffID'];


/*
|--------------------------------------------------------------------------
| DOCTOR DISPLAY NAME
|--------------------------------------------------------------------------
*/

$doctorFullName = trim(
    $doctor['FirstName'] . ' ' .
    ($doctor['MiddleName'] ? $doctor['MiddleName'] . ' ' : '') .
    $doctor['LastName']
);

$doctorDisplayName = 'Dr. ' . $doctorFullName;

$doctorDepartment = $doctor['DepartmentName']
    ?: ($doctor['Specialization'] ?: 'Doctor');


/*
|--------------------------------------------------------------------------
| GET REAL CONSULTATION RECORDS
|--------------------------------------------------------------------------
|
| We retrieve only consultations handled by THIS doctor.
|
| consultations.StaffID
|        ↓
| staff.StaffID
|
| consultations.PatientID
|        ↓
| patients.PatientID
|        ↓
| patients.UserID
|        ↓
| users.UserID
|
*/

$records = [];

$recordsStmt = $conn->prepare("
    SELECT
        c.ConsultationID,
        c.AppointmentID,
        c.PatientID,
        c.StaffID,

        c.ConsultationDate,
        c.ConsultationTime,

        c.ChiefComplaint,
        c.Diagnosis,
        c.Treatment,
        c.LabRequest,
        c.Notes,
        c.FollowUpDate,
        c.Status,

        c.BloodPressure,
        c.Temperature,
        c.PulseRate,

        p.BloodType,
        p.Allergies,

        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Sex,
        u.DateOfBirth,

        s.StaffRole,
        s.Specialization,

        d.DepartmentName

    FROM consultations c

    INNER JOIN patients p
        ON c.PatientID = p.PatientID

    INNER JOIN users u
        ON p.UserID = u.UserID

    INNER JOIN staff s
        ON c.StaffID = s.StaffID

    LEFT JOIN departments d
        ON s.DepartmentID = d.DepartmentID

    WHERE c.StaffID = ?

    ORDER BY
        c.ConsultationDate DESC,
        c.ConsultationTime DESC,
        c.ConsultationID DESC
");

if (!$recordsStmt) {
    die('Consultation query failed: ' . $conn->error);
}

$recordsStmt->bind_param("i", $staffID);
$recordsStmt->execute();

$recordsResult = $recordsStmt->get_result();


/*
|--------------------------------------------------------------------------
| FORMAT REAL DATABASE DATA
|--------------------------------------------------------------------------
*/

while ($row = $recordsResult->fetch_assoc()) {

    /*
    |--------------------------------------------------------------------------
    | PATIENT FULL NAME
    |--------------------------------------------------------------------------
    */

    $patientName = trim(
        $row['FirstName'] . ' ' .
        ($row['MiddleName'] ? $row['MiddleName'] . ' ' : '') .
        $row['LastName']
    );


    /*
    |--------------------------------------------------------------------------
    | CALCULATE PATIENT AGE
    |--------------------------------------------------------------------------
    */

    $age = null;

    if (!empty($row['DateOfBirth'])) {

        try {

            $birthDate = new DateTime($row['DateOfBirth']);
            $today = new DateTime();

            $age = $birthDate->diff($today)->y;

        } catch (Exception $e) {

            $age = null;

        }
    }


    /*
    |--------------------------------------------------------------------------
    | FORMAT CONSULTATION DATE
    |--------------------------------------------------------------------------
    */

    $formattedDate = 'Unknown date';

    if (!empty($row['ConsultationDate'])) {

        $timestamp = strtotime($row['ConsultationDate']);

        if ($timestamp !== false) {
            $formattedDate = date('M j, Y', $timestamp);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | FORMAT TEMPERATURE
    |--------------------------------------------------------------------------
    */

    $temperature = 'Not recorded';

    if ($row['Temperature'] !== null && $row['Temperature'] !== '') {
        $temperature = $row['Temperature'] . '°C';
    }


    /*
    |--------------------------------------------------------------------------
    | FORMAT PULSE RATE
    |--------------------------------------------------------------------------
    */

    $pulseRate = 'Not recorded';

    if ($row['PulseRate'] !== null && $row['PulseRate'] !== '') {
        $pulseRate = $row['PulseRate'] . ' bpm';
    }


    /*
    |--------------------------------------------------------------------------
    | FORMAT BLOOD PRESSURE
    |--------------------------------------------------------------------------
    */

    $bloodPressure = !empty($row['BloodPressure'])
        ? $row['BloodPressure']
        : 'Not recorded';


    /*
    |--------------------------------------------------------------------------
    | BUILD RECORD
    |--------------------------------------------------------------------------
    */

    $records[] = [

        'id' => (int) $row['ConsultationID'],

        'appointment_id' => (int) $row['AppointmentID'],

        'patient_id' => (int) $row['PatientID'],

        'patient' => $patientName,

        'age' => $age,

        'sex' => $row['Sex'] ?? '',

        'blood_type' => $row['BloodType'] ?? '',

        'doctor' => $doctorDisplayName,

        'department' => $row['DepartmentName'] ?? '',

        'specialization' => $row['Specialization'] ?? '',

        'date' => $formattedDate,

        'raw_date' => $row['ConsultationDate'],

        'time' => $row['ConsultationTime'] ?? '',

        'chief_complaint' => $row['ChiefComplaint'] ?? '',

        'diagnosis' => $row['Diagnosis'] ?? '',

        'treatment' => $row['Treatment'] ?? '',

        'lab' => $row['LabRequest'] ?? '',

        'notes' => $row['Notes'] ?? '',

        'follow_up' => $row['FollowUpDate'] ?? '',

        'status' => $row['Status'] ?? '',

        'allergies' => $row['Allergies'] ?? '',

        'vitals' => [

            'bp' => $bloodPressure,

            'temp' => $temperature,

            'pulse' => $pulseRate

        ]

    ];
}

$recordsStmt->close();


/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));

    $first = $parts[0][0] ?? '';

    $last = '';

    if (count($parts) > 1) {
        $last = $parts[count($parts) - 1][0] ?? '';
    }

    return strtoupper($first . $last);
}


/*
|--------------------------------------------------------------------------
| GET DOCTOR INITIALS
|--------------------------------------------------------------------------
*/

$doctorInitials = initials($doctorFullName);


/*
|--------------------------------------------------------------------------
| RECORD COUNT
|--------------------------------------------------------------------------
*/

$totalRecords = count($records);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>Medical Records — Doctor Portal</title>

<link
    rel="stylesheet"
    href="../assets/css/doctor/doctor_dashboard.css"
>

</head>


<body>

<div class="app">


<!-- ============================================================
     SIDEBAR
============================================================ -->

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


    <!-- NAVIGATION -->

    <ul class="nav-list">

        <li class="nav-item">

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


        <li class="nav-item active">

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
                <?= htmlspecialchars($doctorInitials) ?>
                <?php endif; ?>
            </div>

            <div>

                <div class="user-name">
                    <?= htmlspecialchars($doctorDisplayName) ?>
                </div>

                <div class="user-role">
                    <?= htmlspecialchars($doctorDepartment) ?>
                </div>

            </div>

        </div>


        <a
            href="../auth/logout.php"
            class="sign-out"
            onclick="return confirm('Are you sure you want to sign out?');"
        >

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>

            Sign Out

        </a>

    </div>

</aside>


<!-- ============================================================
     MAIN CONTENT
============================================================ -->

<main class="main">


    <!-- HEADER -->

    <div class="doctor-topbar">

        <div class="page-header">

            <h1>
                Medical Records
            </h1>

            <p>
                Consultation history for
                <?= htmlspecialchars($doctorDisplayName) ?>
            </p>

        </div>

    </div>


    <!-- ========================================================
         FILTER BAR
    ========================================================= -->

    <div class="records-filter-bar">

        <div class="filter-field">

            <label for="rf-search">
                Search Patient or Diagnosis
            </label>

            <div class="input-wrap">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <circle cx="11" cy="11" r="8"/>
                    <path d="M21 21l-4.35-4.35"/>
                </svg>

                <input
                    type="text"
                    id="rf-search"
                    placeholder="Search by name or diagnosis..."
                >

            </div>

        </div>


        <div class="filter-field">

            <label for="rf-from">
                From Date
            </label>

            <input
                type="date"
                id="rf-from"
            >

        </div>


        <div class="filter-field">

            <label for="rf-to">
                To Date
            </label>

            <input
                type="date"
                id="rf-to"
            >

        </div>

    </div>


    <!-- ========================================================
         RECORDS PANEL
    ========================================================= -->

    <div class="records-panel">


        <div class="records-panel-head">

            <h2>
                Consultation History
            </h2>

            <span
                class="records-count"
                id="records-count"
            >
                (<?= $totalRecords ?> records)
            </span>

        </div>


        <div id="records-list">


        <?php if (empty($records)): ?>


            <!-- NO RECORDS -->

            <div
                class="records-empty"
                id="database-empty"
            >

                No consultation records found.

            </div>


        <?php else: ?>


            <?php foreach ($records as $i => $r): ?>


                <div
                    class="record-item<?= $i === 0 ? ' open' : '' ?>"
                    data-name="<?= htmlspecialchars(strtolower($r['patient'])) ?>"
                    data-diagnosis="<?= htmlspecialchars(strtolower($r['diagnosis'])) ?>"
                    data-date="<?= htmlspecialchars($r['raw_date']) ?>"
                >


                    <!-- RECORD SUMMARY -->

                    <div
                        class="record-summary"
                        onclick="toggleRecord(this)"
                    >


                        <div class="record-avatar">

                            <?= htmlspecialchars(initials($r['patient'])) ?>

                        </div>


                        <div class="record-info">

                            <div class="record-name">

                                <?= htmlspecialchars($r['patient']) ?>

                            </div>


                            <div class="record-meta">

                                <?php if ($r['age'] !== null): ?>

                                    Age <?= (int)$r['age'] ?>

                                <?php else: ?>

                                    Age not recorded

                                <?php endif; ?>


                                |

                                <?= htmlspecialchars($r['doctor']) ?>


                                |

                                <?= htmlspecialchars($r['date']) ?>


                            </div>

                        </div>


                        <div class="record-chevron">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M6 9l6 6 6-6"/>
                            </svg>

                        </div>


                    </div>


                    <!-- ==================================================
                         RECORD DETAILS
                    ================================================== -->

                    <div class="record-detail">


                        <!-- CHIEF COMPLAINT -->

                        <?php if (!empty($r['chief_complaint'])): ?>

                            <div class="record-block">

                                <div class="record-block-label">
                                    Chief Complaint
                                </div>

                                <div class="record-block-text">

                                    <?= nl2br(
                                        htmlspecialchars($r['chief_complaint'])
                                    ) ?>

                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- DIAGNOSIS -->

                        <div class="record-block">

                            <div class="record-block-label">
                                Diagnosis
                            </div>

                            <div class="record-block-text">

                                <?php if (!empty($r['diagnosis'])): ?>

                                    <?= nl2br(
                                        htmlspecialchars($r['diagnosis'])
                                    ) ?>

                                <?php else: ?>

                                    <span>
                                        Not recorded
                                    </span>

                                <?php endif; ?>

                            </div>

                        </div>


                        <!-- TREATMENT -->

                        <div class="record-block">

                            <div class="record-block-label">
                                Treatment
                            </div>

                            <div class="record-block-text">

                                <?php if (!empty($r['treatment'])): ?>

                                    <?= nl2br(
                                        htmlspecialchars($r['treatment'])
                                    ) ?>

                                <?php else: ?>

                                    Not recorded

                                <?php endif; ?>

                            </div>

                        </div>


                        <!-- LAB REQUEST -->

                        <div class="record-block">

                            <div class="record-block-label">
                                Lab Request
                            </div>

                            <div class="record-block-text">

                                <?php if (!empty($r['lab'])): ?>

                                    <?= nl2br(
                                        htmlspecialchars($r['lab'])
                                    ) ?>

                                <?php else: ?>

                                    No lab request recorded

                                <?php endif; ?>

                            </div>

                        </div>


                        <!-- NOTES -->

                        <?php if (!empty($r['notes'])): ?>

                            <div class="record-block">

                                <div class="record-block-label">
                                    Notes
                                </div>

                                <div class="record-block-text">

                                    <?= nl2br(
                                        htmlspecialchars($r['notes'])
                                    ) ?>

                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- FOLLOW-UP -->

                        <?php if (!empty($r['follow_up'])): ?>

                            <div class="record-block">

                                <div class="record-block-label">
                                    Follow-up Date
                                </div>

                                <div class="record-block-text">

                                    <?= htmlspecialchars(
                                        date(
                                            'F j, Y',
                                            strtotime($r['follow_up'])
                                        )
                                    ) ?>

                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- STATUS -->

                        <?php if (!empty($r['status'])): ?>

                            <div class="record-block">

                                <div class="record-block-label">
                                    Consultation Status
                                </div>

                                <div class="record-block-text">

                                    <?= htmlspecialchars($r['status']) ?>

                                </div>

                            </div>

                        <?php endif; ?>


                        <!-- VITALS -->

                        <div class="record-block">

                            <div class="record-block-label">
                                Vitals
                            </div>


                            <div class="record-vitals">


                                <div class="vital-box">

                                    <div class="vital-label">
                                        Blood Pressure
                                    </div>

                                    <div class="vital-value">

                                        <?= htmlspecialchars(
                                            $r['vitals']['bp']
                                        ) ?>

                                    </div>

                                </div>


                                <div class="vital-box">

                                    <div class="vital-label">
                                        Temperature
                                    </div>

                                    <div class="vital-value">

                                        <?= htmlspecialchars(
                                            $r['vitals']['temp']
                                        ) ?>

                                    </div>

                                </div>


                                <div class="vital-box">

                                    <div class="vital-label">
                                        Pulse Rate
                                    </div>

                                    <div class="vital-value">

                                        <?= htmlspecialchars(
                                            $r['vitals']['pulse']
                                        ) ?>

                                    </div>

                                </div>


                            </div>

                        </div>


                        <!-- PATIENT INFORMATION -->

                        <div class="record-block">

                            <div class="record-block-label">
                                Patient Information
                            </div>


                            <div class="record-block-text">

                                <?php if (!empty($r['sex'])): ?>

                                    Sex:
                                    <?= htmlspecialchars($r['sex']) ?>

                                    <br>

                                <?php endif; ?>


                                <?php if (!empty($r['blood_type'])): ?>

                                    Blood Type:
                                    <?= htmlspecialchars($r['blood_type']) ?>

                                    <br>

                                <?php endif; ?>


                                <?php if (!empty($r['allergies'])): ?>

                                    Allergies:
                                    <?= htmlspecialchars($r['allergies']) ?>

                                <?php endif; ?>

                            </div>

                        </div>


                    </div>

                </div>


            <?php endforeach; ?>


        <?php endif; ?>


        </div>


        <!-- FILTER EMPTY MESSAGE -->

        <div
            class="records-empty"
            id="records-empty"
            style="display:none;"
        >
            No consultation records match your filters.
        </div>


    </div>


</main>

</div>


<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script>

function toggleRecord(summaryEl)
{
    const item = summaryEl.closest('.record-item');

    if (item) {
        item.classList.toggle('open');
    }
}


/*
|--------------------------------------------------------------------------
| FILTER ELEMENTS
|--------------------------------------------------------------------------
*/

const searchInput =
    document.getElementById('rf-search');

const fromInput =
    document.getElementById('rf-from');

const toInput =
    document.getElementById('rf-to');

const items =
    Array.from(
        document.querySelectorAll('.record-item')
    );

const countEl =
    document.getElementById('records-count');

const emptyEl =
    document.getElementById('records-empty');


/*
|--------------------------------------------------------------------------
| APPLY FILTERS
|--------------------------------------------------------------------------
*/

function applyFilters()
{

    const q =
        searchInput.value
        .trim()
        .toLowerCase();


    const from =
        fromInput.value
        ? new Date(fromInput.value + 'T00:00:00')
        : null;


    const to =
        toInput.value
        ? new Date(toInput.value + 'T23:59:59')
        : null;


    let visible = 0;


    items.forEach(item =>
    {

        const name =
            item.dataset.name || '';


        const diagnosis =
            item.dataset.diagnosis || '';


        const rawDate =
            item.dataset.date || '';


        const recordDate =
            rawDate
            ? new Date(rawDate + 'T00:00:00')
            : null;


        let match = true;


        /*
        | Search
        */

        if (
            q &&
            !name.includes(q) &&
            !diagnosis.includes(q)
        ) {

            match = false;

        }


        /*
        | From date
        */

        if (
            match &&
            from &&
            recordDate &&
            recordDate < from
        ) {

            match = false;

        }


        /*
        | To date
        */

        if (
            match &&
            to &&
            recordDate &&
            recordDate > to
        ) {

            match = false;

        }


        /*
        | Display
        */

        item.style.display =
            match ? '' : 'none';


        if (match) {
            visible++;
        }

    });


    /*
    | Update count
    */

    countEl.textContent =
        `(${visible} record${visible === 1 ? '' : 's'})`;


    /*
    | Show empty message
    */

    emptyEl.style.display =
        visible === 0 ? 'block' : 'none';

}


/*
|--------------------------------------------------------------------------
| FILTER EVENTS
|--------------------------------------------------------------------------
*/

searchInput.addEventListener(
    'input',
    applyFilters
);

fromInput.addEventListener(
    'change',
    applyFilters
);

toInput.addEventListener(
    'change',
    applyFilters
);

</script>


</body>
</html>