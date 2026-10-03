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
require_once __DIR__ . '/../includes/admin_notifications.php';
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
    /* The dedicated "Treatment Plan" input was removed from the form --
       plans (treatment, medications, referrals, instructions, follow-up)
       now live in the SOAP "Plan" field below. This variable is only
       kept so the legacy Treatment column can be preserved on re-saves. */
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
    $weight = trim($_POST['vital_weight'] ?? '');
    $height = trim($_POST['vital_height'] ?? '');

    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');

    $followUpDate = trim($_POST['follow_up_date'] ?? '');

    if ($followUpDate === '') {
        $followUpDate = null;
    }


    /* ------------------------------------------------------------
       TREATMENT PLAN (legacy column)
       The separate "Treatment Plan" box was removed -- treatment,
       medications, tests, referrals, instructions and follow-up now
       live in the SOAP "Plan" field (stored in Notes). Prescriptions
       are stored separately in prescriptions / prescription_items.
       The Treatment column is kept for historical records only; its
       value is preserved on re-save when nothing new is submitted
       (see the guard after the existing-consultation check below).
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
        SELECT ConsultationID, Treatment
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


    /* Since the dedicated "Treatment Plan" box is gone, the submitted
       value will always be empty here. Preserve the previously saved
       Treatment text instead of wiping it whenever a consultation is
       re-saved (new consultations simply start with an empty value). */
    if ($treatmentPlan === '' && $existingConsultation) {
        $finalTreatment =
            (string)($existingConsultation['Treatment'] ?? '');
    }


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
            'ssssssssii',
            $chiefComplaint,
            $diagnosis,
            $finalTreatment,
            $clinicalNotes,
            $labRequestsText,
            $followUpDate,
            $consultationDate,
            $consultationTime,
            $consultationID,
            $staffID
        );

        mysqli_stmt_execute($updateStmt);

        mysqli_stmt_close($updateStmt);

        /* Vitals now live in the vitals table as Source='Consultation'. */
        $vitalsSelect = mysqli_prepare(
            $conn,
            'SELECT v.VitalID FROM vitals v
             JOIN consultations c ON c.VitalID = v.VitalID
             WHERE c.ConsultationID = ? AND c.StaffID = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($vitalsSelect, 'ii', $consultationID, $staffID);
        mysqli_stmt_execute($vitalsSelect);
        $vitalsFound = mysqli_fetch_assoc(mysqli_stmt_get_result($vitalsSelect));
        mysqli_stmt_close($vitalsSelect);

        if ($vitalsFound) {
            $vitalsUpdate = mysqli_prepare(
                $conn,
                'UPDATE vitals SET BloodPressure = ?, Temperature = NULLIF(?, \'\'), PulseRate = NULLIF(?, \'\'), Weight = NULLIF(?, \'\'), Height = NULLIF(?, \'\') WHERE VitalID = ?'
            );
            mysqli_stmt_bind_param($vitalsUpdate, 'sssssi', $bloodPressure, $temperature, $pulseRate, $weight, $height, $vitalsFound['VitalID']);
            mysqli_stmt_execute($vitalsUpdate);
            mysqli_stmt_close($vitalsUpdate);
        } else {
            $vitalsInsert = mysqli_prepare(
                $conn,
                'INSERT INTO vitals (AppointmentID, PatientID, StaffID, BloodPressure, Temperature, PulseRate, Weight, Height, Source) SELECT AppointmentID, PatientID, StaffID, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), \'Consultation\' FROM consultations WHERE ConsultationID = ? AND StaffID = ? LIMIT 1'
            );
            mysqli_stmt_bind_param($vitalsInsert, 'sssii', $bloodPressure, $temperature, $pulseRate, $weight, $height, $consultationID, $staffID);
            mysqli_stmt_execute($vitalsInsert);
            $newVitalID = mysqli_insert_id($conn);
            mysqli_stmt_close($vitalsInsert);

            $vitalsLink = mysqli_prepare($conn, 'UPDATE consultations SET VitalID = ? WHERE ConsultationID = ? AND StaffID = ?');
            mysqli_stmt_bind_param($vitalsLink, 'iii', $newVitalID, $consultationID, $staffID);
            mysqli_stmt_execute($vitalsLink);
            mysqli_stmt_close($vitalsLink);
        }

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
                ?,
                ?,
                ?,
                ?,
                ?,
                '" . CONSULTATION_STATUS_COMPLETED . "'
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
            'iiissssssss',
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
            $followUpDate
        );

        mysqli_stmt_execute($insertStmt);

        $consultationID =
            (int) mysqli_insert_id($conn);

        mysqli_stmt_close($insertStmt);

        /* Insert the vitals row with Source='Consultation' and link it. */
        $vitalsInsert = mysqli_prepare(
            $conn,
            'INSERT INTO vitals (AppointmentID, PatientID, StaffID, BloodPressure, Temperature, PulseRate, Weight, Height, Source) VALUES (?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), \'Consultation\')'
        );
        mysqli_stmt_bind_param($vitalsInsert, 'iiisssss', $appointmentID, $patientID, $staffID, $bloodPressure, $temperature, $pulseRate, $weight, $height);
        mysqli_stmt_execute($vitalsInsert);
        $newVitalID = mysqli_insert_id($conn);
        mysqli_stmt_close($vitalsInsert);

        $vitalsLink = mysqli_prepare($conn, 'UPDATE consultations SET VitalID = ? WHERE ConsultationID = ? AND StaffID = ?');
        mysqli_stmt_bind_param($vitalsLink, 'iii', $newVitalID, $consultationID, $staffID);
        mysqli_stmt_execute($vitalsLink);
        mysqli_stmt_close($vitalsLink);
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

    $statusChanged = mysqli_stmt_affected_rows($updateAppointmentStmt) > 0;

    mysqli_stmt_close(
        $updateAppointmentStmt
    );

    if ($statusChanged) {
        adminNotificationNotifyAppointmentStatus(
            $conn,
            $appointmentID,
            APPT_STATUS_COMPLETED
        );
    }


    /* ------------------------------------------------------------
       RETURN TO QUEUE
    ------------------------------------------------------------ */

    $followUpForRedirect = $followUpDate !== null
        ? '&fu=' . urlencode($followUpDate)
        : '';

    header('Location: doctor_queue.php?saved=1&appt=' . $appointmentID . '&pid=' . $patientID . $followUpForRedirect);
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

    /* The "Treatment Plan" box was removed from the form -- plans live
       in the SOAP "Plan" field. For printouts (which can happen before
       saving), fall back to the last saved Treatment value when the
       form did not submit one. */
    $savedTreatment = '';
    $txStmt = mysqli_prepare(
        $conn,
        "SELECT Treatment
         FROM consultations
         WHERE AppointmentID = ?
         LIMIT 1"
    );

    if ($txStmt) {
        mysqli_stmt_bind_param($txStmt, 'i', $apptID);
        mysqli_stmt_execute($txStmt);
        $txRow = mysqli_fetch_assoc(
            mysqli_stmt_get_result($txStmt)
        );
        $savedTreatment =
            (string)($txRow['Treatment'] ?? '');
        mysqli_stmt_close($txStmt);
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
        'clinic_name' => 'Curora Clinic',
        'clinic_info' => 'Curora Outpatient Portal | Tel: (02) 1234-5678',
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
        'treatment' => $treatmentPlan !== '' ? $treatmentPlan : $savedTreatment,
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
   AJAX: CHECK DEPARTMENT FOLLOW-UP AVAILABILITY
   ================================================================
   Called by the follow-up date field to show how many appointment
   slots are open in the doctor's department on a given date.
   Returns JSON: { ok, date, department, doctors, appointments,
                   max_per_day, remaining, available }
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

    /*
    | Department-level availability. The doctor only *suggests* a
    | follow-up date; the patient schedules the appointment
    | themselves. A date is only "open" if the department actually
    | holds consultations on that weekday (department_schedules)
    | AND still has unused slots that day.
    |
    | department_schedules.DayOfWeek uses ISO-8601: 1 = Monday ...
    | 7 = Sunday (same as PHP date('N')).
    */
    $deptID   = (int)($doctor['DepartmentID'] ?? 0);
    $deptName = $doctor['DepartmentName'] ?? 'Department';

    /* Collect the department's consultation schedule (day => sessions). */
    $scheduleStmt = mysqli_prepare(
        $conn,
        "SELECT DayOfWeek, SessionName, StartTime, EndTime, PatientSlots
         FROM department_schedules
         WHERE DepartmentID = ?
         ORDER BY DayOfWeek ASC, StartTime ASC"
    );

    mysqli_stmt_bind_param($scheduleStmt, 'i', $deptID);
    mysqli_stmt_execute($scheduleStmt);
    $scheduleResult = mysqli_stmt_get_result($scheduleStmt);

    $deptSchedules = [];
    while ($schRow = mysqli_fetch_assoc($scheduleResult)) {
        $day = (int)$schRow['DayOfWeek'];
        if (!isset($deptSchedules[$day])) {
            $deptSchedules[$day] = [];
        }
        $deptSchedules[$day][] = $schRow;
    }
    mysqli_stmt_close($scheduleStmt);

    $dateForWeekday = strtotime($checkDate);
    $dayOfWeek = (int)date('N', $dateForWeekday); // 1 = Mon ... 7 = Sun
    $dayName   = date('l', $dateForWeekday);
    $sessions  = $deptSchedules[$dayOfWeek] ?? [];

    /* Short day names for the message (Mon..Sun). */
    $shortDays = [
        1 => 'Monday',   2 => 'Tuesday',  3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday',   6 => 'Saturday',
        7 => 'Sunday',
    ];

    $openDaysText = '';
    if (count($deptSchedules) > 0) {
        $openDayNames = [];
        foreach (array_keys($deptSchedules) as $openDay) {
            $openDayNames[] = $shortDays[(int)$openDay];
        }
        $openDaysText = count($openDayNames) > 1
            ? implode(', ', array_slice($openDayNames, 0, -1)) . ' & ' . end($openDayNames)
            : $openDayNames[0];
    }

    /* Appointments already booked across the department on that date. */
    $availStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS cnt
         FROM appointments
         WHERE DepartmentID = ?
           AND AppointmentDate = ?
           AND Status NOT IN ('" . APPT_STATUS_CANCELLED . "','" . APPT_STATUS_NO_SHOW . "')"
    );

    mysqli_stmt_bind_param($availStmt, 'is', $deptID, $checkDate);
    mysqli_stmt_execute($availStmt);
    $availResult = mysqli_stmt_get_result($availStmt);
    $availRow = mysqli_fetch_assoc($availResult);
    mysqli_stmt_close($availStmt);

    $existingCount = (int)($availRow['cnt'] ?? 0);

    /* The department has no consultations on this weekday -> closed. */
    if (count($sessions) === 0) {
        echo json_encode([
            'ok'            => true,
            'date'          => $checkDate,
            'department'    => $deptName,
            'open'          => false,
            'sessions'      => [],
            'capacity'      => 0,
            'appointments'  => $existingCount,
            'remaining'     => 0,
            'available'     => false,
            'day_name'      => $dayName,
            'message'       => $openDaysText !== ''
                ? $deptName . ' has no consultations on ' . $dayName
                  . '. Open days: ' . $openDaysText . '.'
                : $deptName . ' has no consultations scheduled on ' . $dayName . '.',
        ]);
        exit;
    }

    /* Capacity is the sum of slots across that weekday's sessions. */
    $departmentCapacity = 0;
    $sessionLabels = [];
    foreach ($sessions as $session) {
        $departmentCapacity += (int)($session['PatientSlots'] ?? 0);
        $sessionLabels[] =
            date('g:i A', strtotime($session['StartTime'])) . '–' .
            date('g:i A', strtotime($session['EndTime']));
    }

    $remaining = max(0, $departmentCapacity - $existingCount);

    echo json_encode([
        'ok'            => true,
        'date'          => $checkDate,
        'department'    => $deptName,
        'open'          => true,
        'sessions'      => $sessionLabels,
        'day_name'      => $dayName,
        'capacity'      => $departmentCapacity,
        'appointments'  => $existingCount,
        'remaining'     => $remaining,
        'max_per_day'   => $departmentCapacity,
        'available'     => $remaining > 0,
    ]);
    exit;
}


