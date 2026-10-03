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
require_once __DIR__ . '/../includes/vitals.php';


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
            COALESCE(v.BloodPressure, c.BloodPressure) AS BloodPressure,
            COALESCE(v.Temperature, c.Temperature) AS Temperature,
            COALESCE(v.PulseRate, c.PulseRate) AS PulseRate,
            COALESCE(v.Weight, c.Weight) AS Weight,
            COALESCE(v.Height, c.Height) AS Height
        FROM consultations c
        LEFT JOIN vitals v ON v.VitalID = c.VitalID
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

    /* Clinical Notes — SOAP sections (mirrors the consultation form) */
    $soapSubjective = trim($_POST['edit_soap_subjective'] ?? '');
    $soapObjective  = trim($_POST['edit_soap_objective'] ?? '');
    $soapAssessment = trim($_POST['edit_soap_assessment'] ?? '');
    $soapPlan       = trim($_POST['edit_soap_plan'] ?? '');

    $soapParts = [];
    if ($soapSubjective !== '') {
        $soapParts[] = "SUBJECTIVE:\n" . $soapSubjective;
    }
    if ($soapObjective !== '') {
        $soapParts[] = "OBJECTIVE:\n" . $soapObjective;
    }
    if ($soapAssessment !== '') {
        $soapParts[] = "ASSESSMENT:\n" . $soapAssessment;
    }
    if ($soapPlan !== '') {
        $soapParts[] = "PLAN:\n" . $soapPlan;
    }

    /* Lab requests: one test per row, stored newline-separated. */
    $labRequestsSaved = [];
    foreach (($_POST['edit_lab_requests'] ?? []) as $req) {
        $req = trim((string) $req);
        if ($req !== '') {
            $labRequestsSaved[] = $req;
        }
    }

    /*
    | The current edit form no longer submits a Treatment box (plans live in
    | the SOAP "Plan" section). Preserve the stored legacy Treatment column
    | when nothing new is submitted, mirroring the consultation form.
    */
    $editTreatment = trim($_POST['edit_treatment'] ?? '');
    if ($editTreatment === '') {
        $editTreatment = (string) ($existing['Treatment'] ?? '');
    }

    $newValues = [
        'ChiefComplaint' => trim($_POST['edit_chief_complaint'] ?? ''),
        'Diagnosis'      => trim($_POST['edit_diagnosis'] ?? ''),
        'Treatment'      => $editTreatment,
        'LabRequest'     => implode("\n", $labRequestsSaved),
        'Notes'          => implode("\n\n", $soapParts),
        'FollowUpDate'   => trim($_POST['edit_follow_up'] ?? ''),
        'Status'         => trim($_POST['edit_status'] ?? ''),
        'BloodPressure'  => trim($_POST['edit_blood_pressure'] ?? ''),
        'Temperature'    => trim($_POST['edit_temperature'] ?? ''),
        'PulseRate'      => trim($_POST['edit_pulse_rate'] ?? ''),
        'Weight'         => trim($_POST['edit_weight'] ?? ''),
        'Height'         => trim($_POST['edit_height'] ?? ''),
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

    /*
    | Normalize Weight/Height as numbers so re-saving an unchanged value
    | ("62" in the form vs "62.00" in the DB) does not register a change.
    */
    $canonFloat = function ($val) {
        if ($val === null || $val === '') {
            return '';
        }
        return rtrim(rtrim(number_format((float) $val, 2), '0'), '.');
    };

    $newWeight = $newValues['Weight'];
    if ($newWeight === '') {
        $newValues['Weight'] = null;
    } elseif ($canonFloat($newWeight) !== $canonFloat($existing['Weight'] ?? 0)) {
        $newValues['Weight'] = $canonFloat($newWeight);
    }

    $newHeight = $newValues['Height'];
    if ($newHeight === '') {
        $newValues['Height'] = null;
    } elseif ($canonFloat($newHeight) !== $canonFloat($existing['Height'] ?? 0)) {
        $newValues['Height'] = $canonFloat($newHeight);
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
    | Collect prescription rows (mirrors the consultation form).
    */
    $rxRows = [];
    $rxNames        = $_POST['edit_rx_name'] ?? [];
    $rxDosages      = $_POST['edit_rx_dosage'] ?? [];
    $rxFrequencies  = $_POST['edit_rx_frequency'] ?? [];
    $rxDurations    = $_POST['edit_rx_duration'] ?? [];
    $rxInstructions = $_POST['edit_rx_instructions'] ?? [];

    foreach ((array) $rxNames as $i => $rxNameRaw) {
        $rxName = trim((string) $rxNameRaw);
        if ($rxName === '') {
            continue;
        }
        $rxRows[] = [
            'name'         => $rxName,
            'dosage'       => trim((string) ($rxDosages[$i] ?? '')),
            'frequency'    => trim((string) ($rxFrequencies[$i] ?? '')),
            'duration'     => trim((string) ($rxDurations[$i] ?? '')),
            'instructions' => trim((string) ($rxInstructions[$i] ?? '')),
        ];
    }

    $rxRowSignature = function (array $row): string {
        return implode('|', [
            $row['name'],
            $row['dosage'],
            $row['frequency'],
            $row['duration'],
            $row['instructions'],
        ]);
    };

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
        'Weight'         => 'Weight',
        'Height'         => 'Height',
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
    | Compare prescription rows against what is currently saved so
    | prescription edits are also captured in the change list.
    */
    $existingRx = [];
    $rxLoadStmt = $conn->prepare("
        SELECT pi.MedicineName, pi.Dosage, pi.Frequency, pi.Duration, pi.Instructions
        FROM prescriptions pr
        INNER JOIN prescription_items pi
            ON pi.PrescriptionID = pr.PrescriptionID
        WHERE pr.ConsultationID = ?
        ORDER BY pi.PrescriptionItemID ASC
    ");
    $rxLoadStmt->bind_param("i", $editCid);
    $rxLoadStmt->execute();
    $rxLoadResult = $rxLoadStmt->get_result();
    while ($rxRow = $rxLoadResult->fetch_assoc()) {
        $existingRx[] = [
            'name'         => (string) ($rxRow['MedicineName'] ?? ''),
            'dosage'       => (string) ($rxRow['Dosage'] ?? ''),
            'frequency'    => (string) ($rxRow['Frequency'] ?? ''),
            'duration'     => (string) ($rxRow['Duration'] ?? ''),
            'instructions' => (string) ($rxRow['Instructions'] ?? ''),
        ];
    }
    $rxLoadStmt->close();

    $existingRxSigs = array_map($rxRowSignature, $existingRx);
    $newRxSigs      = array_map($rxRowSignature, $rxRows);

    $rxChanged = ($existingRxSigs !== $newRxSigs);

    if ($rxChanged) {
        $changes[] = [
            'field' => 'Prescriptions',
            'label' => 'Medications / Prescriptions',
            'old'   => json_encode($existingRx, JSON_PRETTY_PRINT),
            'new'   => json_encode($rxRows, JSON_PRETTY_PRINT),
        ];
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
            Status = ?
        WHERE ConsultationID = ?
          AND StaffID = ?
    ");

    /*
    | If this consultation already has a linked vitals row, update it.
    | Otherwise insert a new one and link it. This keeps vitals the
    | canonical store; consultations.BloodPressure etc. are left as
    | the legacy fallback that decision B preserves for NULL-linked rows.
    */
    $existingVitals = $conn->prepare(
        "SELECT v.VitalID FROM vitals v
         JOIN consultations c ON c.VitalID = v.VitalID
         WHERE c.ConsultationID = ? AND c.StaffID = ? LIMIT 1"
    );
    $existingVitals->bind_param("ii", $editCid, $staffID);
    $existingVitals->execute();
    $existingVitalsRow = $existingVitals->get_result()->fetch_assoc();
    $existingVitals->close();

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
    $uWeight  = $newValues['Weight'];
    $uHeight  = $newValues['Height'];

    if ($existingVitalsRow) {
        $vitalsUpdate = $conn->prepare("
            UPDATE vitals SET
                BloodPressure = ?,
                Temperature = NULLIF(?, ''),
                PulseRate = NULLIF(?, ''),
                Weight = NULLIF(?, ''),
                Height = NULLIF(?, '')
            WHERE VitalID = ?
        ");
        $vitalsUpdate->bind_param(
            "sssssi",
            $uBP, $uTemp, $uPulse, $uWeight, $uHeight,
            $existingVitalsRow['VitalID']
        );
        $vitalsUpdate->execute();
        $vitalsUpdate->close();
    } else {
        $vitalsInsert = $conn->prepare("
            INSERT INTO vitals
                (AppointmentID, PatientID, StaffID, BloodPressure,
                 Temperature, PulseRate, Weight, Height, Source)
            SELECT c.AppointmentID, c.PatientID, c.StaffID, ?,
                   NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''),
                   'Consultation'
            FROM consultations c
            WHERE c.ConsultationID = ? AND c.StaffID = ?
        ");
        $vitalsInsert->bind_param(
            "sssii",
            $uBP, $uTemp, $uPulse, $uWeight, $uHeight,
            $editCid, $staffID
        );
        $vitalsInsert->execute();
        $newVitalID = $conn->insert_id;
        $vitalsInsert->close();

        $linkVital = $conn->prepare(
            "UPDATE consultations SET VitalID = ? WHERE ConsultationID = ? AND StaffID = ?"
        );
        $linkVital->bind_param("iii", $newVitalID, $editCid, $staffID);
        $linkVital->execute();
        $linkVital->close();
    }

    $updateStmt->bind_param(
        "sssssssii",
        $uChief,
        $uDiag,
        $uTreat,
        $uLab,
        $uNotes,
        $uFollow,
        $uStatus,
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

    /*
    | Save prescriptions (delete + re-insert, mirroring the consultation
    | form so removed items are actually removed).
    */
    if ($rxChanged) {

        $delItemsStmt = $conn->prepare("
            DELETE pi
            FROM prescription_items pi
            INNER JOIN prescriptions pr
                ON pi.PrescriptionID = pr.PrescriptionID
            WHERE pr.ConsultationID = ?
        ");
        $delItemsStmt->bind_param("i", $editCid);
        $delItemsStmt->execute();
        $delItemsStmt->close();

        $delPrescStmt = $conn->prepare("
            DELETE FROM prescriptions
            WHERE ConsultationID = ?
        ");
        $delPrescStmt->bind_param("i", $editCid);
        $delPrescStmt->execute();
        $delPrescStmt->close();

        if (!empty($rxRows)) {

            $prescDate = date('Y-m-d');

            $insPrescStmt = $conn->prepare("
                INSERT INTO prescriptions (ConsultationID, PrescribedDate)
                VALUES (?, ?)
            ");
            $insPrescStmt->bind_param("is", $editCid, $prescDate);
            $insPrescStmt->execute();
            $newPrescriptionID = (int) $insPrescStmt->insert_id;
            $insPrescStmt->close();

            $insItemStmt = $conn->prepare("
                INSERT INTO prescription_items
                    (PrescriptionID, MedicineName, Dosage, Frequency, Duration, Instructions)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($rxRows as $rxItem) {
                $insItemStmt->bind_param(
                    "isssss",
                    $newPrescriptionID,
                    $rxItem['name'],
                    $rxItem['dosage'],
                    $rxItem['frequency'],
                    $rxItem['duration'],
                    $rxItem['instructions']
                );
                $insItemStmt->execute();
            }

            $insItemStmt->close();
        }
    }

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
            COALESCE(v.BloodPressure, c.BloodPressure) AS BloodPressure,
            COALESCE(v.Temperature, c.Temperature) AS Temperature,
            COALESCE(v.PulseRate, c.PulseRate) AS PulseRate,
            COALESCE(v.Weight, c.Weight) AS Weight,
            COALESCE(v.Height, c.Height) AS Height,

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
        LEFT JOIN vitals v   ON v.VitalID = c.VitalID
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
        'clinic_name'           => 'Curora Clinic',
        'clinic_info'           => 'Curora Outpatient Portal | Tel: (02) 1234-5678',
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

/*
|--------------------------------------------------------------------------
| PARSE SOAP CLINICAL NOTES
|--------------------------------------------------------------------------
|
| The Notes column stores the assembled SOAP text produced by the live
| consultation form ("SUBJECTIVE:...\n\nOBJECTIVE:...", ...). This helper
| splits it back into the four sections so the edit form can prefill its
| Subjective / Objective / Assessment / Plan fields individually.
|
| Returns an assoc array with keys: subjective, objective, assessment, plan.
| Unstructured (legacy) notes are returned under 'subjective' plus the
| remaining sections empty.
|
*/

function parseSoapNotes(?string $text): array
{
    $sections = [
        'subjective' => '',
        'objective'  => '',
        'assessment' => '',
        'plan'       => '',
    ];

    $text = trim((string) $text);

    if ($text === '') {
        return $sections;
    }

    $pattern =
        '/^(SUBJECTIVE|OBJECTIVE|ASSESSMENT|PLAN):\s*(.*?)(?=^(?:SUBJECTIVE|OBJECTIVE|ASSESSMENT|PLAN):|\z)/msi';

    if (
        preg_match_all(
            $pattern,
            $text,
            $matches,
            PREG_SET_ORDER
        ) &&
        !empty($matches)
    ) {
        $map = [
            'SUBJECTIVE' => 'subjective',
            'OBJECTIVE'  => 'objective',
            'ASSESSMENT' => 'assessment',
            'PLAN'       => 'plan',
        ];

        foreach ($matches as $match) {
            $key = strtoupper($match[1]);
            if (isset($map[$key])) {
                $sections[$map[$key]] = trim($match[2]);
            }
        }

        return $sections;
    }

    // Legacy / unstructured note.
    $sections['subjective'] = $text;

    return $sections;
}

/*
|--------------------------------------------------------------------------
| SPLIT LAB REQUESTS INTO ROWS
|--------------------------------------------------------------------------
|
| LabRequest is stored newline-separated. Split it back into an array of
| individual tests so the edit form can render one input per test.
|
*/

function splitLabRequestRows(?string $labText): array
{
    $labText = trim((string) $labText);

    if ($labText === '') {
        return [];
    }

    return array_values(
        array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $labText))
        )
    );
}

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

        COALESCE(v.BloodPressure, c.BloodPressure) AS BloodPressure,
        COALESCE(v.Temperature, c.Temperature) AS Temperature,
        COALESCE(v.PulseRate, c.PulseRate) AS PulseRate,
        COALESCE(v.Weight, c.Weight) AS Weight,
        COALESCE(v.Height, c.Height) AS Height,

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

    LEFT JOIN vitals v
        ON v.VitalID = c.VitalID

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
    | FORMAT WEIGHT / HEIGHT
    |--------------------------------------------------------------------------
    */

    $weight = null;
    if ($row['Weight'] !== null && $row['Weight'] !== '') {
        $weight = rtrim(rtrim(number_format((float) $row['Weight'], 2), '0'), '.');
    }

    $height = null;
    if ($row['Height'] !== null && $row['Height'] !== '') {
        $height = rtrim(rtrim(number_format((float) $row['Height'], 2), '0'), '.');
    }


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

        'weight' => $weight,

        'height' => $height,

        'prescriptions' => $rxRowsByConsultation[(int) $row['ConsultationID']] ?? [],

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
$rxRowsByConsultation = [];

$medsStmt = $conn->prepare("
    SELECT
        pr.ConsultationID,
        pi.MedicineName,
        pi.Dosage,
        pi.Frequency,
        pi.Duration,
        pi.Instructions,
        pi.PrescriptionItemID
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

    $displayName = $medName;
    if ($dosage !== '') {
        $displayName .= ' (' . $dosage . ')';
    }

    $medsByConsultation[$cid][] = $displayName;

    $rxRowsByConsultation[$cid][] = [
        'name'         => $medName,
        'dosage'       => trim($medRow['Dosage'] ?? ''),
        'frequency'    => trim($medRow['Frequency'] ?? ''),
        'duration'     => trim($medRow['Duration'] ?? ''),
        'instructions' => trim($medRow['Instructions'] ?? ''),
        'item_id'      => (int) ($medRow['PrescriptionItemID'] ?? 0),
    ];
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
| GET VITALS PER PATIENT
|--------------------------------------------------------------------------
|
| Builds a map: PatientID -> [ vitals readings (newest first) ].
| Powers the Vitals History panel in each patient's detail view.
|
*/

$vitalsByPatient = [];

if (!empty($records)) {

    $recordPatientIds = [];

    foreach ($records as $r) {
        $recordPatientIds[(int) $r['patient_id']] = true;
    }

    if (!empty($recordPatientIds)) {

        $patientIdList = implode(
            ',',
            array_map('intval', array_keys($recordPatientIds))
        );

        $vitalsResult = $conn->query("
            SELECT
                v.PatientID,
                v.BloodPressure,
                v.Temperature,
                v.PulseRate,
                v.Weight,
                v.Height,
                v.RecordedAt
            FROM vitals v
            WHERE v.PatientID IN ($patientIdList)
            ORDER BY v.RecordedAt DESC, v.VitalID DESC
        ");

        if ($vitalsResult) {

            while ($vRow = $vitalsResult->fetch_assoc()) {
                $vPid = (int) $vRow['PatientID'];
                $vitalsByPatient[$vPid][] = $vRow;
            }
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

    /*
    | Latest vitals readings for this patient (Vitals History panel).
    */
    $group['vitals_history'] = $vitalsByPatient[$pid] ?? [];
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
| VITALS HISTORY CELL RENDERER
|--------------------------------------------------------------------------
|
| Renders a single vitals table cell, color-coded when the reading is
| outside the normal range (using the shared vitals classifier).
|
*/

function renderVitalsCell(array $byKey, string $key): string
{
    $item = $byKey[$key] ?? null;

    if (
        !$item
        || $item['value'] === ''
        || $item['value'] === null
    ) {
        return '<span class="vh-cell-empty">&mdash;</span>';
    }

    $status = $item['status'];

    $attrs = '';

    if ($status === 'warning') {
        $attrs = ' class="vh-cell-warning" title="' .
            htmlspecialchars($item['note']) . '"';
    } elseif ($status === 'high' || $status === 'low') {
        $attrs = ' class="vh-cell-abnormal" title="' .
            htmlspecialchars($item['note']) . '"';
    }

    $warn = '';

    if ($status === 'warning') {
        $warn = ' <span class="vh-cell-warn">&#9888;</span>';
    } elseif ($status === 'high' || $status === 'low') {
        $warn = ' <span class="vh-cell-warn">&#9888;&#65039;</span>';
    }

    return '<span' . $attrs . '>' .
        htmlspecialchars(
            $item['value'] . ' ' . $item['unit']
        ) .
        $warn .
        '</span>';
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
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">

<link
    rel="stylesheet"
    href="../assets/css/doctor/doctor_dashboard.css"
>

<script src="../assets/js/pagination.js"></script>

</head>


<body>

<div class="app">


<!-- ============================================================
     SIDEBAR
============================================================ -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">

            <img src="../assets/images/curora-icon.png" alt="Curora">

        </div>

        <div class="brand-text">

            <div class="brand-title">
                Curora
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
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
          Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a href="doctor_queue.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          Queue
        </a>
      </li>
      <li class="nav-item active">
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
        <a href="announcements.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          Announcements
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
                                    'allergy'    => '⚠',
                                    'high_risk'  => '⚠',
                                    'lab_pending'=> '●',
                                ];

                                echo $flagIcons[$fkey] ?? '●';

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
                         VITALS HISTORY
                    ================================================== -->

                    <div class="vh-history-panel">

                        <div class="vh-history-head">
                            Vitals History
                        </div>

                        <?php if (empty($group['vitals_history'])): ?>

                            <div class="vh-history-empty">
                                No vitals history on file.
                            </div>

                        <?php else: ?>

                            <div class="vh-table-wrap">

                                <table class="vh-table" data-responsive>

                                    <thead>

                                        <tr>
                                            <th>Date/Time</th>
                                            <th>BP</th>
                                            <th>Temp</th>
                                            <th>Pulse</th>
                                            <th>Weight</th>
                                            <th>Height</th>
                                        </tr>

                                    </thead>

                                    <tbody>

                                        <?php
                                        foreach ($group['vitals_history'] as $vhIdx => $vhRow):

                                            $vhClassified = classifyVitals([
                                                'blood_pressure'    => $vhRow['BloodPressure'] ?? '',
                                                'temperature'       => $vhRow['Temperature'] ?? '',
                                                'pulse_rate'        => $vhRow['PulseRate'] ?? '',
                                                'weight'            => $vhRow['Weight'] ?? '',
                                                'height'            => $vhRow['Height'] ?? '',
                                            ]);

                                            $vhByKey = [];

                                            foreach ($vhClassified as $ci) {
                                                $vhByKey[$ci['key']] = $ci;
                                            }
                                        ?>

                                        <tr <?= $vhIdx >= 5 ? 'class="vh-more-row" hidden' : '' ?>>

                                            <td class="vh-cell-date" data-label="Date/Time">
                                                <?= htmlspecialchars(
                                                    date(
                                                        'M d, Y g:i A',
                                                        strtotime($vhRow['RecordedAt'])
                                                    )
                                                ) ?>
                                            </td>

                                            <td data-label="BP"><?= renderVitalsCell($vhByKey, 'blood_pressure') ?></td>
                                            <td data-label="Temp"><?= renderVitalsCell($vhByKey, 'temperature') ?></td>
                                            <td data-label="Pulse"><?= renderVitalsCell($vhByKey, 'pulse_rate') ?></td>
                                            <td data-label="Weight"><?= renderVitalsCell($vhByKey, 'weight') ?></td>
                                            <td data-label="Height"><?= renderVitalsCell($vhByKey, 'height') ?></td>

                                        </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                            <?php if (count($group['vitals_history']) > 5): ?>

                            <button
                                type="button"
                                class="vh-view-all-btn"
                                onclick="toggleVitalsHistory(this)"
                            >
                                View All Vitals History
                            </button>

                            <?php endif; ?>

                        <?php endif; ?>

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

                                        <div class="tl-vital">
                                            <span>Weight</span>
                                            <?= $v['weight'] !== null ? htmlspecialchars((string) $v['weight']) . ' kg' : '—' ?>
                                        </div>

                                        <div class="tl-vital">
                                            <span>Height</span>
                                            <?= $v['height'] !== null ? htmlspecialchars((string) $v['height']) . ' m' : '—' ?>
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

                                                <?php $soapEdit = parseSoapNotes($v['notes']); ?>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Clinical Notes — Subjective</label>
                                                    <textarea name="edit_soap_subjective" rows="3" placeholder="Chief complaint in patient's own words, history of present illness..."><?= htmlspecialchars($soapEdit['subjective']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Clinical Notes — Objective</label>
                                                    <textarea name="edit_soap_objective" rows="3" placeholder="Observations, examination findings, vitals, test results..."><?= htmlspecialchars($soapEdit['objective']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Clinical Notes — Assessment</label>
                                                    <textarea name="edit_soap_assessment" rows="3" placeholder="Working diagnosis, differentials, assessment of findings..."><?= htmlspecialchars($soapEdit['assessment']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Clinical Notes — Plan</label>
                                                    <textarea name="edit_soap_plan" rows="3" placeholder="Treatment, medications, tests, follow-up, referrals..."><?= htmlspecialchars($soapEdit['plan']) ?></textarea>
                                                </div>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <label>Suggested Follow-up Date</label>
                                                    <input type="date" name="edit_follow_up"
                                                           value="<?= htmlspecialchars($v['follow_up']) ?>"
                                                           onchange="checkEditFollowupAvailability(<?= (int)$v['id'] ?>, this.value)">
                                                    <div class="fu-availability" id="edit-fu-availability-<?= (int)$v['id'] ?>" style="display:none"></div>
                                                    <p class="fu-field-note edit-fu-note">
                                                        Suggestion only — the patient schedules the appointment themselves in the patient portal.
                                                    </p>
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

                                                <div class="tl-edit-field tl-edit-wide">
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

                                                <div class="tl-edit-field">
                                                    <label>Weight (kg)</label>
                                                    <input type="number" step="0.01" name="edit_weight"
                                                           value="<?= $v['weight'] !== null ? (float) $v['weight'] : '' ?>">
                                                </div>

                                                <div class="tl-edit-field">
                                                    <label>Height (m)</label>
                                                    <input type="number" step="0.01" name="edit_height"
                                                           value="<?= $v['height'] !== null ? (float) $v['height'] : '' ?>">
                                                </div>

                                                <!-- Prescriptions (rows, mirrors the consultation form) -->
                                                <div class="tl-edit-field tl-edit-wide">
                                                    <div class="tl-edit-subhead">
                                                        <label>Prescriptions</label>
                                                        <button
                                                            type="button"
                                                            class="btn-add-sm"
                                                            onclick="addEditRxRow(
                                                                <?= (int)$v['id'] ?>
                                                            )"
                                                        >
                                                            + Add
                                                        </button>
                                                    </div>

                                                    <div id="edit-rx-list-<?= (int)$v['id'] ?>">

                                                        <?php if (!empty($v['prescriptions'])): ?>

                                                            <?php foreach ($v['prescriptions'] as $rx): ?>

                                                            <div class="prescription-entry">

                                                                <div class="prescription-row">
                                                                    <input type="text" name="edit_rx_name[]" placeholder="Medication"
                                                                           value="<?= htmlspecialchars($rx['name']) ?>">
                                                                    <input type="text" name="edit_rx_dosage[]" placeholder="Dosage & Form"
                                                                           value="<?= htmlspecialchars($rx['dosage']) ?>">
                                                                    <input type="text" name="edit_rx_frequency[]" placeholder="Frequency"
                                                                           value="<?= htmlspecialchars($rx['frequency']) ?>">
                                                                    <input type="text" name="edit_rx_duration[]" placeholder="Duration"
                                                                           value="<?= htmlspecialchars($rx['duration']) ?>">
                                                                </div>

                                                                <div class="prescription-row">
                                                                    <input type="text" name="edit_rx_instructions[]" class="full" placeholder="Instructions"
                                                                           value="<?= htmlspecialchars($rx['instructions']) ?>">
                                                                </div>

                                                                <button
                                                                    type="button"
                                                                    class="btn-remove-rx"
                                                                    onclick="this.closest('.prescription-entry').remove()"
                                                                >
                                                                    Remove
                                                                </button>

                                                            </div>

                                                            <?php endforeach; ?>

                                                        <?php else: ?>

                                                            <div class="prescription-entry">

                                                                <div class="prescription-row">
                                                                    <input type="text" name="edit_rx_name[]" placeholder="Medication">
                                                                    <input type="text" name="edit_rx_dosage[]" placeholder="Dosage & Form">
                                                                    <input type="text" name="edit_rx_frequency[]" placeholder="Frequency">
                                                                    <input type="text" name="edit_rx_duration[]" placeholder="Duration">
                                                                </div>

                                                                <div class="prescription-row">
                                                                    <input type="text" name="edit_rx_instructions[]" class="full" placeholder="Instructions">
                                                                </div>

                                                                <button
                                                                    type="button"
                                                                    class="btn-remove-rx"
                                                                    onclick="this.closest('.prescription-entry').remove()"
                                                                >
                                                                    Remove
                                                                </button>

                                                            </div>

                                                        <?php endif; ?>

                                                    </div>
                                                </div>

                                                <!-- Lab Requests (rows, mirrors the consultation form) -->
                                                <?php $labEditRows = splitLabRequestRows($v['lab']); ?>

                                                <div class="tl-edit-field tl-edit-wide">
                                                    <div class="tl-edit-subhead">
                                                        <label>Lab Test Requests</label>
                                                        <button
                                                            type="button"
                                                            class="btn-add-sm"
                                                            onclick="addEditLabRow(
                                                                <?= (int)$v['id'] ?>
                                                            )"
                                                        >
                                                            + Add
                                                        </button>
                                                    </div>

                                                    <div id="edit-lab-list-<?= (int)$v['id'] ?>">

                                                        <?php foreach ($labEditRows as $labRow): ?>

                                                            <div class="lab-entry">
                                                                <div class="prescription-row">
                                                                    <input type="text" name="edit_lab_requests[]"
                                                                           placeholder="Test to request (e.g. Complete Blood Count)"
                                                                           value="<?= htmlspecialchars($labRow) ?>">
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    class="btn-remove-rx"
                                                                    onclick="this.closest('.lab-entry').remove()"
                                                                >
                                                                    Remove
                                                                </button>
                                                            </div>

                                                        <?php endforeach; ?>

                                                        <?php if (empty($labEditRows)): ?>

                                                            <div class="lab-entry">
                                                                <div class="prescription-row">
                                                                    <input type="text" name="edit_lab_requests[]"
                                                                           placeholder="Test to request (e.g. Complete Blood Count)">
                                                                </div>
                                                                <button
                                                                    type="button"
                                                                    class="btn-remove-rx"
                                                                    onclick="this.closest('.lab-entry').remove()"
                                                                >
                                                                    Remove
                                                                </button>
                                                            </div>

                                                        <?php endif; ?>

                                                    </div>
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

        <?php if (!empty($patients)): ?>

        <!-- PAGINATION -->
        <div class="pagination-bar" data-pagination>

            <div class="pagination-row">

                <button type="button" class="pagination-btn" data-pagination-prev>Previous</button>

                <span class="pagination-label" data-pagination-label>Page 1 of 1</span>

                <button type="button" class="pagination-btn" data-pagination-next>Next</button>

            </div>

        </div>

        <?php endif; ?>


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
| RECORDS FILTER MATCHER
|--------------------------------------------------------------------------
*/

function recordMatches(item)
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


    if (
        q &&
        !name.includes(q) &&
        !diagnosis.includes(q)
    ) {
        return false;
    }


    if (
        from &&
        recordDate &&
        recordDate < from
    ) {
        return false;
    }


    if (
        to &&
        recordDate &&
        recordDate > to
    ) {
        return false;
    }


    return true;

}


/*
|--------------------------------------------------------------------------
| APPLY FILTERS
|--------------------------------------------------------------------------
*/

function applyFilters()
{

    /* When pagination is active, delegate filtering to the pager so
       the filters and page slicing stay in sync. The pager's
       onPageChange keeps the count + empty message up to date. */
    if (window.recordsPager) {
        window.recordsPager.refresh();
        return;
    }


    let visible = 0;


    items.forEach(item =>
    {

        const match = recordMatches(item);


        item.style.display =
            match ? '' : 'none';


        if (match) {
            visible++;
        }

    });


    countEl.textContent =
        `(${visible} patient${visible === 1 ? '' : 's'})`;


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
| EDIT FORM - ADD PRESCRIPTION ROW
|--------------------------------------------------------------------------
*/

function addEditRxRow(consultationId) {

    const list =
        document.getElementById(
            'edit-rx-list-' + consultationId
        );

    if (!list) {
        return;
    }

    const entry =
        document.createElement('div');

    entry.className =
        'prescription-entry';

    entry.innerHTML = `

        <div class="prescription-row">

            <input
                type="text"
                name="edit_rx_name[]"
                placeholder="Medication"
            >

            <input
                type="text"
                name="edit_rx_dosage[]"
                placeholder="Dosage & Form"
            >

            <input
                type="text"
                name="edit_rx_frequency[]"
                placeholder="Frequency"
            >

            <input
                type="text"
                name="edit_rx_duration[]"
                placeholder="Duration"
            >

        </div>

        <div class="prescription-row">

            <input
                type="text"
                name="edit_rx_instructions[]"
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


/*
|--------------------------------------------------------------------------
| EDIT FORM - ADD LAB REQUEST ROW
|--------------------------------------------------------------------------
*/

function addEditLabRow(consultationId) {

    const list =
        document.getElementById(
            'edit-lab-list-' + consultationId
        );

    if (!list) {
        return;
    }

    const entry =
        document.createElement('div');

    entry.className =
        'lab-entry';

    entry.innerHTML = `

        <div class="prescription-row">

            <input
                type="text"
                name="edit_lab_requests[]"
                placeholder="Test to request (e.g. Complete Blood Count)"
            >

        </div>

        <button
            type="button"
            class="btn-remove-rx"
            onclick="this.closest('.lab-entry').remove()"
        >

            Remove

        </button>
    `;

    list.appendChild(entry);
}


/*
|--------------------------------------------------------------------------
| EDIT FORM - FOLLOW-UP DATE AVAILABILITY
| Suggestion only: the doctor views department availability and suggests
| a date; the patient schedules the appointment themselves.
|--------------------------------------------------------------------------
*/

function checkEditFollowupAvailability(consultationId, dateVal) {

    const box =
        document.getElementById(
            'edit-fu-availability-' + consultationId
        );

    if (!box) {
        return;
    }

    if (!dateVal) {
        box.style.display = 'none';
        return;
    }

    box.style.display = '';
    box.innerHTML =
        '<span class="fu-spin"></span> Checking department availability\u2026';

    fetch(
        'doctor_queue.php?action=check_availability&date=' +
        encodeURIComponent(dateVal)
    )
        .then(function (r) {
            return r.json();
        })
        .then(function (d) {
            if (!d.ok) {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--unavail"></span> Error: ' +
                    d.error;
                return;
            }
            if (!d.open) {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--unavail"></span> ' +
                    d.message;
                return;
            }
            let sessionInfo = '';
            if (d.sessions && d.sessions.length > 0) {
                sessionInfo = ' (' + d.day_name + ': ' + d.sessions.join(', ') + ')';
            }
            if (d.available) {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--avail"></span> ' +
                    d.department + ' — ' + d.remaining + ' of ' +
                    d.max_per_day + ' slots open on ' + d.date +
                    sessionInfo;
            } else {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--unavail"></span> ' +
                    d.department + ' is fully booked on ' + d.date + ' (' +
                    d.appointments + '/' + d.max_per_day + ')' +
                    sessionInfo;
            }
        })
        .catch(function () {
            box.innerHTML =
                '<span class="fu-avail-dot fu-avail-dot--unavail"></span> ' +
                'Could not check availability.';
        });
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


/*
|--------------------------------------------------------------------------
| TOGGLE FULL VITALS HISTORY
|--------------------------------------------------------------------------
*/

function toggleVitalsHistory(btn) {

    const panel = btn.closest('.vh-history-panel');

    if (!panel) {
        return;
    }

    const rows = panel.querySelectorAll('tr.vh-more-row');

    const expanding = btn.textContent.indexOf('All') !== -1;

    rows.forEach(function (row) {
        if (expanding) {
            row.removeAttribute('hidden');
        } else {
            row.setAttribute('hidden', 'hidden');
        }
    });

    btn.textContent = expanding
        ? 'Show Less Vitals History'
        : 'View All Vitals History';
}


/*
|--------------------------------------------------------------------------
| RECORDS PAGINATION
|--------------------------------------------------------------------------
*/

window.recordsPager = attachPagination({
    bar: document.querySelector('[data-pagination]'),
    items: function () {
        return items;
    },
    perPage: 5,
    isItemVisible: recordMatches,
    onPageChange: function (page, totalPages, visibleCount) {

        countEl.textContent =
            `(${visibleCount} patient${visibleCount === 1 ? '' : 's'})`;


        emptyEl.style.display =
            visibleCount === 0 ? 'block' : 'none';

    }
});

</script>



<script src="../assets/js/responsive_nav.js"></script>
</body>
</html>