<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
requireRole('Admin');

$flashMessage = '';
$flashType = 'success';

function fetchDepartments(mysqli $conn): array
{
    $departments = [];
    $result = mysqli_query($conn, 'SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentName');
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $departments[] = $row;
        }
    }
    return $departments;
}

function fetchStaff(mysqli $conn): array
{
    $staff = [];
    $result = mysqli_query(
        $conn,
        'SELECT
            s.StaffID,
            s.UserID,
            s.DepartmentID,
            s.StaffRole,
            s.Specialization,
            s.AvailabilityStatus,
            TIME_FORMAT(s.ScheduleStart, "%H:%i") AS ScheduleStart,
            TIME_FORMAT(s.ScheduleEnd, "%H:%i") AS ScheduleEnd,
            COALESCE(s.AssignedResponsibilities, "") AS AssignedResponsibilities,
            u.FirstName,
            u.LastName,
            u.Email,
            COALESCE(u.ContactNumber, "") AS ContactNumber,
            u.Status,
            COALESCE(d.DepartmentName, "") AS DepartmentName,
            CASE WHEN u.Status = "Active" THEN 1 ELSE 0 END AS IsActive
         FROM staff s
         INNER JOIN users u ON u.UserID = s.UserID
         LEFT JOIN departments d ON d.DepartmentID = s.DepartmentID
         WHERE u.RoleID = 2
           AND s.StaffRole NOT IN ("Doctor", "Administrator")
         ORDER BY u.LastName, u.FirstName'
    );

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $staff[] = [
                'staffId' => (int) ($row['StaffID'] ?? 0),
                'userId' => (int) ($row['UserID'] ?? 0),
                'name' => trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? '')),
                'email' => (string) ($row['Email'] ?? ''),
                'phone' => (string) ($row['ContactNumber'] ?? ''),
                'role' => (string) ($row['StaffRole'] ?? 'Staff'),
                'department' => (string) ($row['DepartmentName'] ?? 'Unassigned'),
                'departmentId' => (int) ($row['DepartmentID'] ?? 0),
                'status' => (string) ($row['AvailabilityStatus'] ?? 'Available'),
                'startTime' => (string) ($row['ScheduleStart'] ?? '08:00'),
                'endTime' => (string) ($row['ScheduleEnd'] ?? '17:00'),
                'active' => !empty($row['IsActive']) || ($row['Status'] ?? '') === 'Active',
                'userStatus' => (string) ($row['Status'] ?? 'Active'),
                'specialization' => (string) ($row['Specialization'] ?? ''),
                'responsibilities' => (string) ($row['AssignedResponsibilities'] ?? ''),
            ];
        }
    }

    return $staff;
}

function emailExists(mysqli $conn, string $email, int $excludeUserId = 0): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT UserID FROM users WHERE Email = ? AND UserID <> ?');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'si', $email, $excludeUserId);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