/* ================================================================
   FOLLOW-UP: SUGGESTION ONLY
   ================================================================
   The doctor does NOT schedule the follow-up appointment for the
   patient. The suggested follow-up date is recorded on the
   consultation (FollowUpDate) when the consultation is saved, and
   the patient schedules the appointment themselves through the
   patient portal. This endpoint only re-confirms the suggestion.
   ================================================================ */




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
        COALESCE(v.BloodPressure, c.BloodPressure) AS BloodPressure,
        COALESCE(v.Temperature, c.Temperature) AS Temperature,
        COALESCE(v.PulseRate, c.PulseRate) AS PulseRate,
        COALESCE(v.Weight, c.Weight) AS Weight,
        COALESCE(v.Height, c.Height) AS Height,
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

    LEFT JOIN vitals v
        ON v.VitalID = c.VitalID

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


                /* ==================================================
                   ✅ FIX: CONSULTATION_STATUS_ONGOING was inside
                   the double-quoted SQL string without concatenation,
                   so PHP passed the LITERAL text
                   "CONSULTATION_STATUS_ONGOING" to MySQL, which made
                   mysqli_prepare() return false and caused:

                       mysqli_stmt_bind_param(): Argument #1
                       ($statement) must be of type mysqli_stmt,
                       false given

                   We now concatenate the PHP constant properly,
                   exactly like we do for CONSULTATION_STATUS_COMPLETED
                   and APPT_STATUS_* elsewhere in this file.
                ================================================== */

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
                        '" . CONSULTATION_STATUS_ONGOING . "'
                    )
                ";

                $startStmt =
                    mysqli_prepare(
                        $conn,
                        $startSql
                    );

                /* ✅ FIX: surface the real MySQL error if the
                   prepare still fails, instead of a confusing
                   "false given" TypeError. */
                if (!$startStmt) {
                    die(
                        'Failed to prepare start consultation: ' .
                        mysqli_error($conn) .
                        '<br><br>Query: <pre>' .
                        htmlspecialchars($startSql) .
                        '</pre>'
                    );
                }


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

                $statusChanged = mysqli_stmt_affected_rows($calledStmt) > 0;

                mysqli_stmt_close(
                    $calledStmt
                );

                if ($statusChanged) {
                    adminNotificationNotifyAppointmentStatus(
                        $conn,
                        $appointmentID,
                        APPT_STATUS_CALLED
                    );
                }


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

            $consultPatient['weight'] =
                $consultation['Weight'] ?? '';

            $consultPatient['height'] =
                $consultation['Height'] ?? '';

            /*
             * Load nurse / staff recorded pre-consultation vitals (vitals table).
             * These are stored separately from the consultation record so they
             * can be captured before the doctor opens the visit.
             */

            $consultPatient['nurse_vitals'] = [];
            $consultPatient['has_nurse_vitals'] = false;

            $nurseVitalsStmt = mysqli_prepare(
                $conn,
                'SELECT v.BloodPressure, v.Temperature, v.PulseRate,
                        v.Weight, v.Height,
                        v.RecordedAt,
                        u.FirstName AS RecFirstName,
                        u.LastName AS RecLastName
                 FROM vitals v
                 LEFT JOIN staff s
                    ON v.StaffID = s.StaffID
                 LEFT JOIN users u
                    ON s.UserID = u.UserID
                 WHERE v.AppointmentID = ? AND v.PatientID = ?
                 ORDER BY v.VitalID DESC
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

                $consultPatient['weight'] =
                    $latestNV['Weight'] ?? '';

                $consultPatient['height'] =
                    $latestNV['Height'] ?? '';

                $consultPatient['vitals_recorded_by'] =
                    trim(
                        ($latestNV['RecFirstName'] ?? '') . ' ' .
                        ($latestNV['RecLastName'] ?? '')
                    );

                $consultPatient['vitals_recorded_at'] =
                    $latestNV['RecordedAt'] ?? '';
            }


            /*
             * Load patient's vitals history (all recorded readings,
             * newest first) for the right-side Vitals History panel.
             */

            $consultPatient['vitals_history'] = [];

            $vitalsHistoryStmt = mysqli_prepare(
                $conn,
                'SELECT v.BloodPressure, v.Temperature, v.PulseRate,
                        v.Weight, v.Height,
                        v.RecordedAt,
                        u.FirstName AS RecFirstName,
                        u.LastName AS RecLastName
                 FROM vitals v
                 LEFT JOIN staff s
                    ON v.StaffID = s.StaffID
                 LEFT JOIN users u
                    ON s.UserID = u.UserID
                 WHERE v.PatientID = ?
                 ORDER BY v.VitalID DESC
                 LIMIT 5'
            );

            mysqli_stmt_bind_param(
                $vitalsHistoryStmt,
                'i',
                $consultPatient['patient_id']
            );

            mysqli_stmt_execute($vitalsHistoryStmt);

            $vitalsHistoryResult =
                mysqli_stmt_get_result($vitalsHistoryStmt);

            while ($vhRow = mysqli_fetch_assoc($vitalsHistoryResult)) {
                $consultPatient['vitals_history'][] = $vhRow;
            }

            mysqli_stmt_close($vitalsHistoryStmt);

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
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">

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

        <label for="follow-up-date">

            Suggested Follow-up Date

        </label>

        <input
            type="date"
            id="follow-up-date"
            name="follow_up_date"
            value="<?= htmlspecialchars(
                $consultPatient['follow_up_date'] ?? ''
            ) ?>"
            onchange="checkFollowupAvailability(this.value)"
        >

        <div
            id="followup-availability"
            class="fu-availability fu-availability--inline"
            style="display:none"
        ></div>

        <p class="fu-field-note">
            Suggestion only — the patient schedules the appointment
            themselves in the patient portal.
        </p>

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


