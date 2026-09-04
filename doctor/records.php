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
require_once __DIR__ . '/../includes/pdf_helper.php';


/*
|--------------------------------------------------------------------------
| CSRF TOKEN (generate once per session)
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    return isset($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}


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
| UPDATE CONSULTATION (EDIT)
|--------------------------------------------------------------------------
|
| Handles the "Edit" action from the medical records timeline.
|
| Because medical records are sensitive, this flow is protected by CSRF,
| ownership verification, and a full audit-trail entry written to the
| audit_trail table capturing each field that changed.
|
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'update_consultation'
) {

    if (!verifyCsrf()) {
        header('Location: records.php?msg=csrf');
        exit;
    }

    $editCid = (int) ($_POST['consultation_id'] ?? 0);

    if ($editCid <= 0) {
        header('Location: records.php?msg=invalid');
        exit;
    }

    /*
    | Verify this consultation belongs to the logged-in doctor.
    */
    $ownerStmt = $conn->prepare("
        SELECT
            c.ConsultationID,
            c.ChiefComplaint,
            c.Diagnosis,
            c.Treatment,
            c.LabRequest,
            c.Notes,
            c.FollowUpDate,
            c.Status,
            c.BloodPressure,
            c.Temperature,
            c.PulseRate
        FROM consultations c
        WHERE c.ConsultationID = ?
          AND c.StaffID = ?
        LIMIT 1
    ");

    $ownerStmt->bind_param("ii", $editCid, $staffID);
    $ownerStmt->execute();
    $ownerResult = $ownerStmt->get_result();
    $existing = $ownerResult->fetch_assoc();
    $ownerStmt->close();

    if (!$existing) {
        header('Location: records.php?msg=denied');
        exit;
    }

    /*
    | Collect editable fields.
    */
    $allowedStatuses = ['Ongoing', 'Completed', 'In Progress', 'Cancelled'];

    $newValues = [
        'ChiefComplaint' => trim($_POST['edit_chief_complaint'] ?? ''),
        'Diagnosis'      => trim($_POST['edit_diagnosis'] ?? ''),
        'Treatment'      => trim($_POST['edit_treatment'] ?? ''),
        'LabRequest'     => trim($_POST['edit_lab_request'] ?? ''),
        'Notes'          => trim($_POST['edit_notes'] ?? ''),
        'FollowUpDate'   => trim($_POST['edit_follow_up'] ?? ''),
        'Status'         => trim($_POST['edit_status'] ?? ''),
        'BloodPressure'  => trim($_POST['edit_blood_pressure'] ?? ''),
        'Temperature'    => trim($_POST['edit_temperature'] ?? ''),
        'PulseRate'      => trim($_POST['edit_pulse_rate'] ?? ''),
    ];

    if (!in_array($newValues['Status'], $allowedStatuses, true)) {
        $newValues['Status'] = $existing['Status'];
    }

    if ($newValues['FollowUpDate'] !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newValues['FollowUpDate'])) {
            $newValues['FollowUpDate'] = $existing['FollowUpDate'];
        }
    }

    $newTemp = $newValues['Temperature'];
    if ($newTemp === '') {
        $newValues['Temperature'] = null;
    } elseif ($newTemp !== (string) $existing['Temperature']) {
        $newValues['Temperature'] = (float) $newTemp;
    }

    $newPulse = $newValues['PulseRate'];
    if ($newPulse === '') {
        $newValues['PulseRate'] = null;
    } elseif ($newPulse !== (string) $existing['PulseRate']) {
        $newValues['PulseRate'] = (int) $newPulse;
    }

    if ($newValues['Temperature'] !== null
        && $newValues['Temperature'] === (string) $existing['Temperature']) {
        $newValues['Temperature'] = $existing['Temperature'];
    }

    if ($newValues['PulseRate'] !== null
        && $newValues['PulseRate'] === (string) $existing['PulseRate']) {
        $newValues['PulseRate'] = $existing['PulseRate'];
    }

    /*
    | Compare old vs new, building the list of changed fields.
    */
    $fieldLabels = [
        'ChiefComplaint' => 'Chief Complaint',
        'Diagnosis'      => 'Diagnosis',
        'Treatment'      => 'Treatment / Plan',
        'LabRequest'     => 'Lab Request',
        'Notes'          => 'Notes',
        'FollowUpDate'   => 'Follow-up Date',
        'Status'         => 'Status',
        'BloodPressure'  => 'Blood Pressure',
        'Temperature'    => 'Temperature',
        'PulseRate'      => 'Pulse Rate',
    ];

    $normalize = function ($val) {
        if ($val === null || $val === '') {
            return '';
        }
        return (string) $val;
    };

    $changes = [];

    foreach ($newValues as $field => $newVal) {
        $oldVal = $existing[$field] ?? null;

        if ($normalize($oldVal) !== $normalize($newVal)) {
            $changes[] = [
                'field' => $field,
                'label' => $fieldLabels[$field] ?? $field,
                'old'   => $oldVal,
                'new'   => $newVal,
            ];
        }
    }

    /*
    | If nothing changed, just redirect back (no audit row).
    */
    if (empty($changes)) {
        header('Location: records.php?msg=nochange');
        exit;
    }

    /*
    | Persist the update.
    */
    $updateStmt = $conn->prepare("
        UPDATE consultations
        SET ChiefComplaint = ?,
            Diagnosis = ?,
            Treatment = ?,
            LabRequest = ?,
            Notes = ?,
            FollowUpDate = ?,
            Status = ?,
            BloodPressure = ?,
            Temperature = ?,
            PulseRate = ?
        WHERE ConsultationID = ?
          AND StaffID = ?
    ");

    $uChief   = $newValues['ChiefComplaint'];
    $uDiag    = $newValues['Diagnosis'];
    $uTreat   = $newValues['Treatment'];
    $uLab     = $newValues['LabRequest'];
    $uNotes   = $newValues['Notes'];
    $uFollow  = $newValues['FollowUpDate'];
    $uStatus  = $newValues['Status'];
    $uBP      = $newValues['BloodPressure'];
    $uTemp    = $newValues['Temperature'];
    $uPulse   = $newValues['PulseRate'];

    $updateStmt->bind_param(
        "sssssssssiii",
        $uChief,
        $uDiag,
        $uTreat,
        $uLab,
        $uNotes,
        $uFollow,
        $uStatus,
        $uBP,
        $uTemp,
        $uPulse,
        $editCid,
        $staffID
    );

    $updateStmt->execute();
    $updateStmt->close();

    /*
    | Write the audit-trail entry.
    |
    | OldValue stores the full before-snapshot (JSON).
    | NewValue stores the list of changed fields (JSON), each with
    | label / old / new so the change history can be rendered directly.
    */
    $oldSnapshot = [];
    foreach ($fieldLabels as $field => $_label) {
        $oldSnapshot[$field] = $existing[$field] ?? null;
    }

    $auditAction = count($changes) . ' field(s) updated';

    $oldJson  = json_encode($oldSnapshot, JSON_PRETTY_PRINT);
    $newJson  = json_encode($changes, JSON_PRETTY_PRINT);

    $ipAddress  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR']
        ?? '';

    if (strpos((string) $ipAddress, ',') !== false) {
        $ipAddress = trim(explode(',', (string) $ipAddress)[0]);
    }

    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $auditStmt = $conn->prepare("
        INSERT INTO audit_trail
            (UserID, Action, TableName, RecordID,
             OldValue, NewValue, IPAddress, UserAgent)
        VALUES (?, ?, 'consultations', ?, ?, ?, ?, ?)
    ");

    $uUserID   = $userID;
    $uAction   = $auditAction;
    $uRecID    = $editCid;

    $auditStmt->bind_param(
        "ssissss",
        $uUserID,
        $uAction,
        $uRecID,
        $oldJson,
        $newJson,
        $ipAddress,
        $userAgent
    );

    $auditStmt->execute();
    $auditStmt->close();

    header('Location: records.php?msg=updated&cid=' . $editCid);
    exit;
}


/*
|--------------------------------------------------------------------------
| PRINT CONSULTATION REPORT
|--------------------------------------------------------------------------
|
| Streams a complete printable consultation report as a PDF for a saved
| consultation (GET ?print=consultation_report&cid=N).
|
*/

if (
    isset($_GET['print'])
    && $_GET['print'] === 'consultation_report'
    && isset($_GET['cid'])
) {

    $reportCid = (int) $_GET['cid'];

    if ($reportCid <= 0) {
        header('Location: records.php?error=invalid');
        exit;
    }

    $reportStmt = $conn->prepare("
        SELECT
            c.ConsultationID,
            c.AppointmentID,
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

            p.BloodType,
            p.Allergies,
            p.PastMedicalCondition,

            u.FirstName,
            u.MiddleName,
            u.LastName,
            u.Sex,
            u.DateOfBirth,
            u.Address,

            s.StaffRole,
            s.Specialization,

            d.DepartmentName
        FROM consultations c
        INNER JOIN patients p ON c.PatientID = p.PatientID
        INNER JOIN users u   ON p.UserID = u.UserID
        INNER JOIN staff s   ON c.StaffID = s.StaffID
        LEFT JOIN departments d ON s.DepartmentID = d.DepartmentID
        WHERE c.ConsultationID = ?
          AND c.StaffID = ?
        LIMIT 1
    ");

    $reportStmt->bind_param("ii", $reportCid, $staffID);
    $reportStmt->execute();
    $reportResult = $reportStmt->get_result();
    $report = $reportResult->fetch_assoc();
    $reportStmt->close();

    if (!$report) {
        header('Location: records.php?error=denied');
        exit;
    }

    /*
    | Patient name + age.
    */
    $patName = trim(
        $report['FirstName'] . ' ' .
        ($report['MiddleName'] ? $report['MiddleName'] . ' ' : '') .
        $report['LastName']
    );

    $patAge = '';
    if (!empty($report['DateOfBirth'])) {
        try {
            $patAge = (string)(new DateTime($report['DateOfBirth']))
                ->diff(new DateTime())->y;
        } catch (Exception $e) {
            $patAge = '';
        }
    }

    $dateTxt = !empty($report['ConsultationDate'])
        ? date('F j, Y', strtotime($report['ConsultationDate']))
        : '';

    $timeTxt = !empty($report['ConsultationTime'])
        ? date('g:i A', strtotime($report['ConsultationTime']))
        : '';

    $consultationDatetime = trim($dateTxt . ($timeTxt ? '  |  ' . $timeTxt : ''));

    /*
    | Vitals.
    */
    $vitalsParts = [];
    if (!empty($report['BloodPressure'])) {
        $vitalsParts[] = 'BP: ' . $report['BloodPressure'];
    }
    if ($report['Temperature'] !== null && $report['Temperature'] !== '') {
        $vitalsParts[] = 'Temp: ' . $report['Temperature'] . '°C';
    }
    if ($report['PulseRate'] !== null && $report['PulseRate'] !== '') {
        $vitalsParts[] = 'HR: ' . $report['PulseRate'] . ' bpm';
    }
    $vitals = implode('  |  ', $vitalsParts);

    /*
    | Medications for this consultation (prescription items).
    */
    $rxStmt = $conn->prepare("
        SELECT
            pi.MedicineName,
            pi.Dosage,
            pi.Frequency,
            pi.Duration,
            pi.Instructions
        FROM prescriptions pr
        INNER JOIN prescription_items pi
            ON pi.PrescriptionID = pr.PrescriptionID
        WHERE pr.ConsultationID = ?
        ORDER BY pi.PrescriptionItemID ASC
    ");

    $rxStmt->bind_param("i", $reportCid);
    $rxStmt->execute();
    $rxResult = $rxStmt->get_result();
    $rxItems = [];
    while ($rxRow = $rxResult->fetch_assoc()) {
        $rxItems[] = [
            'MedicineName' => (string) $rxRow['MedicineName'],
            'Dosage'       => (string) ($rxRow['Dosage'] ?? ''),
            'Frequency'    => (string) ($rxRow['Frequency'] ?? ''),
            'Duration'     => (string) ($rxRow['Duration'] ?? ''),
            'Instructions' => (string) ($rxRow['Instructions'] ?? ''),
        ];
    }
    $rxStmt->close();

    /*
    | Lab requests (newline-separated in the column).
    */
    $labRequests = [];
    if (!empty($report['LabRequest'])) {
        $labRequests = array_values(
            array_filter(
                array_map('trim',
                    preg_split('/[\r\n,;]+/', (string) $report['LabRequest']))
            )
        );
    }

    $reportData = [
        'clinic_name'           => 'MediCare Clinic',
        'clinic_info'           => 'MediCare Outpatient Portal | Tel: (02) 1234-5678',
        'doctor_name'           => $doctorDisplayName,
        'doctor_specialization' => (string) ($report['Specialization'] ?? ''),
        'doctor_license'        => '',
        'department'            => (string) ($report['DepartmentName'] ?? ''),
        'status'                => (string) ($report['Status'] ?? ''),
        'consultation_datetime' => $consultationDatetime,
        'patient_name'          => $patName,
        'patient_id'            => 'PT-' . str_pad((string) ($report['PatientID'] ?? ''), 3, '0', STR_PAD_LEFT),
        'patient_age'           => $patAge,
        'patient_sex'           => (string) ($report['Sex'] ?? ''),
        'patient_address'       => (string) ($report['Address'] ?? ''),
        'blood_type'            => (string) ($report['BloodType'] ?? ''),
        'allergies_alerts'      => html_entity_decode(trim((string) ($report['Allergies'] ?? ''))),
        'date_issued'           => date('Y-m-d'),
        'appointment_date'      => date('Y-m-d', strtotime($report['ConsultationDate'])),
        'chief_complaint'       => (string) ($report['ChiefComplaint'] ?? ''),
        'diagnosis'             => (string) ($report['Diagnosis'] ?? ''),
        'treatment'             => (string) ($report['Treatment'] ?? ''),
        'notes'                 => (string) ($report['Notes'] ?? ''),
        'vitals'                => $vitals,
        'follow_up_date'        => !empty($report['FollowUpDate'])
            ? date('F j, Y', strtotime($report['FollowUpDate']))
            : '',
        'follow_up_instructions' => '',
        'rx_items'              => $rxItems,
        'lab_requests'          => $labRequests,
    ];

    $pdfBinary = pdf_build($reportData, 'consultation_report');

    if (ob_get_level()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="consultation_report_' . $reportCid . '.pdf"');
    header('Content-Length: ' . strlen($pdfBinary));
    echo $pdfBinary;
    exit;
}


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
        p.CurrentMedication,
        p.PastMedicalCondition,

        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Sex,
        u.DateOfBirth,
        u.Address,

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

        'address' => $row['Address'] ?? '',

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

        'current_medication' => $row['CurrentMedication'] ?? '',

        'past_medical_condition' => $row['PastMedicalCondition'] ?? '',

        'raw_status' => $row['Status'] ?? '',

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
| GET PRESCRIPTION ITEMS PER CONSULTATION
|--------------------------------------------------------------------------
|
| prescriptions   -> ConsultationID
|   ↓
| prescription_items
|   ↓
| MedicineName
|
| Builds a map: ConsultationID -> [ 'Amoxicillin 500 mg', ... ]
|
*/

$medsByConsultation = [];

$medsStmt = $conn->prepare("
    SELECT
        pr.ConsultationID,
        pi.MedicineName,
        pi.Dosage
    FROM prescriptions pr
    INNER JOIN prescription_items pi
        ON pi.PrescriptionID = pr.PrescriptionID
    INNER JOIN consultations c
        ON c.ConsultationID = pr.ConsultationID
        AND c.StaffID = ?
    ORDER BY
        c.ConsultationDate DESC,
        c.ConsultationTime DESC,
        c.ConsultationID DESC,
        pi.PrescriptionItemID ASC
");

if (!$medsStmt) {
    die('Medication query failed: ' . $conn->error);
}

$medsStmt->bind_param("i", $staffID);
$medsStmt->execute();

$medsResult = $medsStmt->get_result();

while ($medRow = $medsResult->fetch_assoc()) {
    $cid = (int) $medRow['ConsultationID'];
    $medName = trim($medRow['MedicineName'] ?? '');
    $dosage = trim($medRow['Dosage'] ?? '');

    if ($medName === '') {
        continue;
    }

    if ($dosage !== '') {
        $medName .= ' (' . $dosage . ')';
    }

    $medsByConsultation[$cid][] = $medName;
}

$medsStmt->close();


/*
|--------------------------------------------------------------------------
| GET AUDIT TRAIL PER CONSULTATION
|--------------------------------------------------------------------------
|
| Builds a map: ConsultationID -> [ audit events ].
| Each event contains the Action, timestamp, IP, and a decoded list of
| changed fields (label / old / new).
|
*/

$auditByConsultation = [];

if (!empty($records)) {

    $auditSql = "
        SELECT
            RecordID,
            Action,
            ActionTimestamp,
            UserID,
            IPAddress,
            NewValue
        FROM audit_trail
        WHERE TableName = 'consultations'
          AND RecordID IS NOT NULL
        ORDER BY ActionTimestamp ASC
    ";

    $auditResult = $conn->query($auditSql);

    if ($auditResult) {

        while ($auditRow = $auditResult->fetch_assoc()) {

            $cidKey = (int) $auditRow['RecordID'];

            $changes = [];

            $decoded = json_decode((string) $auditRow['NewValue'], true);

            if (is_array($decoded)) {
                foreach ($decoded as $change) {
                    if (isset($change['label'])) {
                        $changes[] = [
                            'label' => (string) $change['label'],
                            'old'   => $change['old'] ?? null,
                            'new'   => $change['new'] ?? null,
                        ];
                    }
                }
            }

            $auditByConsultation[$cidKey][] = [
                'action'    => (string) $auditRow['Action'],
                'timestamp' => (string) $auditRow['ActionTimestamp'],
                'user_id'   => (int) $auditRow['UserID'],
                'ip'        => (string) $auditRow['IPAddress'],
                'fields'    => $changes,
            ];
        }
    }
}


/*
|--------------------------------------------------------------------------
| GROUP RECORDS BY PATIENT
|--------------------------------------------------------------------------
|
| Turn the flat consultation list into patient groups. Each patient gets:
|
|   - demographic summary (name, age, sex, patient id, allergies)
|   - aggregate stats (total visits, last visit, primary diagnosis)
|   - a list of consultations (the medical history timeline)
|
*/

$patients = [];

foreach ($records as $r) {

    $pid = $r['patient_id'];

    if (!isset($patients[$pid])) {

        $patients[$pid] = [
            'patient_id' => $pid,
            'name' => $r['patient'],
            'age' => $r['age'],
            'sex' => $r['sex'],
            'blood_type' => $r['blood_type'],
            'allergies' => $r['allergies'],
            'current_medication' => $r['current_medication'],
            'visits' => [],
        ];
    }

    /*
    | Attach this consultation's medications to the record so the
    | timeline can show them inline.
    */
    $r['medications'] = $medsByConsultation[$r['id']] ?? [];

    /*
    | Attach this consultation's audit-trail events (modification history).
    */
    $r['audit'] = $auditByConsultation[$r['id']] ?? [];

    $patients[$pid]['visits'][] = $r;
}


/*
|--------------------------------------------------------------------------
| FINALIZE PATIENT SUMMARIES
|--------------------------------------------------------------------------
*/

foreach ($patients as $pid => &$group) {

    $visits = $group['visits'];

    $group['total_visits'] = count($visits);

    /*
    | Consultation list is already ordered newest-first, so the first
    | visit is the most recent.
    */
    $latest = $visits[0] ?? null;

    $group['last_visit'] = $latest
        ? ($latest['date'] ?? '')
        : '';

    $group['last_visit_raw'] = $latest
        ? ($latest['raw_date'] ?? '')
        : '';

    /*
    | Primary diagnosis = most recent non-empty diagnosis.
    */
    $primaryDiagnosis = '';

    foreach ($visits as $v) {
        if (!empty(trim($v['diagnosis']))) {
            $primaryDiagnosis = trim($v['diagnosis']);
            break;
        }
    }

    $group['primary_diagnosis'] = $primaryDiagnosis;

    /*
    | Current medications: use the patient's recorded CurrentMedication,
    | otherwise the medicines from the latest visit.
    */
    $currentMeds = trim($group['current_medication'] ?? '');

    if ($currentMeds === '') {
        $latestMeds = $latest['medications'] ?? [];
        $currentMeds = implode(', ', $latestMeds);
    }

    $group['current_medications'] = $currentMeds;

    /*
    | Any ongoing consultation with a requested (non-empty) lab order
    | marks this patient as having a pending lab.
    */
    $labPending = false;

    foreach ($visits as $v) {
        if (
            strtolower(trim($v['raw_status'] ?? '')) === 'ongoing'
            && !empty(trim($v['lab'] ?? ''))
        ) {
            $labPending = true;
            break;
        }
    }

    $group['flags'] = patientFlags(
        [
            'allergies'              => $group['allergies'] ?? '',
            'past_medical_condition' => $visits[0]['past_medical_condition'] ?? '',
        ],
        $labPending
    );
}

unset($group);


/*
|--------------------------------------------------------------------------
| GLOBAL TOTALS
|--------------------------------------------------------------------------
*/

$totalPatients = count($patients);

$grandTotalVisits = 0;

foreach ($patients as $group) {
    $grandTotalVisits += $group['total_visits'];
}


/*
|--------------------------------------------------------------------------
| FILTER BY PATIENT (optional ?patient_id=N)
|--------------------------------------------------------------------------
|
| When linking from search_patient.php, only show the selected patient.
|
*/

$requestedPatientId = isset($_GET['patient_id'])
    ? (int) $_GET['patient_id']
    : 0;

if ($requestedPatientId > 0 && isset($patients[$requestedPatientId])) {

    $patients = [$requestedPatientId => $patients[$requestedPatientId]];

    $totalPatients = 1;

    $grandTotalVisits = $patients[$requestedPatientId]['total_visits'];
}


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


function patientFlags(array $patient, bool $labPending = false): array
{
    $flags = [];

    $allergies = trim((string) ($patient['allergies'] ?? ''));

    if ($allergies !== '') {
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
                Patient medical histories for
                <?= htmlspecialchars($doctorDisplayName) ?>
            </p>

        </div>

    </div>


    <?php if (isset($_GET['msg'])): ?>

    <div class="records-alert records-alert--<?= in_array($_GET['msg'], ['updated', 'nochange'], true) ? 'success' : 'error' ?>">

        <?php
        $msgText = [
            'updated'  => 'Consultation updated. Changes have been recorded in the audit trail.',
            'nochange' => 'No changes were detected.',
            'csrf'     => 'Security token mismatch. Please try again.',
            'denied'   => 'You are not authorized to edit this consultation.',
            'invalid'  => 'The consultation could not be found.',
        ];
        echo htmlspecialchars($msgText[$_GET['msg']] ?? 'Action completed.');
        ?>

    </div>

    <?php endif; ?>


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
                Patient Medical Histories
            </h2>

            <span
                class="records-count"
                id="records-count"
            >
                (<?= $totalPatients ?> patient<?= $totalPatients === 1 ? '' : 's' ?> · <?= $grandTotalVisits ?> visit<?= $grandTotalVisits === 1 ? '' : 's' ?>)
            </span>

        </div>


                <div id="records-list">

        <?php if (empty($patients)): ?>

            <!-- NO PATIENTS -->

            <div
                class="records-empty"
                id="database-empty"
            >

                No patient consultation records found.

            </div>


        <?php else: ?>

            <?php foreach ($patients as $group): ?>

                <div
                    class="record-item open patient-card"
                    data-name="<?= htmlspecialchars(strtolower($group['name'])) ?>"
                    data-diagnosis="<?= htmlspecialchars(strtolower($group['primary_diagnosis'])) ?>"
                    data-date="<?= htmlspecialchars($group['last_visit_raw']) ?>"
                >


                    <!-- ==================================================
                         PATIENT SUMMARY HEADER
                    ================================================== -->

                    <div class="patient-card-head">

                        <div class="record-avatar">
                            <?= htmlspecialchars(initials($group['name'])) ?>
                        </div>

                        <div class="patient-card-id">

                            <div class="record-name">
                                <?= htmlspecialchars($group['name']) ?>
                            </div>

                            <div class="record-meta">

                                <?php if ($group['age'] !== null): ?>
                                    Age <?= (int)$group['age'] ?>
                                <?php else: ?>
                                    Age not recorded
                                <?php endif; ?>

                                <?php if (!empty($group['sex'])): ?>
                                    ·
                                    <?= htmlspecialchars($group['sex']) ?>
                                <?php endif; ?>

                                ·
                                Patient ID:
                                #<?= (int)$group['patient_id'] ?>

                            </div>

                            <div class="record-meta">
                                <?= htmlspecialchars($doctorDisplayName) ?>
                            </div>

                        </div>

                    </div>


                    <?php if (!empty($group['flags'])): ?>

                    <div class="patient-flags pf-flags">

                        <?php foreach ($group['flags'] as $fkey => $flabel): ?>

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


                    <!-- ==================================================
                         PATIENT KEY FACTS
                    ================================================== -->

                    <div class="patient-facts">

                        <div class="pf-item">

                            <div class="pf-label">
                                Total Visits
                            </div>

                            <div class="pf-value">
                                <?= (int)$group['total_visits'] ?>
                            </div>

                        </div>

                        <div class="pf-item">

                            <div class="pf-label">
                                Last Visit
                            </div>

                            <div class="pf-value">
                                <?= htmlspecialchars($group['last_visit'] ?: '—') ?>
                            </div>

                        </div>

                        <div class="pf-item pf-wide">

                            <div class="pf-label">
                                Primary Diagnosis
                            </div>

                            <div class="pf-value">
                                <?= $group['primary_diagnosis'] !== ''
                                    ? nl2br(htmlspecialchars($group['primary_diagnosis']))
                                    : '—'
                                ?>
                            </div>

                        </div>

                        <div class="pf-item pf-wide">

                            <div class="pf-label">
                                Current Medications
                            </div>

                            <div class="pf-value">
                                <?= $group['current_medications'] !== ''
                                    ? nl2br(htmlspecialchars($group['current_medications']))
                                    : '—'
                                ?>
                            </div>

                        </div>

                        <div class="pf-item pf-wide">

                            <div class="pf-label">
                                Allergies
                            </div>

                            <div class="pf-value">
                                <?= $group['allergies'] !== ''
                                    ? nl2br(htmlspecialchars($group['allergies']))
                                    : 'None recorded'
                                ?>
                            </div>

                        </div>

                    </div>


                    <!-- ==================================================
                         MEDICAL HISTORY TIMELINE
                    ================================================== -->

                    <div class="timeline">

                        <div class="timeline-head">
                            Medical History Timeline
                        </div>

                        <?php foreach ($group['visits'] as $v): ?>

                            <div class="timeline-item">

                                <div class="timeline-date">
                                    <?= htmlspecialchars($v['date']) ?>
                                </div>

                                <div class="timeline-card">

                                    <div class="tl-head">

                                        <div class="tl-tag">
                                            Consultation
                                        </div>

                                        <div class="tl-actions">

                                            <?php if (!empty($v['audit'])): ?>
                                            <button
                                                type="button"
                                                class="tl-btn tl-btn-audit"
                                                onclick="toggleAudit(<?= (int)$v['id'] ?>)"
                                            >
                                                View Changes (<?= count($v['audit']) ?>)
                                            </button>
                                            <?php endif; ?>

                                            <button
                                                type="button"
                                                class="tl-btn tl-btn-edit"
                                                onclick="toggleEdit(<?= (int)$v['id'] ?>)"
                                            >
                                                Edit
                                            </button>

                                            <a
                                                class="tl-btn tl-btn-report"
                                                href="records.php?print=consultation_report&amp;cid=<?= (int)$v['id'] ?>"
                                                title="Print complete consultation report"
                                                aria-label="Print complete consultation report"
                                            >
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    aria-hidden="true"
                                                >
                                                    <polyline points="6 9 6 2 18 2 18 9"/>
                                                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                                                    <rect x="6" y="14" width="12" height="8"/>
                                                </svg>
                                            </a>

                                        </div>

                                    </div>

                                    <?php if (!empty($v['chief_complaint'])): ?>

                                        <div class="tl-block">
                                            <div class="tl-label">
                                                Chief Complaint
                                            </div>
                                            <div class="tl-text">
                                                <?= nl2br(htmlspecialchars($v['chief_complaint'])) ?>
                                            </div>
                                        </div>

                                    <?php endif; ?>


                                    <div class="tl-block">
                                        <div class="tl-label">
                                            Diagnosis
                                        </div>
                                        <div class="tl-text">
                                            <?php if (!empty($v['diagnosis'])): ?>
                                                <?= nl2br(htmlspecialchars($v['diagnosis'])) ?>
                                            <?php else: ?>
                                                Not recorded
                                            <?php endif; ?>
                                        </div>
                                    </div>


                                    <div class="tl-block">
                                        <div class="tl-label">
                                            Medication
                                        </div>
                                        <div class="tl-text">
                                            <?php if (!empty($v['medications'])): ?>
                                                <?= nl2br(htmlspecialchars(implode("\n", $v['medications']))) ?>
                                            <?php elseif (!empty($v['treatment'])): ?>
                                                <?= nl2br(htmlspecialchars($v['treatment'])) ?>
                                            <?php else: ?>
                                                None recorded
                                            <?php endif; ?>
                                        </div>
                                    </div>


                                    <?php if (!empty($v['notes'])): ?>

                                        <div class="tl-block">
                                            <div class="tl-label">
                                                Notes
                                            </div>
                                            <div class="tl-text">
                                                <?= nl2br(htmlspecialchars($v['notes'])) ?>
                                            </div>
                                        </div>

                                    <?php endif; ?>


                                    <div class="tl-vitals">

                                        <div class="tl-vital">
                                            <span>BP</span>
                                            <?= htmlspecialchars($v['vitals']['bp']) ?>
                                        </div>

                                        <div class="tl-vital">
                                            <span>Temp</span>
                                            <?= htmlspecialchars($v['vitals']['temp']) ?>
                                        </div>

                                        <div class="tl-vital">
                                            <span>Pulse</span>
                                            <?= htmlspecialchars($v['vitals']['pulse']) ?>
                                        </div>

                                    </div>


                                    <!-- ==================================== -->
                                    <!-- EDIT FORM (collapsible)              -->
                                    <!-- ==================================== -->

                                    <div
                                        class="tl-edit"
                                        id="tl-edit-<?= (int)$v['id'] ?>"
                                        style="display:none;"
                                    >

                                        <div class="tl-edit-title">
                                            Edit Consultation
                                        </div>

                                        <form method="post" action="records.php">

                                            <input type="hidden" name="action" value="update_consultation">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="consultation_id" value="<?= (int)$v['id'] ?>">

                                            <div class="tl-edit-grid">

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Chief Complaint</label>
                                                    <textarea name="edit_chief_complaint" rows="2"><?= htmlspecialchars($v['chief_complaint']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Diagnosis</label>
                                                    <textarea name="edit_diagnosis" rows="3"><?= htmlspecialchars($v['diagnosis']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Treatment / Plan</label>
                                                    <textarea name="edit_treatment" rows="3"><?= htmlspecialchars($v['treatment']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Lab Request</label>
                                                    <textarea name="edit_lab_request" rows="2"><?= htmlspecialchars($v['lab']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Notes</label>
                                                    <textarea name="edit_notes" rows="3"><?= htmlspecialchars($v['notes']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field">
                                                    <label>Follow-up Date</label>
                                                    <input type="date" name="edit_follow_up"
                                                           value="<?= htmlspecialchars($v['follow_up']) ?>">
                                                </div>

                                                <div class="tl-edit-field">
                                                    <label>Status</label>
                                                    <select name="edit_status">
                                                        <?php
                                                        $statusOptions = [
                                                            'Ongoing', 'In Progress', 'Completed', 'Cancelled'
                                                        ];
                                                        foreach ($statusOptions as $st) {
                                                            $sel = strcasecmp($st, (string) $v['status']) === 0 ? ' selected' : '';
                                                            echo '<option value="' . $st . '"' . $sel . '>' . $st . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <div class="tl-edit-field">
                                                    <label>Blood Pressure</label>
                                                    <input type="text" name="edit_blood_pressure"
                                                           value="<?= htmlspecialchars($v['vitals']['bp'] === 'Not recorded' ? '' : (string) $v['vitals']['bp']) ?>">
                                                </div>

                                                <div class="tl-edit-field">
                                                    <label>Temperature (°C)</label>
                                                    <input type="number" step="0.1" name="edit_temperature"
                                                           value="<?= $v['vitals']['temp'] !== 'Not recorded' ? (float) filter_var($v['vitals']['temp'], FILTER_SANITIZE_NUMBER_FLOAT) : '' ?>">
                                                </div>

                                                <div class="tl-edit-field">
                                                    <label>Pulse Rate (bpm)</label>
                                                    <input type="number" name="edit_pulse_rate"
                                                           value="<?= $v['vitals']['pulse'] !== 'Not recorded' ? (int) filter_var($v['vitals']['pulse'], FILTER_SANITIZE_NUMBER_INT) : '' ?>">
                                                </div>

                                            </div>

                                            <div class="tl-edit-note">
                                                Changes are recorded in the audit trail:
                                                who changed what and when.
                                            </div>

                                            <div class="tl-edit-actions">
                                                <button type="button" class="tl-btn tl-btn-cancel" onclick="toggleEdit(<?= (int)$v['id'] ?>)">Cancel</button>
                                                <button type="submit" class="tl-btn tl-btn-save">Save Changes</button>
                                            </div>

                                        </form>

                                    </div>


                                    <!-- ==================================== -->
                                    <!-- AUDIT TRAIL (collapsible)            -->
                                    <!-- ==================================== -->

                                    <?php if (!empty($v['audit'])): ?>

                                    <div
                                        class="tl-audit"
                                        id="tl-audit-<?= (int)$v['id'] ?>"
                                        style="display:none;"
                                    >

                                        <div class="tl-edit-title">
                                            Audit Trail
                                        </div>

                                        <?php foreach ($v['audit'] as $event): ?>

                                        <div class="tl-audit-event">

                                            <div class="tl-audit-meta">

                                                <span class="tl-audit-action">
                                                    <?= htmlspecialchars($event['action']) ?>
                                                </span>

                                                <span class="tl-audit-time">
                                                    <?= htmlspecialchars(date('M j, Y g:i A', strtotime($event['timestamp']))) ?>
                                                </span>

                                                <?php if ($event['ip'] !== ''): ?>
                                                <span class="tl-audit-ip">
                                                    IP: <?= htmlspecialchars($event['ip']) ?>
                                                </span>
                                                <?php endif; ?>

                                            </div>

                                            <?php if (!empty($event['fields'])): ?>

                                            <div class="tl-audit-changes">

                                                <?php foreach ($event['fields'] as $change): ?>

                                                <div class="tl-audit-change">

                                                    <div class="tl-audit-field">
                                                        <?= htmlspecialchars($change['label']) ?>
                                                    </div>

                                                    <div class="tl-audit-diff">
                                                        <span class="tl-audit-old">
                                                            <?= $change['old'] === null || $change['old'] === ''
                                                                ? '<em>empty</em>'
                                                                : nl2br(htmlspecialchars((string) $change['old'])) ?>
                                                        </span>
                                                        <span class="tl-audit-arrow">&rarr;</span>
                                                        <span class="tl-audit-new">
                                                            <?= $change['new'] === null || $change['new'] === ''
                                                                ? '<em>empty</em>'
                                                                : nl2br(htmlspecialchars((string) $change['new'])) ?>
                                                        </span>
                                                    </div>

                                                </div>

                                                <?php endforeach; ?>

                                            </div>

                                            <?php endif; ?>

                                        </div>

                                        <?php endforeach; ?>

                                    </div>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

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
            No patient records match your filters.
        </div>
</div>


</main>

</div>


<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script>

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
        `(${visible} patient${visible === 1 ? '' : 's'})`;


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


/*
|--------------------------------------------------------------------------
| TOGGLE EDIT FORM
|--------------------------------------------------------------------------
*/

function toggleEdit(consultationId) {

    const editEl =
        document.getElementById('tl-edit-' + consultationId);

    if (!editEl) {
        return;
    }

    const show = editEl.style.display === 'none';

    editEl.style.display = show ? 'block' : 'none';

    if (show) {
        const auditEl =
            document.getElementById('tl-audit-' + consultationId);
        if (auditEl) {
            auditEl.style.display = 'none';
        }
        editEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}


/*
|--------------------------------------------------------------------------
| TOGGLE AUDIT TRAIL
|--------------------------------------------------------------------------
*/

function toggleAudit(consultationId) {

    const auditEl =
        document.getElementById('tl-audit-' + consultationId);

    if (!auditEl) {
        return;
    }

    const show = auditEl.style.display === 'none';

    auditEl.style.display = show ? 'block' : 'none';

    if (show) {
        const editEl =
            document.getElementById('tl-edit-' + consultationId);
        if (editEl) {
            editEl.style.display = 'none';
        }
    }
}

</script>


</body>
</html>