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
require_once __DIR__ . '/../includes/status_constants.php';
require_once __DIR__ . '/../includes/pdf_helper.php';
require_once __DIR__ . '/../includes/vitals.php';


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
    header('Location: ../auth/login.php');
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

// Clean up treatment plan text to remove redundant labels and duplicate prescriptions
function cleanTreatmentPlanText(string $text): string
{
    // Remove leading "Treatment Plan:" or "Treatment:" labels
    $text = preg_replace('/^(Treatment Plan:|Treatment:)\s*/i', '', trim($text));
    
    // Remove "Prescriptions:" section and everything after it (including bullet list)
    $text = preg_replace('/\s*Prescriptions:[\s\S]*$/i', '', $text);
    
    // Clean up extra whitespace
    $text = trim($text);
    
    return $text;
}


/* ================================================================
   PATIENT FLAGS
   ================================================================
   Computes warning badges shown across the queue, search and
   records pages:
     - Allergy        (from patient Allergies column)
     - High Risk      (heuristic: certain chronic conditions in
                       PastMedicalCondition)
     - Lab Pending    (an Ongoing consultation with a LabRequest set)
   Returns an ordered associative array of flag => label.
   ================================================================ */

function patientFlags(
    array $patient,
    bool $labPending = false
): array {
    $flags = [];

    $allergiesRaw = $patient['allergies'] ?? '';

    $allergies = is_array($allergiesRaw)
        ? trim(implode(',', array_map('trim', $allergiesRaw)))
        : trim((string)$allergiesRaw);

    if ($allergies !== '') {
        $flags['allergy'] = 'Allergy';
    }

    $conditions = strtolower(trim((string)($patient['past_medical_condition'] ?? '')));

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
        if (strpos($conditions, $term) !== false) {
            $flags['high_risk'] = 'High Risk';
            break;
        }
    }

    if ($labPending) {
        $flags['lab_pending'] = 'Lab Pending';
    }

    return $flags;
}


/**
 * Render SOAP-structured clinical notes as readable HTML.
 *
 * Accepts either the structured string stored in the Notes column
 * (SUBJECTIVE:/OBJECTIVE:/ASSESSMENT:/PLAN: sections) or a plain,
 * legacy note string. Returns HTML with section labels in bold.
 */
function renderSoapNotes(string $text): string
{
    $text = trim($text);

    if ($text === '') {
        return '';
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

        $html = '';

        foreach ($matches as $match) {

            $label = strtoupper($match[1]);
            $body  = nl2br(htmlspecialchars(trim($match[2])));

            $html .=
                '<div class="soap-block">' .
                '<div class="soap-label">' .
                htmlspecialchars($label) .
                '</div>' .
                '<div class="soap-body">' .
                $body .
                '</div>' .
                '</div>';
        }

        return $html;
    }

    // Legacy / unstructured note
    return nl2br(htmlspecialchars($text));
}