<!-- VITALS HISTORY -->

<div class="consult-panel">

    <div class="consult-panel-head">

        <div class="consult-panel-title">

            Vitals History

        </div>

    </div>


    <?php if (empty($consultPatient['vitals_history'])): ?>

        <div class="records-empty">

            No vitals history on file.

        </div>

    <?php else: ?>

        <?php
            $vhLimit = 3; // readings per page
            $vhTotal = count($consultPatient['vitals_history']);
        ?>

        <div class="vh-list" id="vh-list">

        <?php foreach (
            $consultPatient['vitals_history']
            as $vhIdx => $vh
        ): ?>

            <?php
                $vhClassified = classifyVitals([
                    'blood_pressure'    => $vh['BloodPressure'] ?? '',
                    'temperature'       => $vh['Temperature'] ?? '',
                    'pulse_rate'        => $vh['PulseRate'] ?? '',
                    'weight'            => $vh['Weight'] ?? '',
                    'height'            => $vh['Height'] ?? '',
                ]);

                $vhLabel = '';
                if (!empty($vh['RecFirstName']) || !empty($vh['RecLastName'])) {
                    $vhLabel = trim(
                        ($vh['RecFirstName'] ?? '') . ' ' .
                        ($vh['RecLastName'] ?? '')
                    );
                }
            ?>

        <div class="vh-card" data-vh-page="<?= intdiv($vhIdx, $vhLimit) + 1 ?>">

            <div class="vh-head">

                <span class="vh-datetime">
                    <?= htmlspecialchars(
                        date('M d, Y g:i A', strtotime($vh['RecordedAt']))
                    ) ?>
                </span>

                <?php if ($vhLabel !== ''): ?>

                <span class="vh-recorder">
                    <?= htmlspecialchars($vhLabel) ?>
                </span>

                <?php endif; ?>

            </div>

            <div class="vh-items">

                <?php foreach ($vhClassified as $vhItem): ?>

                    <?php if (
                        $vhItem['value'] === '' ||
                        $vhItem['value'] === null ||
                        $vhItem['key'] === 'bmi'
                    ): ?>
                        <?php continue; ?>
                    <?php endif; ?>

                    <span class="vh-item
                        <?php
                            if ($vhItem['status'] === 'warning') {
                                echo 'vh-item-warning';
                            } elseif ($vhItem['status'] !== 'normal') {
                                echo 'vh-item-abnormal';
                            }
                        ?>"
                        title="<?= htmlspecialchars($vhItem['note']) ?>"
                    >

                        <span class="vh-item-label">
                            <?= htmlspecialchars($vhItem['label']) ?>:
                        </span>

                        <span class="vh-item-value">
                            <?= htmlspecialchars(
                                $vhItem['value'] . ' ' . $vhItem['unit']
                            ) ?>
                        </span>

                        <?php if ($vhItem['status'] === 'warning'): ?>
                        <span class="vh-item-warn">&#9888;</span>
                        <?php elseif ($vhItem['status'] !== 'normal'): ?>
                        <span class="vh-item-warn">&#9888;&#65039;</span>
                        <?php endif; ?>

                    </span>

                <?php endforeach; ?>

            </div>

        </div>

        <?php endforeach; ?>

        </div>

        <?php if ($vhTotal > $vhLimit): ?>

        <div class="vh-pager"
             id="vh-pager"
             data-total="<?= $vhTotal ?>"
             data-limit="<?= $vhLimit ?>">

            <span class="vh-pager-info" id="vh-pager-info" aria-live="polite">
                Showing 1&ndash;<?= min($vhLimit, $vhTotal) ?>
                of <?= $vhTotal ?>
            </span>

            <div class="vh-pager-arrows">

                <button type="button"
                        class="vh-pager-btn"
                        id="vh-prev"
                        aria-label="Newer readings"
                        disabled>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>
                </button>

                <button type="button"
                        class="vh-pager-btn"
                        id="vh-next"
                        aria-label="Older readings">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </button>

            </div>

        </div>

        <script>
        (function () {
            var pager = document.getElementById('vh-pager');
            if (!pager) { return; }

            var total = parseInt(pager.dataset.total, 10);
            var limit = parseInt(pager.dataset.limit, 10);
            var pages = Math.ceil(total / limit);
            var page  = 1;

            var cards = document.querySelectorAll('#vh-list .vh-card');
            var prev  = document.getElementById('vh-prev');
            var next  = document.getElementById('vh-next');
            var info  = document.getElementById('vh-pager-info');

            function render() {
                cards.forEach(function (card) {
                    card.hidden =
                        parseInt(card.dataset.vhPage, 10) !== page;
                });

                var from = (page - 1) * limit + 1;
                var to   = Math.min(page * limit, total);

                info.textContent = 'Showing ' + from + '\u2013' + to +
                                   ' of ' + total;
                prev.disabled = page <= 1;
                next.disabled = page >= pages;
            }

            prev.addEventListener('click', function () {
                if (page > 1) { page--; render(); }
            });
            next.addEventListener('click', function () {
                if (page < pages) { page++; render(); }
            });

            render();
        })();
        </script>

        <?php endif; ?>

    <?php endif; ?>

