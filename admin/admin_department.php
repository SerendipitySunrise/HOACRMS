<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_helper.php';
requireRole('Admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_department') {

        $departmentName = trim($_POST['department_name'] ?? '');

        if ($departmentName === '') {
            echo json_encode([
                'success' => false,
                'message' => 'Department name is required.'
            ]);
            exit;
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO departments (DepartmentName) VALUES (?)");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $departmentName);

            if (mysqli_stmt_execute($stmt)) {
              $departmentId = (int) mysqli_insert_id($conn);
              adminNotificationCreateForActiveAdmins(
                $conn,
                'Department Created',
                'Department "' . $departmentName . '" was created.',
                'Department',
                $departmentId,
                'departments',
                'Low'
              );
                echo json_encode([
                    'success' => true,
                    'message' => 'Department added successfully.',
                'id' => $departmentId
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to save department.'
                ]);
            }

            mysqli_stmt_close($stmt);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to prepare database query.'
            ]);
        }

        exit;
    }

    if ($_POST['action'] === 'update_department') {

    $departmentId = (int)($_POST['department_id'] ?? 0);
    $departmentName = trim($_POST['department_name'] ?? '');

    if ($departmentId <= 0 || $departmentName === '') {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid department information.'
        ]);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE departments SET DepartmentName = ? WHERE DepartmentID = ?"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "si", $departmentName, $departmentId);

        if (mysqli_stmt_execute($stmt)) {
          if (mysqli_stmt_affected_rows($stmt) > 0) {
            adminNotificationCreateForActiveAdmins(
              $conn,
              'Department Updated',
              'Department "' . $departmentName . '" was updated.',
              'Department',
              $departmentId,
              'departments',
              'Low'
            );
          }
            echo json_encode([
                'success' => true,
                'message' => 'Department updated successfully.'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update department.'
            ]);
        }

        mysqli_stmt_close($stmt);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare database query.'
        ]);
    }

    exit;
}

    // ---------------------------------------------------------------
    // SCHEDULE EDITOR: save / delete department_schedules session rows
    // ---------------------------------------------------------------
    if ($_POST['action'] === 'save_schedule') {
        if (!csrfValidate($_POST['csrf_token'] ?? '')) {
            header('Location: admin_department.php?schedule_msg=' . urlencode('Your form session expired. Please refresh and try again.'));
            exit;
        }

        $scheduleId   = (int) ($_POST['schedule_id'] ?? 0);
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $dayOfWeek    = (int) ($_POST['day_of_week'] ?? 0);
        $sessionName  = trim($_POST['session_name'] ?? '');
        $startTime    = trim($_POST['start_time'] ?? '');
        $endTime      = trim($_POST['end_time'] ?? '');
        $patientSlots = (int) ($_POST['patient_slots'] ?? 0);
        $breakStart   = trim($_POST['break_start'] ?? '');
        $breakEnd     = trim($_POST['break_end'] ?? '');
        $maxPerSession = trim($_POST['max_per_session'] ?? '');

        $fail = function (string $msg) use ($scheduleId) {
            $url = 'admin_department.php?schedule_msg=' . urlencode($msg);
            if ($scheduleId > 0) {
                $url .= '&edit_schedule_id=' . $scheduleId;
            }
            header('Location: ' . $url);
            exit;
        };

        if ($departmentId <= 0) $fail('Department is required.');
        if ($dayOfWeek < 1 || $dayOfWeek > 7) $fail('Day of week is required.');
        if ($sessionName === '' || mb_strlen($sessionName) > 20) $fail('Session name is required (20 characters max).');
        if ($startTime === '' || $endTime === '') $fail('Start time and end time are required.');
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime) === 5) $endTime .= ':00';
        if ($startTime >= $endTime) $fail('End time must be after start time.');
        if ($patientSlots < 1) $fail('Session slots must be at least 1.');

        $breakStartBind = null;
        $breakEndBind = null;
        if ($breakStart !== '' || $breakEnd !== '') {
            if ($breakStart === '' || $breakEnd === '') $fail('Break start and break end must both be provided, or both be empty.');
            if (strlen($breakStart) === 5) $breakStart .= ':00';
            if (strlen($breakEnd) === 5) $breakEnd .= ':00';
            if ($breakStart >= $breakEnd) $fail('Break end must be after break start.');
            if ($breakStart < $startTime || $breakEnd > $endTime) $fail('Break must be inside the session window.');
            $breakStartBind = $breakStart;
            $breakEndBind = $breakEnd;
        }

        $maxBind = null;
        if ($maxPerSession !== '') {
            $maxBind = (int) $maxPerSession;
            if ($maxBind < 1) $fail('Maximum patients per session must be at least 1.');
        }

        // (DepartmentID, DayOfWeek, SessionName) must be unique.
        $dupStmt = mysqli_prepare($conn, 'SELECT DepartmentScheduleID FROM department_schedules WHERE DepartmentID = ? AND DayOfWeek = ? AND SessionName = ? AND DepartmentScheduleID <> ? LIMIT 1');
        if ($dupStmt) {
            mysqli_stmt_bind_param($dupStmt, 'iisi', $departmentId, $dayOfWeek, $sessionName, $scheduleId);
            mysqli_stmt_execute($dupStmt);
            $dup = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));
            mysqli_stmt_close($dupStmt);
            if ($dup) $fail('This department already has a session with that name on that day.');
        }

        // Snapshot for the audit trail.
        $oldSnapshot = null;
        if ($scheduleId > 0) {
            $oldRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM department_schedules WHERE DepartmentScheduleID = ' . $scheduleId . ' LIMIT 1'));
            if (!$oldRow) $fail('Schedule session not found.');
            $oldSnapshot = json_encode($oldRow);
        }

        if ($scheduleId > 0) {
            $stmt = mysqli_prepare($conn, 'UPDATE department_schedules SET DepartmentID = ?, DayOfWeek = ?, SessionName = ?, StartTime = ?, EndTime = ?, PatientSlots = ?, BreakStart = ?, BreakEnd = ?, MaxPatientsPerSession = ? WHERE DepartmentScheduleID = ?');
            if (!$stmt || !mysqli_stmt_bind_param($stmt, 'iisssissii', $departmentId, $dayOfWeek, $sessionName, $startTime, $endTime, $patientSlots, $breakStartBind, $breakEndBind, $maxBind, $scheduleId)) {
                $fail('Failed to prepare schedule update.');
            }
        } else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO department_schedules (DepartmentID, DayOfWeek, SessionName, StartTime, EndTime, PatientSlots, BreakStart, BreakEnd, MaxPatientsPerSession) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$stmt || !mysqli_stmt_bind_param($stmt, 'iisssissi', $departmentId, $dayOfWeek, $sessionName, $startTime, $endTime, $patientSlots, $breakStartBind, $breakEndBind, $maxBind)) {
                $fail('Failed to prepare schedule insert.');
            }
        }

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $fail('Failed to save schedule session.');
        }
        if ($scheduleId <= 0) {
            $scheduleId = (int) mysqli_insert_id($conn);
        }
        mysqli_stmt_close($stmt);

        logAudit(db_pdo(), $oldSnapshot === null ? 'SCHEDULE_CREATE' : 'SCHEDULE_UPDATE', 'department_schedules', $scheduleId, $oldSnapshot, json_encode([
            'DepartmentID' => $departmentId, 'DayOfWeek' => $dayOfWeek, 'SessionName' => $sessionName,
            'StartTime' => $startTime, 'EndTime' => $endTime, 'PatientSlots' => $patientSlots,
            'BreakStart' => $breakStartBind, 'BreakEnd' => $breakEndBind, 'MaxPatientsPerSession' => $maxBind,
        ]));

        header('Location: admin_department.php?schedule_msg=' . urlencode('Schedule session saved.'));
        exit;
    }

    if ($_POST['action'] === 'delete_schedule') {
        if (!csrfValidate($_POST['csrf_token'] ?? '')) {
            header('Location: admin_department.php?schedule_msg=' . urlencode('Your form session expired. Please refresh and try again.'));
            exit;
        }
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            header('Location: admin_department.php?schedule_msg=' . urlencode('Schedule session not found.'));
            exit;
        }
        $oldRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM department_schedules WHERE DepartmentScheduleID = ' . $scheduleId . ' LIMIT 1'));
        if (!$oldRow) {
            header('Location: admin_department.php?schedule_msg=' . urlencode('Schedule session not found.'));
            exit;
        }
        $del = mysqli_prepare($conn, 'DELETE FROM department_schedules WHERE DepartmentScheduleID = ?');
        if ($del && mysqli_stmt_bind_param($del, 'i', $scheduleId) && mysqli_stmt_execute($del)) {
            mysqli_stmt_close($del);
            logAudit(db_pdo(), 'SCHEDULE_DELETE', 'department_schedules', $scheduleId, json_encode($oldRow), null);
            header('Location: admin_department.php?schedule_msg=' . urlencode('Schedule session deleted.'));
        } else {
            header('Location: admin_department.php?schedule_msg=' . urlencode('Failed to delete schedule session.'));
        }
        exit;
    }

    // ---------------------------------------------------------------
    // SECTION 6 OVERRIDES: doctor / department unavailability
    // ---------------------------------------------------------------
    if ($_POST['action'] === 'save_unavailability') {
        if (!csrfValidate($_POST['csrf_token'] ?? '')) {
            header('Location: admin_department.php?override_msg=' . urlencode('Your form session expired. Please refresh and try again.'));
            exit;
        }
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $doctorId     = (int) ($_POST['doctor_id'] ?? 0);
        $startDate    = trim($_POST['start_date'] ?? '');
        $endDate      = trim($_POST['end_date'] ?? '');
        $reason       = trim($_POST['reason'] ?? '');

        $fail2 = function (string $msg) {
            header('Location: admin_department.php?override_msg=' . urlencode($msg) . '#doctor-unavailability');
            exit;
        };

        if ($departmentId <= 0 && $doctorId <= 0) $fail2('Select a doctor or a department to place on leave.');
        if ($reason === '' || mb_strlen($reason) > 500) $fail2('A reason is required (500 characters max).');
        $sd = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        $ed = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if (!$sd || $sd->format('Y-m-d') !== $startDate) $fail2('A valid start date is required.');
        if (!$ed || $ed->format('Y-m-d') !== $endDate) $fail2('A valid end date is required.');
        if ($ed < $sd) $fail2('End date must be on or after the start date.');
        if ($doctorId > 0) {
            $docCheck = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT StaffID FROM staff WHERE StaffID = ' . $doctorId . ' LIMIT 1'));
            if (!$docCheck) $fail2('Doctor not found.');
        }
        if ($departmentId > 0) {
            $deptCheck = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT DepartmentID FROM departments WHERE DepartmentID = ' . $departmentId . ' LIMIT 1'));
            if (!$deptCheck) $fail2('Department not found.');
        }

        $doctorIdBind = $doctorId > 0 ? $doctorId : null;
        $departmentIdBind = $departmentId > 0 ? $departmentId : null;
        $createdBy = (int) $_SESSION['UserID'];
        $stmt = mysqli_prepare($conn, 'INSERT INTO doctorunavailability (DoctorID, DepartmentID, StartDate, EndDate, Reason, CreatedBy) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt || !mysqli_stmt_bind_param($stmt, 'iisssi', $doctorIdBind, $departmentIdBind, $startDate, $endDate, $reason, $createdBy)) {
            $fail2('Failed to prepare unavailability insert.');
        }
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            $fail2('Failed to save unavailability.');
        }
        $unavailId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);

        logAudit(db_pdo(), 'DOCTOR_UNAVAILABILITY_ADD', 'doctorunavailability', $unavailId, null, json_encode([
            'DoctorID' => $doctorIdBind, 'DepartmentID' => $departmentIdBind,
            'StartDate' => $startDate, 'EndDate' => $endDate, 'Reason' => $reason,
        ]));

        header('Location: admin_department.php?override_msg=' . urlencode('Leave period saved. Affected bookings during that range will be blocked from new bookings.') . '#doctor-unavailability');
        exit;
    }

    if ($_POST['action'] === 'delete_unavailability') {
        if (!csrfValidate($_POST['csrf_token'] ?? '')) {
            header('Location: admin_department.php?override_msg=' . urlencode('Your form session expired. Please refresh and try again.'));
            exit;
        }
        $unavailId = (int) ($_POST['unavailability_id'] ?? 0);
        if ($unavailId <= 0) {
            header('Location: admin_department.php?override_msg=' . urlencode('Leave record not found.') . '#doctor-unavailability');
            exit;
        }
        $oldRow = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM doctorunavailability WHERE UnavailabilityID = ' . $unavailId . ' LIMIT 1'));
        if (!$oldRow) {
            header('Location: admin_department.php?override_msg=' . urlencode('Leave record not found.') . '#doctor-unavailability');
            exit;
        }
        $del = mysqli_prepare($conn, 'DELETE FROM doctorunavailability WHERE UnavailabilityID = ?');
        if ($del && mysqli_stmt_bind_param($del, 'i', $unavailId) && mysqli_stmt_execute($del)) {
            mysqli_stmt_close($del);
            logAudit(db_pdo(), 'DOCTOR_UNAVAILABILITY_DELETE', 'doctorunavailability', $unavailId, json_encode($oldRow), null);
            header('Location: admin_department.php?override_msg=' . urlencode('Leave record removed.') . '#doctor-unavailability');
        } else {
            header('Location: admin_department.php?override_msg=' . urlencode('Failed to remove leave record.') . '#doctor-unavailability');
        }
        exit;
    }
}

