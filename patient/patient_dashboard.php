<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php?portal=patient');
    exit();
}

if ($_SESSION['RoleName'] !== 'Patient') {
    header('Location: ../portal-select.php?action=login');
    exit();
}

$userID = $_SESSION['UserID'];

$stmt = mysqli_prepare(
    $conn,
    'SELECT
        u.UserID,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Email,
        u.Sex,
        u.DateOfBirth,
        u.ContactNumber,
        u.Address,
        u.ProfilePhoto,
        p.PatientID,
        p.BloodType,
        p.Allergies,
        p.PastMedicalCondition,
        p.CurrentMedication,
        p.FamilyMedicalHistory,
        p.EmergencyContactName,
        p.EmergencyContactNo,
        p.EmergencyRelation
     FROM users u
     INNER JOIN patients p ON u.UserID = p.UserID
     WHERE u.UserID = ?'
);

mysqli_stmt_bind_param($stmt, 'i', $userID);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$patient = mysqli_fetch_assoc($result);

if (!$patient) {
    die('Patient profile not found.');
}

// Get the patient's upcoming appointment
$appointmentStmt = mysqli_prepare(
    $conn,
    'SELECT
        a.AppointmentID,
        a.AppointmentDate,
        a.AppointmentTime,
        a.Purpose,
        a.Status,

        d.DepartmentName,

        s.StaffID,
        s.StaffRole,
        s.Specialization,

        u.FirstName AS StaffFirstName,
        u.LastName AS StaffLastName

     FROM appointments a

     INNER JOIN departments d
        ON a.DepartmentID = d.DepartmentID

     LEFT JOIN staff s
        ON a.StaffID = s.StaffID

     LEFT JOIN users u
        ON s.UserID = u.UserID

     WHERE a.PatientID = ?
       AND a.AppointmentDate >= CURDATE()
       AND a.Status IN ("Pending", "Scheduled", "Confirmed")

     ORDER BY
        a.AppointmentDate ASC,
        a.AppointmentTime ASC

     LIMIT 1'
);

mysqli_stmt_bind_param(
    $appointmentStmt,
    'i',
    $patient['PatientID']
);

mysqli_stmt_execute($appointmentStmt);

$appointmentResult = mysqli_stmt_get_result($appointmentStmt);

$appointment = mysqli_fetch_assoc($appointmentResult);

// Get queue status for this patient
$queueStmt = mysqli_prepare(
    $conn,
    'SELECT
        q.QueueID,
        q.QueueNumber,
        q.Status AS QueueStatus,
        q.PriorityLevel,
        q.QueueDate,
        q.QueueTime,
        d.DepartmentName
     FROM queue q
     INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
     INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
     WHERE a.PatientID = ?
       AND q.Status IN ("Waiting", "Called", "In Progress")
     ORDER BY q.CreatedAt DESC
     LIMIT 1'
);

mysqli_stmt_bind_param($queueStmt, 'i', $patient['PatientID']);
mysqli_stmt_execute($queueStmt);
$queueResult = mysqli_stmt_get_result($queueStmt);
$queue = mysqli_fetch_assoc($queueResult);

// Get current serving number for the same department (if patient has a queue)
$nowServing = null;
if ($queue) {
    $servingStmt = mysqli_prepare(
        $conn,
        'SELECT QueueNumber
         FROM queue
         WHERE Status = "In Progress"
         ORDER BY UpdatedAt DESC
         LIMIT 1'
    );
    mysqli_stmt_execute($servingStmt);
    $servingResult = mysqli_stmt_get_result($servingStmt);
    $nowServing = mysqli_fetch_assoc($servingResult);
}

// Get recent notifications for this patient
$notifStmt = mysqli_prepare(
    $conn,
    'SELECT
        NotificationID,
        Title,
        Message,
        Type,
        IsRead,
        CreatedAt
     FROM notifications
     WHERE UserID = ?
     ORDER BY IsRead ASC, CreatedAt DESC
     LIMIT 5'
);

mysqli_stmt_bind_param($notifStmt, 'i', $userID);
mysqli_stmt_execute($notifStmt);
$notifResult = mysqli_stmt_get_result($notifStmt);