</div>


<!-- VITALS -->

<div class="consult-panel">

    <div class="consult-panel-head">

        <div class="consult-panel-title">

            Vitals

        </div>

    </div>


    <?php
        $vitalsCheck = [
            'blood_pressure'    => $consultPatient['blood_pressure'] ?? '',
            'temperature'       => $consultPatient['temperature'] ?? '',
            'pulse_rate'        => $consultPatient['pulse_rate'] ?? '',
            'weight'            => $consultPatient['weight'] ?? '',
            'height'            => $consultPatient['height'] ?? '',
        ];

        $vitalsValidated = validateVitals($vitalsCheck);
        $vitalsHaveAbnormal = false;

        foreach ($vitalsValidated as $vItem) {
            if ($vItem['status'] !== 'normal') {
                $vitalsHaveAbnormal = true;
                break;
            }
        }
    ?>


    <?php if ($vitalsHaveAbnormal): ?>

    <?php
        // Split readings: flagged ones first, normal ones summarised below.
        $vFlagged = [];
        $vNormal  = [];

        foreach ($vitalsValidated as $vItem) {
            if ($vItem['value'] === '' || $vItem['value'] === null) {
                continue;
            }

            if ($vItem['status'] === 'normal') {
                $vNormal[] = $vItem;
            } else {
                $vFlagged[] = $vItem;
            }
        }
    ?>

    <div class="vitals-alert" id="vitals-alert-box">

        <div class="vitals-alert-head">

            <span class="vitals-alert-badge">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3L2.5 20h19L12 3z"/><path d="M12 10v4"/><path d="M12 17.2v.1"/></svg>
            </span>

            <div>
                <div class="vitals-alert-title">Abnormal Vitals</div>
                <div class="vitals-alert-sub">
                    <?= count($vFlagged) ?>
                    reading<?= count($vFlagged) === 1 ? '' : 's' ?>
                    need<?= count($vFlagged) === 1 ? 's' : '' ?> review
                </div>
            </div>

        </div>

        <?php foreach ($vFlagged as $vItem): ?>

            <?php
                // "Obese (BMI >= 30)" -> pill "Obese" + range "BMI >= 30"
                $vNote  = (string)$vItem['note'];
                $vPill  = $vNote;
                $vRange = '';

                if (preg_match('/^(.*?)\s*\((.*)\)\s*$/u', $vNote, $vm)) {
                    $vPill  = $vm[1];
                    $vRange = $vm[2];
                }

                $vFlagClass = $vItem['status'] === 'warning'
                    ? 'vitals-alert-warning'
                    : 'vitals-alert-abnormal';
            ?>

            <div class="vitals-alert-item <?= $vFlagClass ?>">

                <div class="vitals-alert-text">
                    <div class="vitals-alert-label">
                        <?= htmlspecialchars($vItem['label']) ?>
                        <span class="vitals-alert-value">
                            <?= htmlspecialchars(
                                $vItem['value'] . ' ' . $vItem['unit']
                            ) ?>
                        </span>
                    </div>

                    <?php if ($vRange !== ''): ?>
                        <div class="vitals-alert-range">
                            <?= htmlspecialchars($vRange) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <span class="vitals-alert-pill">
                    <?= htmlspecialchars($vPill) ?>
                </span>

            </div>

        <?php endforeach; ?>

        <?php if (!empty($vNormal)): ?>

        <div class="vitals-alert-normal-title">Within normal range</div>

        <div class="vitals-alert-normal-list">

            <?php foreach ($vNormal as $vItem): ?>

                <div class="vitals-alert-normal-row">

                    <span class="vitals-alert-normal-label">
                        <?= htmlspecialchars($vItem['label']) ?>
                    </span>

                    <span class="vitals-alert-normal-value">
                        <?= htmlspecialchars(
                            $vItem['value'] . ' ' . $vItem['unit']
                        ) ?>
                    </span>

                    <span class="vitals-alert-normal-ok">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg> Normal
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

        <?php endif; ?>

    </div>

    <?php endif; ?>


    <?php if (!empty($consultPatient['vitals_recorded_by'])): ?>

    <div class="vitals-recorded-by">

        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/></svg>

        Vitals recorded by:

        <?= htmlspecialchars($consultPatient['vitals_recorded_by']) ?>

        <?php
            $vitalRecordedDate =
                $consultPatient['vitals_recorded_at'] ?? '';

            if ($vitalRecordedDate !== '') {
                $recordedTs = strtotime($vitalRecordedDate);
                echo ' on ' . ($recordedTs !== false
                    ? date('M j, Y g:i A', $recordedTs)
                    : htmlspecialchars($vitalRecordedDate));
            }
        ?>

    </div>

    <?php endif; ?>


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


        <div class="form-field">

            <label for="vital-weight">

                Weight (kg)

            </label>

            <input
                type="number"
                step="0.01"
                id="vital-weight"
                name="vital_weight"
                value="<?= htmlspecialchars(
                    $consultPatient['weight'] ?? ''
                ) ?>"
                placeholder="70 (kg)"
            >

        </div>


        <div class="form-field">

            <label for="vital-height">

                Height (m)

            </label>

            <input
                type="number"
                step="0.01"
                id="vital-height"
                name="vital_height"
                value="<?= htmlspecialchars(
                    $consultPatient['height'] ?? ''
                ) ?>"
                placeholder="1.70 (m)"
            >

        </div>


    </div>


    <div class="vitals-actions">

        <a class="vitals-btn vitals-btn-back"
           id="vitals-back"
           href="doctor_queue.php">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="M11 6l-6 6 6 6"/></svg> Back to Queue
        </a>

        <button type="button"
                class="vitals-btn vitals-btn-undo"
                id="vitals-undo"
                disabled>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg> Undo changes
        </button>

    </div>

    <script>
    (function () {
        var ids = ['vital-bp', 'vital-temp', 'vital-pulse',
                   'vital-weight', 'vital-height'];
        var fields = ids.map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);

        var undoBtn = document.getElementById('vitals-undo');
        var backBtn = document.getElementById('vitals-back');
        if (!fields.length || !undoBtn || !backBtn) { return; }

        // Values as loaded from the server
        var original = fields.map(function (f) { return f.value; });

        function isDirty() {
            return fields.some(function (f, i) {
                return f.value !== original[i];
            });
        }

        function refresh() {
            undoBtn.disabled = !isDirty();
        }

        fields.forEach(function (f) {
            f.addEventListener('input', refresh);
        });

        undoBtn.addEventListener('click', function () {
            fields.forEach(function (f, i) {
                f.value = original[i];
                f.dispatchEvent(new Event('input', { bubbles: true }));
            });
            refresh();
        });

        backBtn.addEventListener('click', function (e) {
            if (isDirty() && !confirm(
                'You have unsaved vitals changes. Leave without saving?'
            )) {
                e.preventDefault();
            }
        });
    })();
    </script>

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

    <div class="doc-menu" id="docMenu">

        <button
            type="button"
            class="btn-print-consult doc-menu-trigger"
            id="docMenuTrigger"
            aria-haspopup="true"
            aria-expanded="false"
            aria-controls="docMenuList"
        >
            Documents
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5"/></svg>
        </button>

        <div class="doc-menu-list" id="docMenuList" role="menu" hidden>

            <button type="submit" class="doc-menu-item" role="menuitem" name="print_action" value="prescription">
                Generate Prescription
            </button>

            <button type="submit" class="doc-menu-item" role="menuitem" name="print_action" value="medical_certificate">
                Medical Certificate
            </button>

            <button type="submit" class="doc-menu-item" role="menuitem" name="print_action" value="lab_request">
                Lab Request
            </button>

            <button type="submit" class="doc-menu-item" role="menuitem" name="print_action" value="consultation_report">
                Print Consultation Report
            </button>

        </div>

    </div>

    <button
        type="submit"
        class="btn-save-consult"
        name="save_consultation"
    >

        Save Consultation

    </button>

