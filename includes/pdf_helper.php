<?php
/**
 * pdf_helper.php
 *
 * Builds clinical documents (Prescription, Medical Certificate,
 * Laboratory/Diagnostic Request) as PDFs using the vendored FPDF
 * library in includes/fpdf/fpdf.php.
 *
 * Expected $data structure:
 *
 *   $data = [
 *       'clinic_name'   => string,
 *       'clinic_info'   => string,   // address / contact block
 *       'doctor_name'   => string,   // e.g. "Dr. Juan Dela Cruz"
 *       'doctor_specialization' => string,
 *       'doctor_license' => string,  // optional license / PRC number
 *       'patient_name'  => string,
 *       'patient_age'   => string,
 *       'patient_sex'   => string,
 *       'patient_address' => string, // optional
 *       'blood_type'    => string,   // optional
 *       'date_issued'   => string,   // Y-m-d
 *       'appointment_date' => string,// Y-m-d
 *       'chief_complaint' => string,
 *       'diagnosis'     => string,
 *       'treatment'     => string,   // treatment plan text
 *       'notes'         => string,   // clinical notes / SOAP
 *       'vitals'        => string,   // e.g. "BP: 120/80 | Temp: 36.7 | HR: 72"
 *       'follow_up_date' => string,  // optional for medical cert
 *       'rx_items'      => [ [ 'MedicineName','Dosage','Frequency','Duration','Instructions' ], ... ],
 *       'lab_requests'  => [ string, ... ],  // for lab request
 *   ];
 */

require_once __DIR__ . '/fpdf/fpdf.php';

/**
 * Smooth multi-line output that wraps long text within a given width.
 * Falls back to FPDF's MultiCell (which does not justify) for simplicity.
 */
function pdf_multi_cell(FPDF $pdf, float $w, float $h, string $txt, bool $border = false, string $align = 'L'): void
{
    $txt = html_entity_decode((string) $txt, ENT_QUOTES, 'UTF-8');
    $txt = str_replace("\r\n", "\n", $txt);
    $pdf->MultiCell($w, $h, $txt, $border ? 1 : 0, $align);
}

/**
 * Draw a header block shared by all documents.
 */
function pdf_document_header(FPDF $pdf, array $d): void
{
    $clinic = (string)($d['clinic_name'] ?? 'MediCare Clinic');
    $info = (string)($d['clinic_info'] ?? '');

    $pdf->SetFont('Helvetica', 'B', 15);
    $pdf->Cell(0, 8, $clinic, 0, 1, 'C');
    if ($info !== '') {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->MultiCell(0, 4, $info, 0, 'C');
    }

    $pdf->Ln(2);

    // Accent line
    $pdf->SetDrawColor(13, 148, 136);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(4);
}

/**
 * Output prescription document.
 */