$notifications = [];
while ($row = mysqli_fetch_assoc($notifResult)) {
    $row['IsRead'] = (int) $row['IsRead'];
    $row['DisplayTime'] = $row['SentAt'] ?? $row['CreatedAt'];
    $notifications[] = $row;
}

// Get recent consultations count
$consultCountStmt = mysqli_prepare(
    $conn,
    'SELECT COUNT(*) AS total
     FROM consultations
     WHERE PatientID = ?'
);
mysqli_stmt_bind_param($consultCountStmt, 'i', $patient['PatientID']);
mysqli_stmt_execute($consultCountStmt);
$consultCountResult = mysqli_stmt_get_result($consultCountStmt);
$consultCount = mysqli_fetch_assoc($consultCountResult);

// Get total appointments count
$apptCountStmt = mysqli_prepare(
    $conn,
    'SELECT COUNT(*) AS total
     FROM appointments
     WHERE PatientID = ?'
);
mysqli_stmt_bind_param($apptCountStmt, 'i', $patient['PatientID']);
mysqli_stmt_execute($apptCountStmt);
$apptCountResult = mysqli_stmt_get_result($apptCountStmt);
$apptCount = mysqli_fetch_assoc($apptCountResult);

// Helper function for time ago
function timeAgo($datetime) {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) {
        if ($diff->d === 1) return 'Yesterday';
        if ($diff->d < 7) return $diff->d . ' days ago';
        return floor($diff->d / 7) . ' week' . (floor($diff->d / 7) > 1 ? 's' : '') . ' ago';
    }
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

