<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
requireRole('Admin');

$displayName = htmlspecialchars(trim(($_SESSION['FirstName'] ?? '') . ' ' . ($_SESSION['LastName'] ?? '')));

// No-show statistics for the admin dashboard
$noShowCountToday = 0;

$noShowCountStmt = mysqli_prepare(
    $conn,
    'SELECT COUNT(*) AS c
     FROM no_shows
     WHERE NoShowDate = CURDATE()'
);

if ($noShowCountStmt) {
    mysqli_stmt_execute($noShowCountStmt);
    $noShowCountRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($noShowCountStmt)
    );
    $noShowCountToday = (int) ($noShowCountRow['c'] ?? 0);
}

$recentNoShows = [];

$recentNoShowStmt = mysqli_prepare(
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
     INNER JOIN departments d
        ON ns.DepartmentID = d.DepartmentID
     INNER JOIN patients p
        ON ns.PatientID = p.PatientID
     INNER JOIN users u
        ON p.UserID = u.UserID
     ORDER BY ns.CreatedAt DESC,
        ns.NoShowID DESC
     LIMIT 10'
);

if ($recentNoShowStmt) {
    mysqli_stmt_execute($recentNoShowStmt);
    $recentNoShowResult =
        mysqli_stmt_get_result($recentNoShowStmt);
    while ($row = mysqli_fetch_assoc($recentNoShowResult)) {
        $recentNoShows[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard | MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_dashboard.css">
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
        <a href="admin_system_settings.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
          System Settings
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">PA</div>
        <div>
          <div class="user-name">Pedro Andres</div>
          <div class="user-role">Admin</div>
        </div>
      </div>
      <button class="sign-out" type="button">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </button>
    </div>
  </aside>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <div class="staff-topbar">
      <div class="page-header">
        <h1>Admin Dashboard</h1>
        <p>Overview of hospital operations today</p>
      </div>
      <button class="notif-bell" type="button" aria-label="Notifications">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span class="notif-badge">3</span>
      </button>
    </div>

    <!-- Stat cards -->
    <div class="admin-stats">
      <div class="admin-stat-card mint">
        <div class="admin-stat-value">7</div>
        <div class="admin-stat-label">Total Patients Today</div>
      </div>
      <div class="admin-stat-card cream">
        <div class="admin-stat-value">6</div>
        <div class="admin-stat-label">Waiting in Queue</div>
      </div>
      <div class="admin-stat-card lavender">
        <div class="admin-stat-value">1</div>
        <div class="admin-stat-label">In Consultation</div>
      </div>
      <div class="admin-stat-card green">
        <div class="admin-stat-value">0</div>
        <div class="admin-stat-label">Completed Today</div>
      </div>
      <div class="admin-stat-card red">
        <div class="admin-stat-value">6m</div>
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
          <div class="panel-head-meta">10/05/2026</div>
        </div>

        <div class="overview-metrics">
          <div class="overview-metric">
            <div class="overview-metric-value">0</div>
            <div class="overview-metric-label">Scheduled</div>
          </div>
          <div class="overview-metric">
            <div class="overview-metric-value teal">0</div>
            <div class="overview-metric-label">Completed</div>
          </div>
          <div class="overview-metric">
            <div class="overview-metric-value orange">0</div>
            <div class="overview-metric-label">Pending</div>
          </div>
        </div>

        <div class="overview-sessions">
          <div class="overview-session-row">
            <div class="overview-session-name">Morning Session</div>
            <div class="overview-session-bar">
              <div class="overview-session-bar-fill" style="width: 60%;"></div>
            </div>
            <div class="overview-session-count">6/10</div>
          </div>
          <div class="overview-session-row">
            <div class="overview-session-name">Afternoon Session</div>
            <div class="overview-session-bar">
              <div class="overview-session-bar-fill" style="width: 40%;"></div>
            </div>
            <div class="overview-session-count">4/10</div>
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