/* ================================================================
   SAVE CONSULTATION
================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save_consultation' &&
    !isset($_POST['print_action'])
) {

    $appointmentID = (int)($_POST['appointment_id'] ?? 0);
    $patientID = (int)($_POST['patient_id'] ?? 0);

    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatmentPlan = trim($_POST['treatment_plan'] ?? '');

    /* SOAP-format clinical notes */
    $soapSubjective = trim($_POST['soap_subjective'] ?? '');
    $soapObjective = trim($_POST['soap_objective'] ?? '');
    $soapAssessment = trim($_POST['soap_assessment'] ?? '');
    $soapPlan = trim($_POST['soap_plan'] ?? '');

    /* Assemble structured clinical notes (stored in the Notes column) */
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

    $clinicalNotes = implode("\n\n", $soapParts);

    $bloodPressure = trim($_POST['vital_bp'] ?? '');
    $temperature = trim($_POST['vital_temp'] ?? '');
    $pulseRate = trim($_POST['vital_pulse'] ?? '');

    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');

    $followUpDate = trim($_POST['follow_up_date'] ?? '');

    if ($followUpDate === '') {
        $followUpDate = null;
    }


    /* ------------------------------------------------------------
       TREATMENT PLAN
       (Prescriptions are stored separately in the prescriptions /
       prescription_items tables, so only the treatment plan text
       is written to the Treatment column here.)
    ------------------------------------------------------------ */

    $finalTreatment = $treatmentPlan;


    /* ------------------------------------------------------------
       LABORATORY / DIAGNOSTIC REQUESTS
       (One test per row in the form; stored as a newline-separated
       text block in the LabRequest column.)
    ------------------------------------------------------------ */

    $labRequestsSaved = [];

    foreach (($_POST['lab_requests'] ?? []) as $req) {
        $req = trim((string)$req);

        if ($req !== '') {
            $labRequestsSaved[] = $req;
        }
    }

    $labRequestsText = implode("\n", $labRequestsSaved);


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
                LabRequest = ?,
                FollowUpDate = ?,
                Status = '" . CONSULTATION_STATUS_COMPLETED . "',
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
            'sssssssssssii',
            $chiefComplaint,
            $diagnosis,
            $finalTreatment,
            $clinicalNotes,
            $labRequestsText,
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
                LabRequest,
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
                ?,
                '" . CONSULTATION_STATUS_COMPLETED . "',
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
            'iiisssssssssss',
            $appointmentID,
            $patientID,
            $staffID,
            $consultationDate,
            $consultationTime,
            $chiefComplaint,
            $diagnosis,
            $finalTreatment,
            $clinicalNotes,
            $labRequestsText,
            $followUpDate,
            $bloodPressure,
            $temperature,
            $pulseRate
        );

        mysqli_stmt_execute($insertStmt);

        $consultationID =
            (int) mysqli_insert_id($conn);

        mysqli_stmt_close($insertStmt);
    }


    /* ------------------------------------------------------------
       SAVE PRESCRIPTIONS (header + items)
    ------------------------------------------------------------ */

    if (!empty($consultationID)) {

        $deleteRxSql = "
            DELETE pi
            FROM prescription_items pi
            INNER JOIN prescriptions pr
                ON pi.PrescriptionID = pr.PrescriptionID
            WHERE pr.ConsultationID = ?
        ";

        $deleteRxStmt =
            mysqli_prepare($conn, $deleteRxSql);

        mysqli_stmt_bind_param(
            $deleteRxStmt,
            'i',
            $consultationID
        );

        mysqli_stmt_execute($deleteRxStmt);

        mysqli_stmt_close($deleteRxStmt);


        $deletePrescSql = "
            DELETE FROM prescriptions
            WHERE ConsultationID = ?
        ";

        $deletePrescStmt =
            mysqli_prepare($conn, $deletePrescSql);

        mysqli_stmt_bind_param(
            $deletePrescStmt,
            'i',
            $consultationID
        );

        mysqli_stmt_execute($deletePrescStmt);

        mysqli_stmt_close($deletePrescStmt);


        $rxNames = $_POST['rx_name'] ?? [];
        $rxDosages = $_POST['rx_dosage'] ?? [];
        $rxFrequencies = $_POST['rx_frequency'] ?? [];
        $rxDurations = $_POST['rx_duration'] ?? [];
        $rxInstructions = $_POST['rx_instructions'] ?? [];

        $prescDate = date('Y-m-d');

        $insertPrescSql = "
            INSERT INTO prescriptions
                (ConsultationID, PrescribedDate)
            VALUES (?, ?)
        ";

        $insertPrescStmt =
            mysqli_prepare($conn, $insertPrescSql);

        mysqli_stmt_bind_param(
            $insertPrescStmt,
            'is',
            $consultationID,
            $prescDate
        );

        mysqli_stmt_execute($insertPrescStmt);

        $newPrescriptionID =
            (int) mysqli_insert_id($conn);

        mysqli_stmt_close($insertPrescStmt);


        $insertItemSql = "
            INSERT INTO prescription_items
                (PrescriptionID, MedicineName, Dosage, Frequency, Duration, Instructions)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $insertItemStmt =
            mysqli_prepare($conn, $insertItemSql);

        foreach ($rxNames as $i => $name) {

            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $dosage = trim($rxDosages[$i] ?? '');
            $frequency = trim($rxFrequencies[$i] ?? '');
            $duration = trim($rxDurations[$i] ?? '');
            $instructions = trim($rxInstructions[$i] ?? '');

            mysqli_stmt_bind_param(
                $insertItemStmt,
                'isssss',
                $newPrescriptionID,
                $name,
                $dosage,
                $frequency,
                $duration,
                $instructions
            );

            mysqli_stmt_execute($insertItemStmt);
        }

        mysqli_stmt_close($insertItemStmt);
    }


    /* ------------------------------------------------------------
       MARK APPOINTMENT COMPLETED
    ------------------------------------------------------------ */

    $updateAppointmentSql = "
        UPDATE appointments
        SET Status = '" . APPT_STATUS_COMPLETED . "'
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

    header('Location: doctor_queue.php?saved=1&appt=' . $appointmentID . '&pid=' . $patientID);
    exit;
}

