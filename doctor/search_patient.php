<?php
/**
 * search_patient.php — Patient Search (Doctor Portal)
 *
 * REAL DATABASE VERSION
 *
 * Data sources:
 * users          -> patient's name, sex, DOB
 * patients       -> PatientID, blood type, allergies, medical information
 * appointments   -> appointment history
 * consultations  -> consultation history
 * staff          -> logged-in doctor's information
 * departments    -> doctor's department
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
| GET LOGGED-IN USER
|--------------------------------------------------------------------------
|
| Your login system should store UserID in the session.
|
*/

$userId = $_SESSION['UserID'] ?? $_SESSION['user_id'] ?? null;

if (!$userId) {
    header("Location: ../login.php");
    exit;
}

$userId = (int)$userId;


/*
|--------------------------------------------------------------------------
| GET LOGGED-IN DOCTOR INFORMATION
|--------------------------------------------------------------------------
*/

$doctorName = 'Doctor';
$doctorDepartment = 'Doctor';
$doctorStaffId = null;

$doctorSql = "
    SELECT
        s.StaffID,
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
    INNER JOIN departments d
        ON s.DepartmentID = d.DepartmentID
    WHERE s.UserID = ?
    LIMIT 1
";

$stmtDoctor = $conn->prepare($doctorSql);

if ($stmtDoctor) {

    $stmtDoctor->bind_param("i", $userId);
    $stmtDoctor->execute();

    $doctorResult = $stmtDoctor->get_result();

    if ($doctorResult && $doctorResult->num_rows > 0) {

        $doctor = $doctorResult->fetch_assoc();

        $doctorStaffId = (int)$doctor['StaffID'];

        $doctorName = 'Dr. ' .
            trim(
                $doctor['FirstName'] . ' ' .
                ($doctor['MiddleName'] ? $doctor['MiddleName'] . ' ' : '') .
                $doctor['LastName']
            );

        $doctorDepartment = $doctor['DepartmentName'];
    }

    $stmtDoctor->close();
}


/*
|--------------------------------------------------------------------------
| GET ALL PATIENTS
|--------------------------------------------------------------------------
|
| No dummy patients.
|
*/

$patients = [];

$patientSql = "
    SELECT
        p.PatientID,
        p.UserID,
        p.BloodType,
        p.Allergies,
        p.CreatedAt,

        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Sex,
        u.DateOfBirth,

        (
            SELECT COUNT(*)
            FROM appointments a
            WHERE a.PatientID = p.PatientID
        ) AS appointment_count,

        (
            SELECT COUNT(*)
            FROM consultations c
            WHERE c.PatientID = p.PatientID
        ) AS consultation_count,

        (
            SELECT COUNT(*)
            FROM consultations c
            WHERE c.PatientID = p.PatientID
              AND c.Treatment IS NOT NULL
              AND TRIM(c.Treatment) <> ''
        ) AS prescription_count,

        (
            SELECT MIN(
                COALESCE(c.ConsultationDate, a.AppointmentDate)
            )
            FROM appointments a
            LEFT JOIN consultations c
                ON c.AppointmentID = a.AppointmentID
            WHERE a.PatientID = p.PatientID
        ) AS first_visit

    FROM patients p

    INNER JOIN users u
        ON p.UserID = u.UserID

    WHERE u.Status = 'Active'

    ORDER BY
        u.LastName ASC,
        u.FirstName ASC
";

$resultPatients = $conn->query($patientSql);

if (!$resultPatients) {
    die("Patient query failed: " . $conn->error);
}


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function calculateAge($dateOfBirth)
{
    if (!$dateOfBirth) {
        return null;
    }

    try {

        $dob = new DateTime($dateOfBirth);
        $today = new DateTime();

        return $today->diff($dob)->y;

    } catch (Exception $e) {

        return null;
    }
}


function formatName($first, $middle, $last)
{
    return trim(
        $first . ' ' .
        ($middle ? $middle . ' ' : '') .
        $last
    );
}