function pdf_prescription(FPDF $pdf, array $d): void
{
    pdf_document_header($pdf, $d);

    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(0, 7, 'PRESCRIPTION', 0, 1, 'C');
    $pdf->Ln(3);

    // Patient + doctor info
    $pdf->SetFont('Helvetica', '', 10);
    $label = 38;
    $valueW = 60;

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($label, 6, 'Patient:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($valueW, 6, (string)($d['patient_name'] ?? ''), 0, 1);

    $demographics = [];
    if (!empty($d['patient_age'])) {
        $demographics[] = 'Age: ' . $d['patient_age'];
    }
    if (!empty($d['patient_sex'])) {
        $demographics[] = 'Sex: ' . $d['patient_sex'];
    }
    if (!empty($d['blood_type'])) {
        $demographics[] = 'Blood Type: ' . $d['blood_type'];
    }

    if (!empty($demographics)) {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetX(15 + $label);
        $pdf->Cell(0, 5, implode('   |   ', $demographics), 0, 1);
    }

    $pdf->Ln(1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($label, 6, 'Date:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell($valueW, 6, (string)($d['date_issued'] ?? ''), 0, 1);

    if (!empty($d['doctor_name'])) {
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($label, 6, 'Physician:', 0, 0);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, (string)$d['doctor_name'], 0, 1);
        if (!empty($d['doctor_specialization'])) {
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetX(15 + $label);
            $pdf->Cell(0, 5, (string)$d['doctor_specialization'], 0, 1);
        }
    }

    $pdf->Ln(4);

    // Rx table header
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetFillColor(13, 148, 136);
    $pdf->SetTextColor(255, 255, 255);

    $colW = [70, 30, 32, 28, 40];
    $heads = ['Medicine', 'Dosage', 'Frequency', 'Duration', 'Instructions'];
    $x = $pdf->GetX();
    foreach ($heads as $i => $h) {
        $pdf->Cell($colW[$i], 7, $h, 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Helvetica', '', 9);

    $items = (array)($d['rx_items'] ?? []);
    if (empty($items)) {
        $pdf->Cell(array_sum($colW), 14, 'No medications prescribed.', 1, 1, 'C');
    } else {
        $fill = false;
        foreach ($items as $rx) {
            $rowH = 8;
            $yStart = $pdf->GetY();
            $pdf->Cell($colW[0], $rowH, (string)($rx['MedicineName'] ?? ''), 1, 0, 'L', $fill);
            $pdf->Cell($colW[1], $rowH, (string)($rx['Dosage'] ?? ''), 1, 0, 'C', $fill);
            $pdf->Cell($colW[2], $rowH, (string)($rx['Frequency'] ?? ''), 1, 0, 'C', $fill);
            $pdf->Cell($colW[3], $rowH, (string)($rx['Duration'] ?? ''), 1, 0, 'C', $fill);

            // Instructions cell (may wrap)
            $instText = (string)($rx['Instructions'] ?? '');
            $pdf->MultiCell($colW[4], $rowH / 2, $instText, 1, 'L', $fill);
            if ($pdf->GetY() < $yStart + $rowH) {
                $pdf->SetY($yStart + $rowH);
            } else {
                $curY = $pdf->GetY();
                $pdf->SetY($yStart);
                // Move X back across the first four columns so borders align
                // (already rendered). Just ensure the next row starts at the right X.
                $pdf->SetX(15);
                $pdf->SetY($curY);
                continue;
            }
            $pdf->SetX(15 + $colW[0] + $colW[1] + $colW[2] + $colW[3]);
            $fill = !$fill;
        }
    }

    if (!empty($d['treatment'])) {
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'Treatment Plan:', 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        pdf_multi_cell($pdf, 0, 5, (string)$d['treatment']);
    }

    if (!empty($d['follow_up_date'])) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 6, 'Follow-up: ' . $d['follow_up_date'], 0, 1);
    }

    $pdf->Ln(8);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 6, 'Signature:', 0, 1);
    $pdf->Ln(6);
    $pdf->Line(15, $pdf->GetY(), 75, $pdf->GetY());
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 5, (string)($d['doctor_name'] ?? ''), 0, 1);
    if (!empty($d['doctor_license'])) {
        $pdf->Cell(0, 5, 'License No.: ' . $d['doctor_license'], 0, 1);
    }
}

/**
 * Output medical certificate document.
 */
function pdf_medical_certificate(FPDF $pdf, array $d): void
{
    pdf_document_header($pdf, $d);

    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, 'MEDICAL CERTIFICATE', 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', '', 10.5);

    $patAddress = (string)($d['patient_address'] ?? '');
    $patSex = (string)($d['patient_sex'] ?? '');
    $patAge = (string)($d['patient_age'] ?? '');

    $pdf->SetX(15);
    pdf_multi_cell($pdf, 0, 5, 'This is to certify that ' .
        (string)($d['patient_name'] ?? 'the patient') .
        ($patAge !== '' ? ', ' . $patAge . ' years old' : '') .
        ($patSex !== '' ? ', ' . $patSex : '') .
        ($patAddress !== '' ? ', of ' . $patAddress : '') .
        ', was examined and treated by the undersigned physician on ' .
        (string)($d['date_issued'] ?? '') . '.');

    $pdf->Ln(3);

    if (!empty($d['diagnosis'])) {
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->Cell(0, 6, 'Diagnosis:', 0, 1);
        $pdf->SetFont('Helvetica', '', 10.5);
        pdf_multi_cell($pdf, 0, 5, (string)$d['diagnosis']);
        $pdf->Ln(2);
    }

    if (!empty($d['treatment'])) {
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->Cell(0, 6, 'Management / Treatment:', 0, 1);
        $pdf->SetFont('Helvetica', '', 10.5);
        pdf_multi_cell($pdf, 0, 5, (string)$d['treatment']);
        $pdf->Ln(2);
    }

    if (!empty($d['follow_up_date'])) {
        $pdf->SetFont('Helvetica', '', 10.5);
        $pdf->Cell(0, 6, 'The patient is advised to return for follow-up on ' . $d['follow_up_date'] . '.', 0, 1);
        $pdf->Ln(2);
    }

    $pdf->SetFont('Helvetica', '', 10.5);
    $pdf->Cell(0, 6, 'Recommended period of rest / work suspension: ___________________________', 0, 1);
    $pdf->Ln(2);
    $pdf->Cell(0, 6, 'This certificate is issued upon the request of the patient for whatever legal purpose it may serve.', 0, 1);

    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 6, 'Attending Physician:', 0, 1);
    $pdf->Ln(7);
    $pdf->Line(15, $pdf->GetY(), 85, $pdf->GetY());
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, (string)($d['doctor_name'] ?? ''), 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    if (!empty($d['doctor_specialization'])) {
        $pdf->Cell(0, 5, (string)$d['doctor_specialization'], 0, 1);
    }
    if (!empty($d['doctor_license'])) {
        $pdf->Cell(0, 5, 'License No.: ' . $d['doctor_license'], 0, 1);
    }
}