/* ================================================================
   GENERATE CLINICAL DOCUMENT (PDF)
   ================================================================
   Triggered by "Generate Prescription", "Medical Certificate" or
   "Laboratory Request" buttons in the consultation form. Builds the
   PDF from the CURRENT (unsaved) form data and streams it to the
   browser, so the doctor can print before/without saving.
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['print_action'])
) {

    $printType = (string)($_POST['print_action'] ?? 'prescription');

    if (!in_array($printType, ['prescription', 'medical_certificate', 'lab_request', 'consultation_report'], true)) {
        $printType = 'prescription';
    }

    $apptID = (int)($_POST['appointment_id'] ?? 0);
    $patID = (int)($_POST['patient_id'] ?? 0);

    if ($apptID <= 0 || $patID <= 0) {
        header('Location: doctor_queue.php?error=invalid');
        exit;
    }

    /* Verify the appointment belongs to this doctor. */
    $verifySql = "
        SELECT a.AppointmentID, a.AppointmentDate, a.Purpose,
               p.UserID, u.FirstName, u.MiddleName, u.LastName, u.Sex, u.DateOfBirth, u.Address, u.ContactNumber,
               p.BloodType, p.Allergies, p.PastMedicalCondition
        FROM appointments a
        INNER JOIN patients p ON a.PatientID = p.PatientID
        INNER JOIN users u   ON p.UserID = u.UserID
        WHERE a.AppointmentID = ?
          AND a.PatientID = ?
          AND a.StaffID = ?
        LIMIT 1
    ";

    $verifyStmt = mysqli_prepare($conn, $verifySql);

    if (!$verifyStmt) {
        die('Failed to prepare document request.');
    }

    mysqli_stmt_bind_param($verifyStmt, 'iii', $apptID, $patID, $staffID);
    mysqli_stmt_execute($verifyStmt);
    $verifyResult = mysqli_stmt_get_result($verifyStmt);
    $appt = mysqli_fetch_assoc($verifyResult);
    mysqli_stmt_close($verifyStmt);

    if (!$appt) {
        header('Location: doctor_queue.php?error=unauthorized');
        exit;
    }

    /* Patient demographics. */
    $patientName = trim(
        ($appt['FirstName'] ?? '') . ' ' .
        ($appt['MiddleName'] ?? '') . ' ' .
        ($appt['LastName'] ?? '')
    );

    $age = '';
    if (!empty($appt['DateOfBirth'])) {
        $dob = new DateTime((string)$appt['DateOfBirth']);
        $age = (string)$dob->diff(new DateTime())->y;
    }

    /* Read clinical data from the (unsaved) form. */
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatmentPlan = trim($_POST['treatment_plan'] ?? '');

    $soapSub = trim($_POST['soap_subjective'] ?? '');
    $soapObj = trim($_POST['soap_objective'] ?? '');
    $soapAssess = trim($_POST['soap_assessment'] ?? '');
    $soapPlanTxt = trim($_POST['soap_plan'] ?? '');

    $soapParts = [];
    foreach ([
        'SUBJECTIVE' => $soapSub,
        'OBJECTIVE' => $soapObj,
        'ASSESSMENT' => $soapAssess,
        'PLAN' => $soapPlanTxt,
    ] as $k => $v) {
        if ($v !== '') {
            $soapParts[] = $k . ":\n" . $v;
        }
    }
    $notes = implode("\n\n", $soapParts);

    $bp = trim($_POST['vital_bp'] ?? '');
    $temp = trim($_POST['vital_temp'] ?? '');
    $pulse = trim($_POST['vital_pulse'] ?? '');

    $vitalsParts = [];
    if ($bp !== '') {
        $vitalsParts[] = 'BP: ' . $bp;
    }
    if ($temp !== '') {
        $vitalsParts[] = 'Temp: ' . $temp;
    }
    if ($pulse !== '') {
        $vitalsParts[] = 'HR: ' . $pulse;
    }
    $vitals = implode('  |  ', $vitalsParts);

    $followUp = trim($_POST['follow_up_date'] ?? '');

    $rxNames        = $_POST['rx_name'] ?? [];
    $rxDosages      = $_POST['rx_dosage'] ?? [];
    $rxFrequencies  = $_POST['rx_frequency'] ?? [];
    $rxDurations    = $_POST['rx_duration'] ?? [];
    $rxInstructions = $_POST['rx_instructions'] ?? [];

    $rxItems = [];
    foreach ($rxNames as $i => $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }
        $rxItems[] = [
            'MedicineName' => $name,
            'Dosage' => trim($rxDosages[$i] ?? ''),
            'Frequency' => trim($rxFrequencies[$i] ?? ''),
            'Duration' => trim($rxDurations[$i] ?? ''),
            'Instructions' => trim($rxInstructions[$i] ?? ''),
        ];
    }

    /* Laboratory / diagnostic requests: one per row. */
    $labRequests = [];
    foreach (($_POST['lab_requests'] ?? []) as $req) {
        $req = trim((string)$req);
        if ($req !== '') {
            $labRequests[] = $req;
        }
    }

    $allergiesAlerts = trim((string)($appt['Allergies'] ?? ''));

    $data = [
        'clinic_name' => 'MediCare Clinic',
        'clinic_info' => 'MediCare Outpatient Portal | Tel: (02) 1234-5678',
        'doctor_name' => $doctorName,
        'doctor_specialization' => $doctorSpecialization,
        'doctor_license' => '',
        'department' => $doctorDepartment,
        'status' => CONSULTATION_STATUS_ONGOING,
        'consultation_datetime' => date('F j, Y') . '  |  ' . date('g:i A'),
        'patient_name' => $patientName,
        'patient_id' => 'PT-' . str_pad((string)$patID, 3, '0', STR_PAD_LEFT),
        'patient_age' => $age,
        'patient_sex' => (string)($appt['Sex'] ?? ''),
        'patient_address' => (string)($appt['Address'] ?? ''),
        'blood_type' => (string)($appt['BloodType'] ?? ''),
        'allergies_alerts' => $allergiesAlerts,
        'date_issued' => date('Y-m-d'),
        'appointment_date' => (string)($appt['AppointmentDate'] ?? ''),
        'chief_complaint' => trim($_POST['chief_complaint'] ?? ($appt['Purpose'] ?? '')),
        'diagnosis' => $diagnosis,
        'treatment' => $treatmentPlan,
        'notes' => $notes,
        'vitals' => $vitals,
        'follow_up_date' => $followUp,
        'follow_up_instructions' => '',
        'rx_items' => $rxItems,
        'lab_requests' => $labRequests,
    ];

    $pdfBinary = pdf_build($data, $printType);

    $filename = match ($printType) {
        'medical_certificate' => 'medical_certificate_' . $apptID . '.pdf',
        'lab_request' => 'lab_request_' . $apptID . '.pdf',
        'consultation_report' => 'consultation_report_' . $apptID . '.pdf',
        default => 'prescription_' . $apptID . '.pdf',
    };

    if (ob_get_level()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfBinary));
    echo $pdfBinary;
    exit;
}