function formatPatientId($patientId)
{
    return 'PT-' . str_pad((string)$patientId, 3, '0', STR_PAD_LEFT);
}


function formatDate($date)
{
    if (!$date) {
        return 'N/A';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return htmlspecialchars($date);
    }

    return date('M j, Y', $timestamp);
}


function formatFirstVisit($date)
{
    if (!$date) {
        return 'N/A';
    }

    return date('M Y', strtotime($date));
}


function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));

    $first = $parts[0][0] ?? '';
    $last  = $parts[count($parts) - 1][0] ?? '';

    return strtoupper($first . $last);
}


/*
|--------------------------------------------------------------------------
| BUILD PATIENT DATA
|--------------------------------------------------------------------------
*/

while ($row = $resultPatients->fetch_assoc()) {

    $patientName = formatName(
        $row['FirstName'],
        $row['MiddleName'],
        $row['LastName']
    );

    $allergies = [];

    if (!empty($row['Allergies'])) {

        /*
         * If allergies are stored like:
         *
         * Penicillin, Seafood
         *
         * they will be displayed separately.
         */

        $allergies = array_values(
            array_filter(
                array_map(
                    'trim',
                    preg_split('/[,;\n]+/', $row['Allergies'])
                )
            )
        );
    }


    $patients[] = [
        'id' => formatPatientId($row['PatientID']),
        'patient_id' => (int)$row['PatientID'],
        'name' => $patientName,
        'age' => calculateAge($row['DateOfBirth']),
        'sex' => $row['Sex'] ?? 'N/A',
        'blood' => $row['BloodType'] ?: 'Not specified',

        'allergies' => $allergies,

        'appointments' => (int)$row['appointment_count'],
        'consultations' => (int)$row['consultation_count'],
        'prescriptions' => (int)$row['prescription_count'],

        'first_visit' => formatFirstVisit($row['first_visit']),

        'history' => []
    ];
}


/*
|--------------------------------------------------------------------------
| GET CONSULTATION HISTORY
|--------------------------------------------------------------------------
|
| Get all consultation records for all loaded patients.
|
*/

$historySql = "
    SELECT
        c.ConsultationID,
        c.PatientID,
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

        u.FirstName,
        u.MiddleName,
        u.LastName

    FROM consultations c

    INNER JOIN staff s
        ON c.StaffID = s.StaffID

    INNER JOIN users u
        ON s.UserID = u.UserID

    ORDER BY
        c.ConsultationDate DESC,
        c.ConsultationTime DESC
";

$resultHistory = $conn->query($historySql);

if (!$resultHistory) {
    die("Consultation history query failed: " . $conn->error);
}


/*
|--------------------------------------------------------------------------
| MAP CONSULTATIONS TO PATIENTS
|--------------------------------------------------------------------------
*/

$patientIndex = [];

foreach ($patients as $index => $patient) {
    $patientIndex[$patient['patient_id']] = $index;
}


