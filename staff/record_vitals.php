<?php
/**
 * record_vitals.php
 * Pre-consultation Vital Signs capture (nurse / staff).
 */

session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/status_constants.php';
require_once __DIR__ . '/../includes/vitals.php';


// ======================================================
// STAFF SECURITY CHECK
// ======================================================

if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php');
    exit;
}

if (($_SESSION['RoleName'] ?? '') !== 'Staff') {
    header('Location: ../portal-select.php?action=login');
    exit;
}

$currentUserID = (int) $_SESSION['UserID'];


// ======================================================
// STAFF INFO (sidebar)
// ======================================================

$staffFirstName = $_SESSION['FirstName'] ?? '';
$staffLastName  = $_SESSION['LastName'] ?? '';
$staffRole      = 'Staff';
$staffInitials  = strtoupper(substr($staffFirstName, 0, 1) . substr($staffLastName, 0, 1));
$staffName      = htmlspecialchars(trim($staffFirstName . ' ' . $staffLastName));


// ======================================================
// RESOLVE TARGET PATIENT
// ======================================================

$queueID       = (int) ($_GET['queue_id'] ?? ($_POST['queue_id'] ?? 0));
$appointmentID = (int) ($_GET['appointment_id'] ?? ($_POST['appointment_id'] ?? 0));
$patientID     = (int) ($_GET['patient_id'] ?? ($_POST['patient_id'] ?? 0));

$patient = null;

$patientLookup = mysqli_prepare(
    $conn,
    'SELECT
        q.QueueID,
        q.QueueNumber,
        q.Status AS QueueStatus,
        a.AppointmentID,
        a.AppointmentTime,
        a.Purpose,
        p.PatientID,
        d.DepartmentName,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Sex,
        u.DateOfBirth,
        u.ContactNumber
     FROM queue q
     INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
     INNER JOIN patients p ON a.PatientID = p.PatientID
     INNER JOIN users u ON p.UserID = u.UserID
     INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
     WHERE q.QueueID = ?
     LIMIT 1'
);
mysqli_stmt_bind_param($patientLookup, 'i', $queueID);
mysqli_stmt_execute($patientLookup);
$patient = mysqli_fetch_assoc(mysqli_stmt_get_result($patientLookup));

// Fallback: allow deep-linking by appointment + patient (no queue row required).
if (!$patient && $appointmentID > 0 && $patientID > 0) {
    $fallback = mysqli_prepare(
        $conn,
        'SELECT
            0 AS QueueID,
            0 AS QueueNumber,
            "" AS QueueStatus,
            a.AppointmentID,
            a.AppointmentTime,
            a.Purpose,
            p.PatientID,
            d.DepartmentName,
            u.FirstName,
            u.MiddleName,
            u.LastName,
            u.Sex,
            u.DateOfBirth,
            u.ContactNumber
         FROM appointments a
         INNER JOIN patients p ON a.PatientID = p.PatientID
         INNER JOIN users u ON p.UserID = u.UserID
         INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
         WHERE a.AppointmentID = ? AND p.PatientID = ?
         LIMIT 1'
    );
    mysqli_stmt_bind_param($fallback, 'ii', $appointmentID, $patientID);
    mysqli_stmt_execute($fallback);
    $patient = mysqli_fetch_assoc(mysqli_stmt_get_result($fallback));
}

if (!$patient) {
    $patientNotFound = true;
} else {
    $patientNotFound = false;
    $appointmentID = (int) $patient['AppointmentID'];
    $patientID     = (int) $patient['PatientID'];
}


// ======================================================
// EXISTING VITALS (latest + history)
// ======================================================

$latestVitals = [
    'blood_pressure'    => '',
    'temperature'       => '',
    'pulse_rate'        => '',
    'respiratory_rate'  => '',
    'weight'            => '',
    'height'            => '',
    'oxygen_saturation' => '',
];

$vitalsHistory = [];

