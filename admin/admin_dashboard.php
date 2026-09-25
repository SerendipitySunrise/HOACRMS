<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/status_constants.php';
requireRole('Admin');

/**
 * Dashboard queries must tolerate installations that use an older HOACRMS
 * schema. Missing optional tables/columns result in an empty metric instead
 * of a broken admin dashboard.
 */
function dashboardTableExists($conn, $tableName)
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
          LIMIT 1'
    );

    if (!$stmt) {
        error_log('Admin dashboard: unable to inspect database tables.');
        return false;
    }

    mysqli_stmt_bind_param($stmt, 's', $tableName);
    $executed = mysqli_stmt_execute($stmt);
    $result = $executed ? mysqli_stmt_get_result($stmt) : false;
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

function dashboardColumnExists($conn, $tableName, $columnName)
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT 1
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1'
    );

    if (!$stmt) {
        error_log('Admin dashboard: unable to inspect database columns.');
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $tableName, $columnName);
    $executed = mysqli_stmt_execute($stmt);
    $result = $executed ? mysqli_stmt_get_result($stmt) : false;
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

function dashboardScalar($conn, $sql, $key, $default = 0)
{
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        error_log('Admin dashboard query failed: ' . mysqli_error($conn));
        return $default;
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    return isset($row[$key]) ? $row[$key] : $default;
}

function dashboardRows($conn, $sql)
{
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        error_log('Admin dashboard query failed: ' . mysqli_error($conn));
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    mysqli_free_result($result);

    return $rows;
}

$hasAppointments = dashboardTableExists($conn, 'appointments');
$hasQueue = dashboardTableExists($conn, 'queue');
$hasNoShows = dashboardTableExists($conn, 'no_shows');
$hasDepartmentSchedules = dashboardTableExists($conn, 'department_schedules');
$hasPatientSlots = $hasDepartmentSchedules
    && dashboardColumnExists($conn, 'department_schedules', 'PatientSlots');

$totalPatientsToday = 0;
$waitingToday = 0;
$inConsultationToday = 0;
$completedToday = 0;
$averageWaitMinutes = 0;
$scheduledToday = 0;
$pendingToday = 0;

if ($hasAppointments) {
    $totalPatientsToday = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(DISTINCT PatientID) AS total
           FROM appointments
          WHERE AppointmentDate = CURDATE()
            AND Status NOT IN ('" . APPT_STATUS_CANCELLED . "', '" . APPT_STATUS_NO_SHOW . "')",
        'total'
    );

    $scheduledToday = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*) AS total
           FROM appointments
          WHERE AppointmentDate = CURDATE()
            AND Status = '" . APPT_STATUS_SCHEDULED . "'",
        'total'
    );

    $pendingToday = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*) AS total
           FROM appointments
          WHERE AppointmentDate = CURDATE()
            AND Status = '" . APPT_STATUS_PENDING . "'",
        'total'
    );
}

if ($hasQueue) {
    $waitingToday = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*) AS total
           FROM queue
          WHERE QueueDate = CURDATE()
            AND Status = '" . QUEUE_STATUS_WAITING . "'",
        'total'
    );

    $inConsultationToday = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*) AS total
           FROM queue
          WHERE QueueDate = CURDATE()
            AND Status = '" . QUEUE_STATUS_IN_CONSULTATION . "'",
        'total'
    );

    $completedToday = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*) AS total
           FROM queue
          WHERE QueueDate = CURDATE()
            AND Status = '" . QUEUE_STATUS_COMPLETED . "'",
        'total'
    );

    // QueueTime is the available timestamp for a patient's current wait.
    $averageWaitMinutes = (int) dashboardScalar(
        $conn,
        "SELECT COALESCE(ROUND(AVG(TIMESTAMPDIFF(MINUTE,
                    TIMESTAMP(QueueDate, QueueTime), NOW()))), 0) AS total
           FROM queue
          WHERE QueueDate = CURDATE()
            AND Status IN ('" . QUEUE_STATUS_WAITING . "', '" . QUEUE_STATUS_CALLED . "', '" . QUEUE_STATUS_IN_CONSULTATION . "')",
        'total'
    );
}

$noShowCountToday = $hasNoShows
    ? (int) dashboardScalar(
        $conn,
        'SELECT COUNT(*) AS total FROM no_shows WHERE NoShowDate = CURDATE()',
        'total'
    )
    : 0;

$morningAppointments = 0;
$afternoonAppointments = 0;
$morningCapacity = null;
$afternoonCapacity = null;

if ($hasAppointments) {
    $morningAppointments = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*) AS total
           FROM appointments
          WHERE AppointmentDate = CURDATE()
            AND AppointmentTime < '12:00:00'
            AND Status NOT IN ('" . APPT_STATUS_CANCELLED . "', '" . APPT_STATUS_NO_SHOW . "')",
        'total'
    );

    $afternoonAppointments = (int) dashboardScalar(
        $conn,
        "SELECT COUNT(*) AS total
           FROM appointments
          WHERE AppointmentDate = CURDATE()
            AND AppointmentTime >= '12:00:00'
            AND Status NOT IN ('" . APPT_STATUS_CANCELLED . "', '" . APPT_STATUS_NO_SHOW . "')",
        'total'
    );
}

