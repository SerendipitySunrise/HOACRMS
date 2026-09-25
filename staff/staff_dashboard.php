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

// Distinct patients with vitals recorded today (scoped to this department
// through the appointment the vitals belong to)
$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(DISTINCT v.PatientID) AS cnt
     FROM vitals v
     INNER JOIN appointments a ON v.AppointmentID = a.AppointmentID
     WHERE a.DepartmentID = ?
       AND v.RecordedAt >= CURDATE()
       AND v.RecordedAt < CURDATE() + INTERVAL 1 DAY"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$vitalsToday = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['cnt'];

// Yesterday's totals (single aggregate, for trend context)
$yesterdayStats = ['appointments' => 0, 'checked' => 0, 'queue' => 0, 'completed' => 0, 'vitals' => 0];
$stmt = mysqli_prepare(
    $conn,
    "SELECT
       (SELECT COUNT(*) FROM appointments
         WHERE DepartmentID = ? AND AppointmentDate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
           AND Status != '" . APPT_STATUS_CANCELLED . "') AS appointments,
       (SELECT COUNT(*) FROM queue q
         INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
         WHERE a.DepartmentID = ? AND q.QueueDate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)) AS checked,
       (SELECT COUNT(*) FROM queue q
         INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
         WHERE a.DepartmentID = ? AND q.QueueDate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
           AND q.Status != '" . QUEUE_STATUS_COMPLETED . "') AS queue,
       (SELECT COUNT(*) FROM queue q
         INNER JOIN appointments a ON q.AppointmentID = a.AppointmentID
         WHERE a.DepartmentID = ? AND q.QueueDate = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
           AND q.Status = '" . QUEUE_STATUS_COMPLETED . "') AS completed,
       (SELECT COUNT(DISTINCT v.PatientID) FROM vitals v
         INNER JOIN appointments a ON v.AppointmentID = a.AppointmentID
         WHERE a.DepartmentID = ? AND v.RecordedAt >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
           AND v.RecordedAt < CURDATE()) AS vitals"
);
mysqli_stmt_bind_param($stmt, 'iiiii', $departmentId, $departmentId, $departmentId, $departmentId, $departmentId);
mysqli_stmt_execute($stmt);
$yesterdayRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if ($yesterdayRow) {
    foreach ($yesterdayRow as $key => $val) {
        $yesterdayStats[$key] = (int) $val;
    }
}

$nowLabel = date('g:i A');

// Trend chips ("vs yesterday"), empty string = nothing meaningful to compare
$deltaChips = [
    'appointments' => statDelta($todaysAppointments, $yesterdayStats['appointments']),
    'vitals'       => statDelta($vitalsToday, $yesterdayStats['vitals']),
    'checked'      => statDelta($checkedIn, $yesterdayStats['checked']),
    'queue'        => statDelta($inQueue, $yesterdayStats['queue']),
    'completed'    => statDelta($completedToday, $yesterdayStats['completed']),
];

/* -------------------------------------------------------
   PENDING VITALS ALERT
   (Checked-in patients today with no vitals recorded yet)
------------------------------------------------------- */
$stmt = mysqli_prepare(
    $conn,
    "SELECT a.AppointmentID, a.PatientID, a.AppointmentTime,
            CONCAT(u.FirstName, ' ', u.LastName) AS PatientName,
            d.DepartmentName
     FROM appointments a
     INNER JOIN patients p ON a.PatientID = p.PatientID
     INNER JOIN users u ON p.UserID = u.UserID
     INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
     WHERE a.DepartmentID = ?
       AND a.AppointmentDate = CURDATE()
       AND a.Status = '" . APPT_STATUS_CHECKED_IN . "'
       AND a.AppointmentID NOT IN (
           SELECT DISTINCT v.AppointmentID FROM vitals v
           WHERE v.RecordedAt >= CURDATE()
             AND v.RecordedAt < CURDATE() + INTERVAL 1 DAY
       )
     ORDER BY a.AppointmentTime ASC"
);
mysqli_stmt_bind_param($stmt, 'i', $departmentId);
mysqli_stmt_execute($stmt);
$pendingVitals = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