/* ================================================================
   AJAX: CHECK FOLLOW-UP AVAILABILITY
   ================================================================
   Called by the follow-up modal to show how many appointments a
   doctor already has on a given date.
   Returns JSON: { ok, appointments, max_per_day }
   ================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && $_GET['action'] === 'check_availability'
    && isset($_GET['date'])
) {
    header('Content-Type: application/json');

    $checkDate = $_GET['date'];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkDate)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid date format.']);
        exit;
    }

    $maxPerDay = 10;

    $availStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS cnt
         FROM appointments
         WHERE StaffID = ?
           AND AppointmentDate = ?
           AND Status NOT IN ('" . APPT_STATUS_CANCELLED . "','" . APPT_STATUS_NO_SHOW . "')"
    );

    mysqli_stmt_bind_param($availStmt, 'is', $staffID, $checkDate);
    mysqli_stmt_execute($availStmt);
    $availResult = mysqli_stmt_get_result($availStmt);
    $availRow = mysqli_fetch_assoc($availResult);
    mysqli_stmt_close($availStmt);

    $existingCount = (int)($availRow['cnt'] ?? 0);
    $remaining = max(0, $maxPerDay - $existingCount);

    echo json_encode([
        'ok'           => true,
        'date'         => $checkDate,
        'appointments' => $existingCount,
        'max_per_day'  => $maxPerDay,
        'remaining'    => $remaining,
        'available'    => $remaining > 0,
    ]);
    exit;
}


/* ================================================================
   SCHEDULE FOLLOW-UP APPOINTMENT
   ================================================================
   Triggered by the "Confirm Follow-up" button inside the follow-up
   modal. Creates a new appointment with Status = 'Scheduled' and
   redirects back to the queue with a confirmation message.
   ================================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'schedule_followup'
) {
    $fuPatientID = (int)($_POST['followup_patient_id'] ?? 0);
    $fuDate      = $_POST['followup_date'] ?? '';
    $fuTime      = $_POST['followup_time'] ?? '10:00:00';
    $fuPurpose   = trim($_POST['followup_purpose'] ?? 'Follow-up consultation');

    if (!$fuPatientID || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fuDate)) {
        header('Location: doctor_queue.php?error=Invalid follow-up data.');
        exit;
    }

    $fuDeptID = (int)$doctor['DepartmentID'];

    $fuInsert = mysqli_prepare(
        $conn,
        "INSERT INTO appointments
            (PatientID, StaffID, DepartmentID, AppointmentDate,
             AppointmentTime, Purpose, Status)
         VALUES (?, ?, ?, ?, ?, ?, '" . APPT_STATUS_SCHEDULED . "')"
    );

    mysqli_stmt_bind_param(
        $fuInsert,
        'iiisss',
        $fuPatientID,
        $staffID,
        $fuDeptID,
        $fuDate,
        $fuTime,
        $fuPurpose
    );

    mysqli_stmt_execute($fuInsert);
    $newApptId = mysqli_insert_id($conn);
    mysqli_stmt_close($fuInsert);

    header('Location: doctor_queue.php?followup_scheduled=' . $newApptId);
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
        c.LabRequest,
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
       LAB PENDING
       Any ongoing consultation for this patient with a requested
       lab order flags the patient as having a pending lab.
    ------------------------------------------------------------ */

    $labPending = false;

    $labQuery = "
        SELECT COUNT(*) AS cnt
        FROM consultations
        WHERE PatientID = ?
          AND Status = '" . CONSULTATION_STATUS_ONGOING . "'
          AND LabRequest IS NOT NULL
          AND TRIM(LabRequest) <> ''
    ";

    $labStmt = mysqli_prepare($conn, $labQuery);

    mysqli_stmt_bind_param(
        $labStmt,
        'i',
        $row['PatientID']
    );

    mysqli_stmt_execute($labStmt);
    $labResult = mysqli_stmt_get_result($labStmt);
    $labRow = mysqli_fetch_assoc($labResult);
    mysqli_stmt_close($labStmt);

    if ((int)($labRow['cnt'] ?? 0) > 0) {
        $labPending = true;
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

        $historyItem = [
            'consultation_id' =>
                (int)$row['ConsultationID'],

            'date' =>
                $row['ConsultationDate'],

            'doctor' =>
                $doctorName,

            'diagnosis' =>
                $row['Diagnosis'] ?? '',

            'note' =>
                $row['Notes'] ?? '',

            'treatment' =>
                $row['Treatment'] ?? '',

            'tag' =>
                $row['Treatment'] ?? '',

            'blood_pressure' =>
                $row['BloodPressure'] ?? '',

            'temperature' =>
                $row['Temperature'] ?? '',

            'pulse_rate' =>
                $row['PulseRate'] ?? ''
        ];


        /*
         * Load prescription medications for this consultation
         * (used for medication tags on the summary card and for
         * the full prescription list in the details view).
         */

        $meds = [];
        $rxItems = [];

        $histRxSql = "
            SELECT
                pi.MedicineName,
                pi.Dosage,
                pi.Frequency,
                pi.Duration,
                pi.Instructions
            FROM prescriptions pr
            INNER JOIN prescription_items pi
                ON pr.PrescriptionID = pi.PrescriptionID
            WHERE pr.ConsultationID = ?
            ORDER BY pi.PrescriptionItemID ASC
        ";

        $histRxStmt =
            mysqli_prepare($conn, $histRxSql);

        mysqli_stmt_bind_param(
            $histRxStmt,
            'i',
            $historyItem['consultation_id']
        );

        mysqli_stmt_execute($histRxStmt);

        $histRxResult =
            mysqli_stmt_get_result($histRxStmt);

        while ($hr = mysqli_fetch_assoc($histRxResult)) {

            $medName = trim($hr['MedicineName'] ?? '');

            if ($medName !== '') {
                $meds[] = $medName;
            }

            $rxItems[] = [
                'medicine' =>
                    $hr['MedicineName'] ?? '',
                'dosage' =>
                    $hr['Dosage'] ?? '',
                'frequency' =>
                    $hr['Frequency'] ?? '',
                'duration' =>
                    $hr['Duration'] ?? '',
                'instructions' =>
                    $hr['Instructions'] ?? ''
            ];
        }

        mysqli_stmt_close($histRxStmt);

        $historyItem['medications'] = $meds;
        $historyItem['prescription_items'] = $rxItems;

        $history[] = $historyItem;
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
            $history,

        'flags' =>
            patientFlags(
                [
                    'allergies'              => $allergies,
                    'past_medical_condition' => $row['PastMedicalCondition'] ?? '',
                ],
                $labPending
            )
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
                        CONSULTATION_STATUS_ONGOING
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
                    SET Status = '" . APPT_STATUS_CALLED . "'
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

            /*
             * Parse SOAP-format clinical notes back into
             * individual fields for pre-filling the form.
             */

            $soapDefaults =
                [
                    'soap_subjective' => '',
                    'soap_objective' => '',
                    'soap_assessment' => '',
                    'soap_plan' => ''
                ];

            $soapPattern =
                '/^(SUBJECTIVE|OBJECTIVE|ASSESSMENT|PLAN):\s*(.*?)(?=^(?:SUBJECTIVE|OBJECTIVE|ASSESSMENT|PLAN):|\z)/msi';

            if (

                preg_match_all(
                    $soapPattern,
                    $consultPatient['notes'],
                    $soapMatches,
                    PREG_SET_ORDER
                ) &&
                !empty($soapMatches)
            ) {

                foreach ($soapMatches as $soapMatch) {

                    $soapKey =
                        strtolower($soapMatch[1]);

                    $soapValue =
                        trim($soapMatch[2]);

                    $soapDefaults[
                        'soap_' . $soapKey
                    ] = $soapValue;
                }
            } elseif (

                $consultPatient['notes'] !== ''
            ) {

                /*
                 * Legacy / unstructured notes: put the whole
                 * text into the Subjective field so nothing
                 * is lost.
                 */

                $soapDefaults['soap_subjective'] =
                    $consultPatient['notes'];
            }

            $consultPatient['soap_subjective'] =
                $soapDefaults['soap_subjective'];

            $consultPatient['soap_objective'] =
                $soapDefaults['soap_objective'];

            $consultPatient['soap_assessment'] =
                $soapDefaults['soap_assessment'];

            $consultPatient['soap_plan'] =
                $soapDefaults['soap_plan'];

            $consultPatient['blood_pressure'] =
                $consultation['BloodPressure'] ?? '';

            $consultPatient['temperature'] =
                $consultation['Temperature'] ?? '';

            $consultPatient['pulse_rate'] =
                $consultation['PulseRate'] ?? '';

            /*
             * Load nurse / staff recorded pre-consultation vitals (vitals table).
             * These are stored separately from the consultation record so they
             * can be captured before the doctor opens the visit.
             */

            $consultPatient['nurse_vitals'] = [];
            $consultPatient['has_nurse_vitals'] = false;

            $nurseVitalsStmt = mysqli_prepare(
                $conn,
                'SELECT BloodPressure, Temperature, PulseRate, RespiratoryRate,
                        Weight, Height, OxygenSaturation, RecordedAt
                 FROM vitals
                 WHERE AppointmentID = ? AND PatientID = ?
                 ORDER BY VitalID DESC
                 LIMIT 5'
            );

            mysqli_stmt_bind_param(
                $nurseVitalsStmt,
                'ii',
                $consultPatient['appointment_id'],
                $consultPatient['patient_id']
            );

            mysqli_stmt_execute($nurseVitalsStmt);

            $nurseVitalsResult =
                mysqli_stmt_get_result($nurseVitalsStmt);

            while ($nvRow = mysqli_fetch_assoc($nurseVitalsResult)) {
                $consultPatient['nurse_vitals'][] = $nvRow;
            }

            if (!empty($consultPatient['nurse_vitals'])) {

                $latestNV = $consultPatient['nurse_vitals'][0];

                $consultPatient['has_nurse_vitals'] = true;

                // Pre-fill the doctor form from the nurse's latest reading.
                if (($latestNV['BloodPressure'] ?? '') !== '') {
                    $consultPatient['blood_pressure'] =
                        $latestNV['BloodPressure'];
                }

                if (($latestNV['Temperature'] ?? '') !== '') {
                    $consultPatient['temperature'] =
                        $latestNV['Temperature'];
                }

                if (($latestNV['PulseRate'] ?? '') !== '') {
                    $consultPatient['pulse_rate'] =
                        $latestNV['PulseRate'];
                }

                $consultPatient['respiratory_rate'] =
                    $latestNV['RespiratoryRate'] ?? '';

                $consultPatient['weight'] =
                    $latestNV['Weight'] ?? '';

                $consultPatient['height'] =
                    $latestNV['Height'] ?? '';

                $consultPatient['oxygen_saturation'] =
                    $latestNV['OxygenSaturation'] ?? '';
            }

            $consultPatient['follow_up_date'] =
                $consultation['FollowUpDate'] ?? '';


            /*
             * Load saved lab requests so the form can be re-populated.
             * Stored as newline-separated text in the LabRequest column.
             */

            $consultPatient['lab_requests'] = [];

            $savedLabText = $consultation['LabRequest'] ?? '';

            if ($savedLabText !== '') {

                foreach (
                    preg_split('/\r\n|\r|\n/', $savedLabText) as $savedReq
                ) {
                    $savedReq = trim($savedReq);

                    if ($savedReq !== '') {
                        $consultPatient['lab_requests'][] = $savedReq;
                    }
                }
            }


            /*
             * Load saved prescriptions to re-populate the form.
             */

            $consultPatient['prescriptions'] = [];

            $rxSql = "
                SELECT
                    pi.MedicineName,
                    pi.Dosage,
                    pi.Frequency,
                    pi.Duration,
                    pi.Instructions
                FROM prescriptions pr
                INNER JOIN prescription_items pi
                    ON pr.PrescriptionID = pi.PrescriptionID
                WHERE pr.ConsultationID = ?
                ORDER BY pi.PrescriptionItemID ASC
            ";

            $rxStmt =
                mysqli_prepare($conn, $rxSql);

            mysqli_stmt_bind_param(
                $rxStmt,
                'i',
                $consultPatient['consultation_id']
            );

            mysqli_stmt_execute($rxStmt);

            $rxResult =
                mysqli_stmt_get_result($rxStmt);

            while ($rx = mysqli_fetch_assoc($rxResult)) {
                $consultPatient['prescriptions'][] = $rx;
            }

            mysqli_stmt_close($rxStmt);
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
            href="../auth/logout.php"
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

        <textarea
            id="diagnosis"
            name="diagnosis"
            placeholder="Primary: ...  Secondary: ..."
        ><?= htmlspecialchars(
            $consultPatient['diagnosis'] ?? ''
        ) ?></textarea>

    </div>


    <div class="form-field">
        <label>Clinical Notes — SOAP</label>
    </div>

    <div class="form-field">
        <label for="soap-subjective">Subjective</label>
        <textarea
            id="soap-subjective"
            name="soap_subjective"
            placeholder="Chief complaint in patient's own words, history of present illness..."
        ><?= htmlspecialchars(
            $consultPatient['soap_subjective'] ?? ''
        ) ?></textarea>
    </div>

    <div class="form-field">
        <label for="soap-objective">Objective</label>
        <textarea
            id="soap-objective"
            name="soap_objective"
            placeholder="Observations, examination findings, vitals, test results..."
        ><?= htmlspecialchars(
            $consultPatient['soap_objective'] ?? ''
        ) ?></textarea>
    </div>

    <div class="form-field">
        <label for="soap-assessment">Assessment</label>
        <textarea
            id="soap-assessment"
            name="soap_assessment"
            placeholder="Working diagnosis, differentials, assessment of findings..."
        ><?= htmlspecialchars(
            $consultPatient['soap_assessment'] ?? ''
        ) ?></textarea>
    </div>

    <div class="form-field">
        <label for="soap-plan">Plan</label>
        <textarea
            id="soap-plan"
            name="soap_plan"
            placeholder="Treatment, medications, tests, follow-up, referrals..."
        ><?= htmlspecialchars(
            $consultPatient['soap_plan'] ?? ''
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

        <?php
            $savedRxList = $consultPatient['prescriptions'] ?? [];
        ?>

        <?php if (!empty($savedRxList)): ?>

            <?php foreach ($savedRxList as $rx): ?>

            <div class="prescription-entry">

                <div class="prescription-row">

                    <input
                        type="text"
                        name="rx_name[]"
                        placeholder="Medication"
                        value="<?= htmlspecialchars($rx['MedicineName'] ?? '') ?>"
                    >

                    <input
                        type="text"
                        name="rx_dosage[]"
                        placeholder="Dosage & Form"
                        value="<?= htmlspecialchars($rx['Dosage'] ?? '') ?>"
                    >

                    <input
                        type="text"
                        name="rx_frequency[]"
                        placeholder="Frequency"
                        value="<?= htmlspecialchars($rx['Frequency'] ?? '') ?>"
                    >

                    <input
                        type="text"
                        name="rx_duration[]"
                        placeholder="Duration"
                        value="<?= htmlspecialchars($rx['Duration'] ?? '') ?>"
                    >

                </div>


                <div class="prescription-row">

                    <input
                        type="text"
                        name="rx_instructions[]"
                        class="full"
                        placeholder="Instructions"
                        value="<?= htmlspecialchars($rx['Instructions'] ?? '') ?>"
                    >

                </div>

            </div>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="prescription-entry">

                <div class="prescription-row">

                    <input
                        type="text"
                        name="rx_name[]"
                        placeholder="Medication"
                    >

                    <input
                        type="text"
                        name="rx_dosage[]"
                        placeholder="Dosage & Form"
                    >

                    <input
                        type="text"
                        name="rx_frequency[]"
                        placeholder="Frequency"
                    >

                    <input
                        type="text"
                        name="rx_duration[]"
                        placeholder="Duration"
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

        <?php endif; ?>

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

        <div class="past-consult-item pc-card">

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


            <?php if (!empty($c['medications'])): ?>

            <div class="pc-tags">

                <?php foreach ($c['medications'] as $med): ?>

                <span class="pc-tag">

                    <?= htmlspecialchars($med) ?>

                </span>

                <?php endforeach; ?>

            </div>

            <?php endif; ?>


            <button
                type="button"
                class="pc-view-btn"
                onclick="toggleConsultDetails(this)"
            >

                View Details

            </button>


            <div class="pc-full" hidden>

                <?php if (!empty($c['note'])): ?>

                <div class="pc-section">

                    <div class="pc-section-title">

                        Clinical Notes

                    </div>

                    <div class="pc-note soap-note">

                        <?= renderSoapNotes($c['note']) ?>

                    </div>

                </div>

                <?php endif; ?>


                <?php if (!empty($c['treatment'])): ?>

                <div class="pc-section">

                    <div class="pc-section-title">

                        Treatment Plan

                    </div>

                    <div class="pc-note">

                        <?= nl2br(
                            htmlspecialchars(
                                cleanTreatmentPlanText($c['treatment'])
                            )
                        ) ?>

                    </div>

                </div>

                <?php endif; ?>


                <?php if (!empty($c['prescription_items'])): ?>

                <div class="pc-section">

                    <div class="pc-section-title">

                        Prescriptions

                    </div>

                    <div class="pc-rx-list">

                        <?php foreach ($c['prescription_items'] as $rx): ?>

                        <div class="pc-rx-item">

                            <div class="pc-rx-med">

                                <?= htmlspecialchars($rx['medicine']) ?>

                            </div>

                            <?php if (!empty($rx['dosage'])): ?>
                            <div class="pc-rx-meta">Dosage: <?= htmlspecialchars($rx['dosage']) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($rx['frequency'])): ?>
                            <div class="pc-rx-meta">Frequency: <?= htmlspecialchars($rx['frequency']) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($rx['duration'])): ?>
                            <div class="pc-rx-meta">Duration: <?= htmlspecialchars($rx['duration']) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($rx['instructions'])): ?>
                            <div class="pc-rx-meta">Instructions: <?= htmlspecialchars($rx['instructions']) ?></div>
                            <?php endif; ?>

                        </div>

                        <?php endforeach; ?>

                    </div>

                </div>

                <?php endif; ?>


                <?php if (
                    !empty($c['blood_pressure']) ||
                    !empty($c['temperature']) ||
                    !empty($c['pulse_rate'])
                ): ?>

                <div class="pc-section">

                    <div class="pc-section-title">

                        Vitals

                    </div>

                    <div class="pc-vitals">

                        <?php if (!empty($c['blood_pressure'])): ?>
                        <span>BP: <?= htmlspecialchars($c['blood_pressure']) ?></span>
                        <?php endif; ?>

                        <?php if (!empty($c['temperature'])): ?>
                        <span>Temp: <?= htmlspecialchars($c['temperature']) ?>°C</span>
                        <?php endif; ?>

                        <?php if (!empty($c['pulse_rate'])): ?>
                        <span>Pulse: <?= htmlspecialchars($c['pulse_rate']) ?> bpm</span>
                        <?php endif; ?>

                    </div>

                </div>

                <?php endif; ?>

            </div>

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