if (!$patientNotFound) {
    $latestStmt = mysqli_prepare(
        $conn,
        'SELECT BloodPressure, Temperature, PulseRate, RespiratoryRate,
                Weight, Height, OxygenSaturation, RecordedAt
         FROM vitals
         WHERE PatientID = ?
         ORDER BY VitalID DESC
         LIMIT 1'
    );
    mysqli_stmt_bind_param($latestStmt, 'i', $patientID);
    mysqli_stmt_execute($latestStmt);
    $latestRow = mysqli_fetch_assoc(mysqli_stmt_get_result($latestStmt));

    if ($latestRow) {
        $latestVitals = [
            'blood_pressure'    => $latestRow['BloodPressure'] ?? '',
            'temperature'       => $latestRow['Temperature'] ?? '',
            'pulse_rate'        => $latestRow['PulseRate'] ?? '',
            'respiratory_rate'  => $latestRow['RespiratoryRate'] ?? '',
            'weight'            => $latestRow['Weight'] ?? '',
            'height'            => $latestRow['Height'] ?? '',
            'oxygen_saturation' => $latestRow['OxygenSaturation'] ?? '',
        ];
    }

    $historyStmt = mysqli_prepare(
        $conn,
        'SELECT BloodPressure, Temperature, PulseRate, RespiratoryRate,
                Weight, Height, OxygenSaturation, RecordedAt
         FROM vitals
         WHERE PatientID = ?
         ORDER BY VitalID DESC
         LIMIT 10'
    );
    mysqli_stmt_bind_param($historyStmt, 'i', $patientID);
    mysqli_stmt_execute($historyStmt);
    $historyResult = mysqli_stmt_get_result($historyStmt);
    while ($row = mysqli_fetch_assoc($historyResult)) {
        $vitalsHistory[] = $row;
    }
}


// ======================================================
// SAVE VITALS (POST)
// ======================================================