while ($history = $resultHistory->fetch_assoc()) {

    $patientId = (int)$history['PatientID'];

    /*
     * Only add history if this patient exists
     * in our patient list.
     */

    if (!isset($patientIndex[$patientId])) {
        continue;
    }

    $index = $patientIndex[$patientId];


    $consultingDoctor = 'Doctor';

    $consultingDoctor = 'Dr. ' .
        trim(
            $history['FirstName'] . ' ' .
            ($history['MiddleName']
                ? $history['MiddleName'] . ' '
                : '') .
            $history['LastName']
        );


    /*
     * Build consultation note.
     */

    $noteParts = [];


    if (!empty($history['ChiefComplaint'])) {
        $noteParts[] =
            'Chief Complaint: ' .
            $history['ChiefComplaint'];
    }


    if (!empty($history['Diagnosis'])) {
        $noteParts[] =
            'Diagnosis: ' .
            $history['Diagnosis'];
    }


    if (!empty($history['Treatment'])) {
        $noteParts[] =
            'Treatment: ' .
            $history['Treatment'];
    }


    if (!empty($history['Notes'])) {
        $noteParts[] =
            'Notes: ' .
            $history['Notes'];
    }


    if (empty($noteParts)) {
        $noteParts[] = 'No consultation notes recorded.';
    }


    /*
     * Tags
     */

    $tags = [];


    if (!empty($history['Treatment'])) {
        $tags[] = 'Treatment';
    }


    if (!empty($history['LabRequest'])) {
        $tags[] = 'Lab Request';
    }


    if (!empty($history['FollowUpDate'])) {
        $tags[] = 'Follow-up';
    }


    if (empty($tags)) {
        $tags[] = 'Consultation';
    }


    /*
     * Vitals
     */

    $vitals = [
        'bp' => $history['BloodPressure'] ?: 'N/A',

        'temp' =>
            $history['Temperature'] !== null
                ? $history['Temperature'] . ' °C'
                : 'N/A',

        'pulse' =>
            $history['PulseRate'] !== null
                ? $history['PulseRate'] . ' bpm'
                : 'N/A'
    ];


    $patients[$index]['history'][] = [

        'id' => (int)$history['ConsultationID'],

        'date' => formatDate($history['ConsultationDate']),

        'doctor' => $consultingDoctor,

        'note' => implode(' ', $noteParts),

        'diagnosis' => $history['Diagnosis'] ?: 'Not recorded',

        'treatment' => $history['Treatment'] ?: 'Not recorded',

        'lab' => $history['LabRequest'] ?: 'None requested',

        'notes' => $history['Notes'] ?: '',

        'status' => $history['Status'],

        'follow_up' => $history['FollowUpDate']
            ? formatDate($history['FollowUpDate'])
            : null,

        'tags' => $tags,

        'vitals' => $vitals
    ];
}


/*
|--------------------------------------------------------------------------
| DEFAULT SELECTED PATIENT
|--------------------------------------------------------------------------
*/

$selectedPatientId = null;