<div class="consult-panel">

    <div class="consult-panel-head">

        <div class="consult-panel-title">

            Laboratory / Diagnostic Requests

        </div>


        <button
            type="button"
            class="btn-add-sm"
            onclick="addLabRequestRow()"
        >

            + Add Test

        </button>

    </div>


    <div id="lab-requests-list">

        <?php
            $savedLabList = $_POST['lab_requests'] ?? ($consultPatient['lab_requests'] ?? []);
        ?>

        <?php if (!empty($savedLabList)): ?>

            <?php foreach ($savedLabList as $req): ?>

            <div class="lab-entry">

                <div class="prescription-row">

                    <input
                        type="text"
                        name="lab_requests[]"
                        placeholder="Test to request (e.g. Complete Blood Count)"
                        value="<?= htmlspecialchars($req ?? '') ?>"
                    >

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

        <?php else: ?>

            <div class="lab-entry">

                <div class="prescription-row">

                    <input
                        type="text"
                        name="lab_requests[]"
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

            </div>

        <?php endif; ?>

    </div>

</div>


<div class="consult-save-actions">

    <button
        type="submit"
        class="btn-print-consult"
        name="print_action"
        value="prescription"
    >

        Generate Prescription

    </button>

    <button
        type="submit"
        class="btn-print-consult"
        name="print_action"
        value="medical_certificate"
    >

        Medical Certificate

    </button>

    <button
        type="submit"
        class="btn-print-consult"
        name="print_action"
        value="lab_request"
    >

        Lab Request

    </button>

    <button
        type="submit"
        class="btn-print-consult btn-print-report"
        name="print_action"
        value="consultation_report"
    >

        Print Consultation Report

    </button>

    <button
        type="submit"
        class="btn-save-consult"
        name="save_consultation"
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
                placeholder="Medication"
            >

            <input
                type="text"
                name="rx_dosage[]"
                placeholder="Dosage & Form"
            >

            <input
                type="text"
                name="rx_frequency[]"
                placeholder="Frequency"
            >

            <input
                type="text"
                name="rx_duration[]"
                placeholder="Duration"
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