$message = '';
$messageType = '';
$abnormalItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_vitals'
    && !$patientNotFound
) {

    $bloodPressure   = trim($_POST['blood_pressure'] ?? '');
    $temperature     = trim($_POST['temperature'] ?? '');
    $pulseRate       = trim($_POST['pulse_rate'] ?? '');
    $respiratoryRate = trim($_POST['respiratory_rate'] ?? '');
    $weight          = trim($_POST['weight'] ?? '');
    $height          = trim($_POST['height'] ?? '');
    $oxygenSaturation = trim($_POST['oxygen_saturation'] ?? '');

    // Simple validation
    if ($bloodPressure === ''
        && $temperature === ''
        && $pulseRate === ''
        && $respiratoryRate === ''
        && $weight === ''
        && $height === ''
        && $oxygenSaturation === ''
    ) {
        $message = 'Enter at least one vital sign.';
        $messageType = 'error';
    } else {

        // Resolve staff (StaffID) for the logged-in user.
        $staffID = 0;
        $staffStmt = mysqli_prepare(
            $conn,
            'SELECT StaffID FROM staff WHERE UserID = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($staffStmt, 'i', $currentUserID);
        mysqli_stmt_execute($staffStmt);
        $staffRow = mysqli_fetch_assoc(mysqli_stmt_get_result($staffStmt));
        if ($staffRow) {
            $staffID = (int) $staffRow['StaffID'];
        }

        $insertStmt = mysqli_prepare(
            $conn,
            'INSERT INTO vitals
                (AppointmentID, PatientID, StaffID,
                 BloodPressure, Temperature, PulseRate, RespiratoryRate,
                 Weight, Height, OxygenSaturation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        mysqli_stmt_bind_param(
            $insertStmt,
            'iiisssssss',
            $appointmentID,
            $patientID,
            $staffID,
            $bloodPressure,
            $temperature === '' ? null : $temperature,
            $pulseRate === '' ? null : $pulseRate,
            $respiratoryRate === '' ? null : $respiratoryRate,
            $weight === '' ? null : $weight,
            $height === '' ? null : $height,
            $oxygenSaturation === '' ? null : $oxygenSaturation
        );

        if (mysqli_stmt_execute($insertStmt)) {
            $message = 'Vitals recorded successfully.';
            $messageType = 'success';

            // Abnormal alerts for the just-saved values.
            $saved = [
                'blood_pressure'    => $bloodPressure,
                'temperature'       => $temperature,
                'pulse_rate'        => $pulseRate,
                'respiratory_rate'  => $respiratoryRate,
                'weight'            => $weight,
                'height'            => $height,
                'oxygen_saturation' => $oxygenSaturation,
            ];
            $abnormalItems = abnormalVitals($saved);

            // Refresh latest + history.
            $latestVitals = $saved;

            $historyStmt = mysqli_prepare(
                $conn,
                'SELECT BloodPressure, Temperature, PulseRate, RespiratoryRate,
                        Weight, Height, OxygenSaturation, RecordedAt
                 FROM vitals
                 WHERE PatientID = ?
                 ORDER BY VitalID DESC
                 LIMIT 10'
            );
            mysqli_stmt_bind_param($historyStmt, 'i', $patientID);
            mysqli_stmt_execute($historyStmt);
            $vitalsHistory = [];
            $historyResult = mysqli_stmt_get_result($historyStmt);
            while ($row = mysqli_fetch_assoc($historyResult)) {
                $vitalsHistory[] = $row;
            }
        } else {
            $message = 'Unable to save vitals.';
            $messageType = 'error';
        }
    }
}

// Neutralize placeholder values shown with "null" bindings for the form.
foreach ($latestVitals as $k => $v) {
    if ($v === null) {
        $latestVitals[$k] = '';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Record Vitals — Staff Portal</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/staff/staff_dashboard.css">

<style>
  .vitals-wrap { padding: 24px 36px; }
  .vitals-page-title { font-size: 1.35rem; font-weight: 800; margin-bottom: 4px; }
  .vitals-page-sub { color: var(--color-ink-soft); font-size: 0.9rem; margin-bottom: 20px; }

  .vitals-patient-card {
    display: flex; align-items: center; gap: 14px;
    background: #fff; border: 1px solid var(--color-border);
    border-radius: 16px; padding: 16px 18px; margin-bottom: 20px;
  }
  .vitals-avatar {
    width: 48px; height: 48px; border-radius: 50%;
    background: var(--color-primary-tint); color: var(--color-primary-hover);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 1rem; flex-shrink: 0;
  }
  .vitals-pt-name { font-weight: 800; }
  .vitals-pt-meta { font-size: 0.82rem; color: var(--color-ink-soft); }

  .vitals-grid { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; }
  .vitals-grid .full { grid-column: 1 / -1; }

  .vitals-field label { display: block; font-size: 0.8rem; font-weight: 700; margin-bottom: 6px; color: var(--color-ink); }
  .vitals-field input {
    width: 100%; padding: 10px 12px; border: 1px solid var(--color-border);
    border-radius: 10px; font-family: var(--font-family); font-size: 0.9rem;
  }
  .vitals-field input:focus { outline: none; border-color: var(--color-primary); }

  .vitals-actions { display: flex; gap: 12px; margin-top: 20px; align-items: center; }
  .btn-save {
    background: var(--color-primary); color: #fff; border: none;
    padding: 11px 24px; border-radius: 10px; font-family: var(--font-family);
    font-weight: 700; font-size: 0.9rem; cursor: pointer; transition: background .15s;
  }
  .btn-save:hover { background: var(--color-primary-hover); }
  .btn-link {
    display: inline-flex; align-items: center; color: #fff; text-decoration: none;
    padding: 11px 20px; border-radius: 10px; background: var(--color-blue);
    font-weight: 700; font-size: 0.88rem;
  }
  .btn-link:hover { background: var(--color-blue-hover); }

  .alert-block { margin-top: 16px; border-radius: 10px; padding: 14px 16px; font-size: 0.88rem; }
  .alert-error { background: #fee2e2; color: #991b1b; }
  .alert-success { background: #dcfce7; color: #166534; }
  .alert-warn { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }

  .abnormal-list { list-style: none; margin-top: 10px; }
  .abnormal-list li { padding: 8px 12px; margin-bottom: 6px; border-radius: 8px; background: #fff5f5; border: 1px solid #fecaca; color: #991b1b; font-weight: 600; }

  .hist-title { margin: 26px 0 12px; font-weight: 800; font-size: 1rem; }
  .hist-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; font-size: 0.82rem; }
  .hist-table th, .hist-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
  .hist-table th { background: #f8fafc; font-weight: 700; color: var(--color-ink-soft); }

  .vital-badge { display: inline-block; font-size: 0.7rem; font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-left: 6px; }
  .vital-badge-high { background: #fee2e2; color: #b91c1c; }
  .vital-badge-low { background: #e0e7ff; color: #3730a3; }

  @media (max-width: 900px) { .vitals-grid { grid-template-columns: 1fr 1fr; } }
</style>

</head>

<body>

<div class="app">

  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/></svg>
      </div>
      <div class="brand-text">
        <div class="brand-title">MediCare</div>
        <div class="brand-sub">Staff Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item"><a href="../staff/queue.php" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;width:100%;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>Queue</a></li>
      <li class="nav-item"><a href="../staff/checkin_patient.php" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;width:100%;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>Patient Check-in</a></li>
      <li class="nav-item active"><a href="../staff/queue.php" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;width:100%;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>Queue</a></li>
      <li class="nav-item"><a href="staff_profile.php" style="display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;width:100%;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Profile</a></li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar"><?= htmlspecialchars($staffInitials) ?></div>
        <div>
          <div class="user-name"><?= $staffName ?></div>
          <div class="user-role"><?= htmlspecialchars($staffRole) ?></div>
        </div>
      </div>
      <a class="sign-out" href="../auth/logout.php" style="text-decoration:none;cursor:pointer;display:block;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        <span>Sign Out</span>
      </a>
    </div>
  </aside>

  <main class="main">

    <div class="vitals-wrap">

      <div class="vitals-page-title">Record Vitals</div>
      <div class="vitals-page-sub">Pre-consultation vital signs capture for the selected patient</div>

      <?php if ($patientNotFound): ?>

        <div class="alert-block alert-error">
          Patient record not found. Return to the queue to select a patient.
          <div style="margin-top:12px;">
            <a class="btn-link" href="../staff/queue.php">&larr; Back to Queue</a>
          </div>
        </div>

      <?php else: ?>

        <div class="vitals-patient-card">
          <div class="vitals-avatar">
            <?= strtoupper(substr($patient['FirstName'], 0, 1) . substr($patient['LastName'], 0, 1)) ?>
          </div>
          <div>
            <div class="vitals-pt-name">
              <?= htmlspecialchars($patient['FirstName'] . ' ' . $patient['LastName']) ?>
            </div>
            <div class="vitals-pt-meta">
              Q<?= str_pad((int)$patient['QueueNumber'], 3, '0', STR_PAD_LEFT) ?>
              &bull; <?= htmlspecialchars($patient['DepartmentName']) ?>
              &bull; Appt <?= htmlspecialchars(date('h:i A', strtotime($patient['AppointmentTime']))) ?>
              <?php if ($patient['ContactNumber']): ?>
                &bull; <?= htmlspecialchars($patient['ContactNumber']) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($message !== ''): ?>
          <div class="alert-block <?= $messageType === 'success' ? 'alert-success' : 'alert-error' ?>">
            <?= htmlspecialchars($message) ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($abnormalItems)): ?>
          <div class="alert-block alert-warn">
            <strong>Abnormal vitals alert.</strong> Review the following readings:
            <ul class="abnormal-list">
              <?php foreach ($abnormalItems as $item): ?>
                <li>
                  <?= htmlspecialchars($item['label']) ?>:
                  <?= htmlspecialchars($item['value'] . ' ' . $item['unit']) ?>
                  &mdash; <?= htmlspecialchars($item['note']) ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="action" value="save_vitals">
          <input type="hidden" name="queue_id" value="<?= (int)$patient['QueueID'] ?>">
          <input type="hidden" name="appointment_id" value="<?= (int)$patient['AppointmentID'] ?>">
          <input type="hidden" name="patient_id" value="<?= (int)$patient['PatientID'] ?>">

          <div class="vitals-grid">

            <div class="vitals-field">
              <label for="blood_pressure">Blood Pressure (mmHg)</label>
              <input type="text" id="blood_pressure" name="blood_pressure" placeholder="120/80" value="<?= htmlspecialchars($latestVitals['blood_pressure']) ?>">
            </div>

            <div class="vitals-field">
              <label for="temperature">Temperature (&deg;C)</label>
              <input type="number" step="0.1" id="temperature" name="temperature" placeholder="36.8" value="<?= htmlspecialchars($latestVitals['temperature']) ?>">
            </div>

            <div class="vitals-field">
              <label for="pulse_rate">Pulse (bpm)</label>
              <input type="number" id="pulse_rate" name="pulse_rate" placeholder="72" value="<?= htmlspecialchars($latestVitals['pulse_rate']) ?>">
            </div>

            <div class="vitals-field">
              <label for="respiratory_rate">Respiratory Rate (/min)</label>
              <input type="number" id="respiratory_rate" name="respiratory_rate" placeholder="16" value="<?= htmlspecialchars($latestVitals['respiratory_rate']) ?>">
            </div>

            <div class="vitals-field">
              <label for="weight">Weight (kg)</label>
              <input type="number" step="0.01" id="weight" name="weight" placeholder="65.0" value="<?= htmlspecialchars($latestVitals['weight']) ?>">
            </div>

            <div class="vitals-field">
              <label for="height">Height (cm)</label>
              <input type="number" step="0.01" id="height" name="height" placeholder="170" value="<?= htmlspecialchars($latestVitals['height']) ?>">
            </div>

            <div class="vitals-field">
              <label for="oxygen_saturation">Oxygen Saturation / SpO2 (%)</label>
              <input type="number" id="oxygen_saturation" name="oxygen_saturation" placeholder="98" value="<?= htmlspecialchars($latestVitals['oxygen_saturation']) ?>">
            </div>

          </div>

          <div class="vitals-actions">
            <button class="btn-save" type="submit">Save Vitals</button>
            <a class="btn-link" href="../staff/queue.php">&larr; Back to Queue</a>
          </div>
        </form>

        <?php if (!empty($vitalsHistory)): ?>
          <div class="hist-title">Vitals History</div>
          <table class="hist-table">
            <thead>
              <tr>
                <th>Recorded</th>
                <th>BP</th>
                <th>Temp</th>
                <th>Pulse</th>
                <th>Resp</th>
                <th>Wt (kg)</th>
                <th>Ht (cm)</th>
                <th>SpO2</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($vitalsHistory as $h): ?>
                <?php $hItems = classifyVitals([
                    'blood_pressure'    => $h['BloodPressure'] ?? '',
                    'temperature'       => $h['Temperature'] ?? '',
                    'pulse_rate'        => $h['PulseRate'] ?? '',
                    'respiratory_rate'  => $h['RespiratoryRate'] ?? '',
                    'oxygen_saturation' => $h['OxygenSaturation'] ?? '',
                ]); ?>
                <tr>
                  <td><?= htmlspecialchars(date('M d, g:i A', strtotime($h['RecordedAt']))) ?></td>
                  <td><?= htmlspecialchars($h['BloodPressure'] ?? '') ?><?php if (($hItems[0]['status'] ?? '') !== 'normal') { echo vitalStatusBadge($hItems[0]); } ?></td>
                  <td><?= htmlspecialchars($h['Temperature'] ?? '') ?><?php if (($hItems[1]['status'] ?? '') !== 'normal') { echo vitalStatusBadge($hItems[1]); } ?></td>
                  <td><?= htmlspecialchars($h['PulseRate'] ?? '') ?><?php if (($hItems[2]['status'] ?? '') !== 'normal') { echo vitalStatusBadge($hItems[2]); } ?></td>
                  <td><?= htmlspecialchars($h['RespiratoryRate'] ?? '') ?><?php if (($hItems[3]['status'] ?? '') !== 'normal') { echo vitalStatusBadge($hItems[3]); } ?></td>
                  <td><?= htmlspecialchars($h['Weight'] ?? '') ?></td>
                  <td><?= htmlspecialchars($h['Height'] ?? '') ?></td>
                  <td><?= htmlspecialchars($h['OxygenSaturation'] ?? '') ?><?php if (($hItems[4]['status'] ?? '') !== 'normal') { echo vitalStatusBadge($hItems[4]); } ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      <?php endif; ?>

    </div>

  </main>

</div>

</body>

</html>
