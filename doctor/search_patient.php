<?php
/**
 * search_patient.php — Patient Search / Launchpad (Doctor Portal)
 *
 * Lightweight search-and-launch interface:
 *   - Search by name, ID, or phone
 *   - Quick Lookup card: allergies, last visit, current medications
 *   - [Start Consultation] → doctor_queue.php
 *   - [View Full Record]   → records.php?patient_id=
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
*/

$userId = $_SESSION['UserID'] ?? $_SESSION['user_id'] ?? null;

if (!$userId) {
    header("Location: ../auth/login.php");
    exit;
}

$userId = (int) $userId;


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

        $doctorStaffId = (int) $doctor['StaffID'];

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
| GET ALL PATIENTS (streamlined)
|--------------------------------------------------------------------------
|
| Only the fields needed for the launchpad:
|   name, age, sex, phone, patient id, blood type,
|   allergies, current medications, last visit date
|
*/

$patients = [];

$patientSql = "
    SELECT
        p.PatientID,
        p.UserID,
        p.BloodType,
        p.Allergies,
        p.CurrentMedication,
        p.PastMedicalCondition,

        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Sex,
        u.DateOfBirth,
        u.ContactNumber,

        (
            SELECT COUNT(*)
            FROM consultations c
            WHERE c.PatientID = p.PatientID
        ) AS consultation_count,

        (
            SELECT MAX(c.ConsultationDate)
            FROM consultations c
            WHERE c.PatientID = p.PatientID
        ) AS last_visit_date,

        (
            SELECT COUNT(*)
            FROM consultations c
            WHERE c.PatientID = p.PatientID
              AND c.Status = 'Ongoing'
              AND c.LabRequest IS NOT NULL
              AND TRIM(c.LabRequest) <> ''
        ) AS lab_pending_count

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
    return 'PT-' . str_pad((string) $patientId, 3, '0', STR_PAD_LEFT);
}


function formatCompactDate($date)
{
    if (!$date) {
        return null;
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return null;
    }

    return date('M j, Y', $timestamp);
}


function patientFlags(array $patient, bool $labPending = false): array
{
    $flags = [];

    $allergies = '';

    if (!empty($patient['allergies'])) {
        $allergies = is_array($patient['allergies'])
            ? implode(',', $patient['allergies'])
            : trim((string) $patient['allergies']);
    }

    if (trim($allergies) !== '') {
        $flags['allergy'] = 'Allergy';
    }

    $conditions = strtolower(trim((string) ($patient['past_medical_condition'] ?? '')));

    $highRiskTerms = [
        'diabetes',
        'hypertension',
        'high blood pressure',
        'heart disease',
        'cardiovascular',
        'asthma',
        'copd',
        'cancer',
        'malignancy',
        'kidney disease',
        'renal failure',
        'stroke',
        'seizure',
        'epilepsy',
        'hiv',
        'hepatitis',
    ];

    foreach ($highRiskTerms as $term) {
        if ($conditions !== '' && strpos($conditions, $term) !== false) {
            $flags['high_risk'] = 'High Risk';
            break;
        }
    }

    if ($labPending) {
        $flags['lab_pending'] = 'Lab Pending';
    }

    return $flags;
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
        $allergies = array_values(
            array_filter(
                array_map('trim', preg_split('/[,;\n]+/', $row['Allergies']))
            )
        );
    }

    $currentMeds = trim($row['CurrentMedication'] ?? '');

    $patients[] = [
        'id'            => formatPatientId($row['PatientID']),
        'patient_id'    => (int) $row['PatientID'],
        'name'          => $patientName,
        'age'           => calculateAge($row['DateOfBirth']),
        'sex'           => $row['Sex'] ?? '',
        'blood'         => $row['BloodType'] ?: '',
        'phone'         => $row['ContactNumber'] ?? '',
        'allergies'     => $allergies,
        'medications'   => $currentMeds,
        'last_visit'    => formatCompactDate($row['last_visit_date']),
        'consultations' => (int) $row['consultation_count'],
        'flags'         => patientFlags(
            [
                'allergies'              => $allergies,
                'past_medical_condition' => $row['PastMedicalCondition'] ?? '',
            ],
            ((int)($row['lab_pending_count'] ?? 0)) > 0
        ),
    ];
}


/*
|--------------------------------------------------------------------------
| DEFAULT SELECTED PATIENT (via ?patient_id=N)
|--------------------------------------------------------------------------
*/

$selectedPatientId = null;