// Get notification dot color based on type
function getNotifDotColor($type) {
    $type = strtolower($type ?? '');
    if (strpos($type, 'appointment') !== false || strpos($type, 'reminder') !== false) return 'green';
    if (strpos($type, 'queue') !== false || strpos($type, 'urgent') !== false) return 'red';
    if (strpos($type, 'result') !== false || strpos($type, 'lab') !== false) return 'amber';
    return 'green';
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediCare Patient Portal</title>
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
      <li class="nav-item active">
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
      <li class="nav-item">
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
        <div class="user-avatar"><img src="../<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo strtoupper(substr($patient['FirstName'], 0, 1) . substr($patient['LastName'], 0, 1)); ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($patient['FirstName'] . ' ' . $patient['LastName']); ?></div>
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
      <h1>Welcome, <?php echo htmlspecialchars($patient['FirstName'] . ' ' . $patient['LastName']); ?>!</h1>
      <p>Here's your health overview for today</p>
    </div>

    <!-- Quick actions -->
    <div class="quick-actions">
      <a href="book_appointment.php" class="action-card" style="text-decoration:none;color:inherit;">
        <div class="action-icon teal">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 14v4M10 16h4"/></svg>
        </div>
        <div>
          <div class="action-title">Book Appointment</div>
          <div class="action-sub">Schedule a visit</div>
        </div>
      </a>
      <a href="view_results.php" class="action-card" style="text-decoration:none;color:inherit;">
        <div class="action-icon amber">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
        </div>
        <div>
          <div class="action-title">View Results</div>
          <div class="action-sub">Prescriptions & labs</div>
        </div>
      </a>
      <a href="queue_status.php" class="action-card" style="text-decoration:none;color:inherit;">
        <div class="action-icon blue">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </div>
        <div>
          <div class="action-title">Queue Status</div>
          <div class="action-sub">Track your position</div>
        </div>
      </a>
      <a href="consultation_history.php" class="action-card" style="text-decoration:none;color:inherit;">
        <div class="action-icon green">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div>
          <div class="action-title">History</div>
          <div class="action-sub">Past consultations</div>
        </div>
      </a>
    </div>

    <!-- Content grid -->
    <div class="content-grid">

      <!-- Left column -->
      <div>
        <div class="panel">
          <div class="panel-head">
            <h2>Upcoming Appointment</h2>
            <a href="patient_appointment.php">View all</a>
          </div>

          <?php if ($appointment): ?>

    <?php
        $appointmentDate = new DateTime($appointment['AppointmentDate']);
        $appointmentTime = new DateTime($appointment['AppointmentTime']);

        $month = strtoupper($appointmentDate->format('M'));
        $day = $appointmentDate->format('d');
        $formattedTime = $appointmentTime->format('g:i A');
    ?>

    <div class="appt-card">

        <div class="appt-date">
            <div class="month">
                <?php echo $month; ?>
            </div>

            <div class="day">
                <?php echo $day; ?>
            </div>
        </div>

        <div class="appt-info">

            <div class="dept">
                <?php echo htmlspecialchars($appointment['DepartmentName']); ?>
            </div>

            <div class="doc">
                <?php if (!empty($appointment['StaffFirstName'])): ?>

                    Dr.
                    <?php echo htmlspecialchars($appointment['StaffFirstName']); ?>
                    <?php echo htmlspecialchars($appointment['StaffLastName']); ?>

                <?php else: ?>

                    Doctor not yet assigned

                <?php endif; ?>
            </div>

            <div class="appt-meta">

                <span>
                    <svg viewBox="0 0 24 24" fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round">

                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>

                    </svg>

                    <?php echo $formattedTime; ?>
                </span>

                <span>
                    <svg viewBox="0 0 24 24" fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round">

                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>

                    </svg>

                    Department Appointment
                </span>

            </div>

        </div>

        <span class="badge">
            <?php echo htmlspecialchars($appointment['Status']); ?>
        </span>

    </div>

<?php else: ?>

    <div class="appt-card">
        <div class="appt-info">
            <div class="dept">No Upcoming Appointment</div>
            <div class="doc">You currently have no scheduled appointments.</div>
        </div>
    </div>

<?php endif; ?>

          <a href="patient_appointment.php" class="view-all-link">View All Appointments</a>
        </div>
      </div>

      <!-- Right column -->
      <div>
        <div class="panel">
          <div class="panel-head">
            <h2>Queue Status</h2>
            <a href="queue_status.php">View Details</a>
          </div>

          <?php if ($queue): ?>
          <div class="queue-number">
            <div class="queue-label">Your Queue Number</div>
            <div class="queue-value">Q-<?php echo htmlspecialchars($queue['QueueNumber']); ?></div>
            <span class="queue-status-pill">
              <span class="dot"></span>
              <?php echo htmlspecialchars($queue['QueueStatus']); ?>
            </span>
          </div>
          <div class="queue-details">
            <div class="queue-row">
              <span class="label">Now Serving</span>
              <span class="value"><?php echo $nowServing ? 'Q-' . htmlspecialchars($nowServing['QueueNumber']) : '—'; ?></span>
            </div>
            <div class="queue-row">
              <span class="label">Scheduled</span>
              <span class="value"><?php echo date('g:i A', strtotime($queue['QueueTime'])); ?></span>
            </div>
            <div class="queue-row">
              <span class="label">Department</span>
              <span class="value"><?php echo htmlspecialchars($queue['DepartmentName']); ?></span>
            </div>
          </div>
          <?php else: ?>
          <div class="queue-number">
            <div class="queue-label">No Active Queue</div>
            <div class="queue-value" style="font-size: 1.2rem; color: var(--color-ink-soft);">—</div>
            <span class="queue-status-pill" style="background: #E5E7EB; color: #6B7280;">No Queue</span>
          </div>
          <div class="queue-details">
            <div class="queue-row"><span class="label">Status</span><span class="value">Not in queue</span></div>
          </div>
          <?php endif; ?>
        </div>

        <div class="panel notif-panel">
          <div class="panel-head">
            <h2>Notifications</h2>
            <a href="notifications.php">View All</a>
          </div>

          <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $notif): ?>
              <div class="notif-item <?php echo !$notif['IsRead'] ? 'unread' : ''; ?>">
                <span class="notif-dot <?php echo getNotifDotColor($notif['Type']); ?>"></span>
                <div>
                  <div class="notif-text"><?php echo htmlspecialchars($notif['Message']); ?></div>
                  <div class="notif-time"><?php echo timeAgo($notif['DisplayTime']); ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="notif-item">
              <div>
                <div class="notif-text" style="color: var(--color-ink-soft);">No notifications yet</div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </main>

</div>
</body>
</html>