// LOAD DEPARTMENTS FROM DATABASE
$departmentsFromDB = [];

$result = mysqli_query($conn, "SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentID ASC");

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $departmentsFromDB[] = [
            'id' => (int)$row['DepartmentID'],
            'name' => $row['DepartmentName']
        ];
    }
}

// ---- Schedule editor data ----
$scheduleMsg = trim((string) ($_GET['schedule_msg'] ?? ''));
$editScheduleId = (int) ($_GET['edit_schedule_id'] ?? 0);
$editRow = [
    'schedule_id' => 0, 'department_id' => 0, 'day_of_week' => 1, 'session_name' => '',
    'start_time' => '08:00', 'end_time' => '12:00', 'patient_slots' => 10,
    'break_start' => '', 'break_end' => '', 'max_per_session' => '',
];
$departmentScheduleRows = [];
$schedResult = mysqli_query($conn, 'SELECT ds.DepartmentScheduleID, ds.DepartmentID, ds.DayOfWeek, ds.SessionName, ds.StartTime, ds.EndTime, ds.PatientSlots, ds.BreakStart, ds.BreakEnd, ds.MaxPatientsPerSession, d.DepartmentName FROM department_schedules ds JOIN departments d ON d.DepartmentID = ds.DepartmentID ORDER BY d.DepartmentName, FIELD(ds.DayOfWeek,1,2,3,4,5,6,7), ds.StartTime');
if ($schedResult) {
    while ($srow = mysqli_fetch_assoc($schedResult)) {
        $departmentScheduleRows[] = $srow;
        if ($editScheduleId > 0 && (int) $srow['DepartmentScheduleID'] === $editScheduleId) {
            $editRow = [
                'schedule_id' => $editScheduleId,
                'department_id' => (int) $srow['DepartmentID'],
                'day_of_week' => (int) $srow['DayOfWeek'],
                'session_name' => $srow['SessionName'],
                'start_time' => substr($srow['StartTime'], 0, 5),
                'end_time' => substr($srow['EndTime'], 0, 5),
                'patient_slots' => (int) $srow['PatientSlots'],
                'break_start' => $srow['BreakStart'] !== null ? substr($srow['BreakStart'], 0, 5) : '',
                'break_end' => $srow['BreakEnd'] !== null ? substr($srow['BreakEnd'], 0, 5) : '',
                'max_per_session' => $srow['MaxPatientsPerSession'] !== null ? (string) $srow['MaxPatientsPerSession'] : '',
            ];
        }
    }
}
$dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// ---- Doctor unavailability (overrides) ----
$unavailabilityRows = [];
$unavailResult = mysqli_query($conn, 'SELECT du.UnavailabilityID, du.DoctorID, du.DepartmentID, du.StartDate, du.EndDate, du.Reason, d.DepartmentName, CONCAT(u.FirstName, " ", u.LastName) AS DoctorName FROM doctorunavailability du LEFT JOIN departments d ON d.DepartmentID = du.DepartmentID LEFT JOIN staff s ON s.StaffID = du.DoctorID LEFT JOIN users u ON u.UserID = s.UserID ORDER BY du.StartDate DESC, du.UnavailabilityID DESC LIMIT 50');
if ($unavailResult) {
    while ($urow = mysqli_fetch_assoc($unavailResult)) {
        $unavailabilityRows[] = $urow;
    }
}
$doctorOptions = [];
$docResult = mysqli_query($conn, 'SELECT s.StaffID, s.DepartmentID, CONCAT(u.FirstName, " ", u.LastName) AS FullName, d.DepartmentName FROM staff s JOIN users u ON u.UserID = s.UserID LEFT JOIN departments d ON d.DepartmentID = s.DepartmentID WHERE s.StaffRole = "Doctor" ORDER BY FullName');
if ($docResult) {
    while ($urow = mysqli_fetch_assoc($docResult)) {
        $doctorOptions[] = $urow;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Management — Curora Admin Portal</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_department.css?v=20260922">
<link rel="stylesheet" href="../assets/css/admin/admin_notifications.css">
<script src="../assets/js/admin_notifications.js?v=20260924-clear-all" defer></script>
</head>
<body>
<div class="app">

      <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <img src="../assets/images/curora-icon.png" alt="Curora">
      </div>
      <div class="brand-text">
        <div class="brand-title">Curora</div>
        <div class="brand-sub">Admin Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item">
        <a href="admin_dashboard.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
          Dashboard
        </a>
      </li>
      <li class="nav-item active">
        <a href="admin_department.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 21v-6h6v6"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01"/></svg>
          Departments
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_account_management.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M17 11l2 2 4-4"/></svg>
          Account Management
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
      <li class="nav-item">
        <a href="admin_holidays.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Holidays
        </a>
      </li>
    </ul>

    <?php include __DIR__ . '/../includes/admin_sidebar_footer.php'; ?>
  </aside>

  <!-- ================= MAIN ================= -->
<main class="main">

  <div class="staff-topbar">

    <div class="page-header">
      <h1>Department Management</h1>
      <p id="today-date">Sunday, May 10, 2026</p>
    </div>

   <?php include __DIR__ . '/../includes/admin_notification_widget.php'; ?>

  </div>

  <div class="panel">
    <div class="panel-head">
      <div>
        <div class="panel-head-title" style="font-size:1.15rem;">Departments</div>
        <div class="panel-head-meta" style="margin-top:4px;">Manage department schedules and appointment rules</div>
      </div>

      <div class="staff-header-actions">
        <button class="btn-checkin-solid" id="add-department-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Add Department
        </button>
      </div>
    </div>

    <div class="staff-table-wrap" style="margin-top:0;">
      <table class="staff-table" data-responsive>
        <thead>
          <tr>
            <th>Department</th>
            <th>Operating Days</th>
            <th>Morning</th>
            <th>Afternoon</th>
            <th>Slots</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>

        <tbody id="department-rows">
          <!-- rows rendered by JS -->
        </tbody>

      </table>
    </div>
  </div>

  <!-- ================= SCHEDULE EDITOR ================= -->
  <div class="panel" id="schedule-editor" style="margin-top: 24px;">
    <div class="panel-head">
      <div>
        <div class="panel-head-title" style="font-size:1.15rem;">Department Schedules</div>
        <div class="panel-head-meta" style="margin-top:4px;">Weekly session windows: day, time range, capacity and breaks</div>
      </div>
    </div>

    <?php if ($scheduleMsg !== ''): ?>
      <div style="padding: 10px 18px; background:#ecfdf5; color:#166534; font-size:0.9rem;"><?= htmlspecialchars($scheduleMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="staff-table-wrap" style="margin-top:0;">
      <table class="staff-table" data-responsive>
        <thead>
          <tr>
            <th>Department</th>
            <th>Day</th>
            <th>Session</th>
            <th>Window</th>
            <th>Break</th>
            <th>Slots</th>
            <th>Max/Session</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$departmentScheduleRows): ?>
            <tr><td colspan="8">No schedule sessions yet. Add one below.</td></tr>
          <?php endif; ?>
          <?php foreach ($departmentScheduleRows as $srow): ?>
            <tr>
              <td><?= htmlspecialchars($srow['DepartmentName'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?= $dayNames[(int) $srow['DayOfWeek']] ?? ''; ?></td>
              <td><?= htmlspecialchars($srow['SessionName'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?= htmlspecialchars(substr($srow['StartTime'], 0, 5) . ' – ' . substr($srow['EndTime'], 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?= ($srow['BreakStart'] !== null && $srow['BreakEnd'] !== null) ? htmlspecialchars(substr($srow['BreakStart'], 0, 5) . ' – ' . substr($srow['BreakEnd'], 0, 5), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
              <td><?= (int) $srow['PatientSlots']; ?></td>
              <td><?= $srow['MaxPatientsPerSession'] !== null ? (int) $srow['MaxPatientsPerSession'] : '—'; ?></td>
              <td style="white-space:nowrap;">
                <a href="admin_department.php?edit_schedule_id=<?= (int) $srow['DepartmentScheduleID']; ?>#schedule-editor" class="btn-outline" style="font-size:0.8rem; padding:4px 10px; text-decoration:none;">Edit</a>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this schedule session?');">
                  <?= csrfField(); ?>
                  <input type="hidden" name="action" value="delete_schedule">
                  <input type="hidden" name="schedule_id" value="<?= (int) $srow['DepartmentScheduleID']; ?>">
                  <button type="submit" class="btn-outline" style="font-size:0.8rem; padding:4px 10px;">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="POST" style="padding: 16px 18px; border-top:1px solid #e5e7eb; display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; align-items:end;">
      <?= csrfField(); ?>
      <input type="hidden" name="action" value="save_schedule">
      <input type="hidden" name="schedule_id" value="<?= (int) $editRow['schedule_id']; ?>">

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Department</label>
        <select name="department_id" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
          <option value="">Select…</option>
          <?php foreach ($departmentsFromDB as $d): ?>
            <option value="<?= (int) $d['id']; ?>" <?= $editRow['department_id'] === (int) $d['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Day</label>
        <select name="day_of_week" required style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
          <?php foreach ($dayNames as $dnum => $dname): ?>
            <option value="<?= $dnum; ?>" <?= $editRow['day_of_week'] === $dnum ? 'selected' : ''; ?>><?= $dname; ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Session name</label>
        <input type="text" name="session_name" maxlength="20" required placeholder="Morning" value="<?= htmlspecialchars($editRow['session_name'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Start</label>
        <input type="time" name="start_time" required value="<?= htmlspecialchars($editRow['start_time'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">End</label>
        <input type="time" name="end_time" required value="<?= htmlspecialchars($editRow['end_time'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Slots (per window)</label>
        <input type="number" name="patient_slots" min="1" required value="<?= (int) $editRow['patient_slots']; ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Break start (optional)</label>
        <input type="time" name="break_start" value="<?= htmlspecialchars($editRow['break_start'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Break end (optional)</label>
        <input type="time" name="break_end" value="<?= htmlspecialchars($editRow['break_end'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Max patients/session (optional)</label>
        <input type="number" name="max_per_session" min="1" value="<?= htmlspecialchars($editRow['max_per_session'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0; display:flex; gap:8px;">
        <button type="submit" class="btn-primary-solid" style="padding:8px 18px;"><?= $editRow['schedule_id'] > 0 ? 'Update session' : 'Add session'; ?></button>
        <?php if ($editRow['schedule_id'] > 0): ?>
          <a href="admin_department.php#schedule-editor" class="btn-outline" style="padding:8px 14px; text-decoration:none;">Cancel edit</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- ================= DOCTOR UNAVAILABILITY (OVERRIDES) ================= -->
  <div class="panel" id="doctor-unavailability" style="margin-top: 24px;">
    <div class="panel-head">
      <div>
        <div class="panel-head-title" style="font-size:1.15rem;">Doctor Leave (Overrides)</div>
        <div class="panel-head-meta" style="margin-top:4px;">Block bookings for a doctor or an entire department for a date range</div>
      </div>
    </div>

    <?php $overrideMsg = trim((string) ($_GET['override_msg'] ?? '')); ?>
    <?php if ($overrideMsg !== ''): ?>
      <div style="padding: 10px 18px; background:#ecfdf5; color:#166534; font-size:0.9rem;"><?= htmlspecialchars($overrideMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="staff-table-wrap" style="margin-top:0;">
      <table class="staff-table" data-responsive>
        <thead>
          <tr>
            <th>Scope</th>
            <th>From</th>
            <th>To</th>
            <th>Reason</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$unavailabilityRows): ?>
            <tr><td colspan="5">No leave records yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($unavailabilityRows as $urow): ?>
            <tr>
              <td>
                <?php if ($urow['DoctorID'] !== null): ?>
                  Doctor: <?= htmlspecialchars(($urow['DoctorName'] ?? 'Unknown') . ($urow['DepartmentName'] !== null ? ' (' . $urow['DepartmentName'] . ')' : ''), ENT_QUOTES, 'UTF-8'); ?>
                <?php else: ?>
                  Department: <?= htmlspecialchars($urow['DepartmentName'] ?? 'Unknown', ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($urow['StartDate'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?= htmlspecialchars($urow['EndDate'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?= htmlspecialchars($urow['Reason'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this leave record?');">
                  <?= csrfField(); ?>
                  <input type="hidden" name="action" value="delete_unavailability">
                  <input type="hidden" name="unavailability_id" value="<?= (int) $urow['UnavailabilityID']; ?>">
                  <button type="submit" class="btn-outline" style="font-size:0.8rem; padding:4px 10px;">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="POST" style="padding: 16px 18px; border-top:1px solid #e5e7eb; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items:end;">
      <?= csrfField(); ?>
      <input type="hidden" name="action" value="save_unavailability">

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Doctor (optional)</label>
        <select name="doctor_id" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
          <option value="">— Any doctor —</option>
          <?php foreach ($doctorOptions as $doc): ?>
            <option value="<?= (int) $doc['StaffID']; ?>"><?= htmlspecialchars(trim($doc['FullName'] . ($doc['DepartmentName'] !== null ? ' (' . $doc['DepartmentName'] . ')' : '')), ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Department (whole-department closure)</label>
        <select name="department_id" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
          <option value="">— None —</option>
          <?php foreach ($departmentsFromDB as $d): ?>
            <option value="<?= (int) $d['id']; ?>"><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">From</label>
        <input type="date" name="start_date" required value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">To</label>
        <input type="date" name="end_date" required value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0; grid-column: span 2;">
        <label style="display:block; font-size:0.8rem; color:#6b7280; margin-bottom:4px;">Reason</label>
        <input type="text" name="reason" maxlength="500" required placeholder="e.g. Conference, sick leave, hospital-wide event" value="" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
      </div>

      <div style="margin:0;">
        <button type="submit" class="btn-primary-solid" style="padding:8px 18px;">Save leave</button>
      </div>
    </form>
  </div>

</main>
</div>

<!-- ================= ADD / EDIT DEPARTMENT MODAL ================= -->
<div class="modal-overlay hidden" id="dept-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">Add Department</div>
        <div class="modal-sub" id="modal-sub">Fill in the department details</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <form id="dept-form" onsubmit="return false;">
      <input type="hidden" id="edit-index" value="-1" />

      <div class="form-group">
        <label for="dept-name">Department Name <span class="hint">(e.g. Cardiology)</span></label>
        <input type="text" id="dept-name" placeholder="Cardiology" required />
      </div>

      <div class="form-group">
        <label for="dept-desc">Description</label>
        <textarea id="dept-desc" placeholder="Brief description of the department..."></textarea>
      </div>

      <div class="form-group">
        <label>Operating Days</label>
        <div class="days-grid" id="days-grid">
          <label class="day-toggle"><input type="checkbox" value="Mon"  /> <span>Mon</span></label>
          <label class="day-toggle"><input type="checkbox" value="Tue"  /> <span>Tue</span></label>
          <label class="day-toggle"><input type="checkbox" value="Wed"  /> <span>Wed</span></label>
          <label class="day-toggle"><input type="checkbox" value="Thu"  /> <span>Thu</span></label>
          <label class="day-toggle"><input type="checkbox" value="Fri"  /> <span>Fri</span></label>
        </div>

        <div class="days-quick-actions">
          <button type="button" class="quick-btn" onclick="selectAllDays()">All</button>
          <button type="button" class="quick-btn" onclick="clearAllDays()">Clear</button>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="morning-start">Morning Start</label>
          <input type="time" id="morning-start" value="06:00" />
        </div>
        <div class="form-group">
          <label for="morning-end">Morning End</label>
          <input type="time" id="morning-end" value="11:59" />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="afternoon-start">Afternoon Start</label>
          <input type="time" id="afternoon-start" value="12:00" />
        </div>
        <div class="form-group">
          <label for="afternoon-end">Afternoon End</label>
          <input type="time" id="afternoon-end" value="17:00" />
        </div>
      </div>

      <div class="form-group">
        <label for="dept-slots">Total Slots</label>
        <input type="number" id="dept-slots" value="20" min="1" step="1" />
      </div>

      <div class="form-group">
        <label for="dept-status">Status</label>
        <select id="dept-status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Department
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= DEPARTMENT DATA =================

const defaultDepartments = [
  {
    name: "Orthopedics",
    desc: "Advanced joint replacement, sports medicine, and minimal invasive surgery...",
    days: ["Tue", "Fri"],
    morning: "06:00",
    morningEnd: "11:59",
    afternoon: "12:00",
    afternoonEnd: "17:00",
    slots: 20,
    active: true
  },

  {
    name: "Internal Medicine",
    desc: "Preventive care, chronic disease management, and complete checkups...",
    days: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
    morning: "06:00",
    morningEnd: "11:59",
    afternoon: "12:00",
    afternoonEnd: "17:00",
    slots: 20,
    active: true
  },

  {
    name: "Obstetrics & Gynecology",
    desc: "Complete women's health services from prenatal care, to postpartum and beyond...",
    days: ["Wed", "Fri"],
    morning: "06:00",
    morningEnd: "11:59",
    afternoon: "12:00",
    afternoonEnd: "17:00",
    slots: 20,
    active: true
  },

  {
    name: "Cardiology",
    desc: "Comprehensive heart care including advanced diagnostics, in-patient, and outpatient services...",
    days: ["Mon", "Tue"],
    morning: "06:00",
    morningEnd: "11:59",
    afternoon: "12:00",
    afternoonEnd: "17:00",
    slots: 20,
    active: true
  },

  {
    name: "Neurology",
    desc: "Expert diagnosis and treatment of complex neurological disorders, strokes, and diseases...",
    days: ["Mon", "Thu"],
    morning: "06:00",
    morningEnd: "11:59",
    afternoon: "12:00",
    afternoonEnd: "17:00",
    slots: 20,
    active: true
  },

  {
    name: "Pediatrics",
    desc: "General pediatric care, immunizations, developmental screenings...",
    days: ["Tue", "Thu", "Sat"],
    morning: "08:00",
    morningEnd: "13:00",
    afternoon: "14:00",
    afternoonEnd: "18:00",
    slots: 30,
    active: true
  }
];

const departments = defaultDepartments.map(dept => ({
  ...dept
}));

// Add departments from database that are not already in the default list
<?php echo "const databaseDepartments = " . json_encode($departmentsFromDB) . ";"; ?>

databaseDepartments.forEach(dbDept => {
  const exists = departments.some(
    dept => dept.name.toLowerCase() === dbDept.name.toLowerCase()
  );

  if (!exists) {
    departments.push({
      name: dbDept.name,
      desc: "",
      days: ["Mon"],
      morning: "06:00",
      morningEnd: "11:59",
      afternoon: "12:00",
      afternoonEnd: "17:00",
      slots: 20,
      active: true,
      id: dbDept.id
    });
  }
});

  // ================= DOM REFS =================
  const tbody = document.getElementById('department-rows');
  const modal = document.getElementById('dept-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const editIndex = document.getElementById('edit-index');
  const deptName = document.getElementById('dept-name');
  const deptDesc = document.getElementById('dept-desc');
  const morningStart = document.getElementById('morning-start');
  const morningEnd = document.getElementById('morning-end');
  const afternoonStart = document.getElementById('afternoon-start');
  const afternoonEnd = document.getElementById('afternoon-end');
  const deptSlots = document.getElementById('dept-slots');
  const deptStatus = document.getElementById('dept-status');
  const dayCheckboxes = document.querySelectorAll('#days-grid input[type="checkbox"]');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-department-btn');

  // ================= HELPERS =================
  function getSelectedDays() {
    return Array.from(dayCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
  }

  function setSelectedDays(daysArray) {
    dayCheckboxes.forEach(cb => cb.checked = daysArray.includes(cb.value));
  }

  function resetForm() {
    deptName.value = '';
    deptDesc.value = '';
    morningStart.value = '06:00';
    morningEnd.value = '11:59';
    afternoonStart.value = '12:00';
    afternoonEnd.value = '17:00';
    deptSlots.value = 20;
    deptStatus.value = 'active';
    setSelectedDays([]);
    editIndex.value = '-1';
    modalTitle.textContent = 'Add Department';
    modalSub.textContent = 'Fill in the department details';
  }

  function openModal(deptData, index) {
    if (deptData) {
      deptName.value = deptData.name || '';
      deptDesc.value = deptData.desc || '';
      morningStart.value = deptData.morning || '06:00';
      morningEnd.value = deptData.morningEnd || '11:59';
      afternoonStart.value = deptData.afternoon || '12:00';
      afternoonEnd.value = deptData.afternoonEnd || '17:00';
      deptSlots.value = deptData.slots || 20;
      deptStatus.value = deptData.active ? 'active' : 'inactive';
      setSelectedDays(deptData.days || []);
      editIndex.value = index;
      modalTitle.textContent = `Edit ${deptData.name}`;
      modalSub.textContent = 'Update schedule and appointment rules';
    } else {
      resetForm();
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  // ================= RENDER TABLE =================
  function renderRows() {
    if (!departments.length) {
      tbody.innerHTML = `<tr class="empty-row"><td colspan="7">No departments found. Click "Add Department" to create one.</td></tr>`;
      return;
    }
    let html = '';
    departments.forEach((d, i) => {
      const statusClass = d.active ? 'active' : 'inactive';
      const statusText = d.active ? 'Active' : 'Inactive';
      const dayPills = d.days.map(day => `<span class="day-pill">${day}</span>`).join('');
      const morningStr = `${d.morning} – ${d.morningEnd}`;
      const afternoonStr = `${d.afternoon} – ${d.afternoonEnd}`;
      html += `
        <tr>
          <td data-label="Department">
            <div class="dept-name">${d.name}</div>
            <div class="dept-desc">${d.desc || ''}</div>
          </td>
          <td data-label="Operating Days"><div class="day-pills">${dayPills}</div></td>
          <td data-label="Morning"><span class="time-range">${morningStr}</span></td>
          <td data-label="Afternoon"><span class="time-range">${afternoonStr}</span></td>
          <td data-label="Slots"><span class="slots-value">${d.slots}</span> <span class="slots-unit">total</span></td>
          <td data-label="Status"><span class="status-pill ${statusClass}">${statusText}</span></td>
          <td class="actions-cell" data-label="Actions">
            <button class="action-icon-btn" data-index="${i}" aria-label="Edit ${d.name}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
            </button>
          </td>
        </tr>
      `;
    });
    tbody.innerHTML = html;

    document.querySelectorAll('.action-icon-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const dept = departments[idx];
        if (dept) openModal(dept, idx);
      });
    });
  }

  // ================= SAVE DEPARTMENT =================
  async function saveDepartment() {
  const name = deptName.value.trim();

  if (!name) {
    alert('Please enter a department name.');
    return;
  }

  const days = getSelectedDays();

  if (!days.length) {
    alert('Please select at least one operating day.');
    return;
  }

  const idx = parseInt(editIndex.value, 10);

  // ADD NEW DEPARTMENT
  if (idx < 0 || idx >= departments.length) {

    const formData = new FormData();
    formData.append('action', 'add_department');
    formData.append('department_name', name);

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (!result.success) {
        alert(result.message || 'Failed to save department.');
        return;
      }

      const newDept = {
        name: name,
        desc: deptDesc.value.trim(),
        days: days,
        morning: morningStart.value,
        morningEnd: morningEnd.value,
        afternoon: afternoonStart.value,
        afternoonEnd: afternoonEnd.value,
        slots: parseInt(deptSlots.value, 10) || 20,
        active: deptStatus.value === 'active'
      };

      departments.push(newDept);
      renderRows();
      closeModal();

      alert('Department added successfully.');

    } catch (error) {
      console.error(error);
      alert('Unable to connect to the server.');
    }

    return;
  }

  // EDIT EXISTING DEPARTMENT
const currentDept = departments[idx];

if (!currentDept || !currentDept.id) {
  alert('Unable to identify this department in the database.');
  return;
}

const formData = new FormData();

formData.append('action', 'update_department');
formData.append('department_id', currentDept.id);
formData.append('department_name', name);

try {
  const response = await fetch(window.location.href, {
    method: 'POST',
    body: formData
  });

  const result = await response.json();

  if (!result.success) {
    alert(result.message || 'Failed to update department.');
    return;
  }

  const updatedDept = {
    ...currentDept,
    name: name,
    desc: deptDesc.value.trim(),
    days: days,
    morning: morningStart.value,
    morningEnd: morningEnd.value,
    afternoon: afternoonStart.value,
    afternoonEnd: afternoonEnd.value,
    slots: parseInt(deptSlots.value, 10) || 20,
    active: deptStatus.value === 'active'
  };

  departments[idx] = updatedDept;

  renderRows();
  closeModal();

  alert('Department updated successfully.');

} catch (error) {
  console.error(error);
  alert('Unable to connect to the server.');
}

  departments[idx] = newDept;

  renderRows();
  closeModal();
}

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  document.getElementById('dept-form').addEventListener('submit', (e) => {
    e.preventDefault();
    saveDepartment();
  });

  // ================= INIT =================
  renderRows();

  // Set today's date
  const today = new Date();
  const dateStr = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  document.getElementById('today-date').textContent = dateStr;

  // ================= QUICK SELECT HELPERS =================
function selectAllDays() {
  dayCheckboxes.forEach(cb => cb.checked = true);
}

function clearAllDays() {
  dayCheckboxes.forEach(cb => cb.checked = false);
}

function selectWeekdays() {
  const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
  dayCheckboxes.forEach(cb => {
    cb.checked = weekdays.includes(cb.value);
  });
}

function selectWeekends() {
  const weekends = ['Sat', 'Sun'];
  dayCheckboxes.forEach(cb => {
    cb.checked = weekends.includes(cb.value);
  });
}
</script>


<script src="../assets/js/responsive_nav.js"></script>
</body>
</html>