if (!empty($patients)) {

    /*
     * If ?patient_id=5 is provided, select that patient.
     */

    if (isset($_GET['patient_id'])) {

        $requestedPatientId = (int)$_GET['patient_id'];

        foreach ($patients as $patient) {

            if ($patient['patient_id'] === $requestedPatientId) {

                $selectedPatientId = $patient['id'];

                break;
            }
        }
    }


    /*
     * Otherwise select first patient.
     */

    if ($selectedPatientId === null) {
        $selectedPatientId = $patients[0]['id'];
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

<title>Patient Search — Doctor Portal</title>

<link
    rel="stylesheet"
    href="../assets/css/doctor/doctor_dashboard.css"
>

</head>

<body>

<div class="app">


<!-- ==========================================================
     SIDEBAR
========================================================== -->

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
                    <path d="M8 6h13M8 12h13M8 18h13"/>
                    <path d="M3 6h.01M3 12h.01M3 18h.01"/>
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


        <li class="nav-item active">

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


<!-- ==========================================================
     MAIN
========================================================== -->

<main class="main">


    <div class="doctor-topbar">

        <div class="page-header">

            <h1>
                Patient Search
            </h1>

            <p>
                Find patients and view their full medical history
            </p>

        </div>

    </div>


    <!-- SEARCH -->

    <div class="search-bar-wrap">

        <label for="ps-search">
            Search Patient
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
                id="ps-search"
                placeholder="Search by patient name or ID..."
            >

        </div>

    </div>


    <div class="search-grid">


        <!-- ==================================================
             PATIENT RESULTS
        ================================================== -->

        <div class="results-panel">

            <div class="results-panel-head">

                Results

                <span
                    class="count"
                    id="results-count"
                >
                    (<?= count($patients) ?>)
                </span>

            </div>


            <div
                class="result-list"
                id="result-list"
            >

                <?php foreach ($patients as $i => $p): ?>

                    <div
                        class="result-item<?= $p['id'] === $selectedPatientId ? ' selected' : '' ?>"
                        data-id="<?= htmlspecialchars($p['id']) ?>"
                        data-patient-id="<?= (int)$p['patient_id'] ?>"
                        data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                        onclick="selectPatient('<?= htmlspecialchars($p['id'], ENT_QUOTES) ?>', this)"
                    >

                        <div class="result-avatar">

                            <?= htmlspecialchars(initials($p['name'])) ?>

                        </div>


                        <div class="result-info">

                            <div class="result-name">

                                <?= htmlspecialchars($p['name']) ?>

                            </div>

                            <div class="result-meta">

                                Age
                                <?= $p['age'] !== null ? (int)$p['age'] : 'N/A' ?>

                                &nbsp;|&nbsp;

                                <?= htmlspecialchars($p['id']) ?>

                            </div>

                        </div>


                        <div class="result-arrow">

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            >
                                <path d="M9 18l6-6-6-6"/>
                            </svg>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>


            <div
                class="results-empty"
                id="results-empty"
                style="<?= empty($patients) ? '' : 'display:none;' ?>"
            >
                No patients match your search.
            </div>

        </div>


        <!-- ==================================================
             PATIENT DETAIL
        ================================================== -->

        <div
            class="patient-detail-panel"
            id="patient-detail-panel"
        >

        </div>

    </div>

</main>

</div>


<!-- ==========================================================
     PATIENT DATA
========================================================== -->

<script id="patient-data" type="application/json">

<?= json_encode(
    array_values($patients),
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE |
    JSON_HEX_TAG |
    JSON_HEX_AMP |
    JSON_HEX_APOS |
    JSON_HEX_QUOT
) ?>

</script>


<script>

const patients =
    JSON.parse(
        document.getElementById('patient-data').textContent
    );

const detailPanel =
    document.getElementById('patient-detail-panel');


/*
|--------------------------------------------------------------------------
| Escape HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    if (value === null || value === undefined) {
        return '';
    }

    const div = document.createElement('div');

    div.textContent = String(value);

    return div.innerHTML;
}


/*
|--------------------------------------------------------------------------
| Initials
|--------------------------------------------------------------------------
*/

function getInitials(name) {

    const parts =
        name.trim().split(/\s+/);

    const first =
        parts[0]?.[0] || '';

    const last =
        parts[parts.length - 1]?.[0] || '';

    return (
        first + last
    ).toUpperCase();
}


/*
|--------------------------------------------------------------------------
| Render Patient
|--------------------------------------------------------------------------
*/

function renderPatient(patient) {

    if (!patient) {

        detailPanel.innerHTML = `
            <div class="patient-detail-empty">
                Select a patient to view their profile.
            </div>
        `;

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Allergies
    |--------------------------------------------------------------------------
    */

    let allergyHtml = '';


    if (
        patient.allergies &&
        patient.allergies.length > 0
    ) {

        allergyHtml = `
            <div class="allergy-flag">

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <path d="M12 9v4M12 17h.01"/>
                </svg>

                Allergies:
                ${escapeHtml(patient.allergies.join(', '))}

            </div>
        `;

    }


    /*
    |--------------------------------------------------------------------------
    | Consultation History
    |--------------------------------------------------------------------------
    */

    let historyHtml = '';


    if (
        patient.history &&
        patient.history.length > 0
    ) {

        historyHtml =
            patient.history.map(
                h => `

                <div class="pd-history-item">

                    <div class="pd-history-head">

                        <span class="pd-history-date">

                            ${escapeHtml(h.date)}

                        </span>

                        <span>|</span>

                        <span class="pd-history-doc">

                            ${escapeHtml(h.doctor)}

                        </span>

                    </div>


                    <div class="pd-history-note">

                        ${escapeHtml(h.note)}

                    </div>


                    ${
                        h.tags && h.tags.length
                        ?
                        `
                        <div class="pd-tags">

                            ${h.tags.map(
                                (tag, index) => `

                                <span
                                    class="pd-tag ${
                                        index % 2 === 0
                                        ? 'blue'
                                        : 'amber'
                                    }"
                                >

                                    ${escapeHtml(tag)}

                                </span>

                            `).join('')}

                        </div>
                        `
                        :
                        ''
                    }


                    ${
                        h.follow_up
                        ?
                        `
                        <div class="pd-history-followup">

                            Follow-up:
                            ${escapeHtml(h.follow_up)}

                        </div>
                        `
                        :
                        ''
                    }

                </div>

            `
            ).join('');

    } else {

        historyHtml = `

            <div class="records-empty">

                No consultation history on file.

            </div>

        `;

    }


    /*
    |--------------------------------------------------------------------------
    | Render Detail
    |--------------------------------------------------------------------------
    */

    detailPanel.innerHTML = `

        <div class="pd-header">

            <div class="pd-avatar">

                ${getInitials(patient.name)}

            </div>


            <div>

                <div class="pd-name">

                    ${escapeHtml(patient.name)}

                </div>


                <div class="pd-meta">

                    <span>

                        Age
                        ${
                            patient.age !== null
                            ? escapeHtml(patient.age)
                            : 'N/A'
                        }

                        |

                        ${escapeHtml(patient.id)}

                    </span>


                    <span class="blood-pill">

                        Blood:
                        ${escapeHtml(patient.blood)}

                    </span>

                </div>


                ${allergyHtml}

            </div>

        </div>


        <div class="pd-stats">


            <div class="pd-stat">

                <div class="pd-stat-value">

                    ${patient.appointments}

                </div>

                <div class="pd-stat-label">

                    Total Appointments

                </div>

            </div>


            <div class="pd-stat">

                <div class="pd-stat-value">

                    ${patient.consultations}

                </div>

                <div class="pd-stat-label">

                    Consultations

                </div>

            </div>


            <div class="pd-stat">

                <div class="pd-stat-value">

                    ${patient.prescriptions}

                </div>

                <div class="pd-stat-label">

                    Treatments

                </div>

            </div>


            <div class="pd-stat">

                <div class="pd-stat-value">

                    ${escapeHtml(patient.first_visit)}

                </div>

                <div class="pd-stat-label">

                    First Visit

                </div>

            </div>


        </div>


        <div class="pd-history-title">

            Consultation History

        </div>


        ${historyHtml}

    `;

}


/*
|--------------------------------------------------------------------------
| Select Patient
|--------------------------------------------------------------------------
*/

function selectPatient(id, element) {

    document
        .querySelectorAll('.result-item')
        .forEach(
            item =>
                item.classList.remove('selected')
        );


    element.classList.add('selected');


    const patient =
        patients.find(
            p => p.id === id
        );


    renderPatient(patient);

}


/*
|--------------------------------------------------------------------------
| Initial Patient
|--------------------------------------------------------------------------
*/

const initiallySelected =
    document.querySelector(
        '.result-item.selected'
    );


if (initiallySelected) {

    renderPatient(
        patients.find(
            p =>
                p.id ===
                initiallySelected.dataset.id
        )
    );

} else if (patients.length > 0) {

    renderPatient(patients[0]);

}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

const searchInput =
    document.getElementById('ps-search');

const resultItems =
    Array.from(
        document.querySelectorAll('.result-item')
    );

const resultsCount =
    document.getElementById('results-count');

const resultsEmpty =
    document.getElementById('results-empty');


searchInput.addEventListener(
    'input',
    () => {

        const q =
            searchInput.value
                .trim()
                .toLowerCase();


        let visible = 0;


        resultItems.forEach(
            item => {

                const name =
                    item.dataset.name;

                const patientId =
                    item.dataset.id
                        .toLowerCase();


                const match =
                    !q ||
                    name.includes(q) ||
                    patientId.includes(q);


                item.style.display =
                    match ? '' : 'none';


                if (match) {
                    visible++;
                }

            }
        );


        resultsCount.textContent =
            `(${visible})`;


        resultsEmpty.style.display =
            visible === 0
            ? 'block'
            : 'none';

    }
);

</script>

</body>
</html>