function addLabRequestRow()
{
    const list =
        document.getElementById(
            'lab-requests-list'
        );

    const entry =
        document.createElement(
            'div'
        );

    entry.className =
        'lab-entry';

    entry.innerHTML = `

        <div class="prescription-row">

            <input
                type="text"
                name="lab_requests[]"
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

function toggleConsultDetails(btn)
{
    const card =
        btn.closest('.past-consult-item');

    if (!card) {
        return;
    }

    const detail =
        card.querySelector('.pc-full');

    if (!detail) {
        return;
    }

    const isHidden =
        detail.hasAttribute('hidden');

    if (isHidden) {
        detail.removeAttribute('hidden');
        btn.textContent = 'Hide Details';
    } else {
        detail.setAttribute('hidden', '');
        btn.textContent = 'View Details';
    }
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

<div class="queue-alert queue-alert--success">

    Consultation saved successfully.

    <?php if (!empty($_GET['pid'])): ?>

    <button
        type="button"
        class="btn-followup-launch"
        onclick="openFollowupModal(<?= (int)$_GET['pid'] ?>)"
    >
        Schedule Follow-up
    </button>

    <?php endif; ?>

</div>

<?php endif; ?>


<?php if (isset($_GET['followup_scheduled'])): ?>

<div class="queue-alert queue-alert--info">

    Follow-up appointment booked successfully (Appointment #<?= (int)$_GET['followup_scheduled'] ?>).

</div>

<?php endif; ?>


<?php if (isset($_GET['error'])): ?>

<div class="queue-alert queue-alert--error">

    <?= htmlspecialchars($_GET['error']) ?>

</div>

<?php endif; ?>


<!-- ==========================================================
     FOLLOW-UP SCHEDULING MODAL
========================================================== -->

<div id="followupModal" class="fu-modal" style="display:none">

    <div class="fu-modal-backdrop" onclick="closeFollowupModal()"></div>

    <div class="fu-modal-dialog">

        <div class="fu-modal-header">
            <h3>Schedule Follow-up</h3>
            <button type="button" class="fu-modal-close" onclick="closeFollowupModal()">&times;</button>
        </div>

        <div class="fu-modal-body">

            <div class="fu-field">
                <label for="fu_date">Follow-up Date</label>
                <input
                    type="date"
                    id="fu_date"
                    min="<?= date('Y-m-d', strtotime('+1 day')) ?>"
                    onchange="checkFollowupAvailability(this.value)"
                />
            </div>

            <div id="fu_availability" class="fu-availability" style="display:none"></div>

            <div class="fu-field">
                <label for="fu_time">Preferred Time</label>
                <select id="fu_time">
                    <option value="08:00:00">8:00 AM</option>
                    <option value="08:30:00">8:30 AM</option>
                    <option value="09:00:00" selected>9:00 AM</option>
                    <option value="09:30:00">9:30 AM</option>
                    <option value="10:00:00">10:00 AM</option>
                    <option value="10:30:00">10:30 AM</option>
                    <option value="11:00:00">11:00 AM</option>
                    <option value="11:30:00">11:30 AM</option>
                    <option value="13:00:00">1:00 PM</option>
                    <option value="13:30:00">1:30 PM</option>
                    <option value="14:00:00">2:00 PM</option>
                    <option value="14:30:00">2:30 PM</option>
                    <option value="15:00:00">3:00 PM</option>
                    <option value="15:30:00">3:30 PM</option>
                    <option value="16:00:00">4:00 PM</option>
                </select>
            </div>

            <div class="fu-field">
                <label for="fu_purpose">Purpose</label>
                <input
                    type="text"
                    id="fu_purpose"
                    value="Follow-up consultation"
                    maxlength="255"
                />
            </div>

        </div>

        <div class="fu-modal-footer">
            <button type="button" class="fu-btn fu-btn-cancel" onclick="closeFollowupModal()">Cancel</button>
            <button
                type="button"
                id="fu_confirm"
                class="fu-btn fu-btn-confirm"
                disabled
                onclick="submitFollowup()"
            >
                Confirm Follow-up
            </button>
        </div>

    </div>

</div>


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

<?php
    $viewApptDate = $p['appointment_date'] ?? '';
    $viewTarget   = ($viewApptDate === $today)
        ? 'doctor_queue.php?consult=' . (int)$p['appointment_id']
        : 'records.php';
?>

<a
    class="btn-done"
    href="<?= htmlspecialchars($viewTarget) ?>"
>

    <?= ($viewApptDate === $today) ? 'View' : 'Records' ?>

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


/* ==========================================================
   FOLLOW-UP SCHEDULING MODAL
========================================================== */

var _fuPatientId = 0;

function openFollowupModal(patientId) {
    _fuPatientId = patientId;
    document.getElementById('fu_date').value = '';
    document.getElementById('fu_time').value = '09:00:00';
    document.getElementById('fu_purpose').value = 'Follow-up consultation';
    document.getElementById('fu_availability').style.display = 'none';
    document.getElementById('fu_confirm').disabled = true;
    document.getElementById('followupModal').style.display = '';
    document.getElementById('fu_date').focus();
}

function closeFollowupModal() {
    document.getElementById('followupModal').style.display = 'none';
    _fuPatientId = 0;
}

function checkFollowupAvailability(dateVal) {
    var box = document.getElementById('fu_availability');
    var btn = document.getElementById('fu_confirm');

    if (!dateVal) {
        box.style.display = 'none';
        btn.disabled = true;
        return;
    }

    box.style.display = '';
    box.innerHTML = '<span class="fu-spin"></span> Checking availability\u2026';
    btn.disabled = true;

    fetch('doctor_queue.php?action=check_availability&date=' + encodeURIComponent(dateVal))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) {
                box.innerHTML = '<span class="fu-avail-dot fu-avail-dot--unavail"></span> Error: ' + d.error;
                return;
            }
            if (d.available) {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--avail"></span> ' +
                    d.remaining + ' of ' + d.max_per_day + ' slots available on ' + d.date;
                btn.disabled = false;
            } else {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--unavail"></span> ' +
                    'Fully booked on ' + d.date + ' (' + d.appointments + '/' + d.max_per_day + ')';
                btn.disabled = true;
            }
        })
        .catch(function() {
            box.innerHTML = '<span class="fu-avail-dot fu-avail-dot--unavail"></span> Could not check availability.';
            btn.disabled = true;
        });
}

function submitFollowup() {
    var dateVal = document.getElementById('fu_date').value;
    var timeVal = document.getElementById('fu_time').value;
    var purposeVal = document.getElementById('fu_purpose').value;

    if (!_fuPatientId || !dateVal) {
        return;
    }

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'doctor_queue.php';

    var fields = {
        action: 'schedule_followup',
        followup_patient_id: _fuPatientId,
        followup_date: dateVal,
        followup_time: timeVal,
        followup_purpose: purposeVal
    };

    Object.keys(fields).forEach(function(key) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

</script>


<?php endif; ?>


</main>

</div>

</body>

</html>