/**
 * Output laboratory / diagnostic request document.
 */
function pdf_lab_request(FPDF $pdf, array $d): void
{
    pdf_document_header($pdf, $d);

    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, 'LABORATORY / DIAGNOSTIC REQUEST', 0, 1, 'C');
    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', '', 10);
    $label = 45;

    $pdf->Cell($label, 6, 'Patient:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, (string)($d['patient_name'] ?? ''), 0, 1);

    $demographics = [];
    if (!empty($d['patient_age'])) {
        $demographics[] = 'Age: ' . $d['patient_age'];
    }
    if (!empty($d['patient_sex'])) {
        $demographics[] = 'Sex: ' . $d['patient_sex'];
    }
    if (!empty($d['blood_type'])) {
        $demographics[] = 'Blood Type: ' . $d['blood_type'];
    }
    if (!empty($demographics)) {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetX(15 + $label);
        $pdf->Cell(0, 5, implode('  |  ', $demographics), 0, 1);
    }

    $pdf->Ln(1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($label, 6, 'Date:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, (string)($d['date_issued'] ?? ''), 0, 1);

    if (!empty($d['diagnosis'])) {
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($label, 6, 'Diagnosis:', 0, 0);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 6, (string)$d['diagnosis'], 0, 1);
    }

    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', 'B', 10.5);
    $pdf->Cell(0, 6, 'Laboratory / Diagnostic Examinations Requested:', 0, 1);
    $pdf->Ln(1);

    $requests = (array)($d['lab_requests'] ?? []);
    if (empty($requests)) {
        $requests = ['Complete Blood Count (CBC)', 'Urinalysis'];
    }

    $pdf->SetFont('Helvetica', '', 10);
    foreach ($requests as $i => $req) {
        $pdf->Cell(7, 6, ($i + 1) . '.', 0, 0);
        $pdf->Cell(0, 6, (string)$req, 0, 1);
    }

    if (!empty($d['notes'])) {
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->Cell(0, 6, 'Remarks / Clinical Notes:', 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        pdf_multi_cell($pdf, 0, 5, (string)$d['notes']);
    }

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 6, 'Requesting Physician:', 0, 1);
    $pdf->Ln(7);
    $pdf->Line(120, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, (string)($d['doctor_name'] ?? ''), 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    if (!empty($d['doctor_specialization'])) {
        $pdf->Cell(0, 5, (string)$d['doctor_specialization'], 0, 1);
    }
    if (!empty($d['doctor_license'])) {
        $pdf->Cell(0, 5, 'License No.: ' . $d['doctor_license'], 0, 1);
    }
}

/**
 * Parse clinical notes into SOAP sections (Subjective / Objective /
 * Assessment / Plan) when the notes are stored in the structured
 * "SUBJECTIVE: ... OBJECTIVE: ..." format.
 *
 * Returns an associative array with keys: subjective, objective,
 * assessment, plan. Sections that are not present are empty strings.
 * If the notes contain no SOAP markers, the whole text is returned
 * as the 'plain' key.
 */
function pdf_parse_soap(string $text): array
{
    $text = trim((string) $text);

    if ($text === '') {
        return [];
    }

    $sections = ['SUBJECTIVE', 'OBJECTIVE', 'ASSESSMENT', 'PLAN'];

    $pattern =
        '/^(SUBJECTIVE|OBJECTIVE|ASSESSMENT|PLAN)\s*:\s*(.*?)(?=^(?:SUBJECTIVE|OBJECTIVE|ASSESSMENT|PLAN)\s*:|\z)/msi';

    if (!preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) || empty($matches)) {
        return ['plain' => $text];
    }

    $result = [];

    foreach ($matches as $m) {
        $key = strtolower($m[1]);
        if (isset($sections)) { /* keep for clarity */ }
        $result[$key] = trim($m[2]);
    }

    return $result;
}

/**
 * Output a complete consultation report.
 *
 * Unlike the focused documents (prescription / medical certificate /
 * lab request), this bundles the full consultation record: patient and
 * doctor demographics, vitals, allergies/alerts, chief complaint,
 * diagnosis, SOAP clinical notes, treatment plan, medications, lab
 * requests, follow-up and consultation status.
 */
function pdf_consultation_report(FPDF $pdf, array $d): void
{
    pdf_document_header($pdf, $d);

    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(0, 7, 'CONSULTATION REPORT', 0, 1, 'C');
    $pdf->Ln(4);


    /* ---------------- Patient + consultation header ---------------- */

    $label = 40;

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($label, 6, 'Patient:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, (string)($d['patient_name'] ?? ''), 0, 1);

    $demographics = [];
    if (!empty($d['patient_age'])) {
        $demographics[] = 'Age: ' . $d['patient_age'];
    }
    if (!empty($d['patient_sex'])) {
        $demographics[] = 'Sex: ' . $d['patient_sex'];
    }
    if (!empty($d['patient_id'])) {
        $demographics[] = 'Patient ID: ' . $d['patient_id'];
    }
    if (!empty($d['blood_type'])) {
        $demographics[] = 'Blood Type: ' . $d['blood_type'];
    }
    if (!empty($d['patient_address'])) {
        $demographics[] = 'Address: ' . $d['patient_address'];
    }
    if (!empty($demographics)) {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetX(15 + $label);
        pdf_multi_cell($pdf, 0, 5, implode('   |   ', $demographics));
    }

    $pdf->Ln(1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell($label, 6, 'Date:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, (string)($d['consultation_datetime'] ?? $d['date_issued'] ?? ''), 0, 1);

    if (!empty($d['doctor_name'])) {
        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell($label, 6, 'Physician:', 0, 0);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, (string)$d['doctor_name'], 0, 1);
        if (!empty($d['department']) || !empty($d['doctor_specialization'])) {
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetX(15 + $label);
            $sub = [];
            if (!empty($d['department'])) {
                $sub[] = 'Department: ' . $d['department'];
            }
            if (!empty($d['doctor_specialization'])) {
                $sub[] = (string)$d['doctor_specialization'];
            }
            $pdf->Cell(0, 5, implode('   |   ', $sub), 0, 1);
        }
    }

    $pdf->Ln(3);


    /* ---------------- Vitals box ---------------- */

    if (!empty($d['vitals'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetFillColor(238, 250, 246);
        $pdf->SetDrawColor(13, 148, 136);
        $pdf->Cell(0, 7, '  VITAL SIGNS', 1, 1, 'L', true);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetX(15);
        pdf_multi_cell($pdf, 0, 6, (string)$d['vitals']);
        $pdf->Ln(2);
    }


    /* ---------------- Alerts / allergies box ---------------- */

    if (!empty($d['allergies_alerts'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetFillColor(255, 241, 242);
        $pdf->SetDrawColor(220, 38, 38);
        $pdf->Cell(0, 7, '  ALLERGIES / ALERTS', 1, 1, 'L', true);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(185, 28, 28);
        $pdf->SetX(15);
        pdf_multi_cell($pdf, 0, 6, (string)$d['allergies_alerts']);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }


    /* ---------------- Section helper ---------------- */

    $section = function (string $title, string $body) use ($pdf) {
        if ($body === '') {
            return;
        }
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, $title, 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        pdf_multi_cell($pdf, 0, 5, $body);
        $pdf->Ln(2);
    };

    $section('CHIEF COMPLAINT', (string)($d['chief_complaint'] ?? ''));


    /* ---------------- Diagnosis ---------------- */

    if (!empty($d['diagnosis'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'DIAGNOSIS', 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        pdf_multi_cell($pdf, 0, 5, (string)$d['diagnosis']);
        $pdf->Ln(2);
    }


    /* ---------------- Clinical notes (SOAP) ---------------- */

    $soap = pdf_parse_soap((string)($d['notes'] ?? ''));

    if (!empty($soap['plain'])) {
        $section('CLINICAL NOTES', $soap['plain']);
    } else {
        $hasSoap = false;
        foreach (['subjective', 'objective', 'assessment', 'plan'] as $sKey) {
            if (!empty($soap[$sKey])) {
                $hasSoap = true;
                break;
            }
        }

        if ($hasSoap) {
            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'CLINICAL NOTES — SOAP', 0, 1);
            $pdf->Ln(1);

            $soapTitles = [
                'subjective' => 'SUBJECTIVE',
                'objective'  => 'OBJECTIVE',
                'assessment' => 'ASSESSMENT',
                'plan'       => 'PLAN',
            ];

            foreach ($soapTitles as $sKey => $sTitle) {
                if (empty($soap[$sKey])) {
                    continue;
                }
                $pdf->SetFont('Helvetica', 'B', 9.5);
                $pdf->Cell(0, 6, $sTitle . ':', 0, 1);
                $pdf->SetFont('Helvetica', '', 9.5);
                pdf_multi_cell($pdf, 0, 5, $soap[$sKey]);
                $pdf->Ln(2);
            }
        }
    }


    /* ---------------- Treatment plan ---------------- */

    if (!empty($d['treatment'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'TREATMENT PLAN', 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        pdf_multi_cell($pdf, 0, 5, (string)$d['treatment']);
        $pdf->Ln(2);
    }


    /* ---------------- Medications table ---------------- */

    $rxItems = (array)($d['rx_items'] ?? []);
    if (!empty($rxItems)) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'MEDICATIONS', 0, 1);

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(13, 148, 136);
        $pdf->SetTextColor(255, 255, 255);
        $colW = [62, 34, 38, 28, 33];
        $heads = ['Medicine', 'Dosage', 'Frequency', 'Duration', 'Instructions'];
        foreach ($heads as $i => $h) {
            $pdf->Cell($colW[$i], 7, $h, 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', '', 8.5);

        foreach ($rxItems as $rx) {
            $yStart = $pdf->GetY();
            $inst = (string)($rx['Instructions'] ?? '');
            $pdf->Cell($colW[0], 8, (string)($rx['MedicineName'] ?? ''), 1, 0, 'L');
            $pdf->Cell($colW[1], 8, (string)($rx['Dosage'] ?? ''), 1, 0, 'C');
            $pdf->Cell($colW[2], 8, (string)($rx['Frequency'] ?? ''), 1, 0, 'C');
            $pdf->Cell($colW[3], 8, (string)($rx['Duration'] ?? ''), 1, 0, 'C');
            $pdf->MultiCell($colW[4], 4, $inst, 1, 'L');
            if ($pdf->GetY() < $yStart + 8) {
                $pdf->SetY($yStart + 8);
            }
            $pdf->SetX(15);
        }
        $pdf->Ln(2);
    }


    /* ---------------- Lab requests ---------------- */

    $labRequests = (array)($d['lab_requests'] ?? []);
    if (!empty($labRequests)) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'LABORATORY / DIAGNOSTIC REQUESTS', 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        foreach ($labRequests as $i => $req) {
            $pdf->Cell(7, 6, ($i + 1) . '.', 0, 0);
            $pdf->Cell(0, 6, (string)$req, 0, 1);
        }
        $pdf->Ln(2);
    }


    /* ---------------- Follow-up + status ---------------- */

    if (!empty($d['follow_up_date'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'FOLLOW-UP', 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->Cell(0, 5, 'Date: ' . (string)$d['follow_up_date'], 0, 1);
        if (!empty($d['follow_up_instructions'])) {
            pdf_multi_cell($pdf, 0, 5, 'Instructions: ' . (string)$d['follow_up_instructions']);
        }
        $pdf->Ln(2);
    }

    if (!empty($d['status'])) {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'CONSULTATION STATUS', 0, 1);
        $pdf->SetFont('Helvetica', '', 9.5);
        $pdf->Cell(0, 6, (string)$d['status'], 0, 1);
        $pdf->Ln(2);
    }


    /* ---------------- Signature ---------------- */

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 6, 'Attending Physician:', 0, 1);
    $pdf->Ln(7);
    $pdf->Line(120, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, (string)($d['doctor_name'] ?? ''), 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    if (!empty($d['doctor_specialization'])) {
        $pdf->Cell(0, 5, (string)$d['doctor_specialization'], 0, 1);
    }
    if (!empty($d['department'])) {
        $pdf->Cell(0, 5, (string)$d['department'], 0, 1);
    }
    if (!empty($d['doctor_license'])) {
        $pdf->Cell(0, 5, 'License No.: ' . $d['doctor_license'], 0, 1);
    }
}

/**
 * Given a data array and a document type, returns the built PDF binary string.
 */
function pdf_build(array $d, string $type): string
{
    $pdf = new FPDF('P', 'mm', 'Letter');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    switch ($type) {
        case 'medical_certificate':
            pdf_medical_certificate($pdf, $d);
            break;
        case 'lab_request':
            pdf_lab_request($pdf, $d);
            break;
        case 'consultation_report':
            pdf_consultation_report($pdf, $d);
            break;
        case 'prescription':
        default:
            pdf_prescription($pdf, $d);
            break;
    }

    return $pdf->Output('S');
}
