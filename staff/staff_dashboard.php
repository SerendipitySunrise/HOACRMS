<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/status_constants.php';

/* -------------------------------------------------------
   AUTH GUARD
------------------------------------------------------- */
if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php?portal=staff&expired=1');
    exit();
}

$sessionRole = strtolower(trim((string) ($_SESSION['RoleName'] ?? '')));
if (!in_array($sessionRole, ['staff', 'nurse'], true)) {
    header('Location: ../auth/login.php?portal=staff');
    exit();
}

$userId = (int) $_SESSION['UserID'];

/* -------------------------------------------------------
   GET STAFF + DEPARTMENT INFO
------------------------------------------------------- */
$staffStmt = mysqli_prepare(
    $conn,
    "SELECT s.StaffID, s.DepartmentID, s.StaffRole, d.DepartmentName,
            u.FirstName, u.LastName, u.ProfilePhoto
     FROM staff s
     INNER JOIN users u ON s.UserID = u.UserID
     INNER JOIN departments d ON s.DepartmentID = d.DepartmentID
     WHERE s.UserID = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($staffStmt, 'i', $userId);
mysqli_stmt_execute($staffStmt);
$staffResult = mysqli_stmt_get_result($staffStmt);
$staffInfo = mysqli_fetch_assoc($staffResult);

if (!$staffInfo) {
    // Logged in as staff/nurse role but no matching staff record
    session_destroy();
    header('Location: ../auth/login.php?portal=staff');
    exit();
}

$departmentId = (int) $staffInfo['DepartmentID'];
$departmentName = $staffInfo['DepartmentName'];
$staffFirstName = $staffInfo['FirstName'];
$staffLastName = $staffInfo['LastName'];
$staffRole = $staffInfo['StaffRole'];

$initials = strtoupper(substr($staffFirstName, 0, 1) . substr($staffLastName, 0, 1));
$displayName = $staffFirstName . ' ' . $staffLastName;

/* -------------------------------------------------------
   STATS
------------------------------------------------------- */

// Today's appointments (not cancelled)
$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS cnt
     FROM appointments
     WHERE DepartmentID = ?
       AND AppointmentDate = CURDATE()
       AND Status != '" . APPT_STATUS_CANCELLED . "'"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$todaysAppointments = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

// Checked in today (appointment has a queue entry today)
$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS cnt
     FROM queue q
     INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
     WHERE a.DepartmentID = ?
       AND q.QueueDate = CURDATE()"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$checkedIn = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

// Still active in queue (waiting / called / in consultation)
$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS cnt
     FROM queue q
     INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
     WHERE a.DepartmentID = ?
       AND q.QueueDate = CURDATE()
       AND q.Status != '" . QUEUE_STATUS_COMPLETED . "'"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$inQueue = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

// Completed today
$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS cnt
     FROM queue q
     INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
     WHERE a.DepartmentID = ?
       AND q.QueueDate = CURDATE()
       AND q.Status = '" . QUEUE_STATUS_COMPLETED . "'"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$completedToday = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

/* -------------------------------------------------------
   SCHEDULED TODAY (not yet checked in)
------------------------------------------------------- */
$stmt = mysqli_prepare(
    $conn,
    "SELECT a.AppointmentID, a.AppointmentTime, a.Purpose,
            CONCAT(u.FirstName, ' ', u.LastName) AS PatientName
     FROM appointments a
     INNER JOIN patients p ON a.PatientID = p.PatientID
     INNER JOIN users u ON p.UserID = u.UserID
     WHERE a.DepartmentID = ?
       AND a.AppointmentDate = CURDATE()
       AND a.Status != '" . APPT_STATUS_CANCELLED . "'
       AND a.AppointmentID NOT IN (
           SELECT AppointmentID FROM queue WHERE QueueDate = CURDATE()
       )
     ORDER BY a.AppointmentTime ASC"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$scheduledResult = mysqli_stmt_get_result($stmt);
$scheduledToday = mysqli_fetch_all($scheduledResult, MYSQLI_ASSOC);

/* -------------------------------------------------------
   ACTIVE QUEUE
------------------------------------------------------- */
$stmt = mysqli_prepare(
    $conn,
    "SELECT q.QueueID, q.QueueNumber, q.Status AS QueueStatus, q.PriorityLevel,
            a.AppointmentTime, a.AppointmentTime AS StartTime, a.Purpose,
            CONCAT(u.FirstName, ' ', u.LastName) AS PatientName,
            d.DepartmentName
     FROM queue q
     INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
     INNER JOIN patients p ON a.PatientID = p.PatientID
     INNER JOIN users u ON p.UserID = u.UserID
     INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
     WHERE a.DepartmentID = ?
       AND q.QueueDate = CURDATE()
       AND q.Status != '" . QUEUE_STATUS_COMPLETED . "'
     ORDER BY FIELD(q.Status, '" . QUEUE_STATUS_IN_CONSULTATION . "', '" . QUEUE_STATUS_CALLED . "', '" . QUEUE_STATUS_WAITING . "'), q.QueueNumber ASC"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$queueResult = mysqli_stmt_get_result($stmt);
$activeQueue = mysqli_fetch_all($queueResult, MYSQLI_ASSOC);

// Notification badge: patients currently waiting to be called
$notifCount = 0;
foreach ($activeQueue as $row) {
    if (strtolower($row['QueueStatus']) === 'waiting') {
        $notifCount++;
    }
}

/* -------------------------------------------------------
   HELPERS
------------------------------------------------------- */
function queueBadge(string $departmentName, int $queueNumber): string
{
    $prefix = strtoupper(substr(trim($departmentName), 0, 1));
    return $prefix . str_pad((string) $queueNumber, 3, '0', STR_PAD_LEFT);
}

function formatTimeRange(string $time): string
{
    $start = strtotime($time);
    $end = strtotime('+30 minutes', $start);
    return date('g:i', $start) . '–' . date('g:i A', $end);
}

function statusLabel(string $status): string
{
    return match (strtolower($status)) {
        'inconsultation', 'in consultation' => 'In Consultation',
        'called', 'in progress' => 'called',
        'waiting' => 'waiting',
        default => strtolower($status),
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard — MediCare Staff Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/staff/staff_dashboard.css">
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
        <div class="brand-sub">Staff Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
        Dashboard
      </li>
      <li class="nav-item">
        <a href="checkin_patient.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
          Patient Check-in
        </a>
      </li>
      <li class="nav-item">
        <a href="queue.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          Queue
        </a>
      </li>
      <li class="nav-item">
        <a href="staff_profile.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">

        <div class="sidebar-user">

            <div class="user-avatar">
                <?php if (!empty($staffInfo['ProfilePhoto'])): ?>
                <img src="../<?php echo htmlspecialchars($staffInfo['ProfilePhoto']); ?>" alt="Photo">
                <?php else: ?>
                <?php echo htmlspecialchars($initials); ?>
                <?php endif; ?>
            </div>

            <div>

                <div class="user-name">
                    <?php echo htmlspecialchars($displayName); ?>
                </div>

                <div class="user-role">
                    <?php echo htmlspecialchars($staffRole); ?>
                </div>

            </div>

        </div>


        <a
            class="sign-out"
            href="../auth/logout.php"
            onclick="return confirm('Are you sure you want to sign out?');"
        >

            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>

            Sign Out

        </a>

    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">

    <?php if (isset($_GET['message']) && $_GET['message'] !== ''): ?>
    <div style="margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:14px;font-weight:500;background:<?php echo ($_GET['type'] ?? 'success') === 'success' ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo ($_GET['type'] ?? 'success') === 'success' ? '#166534' : '#991b1b'; ?>">
      <?php echo htmlspecialchars($_GET['message']); ?>
    </div>
    <?php endif; ?>

    <div class="staff-topbar">
      <div class="page-header">
        <h1>Staff Dashboard</h1>
        <p><?php echo date('l, F j, Y'); ?> — <?php echo htmlspecialchars($departmentName); ?></p>
      </div>
      <div class="notif-bell">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($notifCount > 0): ?>
          <span class="notif-badge"><?php echo $notifCount; ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- STATS -->
    <div class="staff-stats">
      <div class="staff-stat-card">
        <div class="staff-stat-label">Today's Appointments</div>
        <div class="staff-stat-value"><?php echo $todaysAppointments; ?></div>
      </div>
      <div class="staff-stat-card">
        <div class="staff-stat-label">Checked In</div>
        <div class="staff-stat-value blue"><?php echo $checkedIn; ?></div>
      </div>
      <div class="staff-stat-card">
        <div class="staff-stat-label">In Queue</div>
        <div class="staff-stat-value orange"><?php echo $inQueue; ?></div>
      </div>
      <div class="staff-stat-card">
        <div class="staff-stat-label">Completed</div>
        <div class="staff-stat-value green"><?php echo $completedToday; ?></div>
      </div>
    </div>

    <!-- QUICK ACTIONS -->
    <div class="staff-quick-actions">
      <a href="checkin_patient.php" class="btn-quick blue" style="text-decoration:none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
        Patient Check-in
      </a>
      <a href="queue.php" class="btn-quick teal" style="text-decoration:none;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
        Manage Queue
      </a>
    </div>

    <!-- CONTENT GRID -->
    <div class="staff-grid">

      <!-- SCHEDULED TODAY -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-head-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            Scheduled Today
          </div>
          <div class="panel-head-meta"><?php echo count($scheduledToday); ?> to check in</div>
        </div>

        <?php if (empty($scheduledToday)): ?>
          <div class="empty-state">All patients checked in</div>
        <?php else: ?>
          <div class="queue-list">
            <?php foreach ($scheduledToday as $appt): ?>
              <div class="queue-list-row">
                <div class="queue-info">
                  <div class="queue-name"><?php echo htmlspecialchars($appt['PatientName']); ?></div>
                  <div class="queue-sub">
                    <?php echo htmlspecialchars(formatTimeRange($appt['AppointmentTime'])); ?>
                    <?php if (!empty($appt['Purpose'])): ?>
                      | <?php echo htmlspecialchars($appt['Purpose']); ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="queue-actions">
                  <a href="checkin_patient.php?appointment_id=<?php echo (int) $appt['AppointmentID']; ?>" class="btn-call">Check In</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ACTIVE QUEUE -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-head-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
            Active Queue
          </div>
          <div class="panel-head-meta"><?php echo count($activeQueue); ?> active</div>
        </div>

        <?php if (empty($activeQueue)): ?>
          <div class="empty-state">No patients in queue</div>
        <?php else: ?>
          <div class="queue-list">
            <?php foreach ($activeQueue as $q): ?>
              <?php
                $status = strtolower($q['QueueStatus']);
                $isHighlighted = $status === 'inconsultation';
                $badge = queueBadge($q['DepartmentName'], (int) $q['QueueNumber']);
                $isUrgent = strtolower($q['PriorityLevel']) !== 'normal';
              ?>
              <div class="queue-list-row<?php echo $isHighlighted ? ' highlight' : ''; ?>">
                <div class="queue-badge"><?php echo htmlspecialchars($badge); ?></div>
                <div class="queue-info">
                  <div class="queue-name">
                    <?php echo htmlspecialchars($q['PatientName']); ?>
                    <?php if ($isUrgent): ?>
                      <span class="urgent-badge"><?php echo htmlspecialchars($q['PriorityLevel']); ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="queue-sub">
                    <?php echo htmlspecialchars(formatTimeRange($q['AppointmentTime'])); ?>
                    | <?php echo htmlspecialchars($q['DepartmentName']); ?>
                    <?php if ($isHighlighted): ?>
                      | In Consultation
                    <?php endif; ?>
                  </div>
                </div>
                <div class="queue-actions">
                  <?php if ($isHighlighted): ?>
                    <form method="POST" action="queue_action.php" style="display:inline;">
                      <input type="hidden" name="queue_id" value="<?php echo (int) $q['QueueID']; ?>">
                      <input type="hidden" name="action" value="complete">
                      <button class="btn-complete" type="submit">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Complete
                      </button>
                    </form>
                  <?php elseif ($status === 'called'): ?>
                    <span class="queue-status called">called</span>
                  <?php else: ?>
                    <span class="queue-status waiting">waiting</span>
                    <form method="POST" action="queue_action.php" style="display:inline;">
                      <input type="hidden" name="queue_id" value="<?php echo (int) $q['QueueID']; ?>">
                      <input type="hidden" name="action" value="call">
                      <button class="btn-call" type="submit">Call</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

  </main>

</div>
</body>
</html>