$departments = fetchDepartments($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['staff-name'] ?? '');
    $email = strtolower(trim($_POST['staff-email'] ?? ''));
    $phone = trim($_POST['staff-phone'] ?? '');
    $role = trim($_POST['staff-role'] ?? '');
    $departmentId = (int) ($_POST['staff-department'] ?? 0);
    $status = trim($_POST['staff-status'] ?? 'Available');
    $startTime = trim($_POST['staff-start-time'] ?? '08:00');
    $endTime = trim($_POST['staff-end-time'] ?? '17:00');
    $active = !empty($_POST['staff-active']);
    $userId = (int) ($_POST['user_id'] ?? 0);
    $specialization = trim($_POST['staff-specialization'] ?? '');
    $responsibilities = trim($_POST['staff-responsibilities'] ?? '');

    if ($action === 'toggle') {
        $toggleUserId = (int) ($_POST['user_id'] ?? 0);
        $currentStatus = trim((string) ($_POST['current_status'] ?? ''));
        $newStatus = ($currentStatus === 'Active') ? 'Inactive' : 'Active';

        if ($toggleUserId > 0) {
            $stmt = mysqli_prepare($conn, 'UPDATE users SET Status = ? WHERE UserID = ?');
            mysqli_stmt_bind_param($stmt, 'si', $newStatus, $toggleUserId);
            if (mysqli_stmt_execute($stmt)) {
                if (mysqli_stmt_affected_rows($stmt) > 0) {
                    adminNotificationCreateForActiveAdmins(
                        $conn,
                        'Staff Status Changed',
                        'A staff account was changed to ' . $newStatus . '.',
                        'Staff',
                        $toggleUserId,
                        'users',
                        'Medium'
                    );
                }
                $flashMessage = 'Staff status updated.';
            } else {
                $flashMessage = 'Could not update staff status.';
                $flashType = 'error';
            }
        }
    } elseif ($action === 'add' || $action === 'update') {
        if ($name === '') {
            $flashMessage = 'Please enter the staff member\'s full name.';
            $flashType = 'error';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashMessage = 'Please enter a valid email address.';
            $flashType = 'error';
        } elseif ($role === '') {
            $flashMessage = 'Please select a staff role.';
            $flashType = 'error';
        } elseif (in_array($role, ['Doctor', 'Administrator'], true)) {
            // Guard: never allow staff to be created with doctor/admin role
            $flashMessage = 'That role cannot be created from Staff Management.';
            $flashType = 'error';
        } elseif ($departmentId <= 0) {
            $flashMessage = 'Please select a department.';
            $flashType = 'error';
        } else {
            $nameParts = preg_split('/\s+/', $name, 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            $userStatus = $active ? 'Active' : 'Inactive';

            if ($action === 'add') {
                if (emailExists($conn, $email)) {
                    $flashMessage = 'A user with this email address already exists.';
                    $flashType = 'error';
                } else {
                    $hashedPassword = password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT);
                    mysqli_begin_transaction($conn);

                    $userStmt = mysqli_prepare(
                        $conn,
                        'INSERT INTO users (RoleID, FirstName, LastName, Email, Password, Sex, ContactNumber, Status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $roleId = 2;
                    $sex = 'Not Specified';
                    $contact = $phone !== '' ? $phone : null;
                    mysqli_stmt_bind_param(
                        $userStmt,
                        'isssssss',
                        $roleId,
                        $firstName,
                        $lastName,
                        $email,
                        $hashedPassword,
                        $sex,
                        $contact,
                        $userStatus
                    );

                    if (!mysqli_stmt_execute($userStmt)) {
                        mysqli_rollback($conn);
                        $flashMessage = 'Unable to create staff account.';
                        $flashType = 'error';
                    } else {
                        $newUserId = (int) mysqli_insert_id($conn);
                        $staffStmt = mysqli_prepare(
                            $conn,
                            'INSERT INTO staff (UserID, DepartmentID, StaffRole, Specialization, AvailabilityStatus, ScheduleStart, ScheduleEnd, AssignedResponsibilities)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $spec = $specialization !== '' ? $specialization : null;
                        $start = $startTime !== '' ? $startTime : null;
                        $end = $endTime !== '' ? $endTime : null;
                        $resp = $responsibilities !== '' ? $responsibilities : null;
                        mysqli_stmt_bind_param(
                            $staffStmt,
                            'iissssss',
                            $newUserId,
                            $departmentId,
                            $role,
                            $spec,
                            $status,
                            $start,
                            $end,
                            $resp
                        );

                        if (!mysqli_stmt_execute($staffStmt)) {
                            mysqli_rollback($conn);
                            $flashMessage = 'Unable to create staff profile.';
                            $flashType = 'error';
                        } else {
                            mysqli_commit($conn);
                            $flashMessage = 'Staff member added successfully.';
                            adminNotificationCreateForActiveAdmins(
                                $conn,
                                'Staff Created',
                                'Staff member ' . $name . ' was added to the Admin Portal.',
                                'Staff',
                                $newUserId,
                                'users',
                                'Medium'
                            );
                        }
                    }
                }
            } else {
                if ($userId <= 0) {
                    $flashMessage = 'Staff record not found for update.';
                    $flashType = 'error';
                } else {
                    mysqli_begin_transaction($conn);

                    $userStmt = mysqli_prepare(
                        $conn,
                        'UPDATE users SET FirstName = ?, LastName = ?, Email = ?, ContactNumber = ?, Status = ? WHERE UserID = ?'
                    );
                    mysqli_stmt_bind_param(
                        $userStmt,
                        'sssssi',
                        $firstName,
                        $lastName,
                        $email,
                        $phone,
                        $userStatus,
                        $userId
                    );

                    if (!mysqli_stmt_execute($userStmt)) {
                        mysqli_rollback($conn);
                        $flashMessage = 'Unable to update staff account.';
                        $flashType = 'error';
                    } else {
                        $staffStmt = mysqli_prepare(
                            $conn,
                            'UPDATE staff SET DepartmentID = ?, StaffRole = ?, Specialization = ?, AvailabilityStatus = ?, ScheduleStart = ?, ScheduleEnd = ?, AssignedResponsibilities = ? WHERE UserID = ?'
                        );
                        $spec = $specialization !== '' ? $specialization : null;
                        $start = $startTime !== '' ? $startTime : null;
                        $end = $endTime !== '' ? $endTime : null;
                        $resp = $responsibilities !== '' ? $responsibilities : null;
                        mysqli_stmt_bind_param(
                            $staffStmt,
                            'issssssi',
                            $departmentId,
                            $role,
                            $spec,
                            $status,
                            $start,
                            $end,
                            $resp,
                            $userId
                        );

                        if (!mysqli_stmt_execute($staffStmt)) {
                            mysqli_rollback($conn);
                            $flashMessage = 'Unable to update staff profile.';
                            $flashType = 'error';
                        } else {
                            $staffChanged = mysqli_stmt_affected_rows($userStmt) > 0
                                || mysqli_stmt_affected_rows($staffStmt) > 0;
                            mysqli_commit($conn);
                            $flashMessage = 'Staff member updated successfully.';
                            if ($staffChanged) {
                                adminNotificationCreateForActiveAdmins(
                                    $conn,
                                    'Staff Updated',
                                    'Staff member ' . $name . ' was updated.',
                                    'Staff',
                                    $userId,
                                    'users',
                                    'Low'
                                );
                            }
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        if ($userId <= 0) {
            $flashMessage = 'Invalid staff selection.';
            $flashType = 'error';
        } else {
            mysqli_begin_transaction($conn);
            $staffStmt = mysqli_prepare($conn, 'DELETE FROM staff WHERE UserID = ?');
            mysqli_stmt_bind_param($staffStmt, 'i', $userId);
            if (!mysqli_stmt_execute($staffStmt)) {
                mysqli_rollback($conn);
                $flashMessage = 'Unable to delete staff record: ' . mysqli_error($conn);
                $flashType = 'error';
            } else {
                $userStmt = mysqli_prepare($conn, 'DELETE FROM users WHERE UserID = ? AND RoleID = 2');
                mysqli_stmt_bind_param($userStmt, 'i', $userId);
                if (!mysqli_stmt_execute($userStmt)) {
                    mysqli_rollback($conn);
                    $flashMessage = 'Unable to delete staff account: ' . mysqli_error($conn);
                    $flashType = 'error';
                } else {
                    mysqli_commit($conn);
                    $flashMessage = 'Staff member removed successfully.';
                }
            }
        }
    }
}

$staffMembers = fetchStaff($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Management — Curora Admin Portal</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_staff_management.css">
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
      <li class="nav-item active">
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
        <h1>Staff Management</h1>
        <p id="staff-count"><?php echo count($staffMembers); ?> staff members</p>
      </div>

      <?php include __DIR__ . '/../includes/admin_notification_widget.php'; ?>
    </div>

    <div class="panel">
      <?php if ($flashMessage !== ''): ?>
        <div class="flash-message" style="padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;font-weight:500;border:1px solid;
          <?php echo $flashType === 'error' ? 'background:#fee2e2;color:#991b1b;border-color:#fecaca;' : 'background:#dcfce7;color:#065f46;border-color:#bbf7d0;'; ?>">
          <?php echo htmlspecialchars($flashMessage); ?>
        </div>
      <?php endif; ?>

      <div class="panel-head">
        <div>
          <div class="panel-head-title" style="font-size:1.15rem;">Staff</div>
          <div class="panel-head-meta" style="margin-top:4px;">Manage staff accounts and assignments</div>
        </div>
        <div class="staff-header-actions">
          <button class="btn-checkin-solid" id="add-staff-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Staff
          </button>
        </div>
      </div>

      <!-- Staff Table -->
      <div class="table-wrap">
        <table class="staff-table">
          <thead>
            <tr>
              <th>Staff Member</th>
              <th>Role</th>
              <th>Department</th>
              <th>Schedule</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="staff-rows">
            <!-- Rendered by JavaScript -->
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<!-- ================= ADD / EDIT STAFF MODAL ================= -->
<div class="modal-overlay hidden" id="staff-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">Add New Staff</div>
        <div class="modal-sub" id="modal-sub">Fill in the staff member's details</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <form id="staff-form" method="POST">
      <input type="hidden" id="edit-index" value="-1" />
      <input type="hidden" id="staff-action" name="action" value="add" />
      <input type="hidden" id="staff-user-id" name="user_id" value="" />

      <div class="form-group">
        <label for="staff-name">Full Name <span class="hint">(e.g. Nurse Jennifer Jones)</span></label>
        <input type="text" id="staff-name" name="staff-name" placeholder="Nurse Jennifer Jones" required />
      </div>

      <div class="form-group">
        <label for="staff-email">Email</label>
        <input type="email" id="staff-email" name="staff-email" placeholder="staff@hospital.com" />
      </div>

      <div class="form-group">
        <label for="staff-phone">Phone Number</label>
        <input type="text" id="staff-phone" name="staff-phone" placeholder="(555) 123-4567" />
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="staff-role">Staff Role</label>
          <select id="staff-role" name="staff-role">
            <option value="Nurse">Nurse</option>
            <option value="Receptionist">Receptionist</option>
            <option value="Technician">Technician</option>
            <option value="Pharmacist">Pharmacist</option>
            <option value="Therapist">Therapist</option>
            <option value="Lab Assistant">Lab Assistant</option>
            <option value="Janitorial">Janitorial</option>
          </select>
        </div>
        <div class="form-group">
          <label for="staff-department">Assigned Department</label>
          <select id="staff-department" name="staff-department">
            <?php foreach ($departments as $dept): ?>
              <option value="<?php echo (int) $dept['DepartmentID']; ?>"><?php echo htmlspecialchars($dept['DepartmentName']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label for="staff-specialization">Specialization</label>
        <input type="text" id="staff-specialization" name="staff-specialization" placeholder="General Nursing, Lab Services..." />
      </div>

      <div class="form-group">
        <label>Availability Status</label>
        <div class="status-options">
          <label class="status-option">
            <input type="radio" name="staff-status" value="Available" checked />
            Available
          </label>
          <label class="status-option">
            <input type="radio" name="staff-status" value="Off Duty" />
            Off Duty
          </label>
          <label class="status-option">
            <input type="radio" name="staff-status" value="On Leave" />
            On Leave
          </label>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="staff-start-time">Duty Start Time</label>
          <input type="time" id="staff-start-time" name="staff-start-time" value="08:00" />
        </div>
        <div class="form-group">
          <label for="staff-end-time">Duty End Time</label>
          <input type="time" id="staff-end-time" name="staff-end-time" value="17:00" />
        </div>
      </div>

      <div class="form-group">
        <label for="staff-responsibilities">Assigned Responsibilities</label>
        <textarea id="staff-responsibilities" name="staff-responsibilities" placeholder="Patient intake, triage, records..."></textarea>
      </div>

      <div class="form-group">
        <label class="toggle-active">
          <input type="checkbox" id="staff-active" name="staff-active" checked />
          <span>Staff member is active</span>
        </label>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Create Staff
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= STAFF DATA =================
  let staffMembers = <?php echo json_encode($staffMembers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

  // Defensive: strip any doctors/admins that may have leaked in
  staffMembers = staffMembers.filter(s => {
    const r = (s.role || '').toLowerCase();
    return r !== 'doctor' && r !== 'administrator';
  });

  // ================= DOM REFS =================
  const tbody = document.getElementById('staff-rows');
  const staffCount = document.getElementById('staff-count');
  const modal = document.getElementById('staff-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const editIndex = document.getElementById('edit-index');
  const staffName = document.getElementById('staff-name');
  const staffEmail = document.getElementById('staff-email');
  const staffPhone = document.getElementById('staff-phone');
  const staffRole = document.getElementById('staff-role');
  const staffDepartment = document.getElementById('staff-department');
  const staffStatusRadios = document.querySelectorAll('input[name="staff-status"]');
  const staffStartTime = document.getElementById('staff-start-time');
  const staffEndTime = document.getElementById('staff-end-time');
  const staffActive = document.getElementById('staff-active');
  const staffSpecialization = document.getElementById('staff-specialization');
  const staffResponsibilities = document.getElementById('staff-responsibilities');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-staff-btn');

  function getSelectedStatus() {
    for (const radio of staffStatusRadios) {
      if (radio.checked) return radio.value;
    }
    return 'Available';
  }

  function setSelectedStatus(status) {
    for (const radio of staffStatusRadios) {
      radio.checked = radio.value === status;
    }
  }

  function resetForm() {
    staffName.value = '';
    staffEmail.value = '';
    staffPhone.value = '';
    staffRole.value = 'Nurse';
    staffDepartment.value = staffDepartment.options[0] ? staffDepartment.options[0].value : '';
    setSelectedStatus('Available');
    staffStartTime.value = '08:00';
    staffEndTime.value = '17:00';
    staffSpecialization.value = '';
    staffResponsibilities.value = '';
    staffActive.checked = true;
    editIndex.value = '-1';
    document.getElementById('staff-action').value = 'add';
    document.getElementById('staff-user-id').value = '';
    modalTitle.textContent = 'Add New Staff';
    modalSub.textContent = 'Fill in the staff member\'s details';
    saveBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Create Staff
    `;
  }

  function openModal(staffData, index) {
    if (staffData) {
      staffName.value = staffData.name || '';
      staffEmail.value = staffData.email || '';
      staffPhone.value = staffData.phone || '';
      staffRole.value = staffData.role || 'Nurse';
      staffDepartment.value = String(staffData.departmentId || staffDepartment.options[0]?.value || '');
      setSelectedStatus(staffData.status || 'Available');
      staffStartTime.value = staffData.startTime || '08:00';
      staffEndTime.value = staffData.endTime || '17:00';
      staffSpecialization.value = staffData.specialization || '';
      staffResponsibilities.value = staffData.responsibilities || '';
      staffActive.checked = staffData.active !== undefined ? staffData.active : true;
      editIndex.value = index;
      document.getElementById('staff-action').value = 'update';
      document.getElementById('staff-user-id').value = staffData.userId || '';
      modalTitle.textContent = `Edit ${staffData.name}`;
      modalSub.textContent = 'Update staff information';
      saveBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Update Staff
      `;
    } else {
      resetForm();
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  function renderStaff() {
    const activeStaff = staffMembers.filter(s => s.active);
    staffCount.textContent = `${activeStaff.length} staff members`;

    if (!staffMembers.length) {
      tbody.innerHTML = `<tr><td colspan="6" class="empty-row">No staff members found. Click "Add Staff" to create one.</td></tr>`;
      return;
    }

    let html = '';
    staffMembers.forEach((s, i) => {
      const statusClass = (s.status || 'Available').toLowerCase().replace(/\s+/g, '-');
      const schedule = `${s.startTime || '08:00'} - ${s.endTime || '17:00'}`;
      const activeBadge = s.active ? 'Active' : 'Inactive';
      const activeClass = s.active ? 'badge-active' : 'badge-inactive';

      html += `
        <tr>
          <td>
            <div class="staff-name">${s.name}</div>
            <div class="staff-email">${s.email || ''}</div>
          </td>
          <td><span class="role-badge">${s.role}</span></td>
          <td>${s.department}</td>
          <td><span class="schedule-time">${schedule}</span></td>
          <td>
            <span class="status-badge ${statusClass}">${s.status}</span>
            <span class="active-badge ${activeClass}">${activeBadge}</span>
          </td>
          <td>
            <button class="action-btn edit-btn" data-index="${i}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
            </button>
            <button class="action-btn toggle-btn" data-index="${i}">
              ${s.active ? 'Deactivate' : 'Activate'}
            </button>
            <button class="action-btn delete-btn" data-index="${i}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </td>
        </tr>
      `;
    });
    tbody.innerHTML = html;

    document.querySelectorAll('.edit-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const staff = staffMembers[idx];
        if (staff) openModal(staff, idx);
      });
    });

    document.querySelectorAll('.toggle-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const staff = staffMembers[idx];
        if (!staff) return;

        const currentStatus = (staff.userStatus ?? (staff.active ? 'Active' : 'Inactive'));
        const nextStatus = currentStatus === 'Active' ? 'Inactive' : 'Active';

        const params = new URLSearchParams();
        params.set('action', 'toggle');
        params.set('user_id', staff.userId || '');
        params.set('current_status', currentStatus);
        params.set('next_status', nextStatus);

        fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: params.toString()
        })
        .then(r => r.text())
        .then(data => {
          console.log('Toggle response:', data);
          window.location.reload();
        })
        .catch(err => {
          console.error('Toggle error:', err);
          window.location.reload();
        });
      });
    });

    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const staff = staffMembers[idx];
        if (!staff) return;

        if (!staff.userId || staff.userId <= 0) {
          alert('Cannot delete: invalid staff ID.');
          return;
        }

        if (confirm(`Are you sure you want to remove ${staff.name}?`)) {
          const params = new URLSearchParams();
          params.set('action', 'delete');
          params.set('user_id', String(staff.userId));

          fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
          })
          .then(r => r.text())
          .then(data => {
            console.log('Delete response:', data);
            window.location.reload();
          })
          .catch(err => {
            console.error('Delete error:', err);
            window.location.reload();
          });
        }
      });
    });
  }

  function saveStaff() {
    const name = staffName.value.trim();
    if (!name) { alert('Please enter the staff member\'s full name.'); return; }

    const params = new URLSearchParams();
    params.set('action', document.getElementById('staff-action').value || 'add');
    params.set('user_id', document.getElementById('staff-user-id').value || '');
    params.set('staff-name', staffName.value.trim());
    params.set('staff-email', staffEmail.value.trim());
    params.set('staff-phone', staffPhone.value.trim());
    params.set('staff-role', staffRole.value);
    params.set('staff-department', staffDepartment.value);
    params.set('staff-status', getSelectedStatus());
    params.set('staff-start-time', staffStartTime.value || '08:00');
    params.set('staff-end-time', staffEndTime.value || '17:00');
    params.set('staff-specialization', staffSpecialization.value.trim());
    params.set('staff-responsibilities', staffResponsibilities.value.trim());
    params.set('staff-active', staffActive.checked ? '1' : '0');

    fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: params.toString()
    }).then(() => {
      window.location.reload();
    }).catch(() => {
      window.location.reload();
    });
  }

  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

  document.getElementById('staff-form').addEventListener('submit', (e) => {
    e.preventDefault();
    saveStaff();
  });

  renderStaff();
</script>
</body>
</html>