</div>

<script>
(function () {
    var wrap    = document.getElementById('docMenu');
    var trigger = document.getElementById('docMenuTrigger');
    var list    = document.getElementById('docMenuList');
    if (!wrap || !trigger || !list) return;

    function setOpen(open) {
        list.hidden = !open;
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(list.hidden);
    });

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target)) setOpen(false);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !list.hidden) {
            setOpen(false);
            trigger.focus();
        }
    });
})();
</script>


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

/* ==========================================================
   FOLLOW-UP DATE AVAILABILITY (INLINE SUGGESTION)
   The doctor only suggests a date; the patient books their
   own appointment. This shows department-level availability.
========================================================== */

function checkFollowupAvailability(dateVal) {
    var box = document.getElementById('followup-availability');

    if (!dateVal) {
        box.style.display = 'none';
        return;
    }

    box.style.display = '';
    box.innerHTML = '<span class="fu-spin"></span> Checking department availability\u2026';

    fetch('doctor_queue.php?action=check_availability&date=' + encodeURIComponent(dateVal))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.ok) {
                box.innerHTML = '<span class="fu-avail-dot fu-avail-dot--unavail"></span> Error: ' + d.error;
                return;
            }
            if (!d.open) {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--unavail"></span> ' +
                    d.message;
                return;
            }
            var sessionInfo = '';
            if (d.sessions && d.sessions.length > 0) {
                sessionInfo = ' (' + d.day_name + ': ' + d.sessions.join(', ') + ')';
            }
            if (d.available) {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--avail"></span> ' +
                    d.department + ' — ' + d.remaining + ' of ' + d.max_per_day + ' slots open on ' + d.date +
                    sessionInfo;
            } else {
                box.innerHTML =
                    '<span class="fu-avail-dot fu-avail-dot--unavail"></span> ' +
                    d.department + ' is fully booked on ' + d.date + ' (' + d.appointments + '/' + d.max_per_day + ')' +
                    sessionInfo;
            }
        })
        .catch(function() {
            box.innerHTML = '<span class="fu-avail-dot fu-avail-dot--unavail"></span> Could not check availability.';
        });
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

    <?php if (!empty($_GET['pid']) && !empty($_GET['fu'])): ?>

        <span class="queue-alert-note">
            Suggested follow-up date recorded (<strong><?= htmlspecialchars($_GET['fu']) ?></strong>).
            The patient will schedule their own appointment in the patient portal.
        </span>

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