/* -------------------------------------------------------
   SCHEDULED TODAY (not yet checked in)
------------------------------------------------------- */
$stmt = mysqli_prepare(
    $conn,
    "SELECT a.AppointmentID, a.PatientID, a.AppointmentTime, a.Purpose,
            CONCAT(u.FirstName, ' ', u.LastName) AS PatientName,
            (SELECT COUNT(*) FROM vitals v
              WHERE v.PatientID = a.PatientID
                AND v.RecordedAt >= CURDATE()
                AND v.RecordedAt < CURDATE() + INTERVAL 1 DAY) AS vitals_today
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
            d.DepartmentName,
            (SELECT COUNT(*) FROM vitals v
              WHERE v.PatientID = a.PatientID
                AND v.RecordedAt >= CURDATE()
                AND v.RecordedAt < CURDATE() + INTERVAL 1 DAY) AS vitals_today
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

/**
 * Small "vs yesterday" trend chip. Returns raw HTML (numbers are ints,
 * markup is static) or '' when there's nothing meaningful to compare.
 */
function statDelta(int $todayCount, int $yesterdayCount): string
{
    $diff = $todayCount - $yesterdayCount;
    if ($diff > 0) {
        return '<span class="stat-delta up"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>+' . $diff . ' vs yesterday</span>';
    }
    if ($diff < 0) {
        return '<span class="stat-delta down"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>' . $diff . ' vs yesterday</span>';
    }
    if ($yesterdayCount > 0) {
        return '<span class="stat-delta flat">same as yesterday</span>';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard — Curora Staff Portal</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
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
        <img src="../assets/images/curora-icon.png" alt="Curora">
      </div>
      <div class="brand-text">
        <div class="brand-title">Curora</div>
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

    <!-- VITALS PENDING ALERT -->
    <?php if (!empty($pendingVitals)): ?>
      <div class="vitals-alert">
        <div class="vitals-alert-head">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <span><strong>Alert:</strong> <?php echo count($pendingVitals); ?> checked-in patient(s) need vitals recorded before consultation</span>
        </div>
        <div class="vitals-alert-list">
          <?php foreach ($pendingVitals as $pv): ?>
            <div class="vitals-alert-item">
              <span class="vitals-alert-name"><?php echo htmlspecialchars($pv['PatientName']); ?></span>
              <span class="vitals-alert-dept">(<?php echo htmlspecialchars($pv['DepartmentName']); ?>)</span>
              <span class="vitals-alert-time">— Appt at <?php echo htmlspecialchars(date('g:i A', strtotime($pv['AppointmentTime']))); ?></span>
              <a
                href="record_vitals.php?appointment_id=<?php echo (int) $pv['AppointmentID']; ?>&patient_id=<?php echo (int) $pv['PatientID']; ?>"
                class="vitals-alert-btn"
              >Record Vitals Now</a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="staff-stats-stack">

      <!-- Context stats (not part of the day's patient-flow pipeline) -->
      <div class="staff-stats-context">
        <div class="staff-stat-card">
          <div class="skeleton"></div>
          <div class="staff-stat-top">
            <div class="staff-stat-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            </div>
            <div class="staff-stat-label">Today's Appointments</div>
          </div>
          <div class="staff-stat-value"><?php echo $todaysAppointments; ?></div>
          <div class="staff-stat-meta">as of <?php echo htmlspecialchars($nowLabel); ?><?php if ($deltaChips['appointments'] !== '') echo '&nbsp;<span class="dot-sep">·</span>&nbsp;' . $deltaChips['appointments']; ?></div>
        </div>
        <div class="staff-stat-card">
          <div class="skeleton"></div>
          <div class="staff-stat-top">
            <div class="staff-stat-icon teal">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            </div>
            <div class="staff-stat-label">Vitals Done</div>
          </div>
          <div class="staff-stat-value teal"><?php echo $vitalsToday; ?></div>
          <div class="staff-stat-meta">as of <?php echo htmlspecialchars($nowLabel); ?><?php if ($deltaChips['vitals'] !== '') echo '&nbsp;<span class="dot-sep">·</span>&nbsp;' . $deltaChips['vitals']; ?></div>
        </div>
      </div>

      <!-- Patient-flow pipeline: Checked In → In Queue → Completed -->
      <div class="staff-flow">
        <div class="staff-flow-step">
          <div class="skeleton"></div>
          <div class="staff-flow-icon blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg>
          </div>
          <div class="staff-flow-label">Checked In</div>
          <div class="staff-flow-value blue"><?php echo $checkedIn; ?></div>
          <div class="staff-stat-meta">as of <?php echo htmlspecialchars($nowLabel); ?><?php if ($deltaChips['checked'] !== '') echo '&nbsp;<span class="dot-sep">·</span>&nbsp;' . $deltaChips['checked']; ?></div>
        </div>
        <div class="staff-flow-arrow">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </div>
        <div class="staff-flow-step">
          <div class="skeleton"></div>
          <div class="staff-flow-icon orange">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
          <div class="staff-flow-label">In Queue</div>
          <div class="staff-flow-value orange"><?php echo $inQueue; ?></div>
          <div class="staff-stat-meta">as of <?php echo htmlspecialchars($nowLabel); ?><?php if ($deltaChips['queue'] !== '') echo '&nbsp;<span class="dot-sep">·</span>&nbsp;' . $deltaChips['queue']; ?></div>
        </div>
        <div class="staff-flow-arrow">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
        </div>
        <div class="staff-flow-step">
          <div class="skeleton"></div>
          <div class="staff-flow-icon green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </div>
          <div class="staff-flow-label">Completed</div>
          <div class="staff-flow-value green"><?php echo $completedToday; ?></div>
          <div class="staff-stat-meta">as of <?php echo htmlspecialchars($nowLabel); ?><?php if ($deltaChips['completed'] !== '') echo '&nbsp;<span class="dot-sep">·</span>&nbsp;' . $deltaChips['completed']; ?></div>
        </div>
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

        <div class="panel-body">
          <div class="skeleton"></div>
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
                  <div class="vitals-indicator">
                    <?php if ((int) $appt['vitals_today'] > 0): ?>
                      <span class="vitals-status recorded" title="Vitals recorded today">✅ Recorded</span>
                    <?php else: ?>
                      <span class="vitals-status pending" title="Vitals not recorded today">⚠️ Pending</span>
                    <?php endif; ?>
                  </div>
                  <div class="queue-actions">
                    <a
                      href="record_vitals.php?appointment_id=<?php echo (int) $appt['AppointmentID']; ?>&patient_id=<?php echo (int) $appt['PatientID']; ?>"
                      class="btn-vitals-sm"
                      title="Record vitals for this patient"
                    >📊 Vitals</a>
                    <a href="checkin_patient.php?appointment_id=<?php echo (int) $appt['AppointmentID']; ?>" class="btn-call">Check In</a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
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

        <div class="panel-body">
          <div class="skeleton"></div>
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
                  <div class="vitals-indicator">
                    <?php if ((int) $q['vitals_today'] > 0): ?>
                      <span
                        class="vitals-check"
                        title="Vitals recorded today"
                      >✅</span>
                    <?php else: ?>
                      <span
                        class="vitals-missing"
                        title="Vitals not recorded today"
                      >⚠️</span>
                    <?php endif; ?>
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

    </div>

  </main>

</div>
</body>
</html>