if ($hasPatientSlots) {
    $morningCapacity = (int) dashboardScalar(
        $conn,
        "SELECT COALESCE(SUM(PatientSlots), 0) AS total
           FROM department_schedules
          WHERE DayOfWeek = DAYOFWEEK(CURDATE()) - 1
            AND SessionName = 'Morning'",
        'total'
    );

    $afternoonCapacity = (int) dashboardScalar(
        $conn,
        "SELECT COALESCE(SUM(PatientSlots), 0) AS total
           FROM department_schedules
          WHERE DayOfWeek = DAYOFWEEK(CURDATE()) - 1
            AND SessionName = 'Afternoon'",
        'total'
    );
}

$morningProgress = $morningCapacity > 0
    ? min(100, (int) round(($morningAppointments / $morningCapacity) * 100))
    : 0;
$afternoonProgress = $afternoonCapacity > 0
    ? min(100, (int) round(($afternoonAppointments / $afternoonCapacity) * 100))
    : 0;

$recentNoShows = [];
if (
    $hasNoShows
    && dashboardTableExists($conn, 'departments')
    && dashboardTableExists($conn, 'patients')
    && dashboardTableExists($conn, 'users')
) {
    $recentNoShows = dashboardRows(
        $conn,
        'SELECT
            ns.NoShowID,
            ns.NoShowDate,
            ns.NoShowReason,
            ns.FollowUpStatus,
            d.DepartmentName,
            u.FirstName,
            u.LastName
         FROM no_shows ns
         INNER JOIN departments d ON ns.DepartmentID = d.DepartmentID
         INNER JOIN patients p ON ns.PatientID = p.PatientID
         INNER JOIN users u ON p.UserID = u.UserID
         ORDER BY ns.CreatedAt DESC, ns.NoShowID DESC
         LIMIT 10'
    );
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard | MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_dashboard.css">
<link rel="stylesheet" href="../assets/css/admin/admin_notifications.css">
<script src="../assets/js/admin_notifications.js?v=20260924-clear-all" defer></script>
</head>
<body>

<div class="app">

    <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/></svg>
      </div>
      <div class="brand-text">
        <div class="brand-title">MediCare</div>
        <div class="brand-sub">Admin Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item active">
        <a href="admin_dashboard.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
          Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_department.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 21v-6h6v6"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01"/></svg>
          Departments
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_doctor_management.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-1a6 6 0 0 1 6-6h1a6 6 0 0 1 6 6v1"/><circle cx="9.5" cy="7" r="4"/><path d="M19 8v4M21 10h-4"/></svg>
          Doctors
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_patient_management.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-1a7 7 0 0 0-7-7h-2a7 7 0 0 0-7 7v1"/><circle cx="12" cy="7" r="4"/></svg>
          Patients
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_staff_management.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          Staff
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_reports.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-4"/></svg>
          Reports
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_announcements.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          Announcement
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_profile.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_system_settings.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
          System Settings
        </a>
      </li>
    </ul>

    <?php include __DIR__ . '/../includes/admin_sidebar_footer.php'; ?>
  </aside>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <div class="staff-topbar">
  <div class="page-header">
    <h1>Admin Dashboard</h1>
    <p>Overview of hospital operations today</p>
  </div>

  <?php include __DIR__ . '/../includes/admin_notification_widget.php'; ?>
</div>

    <!-- Stat cards -->
    <div class="admin-stats">
      <div class="admin-stat-card mint">
        <div class="admin-stat-value"><?= $totalPatientsToday ?></div>
        <div class="admin-stat-label">Total Patients Today</div>
      </div>
      <div class="admin-stat-card cream">
        <div class="admin-stat-value"><?= $waitingToday ?></div>
        <div class="admin-stat-label">Waiting in Queue</div>
      </div>
      <div class="admin-stat-card lavender">
        <div class="admin-stat-value"><?= $inConsultationToday ?></div>
        <div class="admin-stat-label">In Consultation</div>
      </div>
      <div class="admin-stat-card green">
        <div class="admin-stat-value"><?= $completedToday ?></div>
        <div class="admin-stat-label">Completed Today</div>
      </div>
      <div class="admin-stat-card red">
        <div class="admin-stat-value"><?= $averageWaitMinutes ?>m</div>
        <div class="admin-stat-label">Avg Wait Time</div>
      </div>
      <div class="admin-stat-card red">
        <div class="admin-stat-value"><?= $noShowCountToday ?></div>
        <div class="admin-stat-label">No-Shows Today</div>
      </div>
    </div>

    <!-- Two column grid -->
    <div class="staff-grid">

      <!-- Today's Overview -->
      <section class="panel">
        <div class="panel-head">
          <div class="panel-head-title">Today's Overview</div>
          <div class="panel-head-meta"><?= date('m/d/Y') ?></div>
        </div>

        <div class="overview-metrics">
          <div class="overview-metric">
            <div class="overview-metric-value"><?= $scheduledToday ?></div>
            <div class="overview-metric-label">Scheduled</div>
          </div>
          <div class="overview-metric">
            <div class="overview-metric-value teal"><?= $completedToday ?></div>
            <div class="overview-metric-label">Completed</div>
          </div>
          <div class="overview-metric">
            <div class="overview-metric-value orange"><?= $pendingToday ?></div>
            <div class="overview-metric-label">Pending</div>
          </div>
        </div>

        <div class="overview-sessions">
          <div class="overview-session-row">
            <div class="overview-session-name">Morning Session</div>
            <?php if ($morningCapacity !== null): ?>
              <div class="overview-session-bar">
                <div class="overview-session-bar-fill" style="width: <?= $morningProgress ?>%;"></div>
              </div>
              <div class="overview-session-count"><?= $morningAppointments ?>/<?= $morningCapacity ?></div>
            <?php else: ?>
              <div class="overview-session-bar"></div>
              <div class="overview-session-count"><?= $morningAppointments ?></div>
            <?php endif; ?>
          </div>
          <div class="overview-session-row">
            <div class="overview-session-name">Afternoon Session</div>
            <?php if ($afternoonCapacity !== null): ?>
              <div class="overview-session-bar">
                <div class="overview-session-bar-fill" style="width: <?= $afternoonProgress ?>%;"></div>
              </div>
              <div class="overview-session-count"><?= $afternoonAppointments ?>/<?= $afternoonCapacity ?></div>
            <?php else: ?>
              <div class="overview-session-bar"></div>
              <div class="overview-session-count"><?= $afternoonAppointments ?></div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- Quick Actions -->
      <section class="panel">
        <div class="panel-head">
          <div class="panel-head-title">Quick Actions</div>
        </div>

        <div class="qa-list">
          <a class="qa-item" href="admin_department.php">
            <div class="qa-icon">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 21v-6h6v6"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01"/></svg>
            </div>
            <div class="qa-info">
              <div class="qa-title">Manage Departments</div>
              <div class="qa-sub">Set schedules &amp; rules</div>
            </div>
            <svg class="qa-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </a>

          <a class="qa-item" href="admin_doctor_management.php">
            <div class="qa-icon">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-1a6 6 0 0 1 6-6h1a6 6 0 0 1 6 6v1"/><circle cx="9.5" cy="7" r="4"/><path d="M19 8v4M21 10h-4"/></svg>
            </div>
            <div class="qa-info">
              <div class="qa-title">Add Doctor</div>
              <div class="qa-sub">Register new doctor</div>
            </div>
            <svg class="qa-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </a>

          <a class="qa-item" href="admin_patient_management.php">
            <div class="qa-icon">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-1a7 7 0 0 0-7-7h-2a7 7 0 0 0-7 7v1"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div class="qa-info">
              <div class="qa-title">View Patients</div>
              <div class="qa-sub">Patient records</div>
            </div>
            <svg class="qa-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </a>

          <a class="qa-item" href="admin_reports.php">
            <div class="qa-icon">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
            </div>
            <div class="qa-info">
              <div class="qa-title">Generate Report</div>
              <div class="qa-sub">View analytics</div>
            </div>
            <svg class="qa-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>
      </section>

    </div>


    <!-- Recent No-Shows -->
    <section class="panel" style="margin-top:20px;">
      <div class="panel-head">
        <div class="panel-head-title">Recent No-Shows</div>
        <div class="panel-head-meta">Latest <?= count($recentNoShows) ?> records</div>
      </div>

      <?php if (empty($recentNoShows)): ?>
        <div style="color:var(--color-ink-soft);font-size:0.9rem;padding:8px 0;">
          No no-show records yet.
        </div>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:10px;">
          <?php foreach ($recentNoShows as $ns): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid var(--color-border);border-radius:10px;background:#fff;">
              <div style="width:36px;height:36px;border-radius:50%;background:#fde2e2;color:var(--color-red);display:flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0;">
                <?= strtoupper(
                    substr($ns['FirstName'], 0, 1) .
                    substr($ns['LastName'], 0, 1)
                ) ?>
              </div>
              <div style="flex:1;min-width:0;">
                <div style="font-weight:700;">
                  <?= htmlspecialchars($ns['FirstName'] . ' ' . $ns['LastName']) ?>
                </div>
                <div style="font-size:0.78rem;color:var(--color-ink-soft);">
                  <?= htmlspecialchars($ns['DepartmentName']) ?>
                  &bull;
                  <?= htmlspecialchars(date('M d, Y', strtotime($ns['NoShowDate']))) ?>
                  <?php if ($ns['NoShowReason'] !== ''): ?>
                    &bull;
                    <span style="font-style:italic;">
                      <?= htmlspecialchars($ns['NoShowReason']) ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <span style="font-size:0.72rem;font-weight:700;padding:4px 10px;border-radius:20px;background:#fde2e2;color:var(--color-red);flex-shrink:0;">
                <?= htmlspecialchars($ns['FollowUpStatus']) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </main>
</div>

</body>
</html>
