<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php?portal=patient');
    exit();
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    header('Location: ../portal-select.php?action=login');
    exit();
}

$userID = (int) $_SESSION['UserID'];

// Get patient info
$patientStmt = mysqli_prepare(
    $conn,
    'SELECT
        p.PatientID,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.ProfilePhoto
     FROM patients p
     INNER JOIN users u ON p.UserID = u.UserID
     WHERE p.UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param($patientStmt, 'i', $userID);
mysqli_stmt_execute($patientStmt);
$patientResult = mysqli_stmt_get_result($patientStmt);
$patient = mysqli_fetch_assoc($patientResult);

if (!$patient) {
    die('Patient profile not found.');
}

$patientID = (int) $patient['PatientID'];
$initials = strtoupper(substr($patient['FirstName'], 0, 1) . substr($patient['LastName'], 0, 1));
$fullName = trim($patient['FirstName'] . ' '
    . ($patient['MiddleName'] ? $patient['MiddleName'] . ' ' : '')
    . $patient['LastName']);

// Get all consultations for this patient
$consultStmt = mysqli_prepare(
    $conn,
    'SELECT
        c.ConsultationID,
        c.ConsultationDate,
        c.Diagnosis,
        c.Treatment,
        c.LabRequest,
        c.Notes,
        c.FollowUpDate,
        c.Status,
        c.ChiefComplaint,
        CONCAT(docUser.FirstName, " ", docUser.LastName) AS DoctorName,
        d.DepartmentName
     FROM consultations c
     INNER JOIN staff s ON c.StaffID = s.StaffID
     INNER JOIN users docUser ON s.UserID = docUser.UserID
     INNER JOIN departments d ON s.DepartmentID = d.DepartmentID
     WHERE c.PatientID = ?
     ORDER BY c.ConsultationDate DESC, c.ConsultationTime DESC'
);

mysqli_stmt_bind_param($consultStmt, 'i', $patientID);
mysqli_stmt_execute($consultStmt);
$consultResult = mysqli_stmt_get_result($consultStmt);

$consultations = [];
while ($row = mysqli_fetch_assoc($consultResult)) {
    $consultations[] = $row;
}

// Parse prescriptions from Treatment field
function parsePrescriptions($treatment) {
    $prescriptions = [];
    if (empty($treatment)) return $prescriptions;

    $lines = preg_split('/\r\n|\r|\n/', $treatment);
    $inPrescriptions = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (stripos($line, 'Prescriptions:') !== false) {
            $inPrescriptions = true;
            continue;
        }

        if ($inPrescriptions && preg_match('/^-\s*(.+)$/i', $line, $matches)) {
            $prescriptions[] = trim($matches[1]);
        } elseif (!$inPrescriptions && preg_match('/^-\s*(.+)$/i', $line, $matches)) {
            $prescriptions[] = trim($matches[1]);
        }
    }

    return $prescriptions;
}

// Parse lab requests
function parseLabRequests($labRequest) {
    $requests = [];
    if (empty($labRequest)) return $requests;

    $lines = preg_split('/\r\n|\r|\n/', $labRequest);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') $requests[] = $line;
    }

    return $requests;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Consultation History — MediCare Patient Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/patient/patient_dashboard.css">
</head>
<body>
<div class="app">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </div>
      <div class="brand-text">
        <div class="brand-title">MediCare</div>
        <div class="brand-sub">Patient Portal</div>
      </div>
    </div>

    <ul class="nav-list">
  <li>
    <a href="patient_dashboard.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
      Dashboard
    </a>
  </li>
  <li>
    <a href="patient_appointment.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      Appointments
    </a>
  </li>
  <li>
    <a href="queue_status.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
      Queue Status
    </a>
  </li>
  <li>
    <a href="view_results.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
      View Results
    </a>
  </li>
  <li>
    <a href="consultation_history.php" class="nav-item active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Consultation History
    </a>
  </li>
  <li>
    <a href="notifications.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      Notifications
    </a>
  </li>
  <li>
    <a href="patient_profile.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Profile
    </a>
  </li>
</ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <?php if (!empty($patient['ProfilePhoto'])): ?>
        <div class="user-avatar"><img src="../<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo $initials; ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
          <div class="user-role">Patient</div>
        </div>
      </div>
      <a class="sign-out" href="../auth/logout.php" onclick="return confirm('Are you sure you want to sign out?');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="page-header">
      <h1>Consultation History</h1>
      <p>Your past visits and diagnoses</p>
    </div>

    <?php if (empty($consultations)): ?>
      <div class="empty-state">
        <div class="empty-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <h3>No Consultation History</h3>
        <p>Your past consultations will appear here once you complete a visit with a doctor.</p>
      </div>
    <?php else: ?>
      <?php foreach ($consultations as $consult): ?>
        <?php
          $prescriptions = parsePrescriptions($consult['Treatment']);
          $labRequests = parseLabRequests($consult['LabRequest']);
          $hasPrescriptions = !empty($prescriptions);
          $hasLabRequests = !empty($labRequests);
        ?>
        <div class="history-card" onclick="toggleHistoryCard(this)">
          <div class="history-card-header">
            <div class="history-card-left">
              <div class="history-card-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/></svg>
              </div>
              <div class="history-card-info">
                <div class="history-card-title"><?php echo htmlspecialchars($consult['Diagnosis'] ?: ($consult['ChiefComplaint'] ?: 'Consultation')); ?></div>
                <div class="history-card-meta">
                  <?php echo htmlspecialchars($consult['ConsultationDate']); ?>
                  <span class="sep">|</span>
                  Dr. <?php echo htmlspecialchars($consult['DoctorName']); ?>
                  <span class="sep">(</span><?php echo htmlspecialchars($consult['DepartmentName']); ?><span class="sep">)</span>
                </div>
              </div>
            </div>
            <div class="history-card-right">
              <div class="history-card-badges">
                <?php if ($hasPrescriptions): ?>
                  <span class="history-badge prescription">Prescription</span>
                <?php endif; ?>
                <?php if ($hasLabRequests): ?>
                  <span class="history-badge lab">Lab</span>
                <?php endif; ?>
              </div>
              <div class="history-card-chevron">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
              </div>
            </div>
          </div>
          <div class="history-card-body">
            <?php if ($hasPrescriptions): ?>
              <div class="history-section">
                <div class="history-section-label">Prescription</div>
                <div class="history-section-content">
                  <?php echo nl2br(htmlspecialchars(implode("\n", $prescriptions))); ?>
                </div>
              </div>
            <?php endif; ?>
            <?php if ($hasLabRequests): ?>
              <div class="history-section">
                <div class="history-section-label">Lab Request</div>
                <div class="history-section-content">
                  <?php echo nl2br(htmlspecialchars(implode("\n", $labRequests))); ?>
                </div>
              </div>
            <?php endif; ?>
            <?php if (!$hasPrescriptions && !$hasLabRequests): ?>
              <div class="history-section">
                <div class="history-section-label">Notes</div>
                <div class="history-section-content">
                  <?php echo htmlspecialchars($consult['Notes'] ?: 'No additional notes recorded.'); ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </main>

</div>

<script>
function toggleHistoryCard(card) {
  const body = card.querySelector('.history-card-body');
  const chevron = card.querySelector('.history-card-chevron');
  const isOpen = card.classList.contains('open');

  if (isOpen) {
    card.classList.remove('open');
    body.style.maxHeight = '0';
    chevron.style.transform = 'rotate(0deg)';
  } else {
    card.classList.add('open');
    body.style.maxHeight = body.scrollHeight + 'px';
    chevron.style.transform = 'rotate(180deg)';
  }
}
</script>

</body>
</html>