<table class="queue-table" data-responsive>

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


<td data-label="Queue #">

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


<td data-label="Patient">

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

</td>


<td data-label="Appointment">

    <?= htmlspecialchars(
        date(
            'h:i A',
            strtotime(
                $p['appointment_time']
            )
        )
    ) ?>

</td>


<td data-label="Status">

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


<td data-label="Est. Wait">

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


<td class="queue-actions-cell" data-label="Actions">


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

<?php if (!empty($queue)): ?>

<!-- PAGINATION -->
<div class="pagination-bar" data-pagination>

    <div class="pagination-row">

        <button type="button" class="pagination-btn" data-pagination-prev>Previous</button>

        <span class="pagination-label" data-pagination-label>Page 1 of 1</span>

        <button type="button" class="pagination-btn" data-pagination-next>Next</button>

    </div>

</div>

<?php endif; ?>

</div>


<script src="../assets/js/pagination.js"></script>

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


    /* When pagination is active, delegate the status filter to the
       pager so filtering and page slicing stay in sync. */
    if (window.doctorQueuePager) {
        window.doctorQueuePager.refresh();
        return;
    }


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
   LIVE ABNORMAL VITALS ALERT
   Rebuilds the alert box as the doctor types in the vitals.
========================================================== */

function classifyVitalJS(key, value) {
    if (value === '' || value === null || value === undefined) {
        return { status: 'normal', note: '' };
    }

    switch (key) {
        case 'blood_pressure': {
            var m = String(value).match(/^\s*(\d{2,3})\s*\/\s*(\d{2,3})\s*$/);
            if (!m) { return { status: 'normal', note: '' }; }
            var sys = parseInt(m[1], 10), dia = parseInt(m[2], 10);
            if (sys >= 140 || dia >= 90) { return { status: 'high', note: 'Hypertension range (≥140/90)' }; }
            if (sys > 120 || dia > 80) { return { status: 'warning', note: 'Elevated blood pressure (121–139/81–89)' }; }
            if (sys < 90 || dia < 60) { return { status: 'low', note: 'Low blood pressure (<90/60)' }; }
            return { status: 'normal', note: '' };
        }
        case 'temperature': {
            var t = parseFloat(value);
            if (isNaN(t) || t <= 0) { return { status: 'normal', note: '' }; }
            if (t > 37.5) { return { status: 'high', note: 'Fever (>37.5\u00B0C)' }; }
            if (t > 37.2) { return { status: 'warning', note: 'Elevated temperature (37.3\u201337.5\u00B0C)' }; }
            if (t < 36.1) { return { status: 'low', note: 'Hypothermia (<36.1\u00B0C)' }; }
            return { status: 'normal', note: '' };
        }
        case 'pulse_rate': {
            var p = parseInt(value, 10);
            if (isNaN(p)) { return { status: 'normal', note: '' }; }
            if (p > 120) { return { status: 'high', note: 'Tachycardia (>120 bpm)' }; }
            if (p > 100) { return { status: 'warning', note: 'Elevated pulse (101\u2013120 bpm)' }; }
            if (p < 50) { return { status: 'low', note: 'Bradycardia (<50 bpm)' }; }
            return { status: 'normal', note: '' };
        }
        default:
            return { status: 'normal', note: '' };
    }
}

