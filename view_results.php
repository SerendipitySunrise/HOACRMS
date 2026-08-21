<?php
session_start();

require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header('Location: login.php?portal=patient');
    exit();
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    header('Location: portal-select.php?action=login');
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

// Get consultations with prescriptions
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
        c.BloodPressure,
        c.Temperature,
        c.PulseRate,
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
<title>View Results — MediCare Patient Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/patient_dashboard.css">
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
      <li class="nav-item">
        <a href="patient_dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
        Dashboard
      </li>
      <li class="nav-item">
        <a href="patient_appointment.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Appointments
      </li>
      <li class="nav-item">
        <a href="queue_status.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
        Queue Status
      </li>
      <li class="nav-item active">
        <a href="view_results.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
        View Results
      </li>
      <li class="nav-item">
        <a href="consultation_history.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Consultation History
      </li>
      <li class="nav-item">
        <a href="notifications.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
      </li>
      <li class="nav-item">
        <a href="patient_profile.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <?php if (!empty($patient['ProfilePhoto'])): ?>
        <div class="user-avatar"><img src="<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo $initials; ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
          <div class="user-role">Patient</div>
        </div>
      </div>
      <a class="sign-out" href="logout.php" onclick="return confirm('Are you sure you want to sign out?');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="page-header">
      <h1>View Results</h1>
      <p>Your prescriptions and lab requests</p>
    </div>

    <!-- Tab Switcher -->
    <div class="tab-switch">
      <button class="tab-btn active" onclick="switchResultTab('prescriptions')">Prescriptions</button>
      <button class="tab-btn" onclick="switchResultTab('lab-requests')">Lab Requests</button>
    </div>

    <!-- Prescriptions Tab -->
    <div class="result-tab-content active" id="tab-prescriptions">
      <?php if (empty($consultations)): ?>
        <div class="empty-state">
          <div class="empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          </div>
          <h3>No Results Yet</h3>
          <p>Your consultation results will appear here once your doctor completes a visit.</p>
        </div>
      <?php else: ?>
        <?php foreach ($consultations as $consult): ?>
          <?php $prescriptions = parsePrescriptions($consult['Treatment']); ?>
          <?php if (!empty($prescriptions)): ?>
            <?php foreach ($prescriptions as $index => $prescription): ?>
              <div class="result-card" onclick="toggleCard(this)">
                <div class="result-card-header">
                  <div class="result-card-left">
                    <div class="result-card-icon">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    </div>
                    <div class="result-card-info">
                      <div class="result-card-title-row">
                        <span class="result-card-title"><?php echo htmlspecialchars($prescription); ?></span>
                        <span class="result-status-badge <?php echo $consult['Status'] === 'Completed' ? 'completed' : 'active'; ?>">
                          <?php echo htmlspecialchars($consult['Status']); ?>
                        </span>
                      </div>
                      <div class="result-card-meta">
                        Dr. <?php echo htmlspecialchars($consult['DoctorName']); ?>
                        <span class="sep">—</span>
                        <?php echo htmlspecialchars($consult['ConsultationDate']); ?>
                      </div>
                    </div>
                  </div>
                  <div class="result-card-chevron">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                  </div>
                </div>
                <div class="result-card-body">
                  <div class="result-detail-grid">
                    <div class="result-detail">
                      <div class="result-detail-label">Diagnosis</div>
                      <div class="result-detail-value"><?php echo htmlspecialchars($consult['Diagnosis'] ?: 'Not specified'); ?></div>
                    </div>
                    <div class="result-detail">
                      <div class="result-detail-label">Department</div>
                      <div class="result-detail-value"><?php echo htmlspecialchars($consult['DepartmentName']); ?></div>
                    </div>
                    <div class="result-detail">
                      <div class="result-detail-label">Chief Complaint</div>
                      <div class="result-detail-value"><?php echo htmlspecialchars($consult['ChiefComplaint'] ?: 'Not specified'); ?></div>
                    </div>
                    <div class="result-detail">
                      <div class="result-detail-label">Consultation Date</div>
                      <div class="result-detail-value"><?php echo htmlspecialchars($consult['ConsultationDate']); ?></div>
                    </div>
                    <?php if (!empty($consult['FollowUpDate'])): ?>
                    <div class="result-detail">
                      <div class="result-detail-label">Follow-Up Date</div>
                      <div class="result-detail-value"><?php echo htmlspecialchars($consult['FollowUpDate']); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($consult['Notes'])): ?>
                    <div class="result-detail full-width">
                      <div class="result-detail-label">Doctor's Notes</div>
                      <div class="result-detail-value"><?php echo nl2br(htmlspecialchars($consult['Notes'])); ?></div>
                    </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php
        $hasAny = false;
        foreach ($consultations as $c) {
            if (!empty(parsePrescriptions($c['Treatment']))) {
                $hasAny = true;
                break;
            }
        }
        if (!$hasAny):
        ?>
          <div class="empty-state">
            <div class="empty-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h3>No Prescriptions Found</h3>
            <p>No prescriptions have been recorded for your consultations yet.</p>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Lab Requests Tab -->
    <div class="result-tab-content" id="tab-lab-requests">
      <?php if (empty($consultations)): ?>
        <div class="empty-state">
          <div class="empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
          </div>
          <h3>No Results Yet</h3>
          <p>Your lab requests will appear here once your doctor orders tests.</p>
        </div>
      <?php else: ?>
        <?php $hasLabRequests = false; ?>
        <?php foreach ($consultations as $consult): ?>
          <?php $labRequests = parseLabRequests($consult['LabRequest']); ?>
          <?php if (!empty($labRequests)): ?>
            <?php $hasLabRequests = true; ?>
            <?php foreach ($labRequests as $labRequest): ?>
              <?php $isCompleted = $consult['Status'] === 'Completed'; ?>
              <div class="result-card lab-card" onclick="toggleCard(this)">
                <div class="result-card-header">
                  <div class="result-card-left">
                    <div class="result-card-icon lab <?php echo $isCompleted ? 'completed' : 'pending'; ?>">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6v11l4 6H5l4-6z"/><line x1="9" y1="3" x2="15" y2="3"/></svg>
                    </div>
                    <div class="result-card-info">
                      <div class="result-card-title-row">
                        <span class="result-card-title"><?php echo htmlspecialchars($labRequest); ?></span>
                        <span class="result-status-badge lab <?php echo $isCompleted ? 'completed' : 'pending'; ?>">
                          <?php echo $isCompleted ? 'Completed' : 'Pending'; ?>
                        </span>
                      </div>
                      <div class="result-card-meta">
                        Requested: <?php echo htmlspecialchars($consult['ConsultationDate']); ?>
                      </div>
                    </div>
                  </div>
                  <div class="result-card-chevron">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                  </div>
                </div>
                <div class="result-card-body">
                  <div class="lab-card-expanded">
                    <div class="lab-detail-section">
                      <div class="lab-detail-label">Instructions</div>
                      <div class="lab-detail-value"><?php echo htmlspecialchars($consult['Notes'] ?: 'No additional instructions provided.'); ?></div>
                    </div>
                    <div class="lab-card-footer">
                      <div class="lab-footer-item">
                        <span class="lab-footer-label">Status</span>
                        <span class="lab-footer-value status-text <?php echo $isCompleted ? 'completed' : 'waiting'; ?>">
                          <?php echo $isCompleted ? 'Completed' : 'Pending'; ?>
                        </span>
                      </div>
                      <div class="lab-footer-item">
                        <span class="lab-footer-label">Ordered by</span>
                        <span class="lab-footer-value">Dr. <?php echo htmlspecialchars($consult['DoctorName']); ?></span>
                      </div>
                      <div class="lab-footer-item">
                        <span class="lab-footer-label">Department</span>
                        <span class="lab-footer-value"><?php echo htmlspecialchars($consult['DepartmentName']); ?></span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$hasLabRequests): ?>
          <div class="empty-state">
            <div class="empty-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h3>No Lab Requests Found</h3>
            <p>No lab tests have been ordered for your consultations yet.</p>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </main>

</div>

<script>
function switchResultTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelectorAll('.result-tab-content').forEach(content => content.classList.remove('active'));

  if (tab === 'prescriptions') {
    document.querySelectorAll('.tab-btn')[0].classList.add('active');
    document.getElementById('tab-prescriptions').classList.add('active');
  } else {
    document.querySelectorAll('.tab-btn')[1].classList.add('active');
    document.getElementById('tab-lab-requests').classList.add('active');
  }
}

function toggleCard(card) {
  const body = card.querySelector('.result-card-body');
  const chevron = card.querySelector('.result-card-chevron');
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