if (!empty($patients) && isset($_GET['patient_id'])) {

    $requestedPatientId = (int) $_GET['patient_id'];

    foreach ($patients as $patient) {
        if ($patient['patient_id'] === $requestedPatientId) {
            $selectedPatientId = $patient['id'];
            break;
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

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                    <path d="M9 22V12h6v10"/>
                </svg>

                Dashboard

            </a>

        </li>

        <li class="nav-item">

            <a href="doctor_queue.php">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 6h13M8 12h13M8 18h13"/>
                    <path d="M3 6h.01M3 12h.01M3 18h.01"/>
                </svg>

                Queue

            </a>

        </li>

        <li class="nav-item">

            <a href="records.php">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>

                Search Patient

            </a>

        </li>

        <li class="nav-item">

            <a href="doctor_profile.php">

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
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
            href="../auth/logout.php"
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
                Find a patient and launch their consultation
            </p>

        </div>

    </div>


    <!-- SEARCH BAR -->

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
                placeholder="Search by name, patient ID, or phone number..."
                autofocus
            >

        </div>

    </div>


    <!-- RESULTS LIST -->

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

            <?php if (empty($patients)): ?>

                <div class="results-empty">
                    No patients found in the database.
                </div>

            <?php else: ?>

                <?php foreach ($patients as $i => $p): ?>

                    <div
                        class="result-item<?= $selectedPatientId === $p['id'] ? ' selected' : '' ?>"
                        data-id="<?= htmlspecialchars($p['id']) ?>"
                        data-patient-id="<?= (int) $p['patient_id'] ?>"
                        data-name="<?= htmlspecialchars(strtolower($p['name'])) ?>"
                        data-phone="<?= htmlspecialchars(strtolower($p['phone'])) ?>"
                        onclick="selectPatient(this)"
                    >

                        <div class="result-avatar">
                            <?= htmlspecialchars(initials($p['name'])) ?>
                        </div>

                        <div class="result-info">

                            <div class="result-name">
                                <?= htmlspecialchars($p['name']) ?>
                            </div>

                            <div class="result-meta">
                                <?= htmlspecialchars($p['id']) ?>

                                <?php if ($p['last_visit']): ?>
                                    &nbsp;&middot;&nbsp;
                                    Last Visit:
                                    <?= htmlspecialchars($p['last_visit']) ?>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($p['flags'])): ?>

                            <div class="patient-flags">

                                <?php foreach ($p['flags'] as $fkey => $flabel): ?>

                                <span class="pflag pflag--<?= htmlspecialchars($fkey) ?>">

                                    <span class="pflag-icon">

                                        <?php

                                        $flagIcons = [
                                            'allergy'    => '\u26a0',
                                            'high_risk'  => '\u26a0',
                                            'lab_pending'=> '\u25cf',
                                        ];

                                        echo $flagIcons[$fkey] ?? '\u25cf';

                                        ?>

                                    </span>

                                    <?= htmlspecialchars($flabel) ?>

                                </span>

                                <?php endforeach; ?>

                            </div>

                            <?php endif; ?>

                        </div>

                        <div class="result-arrow">

                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 18l6-6-6-6"/>
                            </svg>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>


        <div
            class="results-empty"
            id="results-empty"
            style="display:none;"
        >
            No patients match your search.
        </div>

    </div>


    <!-- QUICK LOOKUP PANEL (JS-rendered) -->

    <div class="patient-launchpad" id="patient-launchpad">

        <div class="launchpad-empty" id="launchpad-empty">
            Select a patient to view their quick lookup.
        </div>

        <div id="launchpad-content" style="display:none;"></div>

    </div>


</main>

</div>


<!-- ==========================================================
     PATIENT DATA (JSON)
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


<!-- ==========================================================
     JAVASCRIPT
========================================================== -->

<script>

const patients =
    JSON.parse(
        document.getElementById('patient-data').textContent
    );

const launchpadEmpty =
    document.getElementById('launchpad-empty');

const launchpadContent =
    document.getElementById('launchpad-content');

let activePatientId =
    null;


/*
|--------------------------------------------------------------------------
| Escape HTML
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    if (value === null || value === undefined) {
        return '';
    }

    const div =
        document.createElement('div');

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
| Render Quick Lookup
|--------------------------------------------------------------------------
*/

function renderQuickLookup(patient) {

    if (!patient) {

        launchpadEmpty.style.display = '';
        launchpadContent.style.display = 'none';

        return;
    }


    launchpadEmpty.style.display = 'none';
    launchpadContent.style.display = '';


    /* ---- Allergy flags ---- */

    let allergyHtml = '';

    if (patient.allergies && patient.allergies.length > 0) {

        allergyHtml = patient.allergies.map(function(a) {
            return '<span class="ql-allergy">' +
                escapeHtml(a) +
            '</span>';
        }).join('');

    } else {

        allergyHtml = '<span class="ql-none">None recorded</span>';
    }


    /* ---- Patient flags (allergy / high risk / lab pending) ---- */

    let flagHtml = '';

    if (patient.flags && Object.keys(patient.flags).length > 0) {

        let flagIcons = {
            allergy: '\u26a0',
            high_risk: '\u26a0',
            lab_pending: '\u25cf'
        };

        flagHtml = Object.keys(patient.flags).map(function(key) {
            let label = patient.flags[key];
            let icon = flagIcons[key] || '\u25cf';
            return '<span class="pflag pflag--' + key + '">' +
                '<span class="pflag-icon">' + icon + '</span>' +
                escapeHtml(label) +
            '</span>';
        }).join('');

    }


    /* ---- Medications ---- */

    let medsHtml = '';

    if (patient.medications) {

        medsHtml = '<span>' +
            escapeHtml(patient.medications) +
        '</span>';

    } else {

        medsHtml = '<span class="ql-none">None recorded</span>';
    }


    /* ---- Assemble ---- */

    launchpadContent.innerHTML = `

        <div class="ql-card">

            <div class="ql-header">

                <div class="ql-avatar">
                    ${getInitials(patient.name)}
                </div>

                <div class="ql-identity">

                    <div class="ql-name">
                        ${escapeHtml(patient.name)}
                    </div>

                    <div class="ql-meta">
                        ${escapeHtml(patient.id)}
                        ${patient.sex ? ' &middot; ' + escapeHtml(patient.sex) : ''}
                        ${patient.age !== null ? ' &middot; Age ' + patient.age : ''}
                        ${patient.blood ? ' &middot; Blood ' + escapeHtml(patient.blood) : ''}
                        ${patient.consultations ? ' &middot; ' + patient.consultations + ' visit' + (patient.consultations === 1 ? '' : 's') : ''}
                    </div>

                </div>

            </div>


            ${flagHtml
                ? '<div class="ql-flags patient-flags">' + flagHtml + '</div>'
                : ''
            }


            <div class="ql-facts">

                <div class="ql-fact">

                    <div class="ql-fact-label">
                        Last Visit
                    </div>

                    <div class="ql-fact-value">
                        ${patient.last_visit
                            ? escapeHtml(patient.last_visit)
                            : '<span class="ql-none">No visits yet</span>'
                        }
                    </div>

                </div>

                <div class="ql-fact">

                    <div class="ql-fact-label">
                        Allergies
                    </div>

                    <div class="ql-fact-value ql-allergies">
                        ${allergyHtml}
                    </div>

                </div>

                <div class="ql-fact">

                    <div class="ql-fact-label">
                        Current Medications
                    </div>

                    <div class="ql-fact-value">
                        ${medsHtml}
                    </div>

                </div>

            </div>


            <div class="ql-actions">

                <a
                    class="ql-btn ql-btn-primary"
                    href="doctor_queue.php"
                >
                    Start Consultation
                </a>

                <a
                    class="ql-btn ql-btn-secondary"
                    href="records.php?patient_id=${patient.patient_id}"
                >
                    View Full Record
                </a>

            </div>

        </div>

    `;

}


/*
|--------------------------------------------------------------------------
| Select Patient
|--------------------------------------------------------------------------
*/

function selectPatient(element) {

    document
        .querySelectorAll('.result-item')
        .forEach(function(item) {
            item.classList.remove('selected');
        });

    element.classList.add('selected');

    activePatientId =
        element.dataset.patientId;

    const patient =
        patients.find(
            function(p) {
                return p.patient_id ==
                    parseInt(activePatientId, 10);
            }
        );

    renderQuickLookup(patient);
}


/*
|--------------------------------------------------------------------------
| Initial Selection
|--------------------------------------------------------------------------
*/

(function() {

    if (activePatientId) {
        return;
    }

    const initialItem =
        document.querySelector('.result-item.selected');

    if (initialItem) {
        selectPatient(initialItem);
    }

})();


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
    function() {

        const q =
            searchInput.value
                .trim()
                .toLowerCase();

        let visible = 0;


        resultItems.forEach(
            function(item) {

                const name =
                    item.dataset.name || '';

                const id =
                    item.dataset.id
                        .toLowerCase();

                const phone =
                    item.dataset.phone || '';


                const match =
                    !q ||
                    name.includes(q) ||
                    id.includes(q) ||
                    phone.includes(q);


                item.style.display =
                    match ? '' : 'none';


                if (match) {
                    visible++;
                }

            }
        );


        resultsCount.textContent =
            '(' + visible + ')';


        resultsEmpty.style.display =
            visible === 0 ? 'block' : 'none';

    }
);

</script>

</body>
</html>