var VITALS_ICON_WARN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3L2.5 20h19L12 3z"/><path d="M12 10v4"/><path d="M12 17.2v.1"/></svg>';
var VITALS_ICON_OK = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

function vitalsEsc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function updateVitalsAlert() {
    var fields = {
        'blood_pressure':    ['vital-bp', 'Blood Pressure', 'mmHg'],
        'temperature':       ['vital-temp', 'Temperature', '\u00B0C'],
        'pulse_rate':        ['vital-pulse', 'Pulse', 'bpm']
    };

    var flagged = [];
    var normal  = [];

    Object.keys(fields).forEach(function (key) {
        var el = document.getElementById(fields[key][0]);
        if (!el) { return; }
        var value = el.value.trim();
        if (value === '') { return; }

        var cls = classifyVitalJS(key, value);
        var item = {
            label:  fields[key][1],
            value:  value + ' ' + fields[key][2],
            status: cls.status,
            note:   cls.note
        };

        if (cls.status === 'normal') { normal.push(item); }
        else { flagged.push(item); }
    });

    var box = document.getElementById('vitals-alert-box');

    if (!flagged.length) {
        if (box) { box.remove(); }
        return;
    }

    if (!box) {
        box = document.createElement('div');
        box.id = 'vitals-alert-box';
        box.className = 'vitals-alert';

        var ref = document.querySelector('.vitals-recorded-by') ||
                  document.querySelector('.vitals-grid');
        if (ref && ref.parentNode) { ref.parentNode.insertBefore(box, ref); }
    }

    var html =
        '<div class="vitals-alert-head">' +
            '<span class="vitals-alert-badge">' + VITALS_ICON_WARN + '</span>' +
            '<div>' +
                '<div class="vitals-alert-title">Abnormal Vitals</div>' +
                '<div class="vitals-alert-sub">' + flagged.length +
                    ' reading' + (flagged.length === 1 ? '' : 's') +
                    ' need' + (flagged.length === 1 ? 's' : '') + ' review</div>' +
            '</div>' +
        '</div>';

    flagged.forEach(function (i) {
        var pill = i.note, range = '';
        var m = i.note.match(/^(.*?)\s*\((.*)\)\s*$/);
        if (m) { pill = m[1]; range = m[2]; }

        html +=
            '<div class="vitals-alert-item ' +
                (i.status === 'warning' ? 'vitals-alert-warning' : 'vitals-alert-abnormal') + '">' +
                '<div class="vitals-alert-text">' +
                    '<div class="vitals-alert-label">' + vitalsEsc(i.label) +
                        ' <span class="vitals-alert-value">' + vitalsEsc(i.value) + '</span></div>' +
                    (range ? '<div class="vitals-alert-range">' + vitalsEsc(range) + '</div>' : '') +
                '</div>' +
                '<span class="vitals-alert-pill">' + vitalsEsc(pill) + '</span>' +
            '</div>';
    });

    if (normal.length) {
        html += '<div class="vitals-alert-normal-title">Within normal range</div>' +
                '<div class="vitals-alert-normal-list">';
        normal.forEach(function (i) {
            html +=
                '<div class="vitals-alert-normal-row">' +
                    '<span class="vitals-alert-normal-label">' + vitalsEsc(i.label) + '</span>' +
                    '<span class="vitals-alert-normal-value">' + vitalsEsc(i.value) + '</span>' +
                    '<span class="vitals-alert-normal-ok">' + VITALS_ICON_OK + ' Normal</span>' +
                '</div>';
        });
        html += '</div>';
    }

    box.innerHTML = html;
}

(function() {
    var vitalsInputs = ['vital-bp', 'vital-temp', 'vital-pulse'];
    vitalsInputs.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateVitalsAlert);
        }
    });
})();

/* =============================================
   QUEUE TABLE PAGINATION
============================================= */
window.doctorQueuePager = attachPagination({
    bar: document.querySelector('[data-pagination]'),
    items: function () {
        return document.querySelectorAll('#queue-tbody tr[data-status]');
    },
    perPage: 8,
    isItemVisible: function (row) {
        var activeBtn = document.querySelector('.queue-filter-btn.active');
        var status = activeBtn
            ? activeBtn.textContent.trim().toLowerCase()
            : 'all';

        if (status === 'all') {
            return true;
        }

        var statusKey = status === 'in consultation'
            ? 'in_progress'
            : status;

        return row.dataset.status === statusKey;
    }
});

</script>


<?php endif; ?>


</main>

</div>


<script src="../assets/js/responsive_nav.js"></script>
</body>

</html>