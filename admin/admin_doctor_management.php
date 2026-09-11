<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
requireRole('Admin');

$flashMessage = '';
$flashType = 'success';

// All departments (used by the add/edit modal)
$departments = [];
$deptResult = mysqli_query($conn, 'SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentName');
if ($deptResult) {
    $departments = mysqli_fetch_all($deptResult, MYSQLI_ASSOC);
}

function fetchDoctors(mysqli $conn): array
{
    $doctors = [];
    $result = mysqli_query(
        $conn,
        'SELECT
            s.StaffID,
            s.UserID,
            s.DepartmentID,
            s.Specialization,
            COALESCE(s.LicenseNumber, "") AS LicenseNumber,
            COALESCE(s.YearsOfExperience, 0) AS YearsOfExperience,
            s.AvailabilityStatus,
            TIME_FORMAT(s.ScheduleStart, "%H:%i") AS ScheduleStart,
            TIME_FORMAT(s.ScheduleEnd, "%H:%i") AS ScheduleEnd,
            COALESCE(s.AssignedResponsibilities, "") AS Bio,
            u.FirstName,
            u.LastName,
            u.Email,
            COALESCE(u.ContactNumber, "") AS ContactNumber,
            COALESCE(u.ProfilePhoto, "") AS ProfilePhoto,
            u.Status,
            COALESCE(d.DepartmentName, "") AS DepartmentName,
            (SELECT COUNT(DISTINCT a.PatientID)
             FROM appointments a
             WHERE a.StaffID = s.StaffID) AS PatientsCount
         FROM staff s
         INNER JOIN users u ON u.UserID = s.UserID
         LEFT JOIN departments d ON d.DepartmentID = s.DepartmentID
         WHERE s.StaffRole = "Doctor"
         ORDER BY u.FirstName, u.LastName'
    );

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $doctors[] = [
                'staffId'      => (int) $row['StaffID'],
                'userId'       => (int) $row['UserID'],
                'name'         => trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? '')),
                'phone'        => (string) $row['ContactNumber'],
                'email'        => (string) $row['Email'],
                'departmentId' => (int) $row['DepartmentID'],
                'department'   => (string) $row['DepartmentName'],
                'license'      => (string) $row['LicenseNumber'],
                'specialization' => (string) $row['Specialization'],
                'experience'   => (int) $row['YearsOfExperience'],
                'status'       => (string) $row['AvailabilityStatus'],
                'startTime'    => (string) $row['ScheduleStart'],
                'endTime'      => (string) $row['ScheduleEnd'],
                'bio'          => (string) $row['Bio'],
                'image'        => (string) $row['ProfilePhoto'],
                'active'       => ($row['Status'] ?? '') === 'Active',
                'userStatus'   => (string) $row['Status'],
                'patients'     => (int) $row['PatientsCount']
            ];
        }
    }

    return $doctors;
}

function validateDoctorInput(string $firstName, string $lastName, string $email, int $departmentId, string &$error): bool
{
    if ($firstName === '' || $lastName === '') {
        $error = 'Please enter the doctor\'s full name.';
        return false;
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
        return false;
    }
    if ($departmentId <= 0) {
        $error = 'Please select a department.';
        return false;
    }
    return true;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $name = trim($_POST['doctor-name'] ?? '');
    $email = strtolower(trim($_POST['doctor-email'] ?? ''));
    $phone = trim($_POST['doctor-phone'] ?? '');
    $departmentId = (int) ($_POST['doctor-department'] ?? 0);
    $license = trim($_POST['doctor-license'] ?? '');
    $specialization = trim($_POST['doctor-specialization'] ?? '');
    $experience = (int) ($_POST['doctor-experience'] ?? 0);
    $statusOptions = ['Available', 'Off Duty', 'On Leave'];
    $availabilityStatus = in_array(trim($_POST['doctor-status'] ?? ''), $statusOptions, true)
        ? trim($_POST['doctor-status'])
        : 'Available';
    $startTime = trim($_POST['doctor-start-time'] ?? '');
    $endTime = trim($_POST['doctor-end-time'] ?? '');
    $bio = trim($_POST['doctor-bio'] ?? '');
    $image = trim($_POST['doctor-image'] ?? '');
    $userStatus = !empty($_POST['doctor-active']) ? 'Active' : 'Inactive';

    $nameParts = preg_split('/\s+/', $name, 2);
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';

    if ($action === 'toggle') {
        // Toggle doctor account active/inactive based on the users.Status column
        $toggleUserId = (int) ($_POST['user_id'] ?? 0);
        $newStatus = ($_POST['current_status'] ?? '') === 'Active' ? 'Inactive' : 'Active';

        if ($toggleUserId > 0) {
            $toggleStmt = mysqli_prepare($conn, 'UPDATE users SET Status = ? WHERE UserID = ?');
            mysqli_stmt_bind_param($toggleStmt, 'si', $newStatus, $toggleUserId);
            if (mysqli_stmt_execute($toggleStmt)) {
                $flashMessage = 'Doctor status updated.';
            } else {
                $flashMessage = 'Could not update doctor status. Please try again.';
                $flashType = 'error';
            }
        }
    } elseif ($action === 'add') {
        if (!validateDoctorInput($firstName, $lastName, $email, $departmentId, $flashMessage)) {
            $flashType = 'error';
        } elseif (emailExists($conn, $email)) {
            $flashMessage = 'A user with this email address already exists.';
            $flashType = 'error';
        } else {
            $tempPassword = bin2hex(random_bytes(6));
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);

            mysqli_begin_transaction($conn);

            $roleID = 4; // Doctor role
            $sex = 'Not Specified';
            $contact = $phone !== '' ? $phone : null;
            $photo = $image !== '' ? $image : null;

            $userStmt = mysqli_prepare(
                $conn,
                'INSERT INTO users (RoleID, FirstName, LastName, Email, Password, Sex, ContactNumber, Status, ProfilePhoto)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            mysqli_stmt_bind_param(
                $userStmt,
                'issssssss',
                $roleID,
                $firstName,
                $lastName,
                $email,
                $hashedPassword,
                $sex,
                $contact,
                $userStatus,
                $photo
            );

            if (!mysqli_stmt_execute($userStmt)) {
                mysqli_rollback($conn);
                $flashMessage = 'Registration failed. Please try again.';
                $flashType = 'error';
            } else {
                $userId = (int) mysqli_insert_id($conn);
                $staffRole = 'Doctor';
                $licenseV = $license !== '' ? $license : null;
                $specV = $specialization !== '' ? $specialization : null;
                $expV = $experience > 0 ? $experience : null;
                $startV = $startTime !== '' ? $startTime : null;
                $endV = $endTime !== '' ? $endTime : null;
                $bioV = $bio !== '' ? $bio : null;

                $staffStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO staff (UserID, DepartmentID, StaffRole, Specialization, LicenseNumber, YearsOfExperience, AvailabilityStatus, ScheduleStart, ScheduleEnd, AssignedResponsibilities)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                mysqli_stmt_bind_param(
                    $staffStmt,
                    'iisssissss',
                    $userId,
                    $departmentId,
                    $staffRole,
                    $specV,
                    $licenseV,
                    $expV,
                    $availabilityStatus,
                    $startV,
                    $endV,
                    $bioV
                );

                if (mysqli_stmt_execute($staffStmt)) {
                    mysqli_commit($conn);
                    $flashMessage = 'Doctor account created for ' . $name . '. Temporary password: ' . $tempPassword;

                    // Best-effort email of the new credentials
                    if (is_file(__DIR__ . '/../includes/mailer.php') && !function_exists('sendMediCareEmail')) {
                        require_once __DIR__ . '/../includes/mailer.php';
                    }
                    if (function_exists('sendMediCareEmail')) {
                        sendMediCareEmail(
                            $email,
                            'Your MediCare doctor account has been created',
                            '<p>Hello ' . htmlspecialchars($firstName) . ',</p>'
                            . '<p>An administrator has created your doctor account for the Hospital Outpatient '
                            . 'Appointment and Consultation Record Management System (MediCare).</p>'
                            . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '<br>'
                            . '<strong>Temporary password:</strong> ' . htmlspecialchars($tempPassword) . '</p>'
                            . '<p>Please sign in and change your password as soon as possible.</p>'
                        );
                    }
                } else {
                    mysqli_rollback($conn);
                    $flashMessage = 'Registration failed. Please try again.';
                    $flashType = 'error';
                }
            }
        }
    } elseif ($action === 'update') {
        $staffId = (int) ($_POST['staff_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($staffId <= 0 || $userId <= 0) {
            $flashMessage = 'Missing doctor record. Please try again.';
            $flashType = 'error';
        } elseif (!validateDoctorInput($firstName, $lastName, $email, $departmentId, $flashMessage)) {
            $flashType = 'error';
        } elseif (emailExists($conn, $email, $userId)) {
            $flashMessage = 'A user with this email address already exists.';
            $flashType = 'error';
        } else {
            mysqli_begin_transaction($conn);

            $contact = $phone !== '' ? $phone : null;
            $photo = $image !== '' ? $image : null;

            $userStmt = mysqli_prepare(
                $conn,
                'UPDATE users
                 SET FirstName = ?, LastName = ?, Email = ?, ContactNumber = ?, ProfilePhoto = ?, Status = ?
                 WHERE UserID = ?'
            );
            mysqli_stmt_bind_param(
                $userStmt,
                'ssssssi',
                $firstName,
                $lastName,
                $email,
                $contact,
                $photo,
                $userStatus,
                $userId
            );

            if (!mysqli_stmt_execute($userStmt)) {
                mysqli_rollback($conn);
                $flashMessage = 'Update failed. Please try again.';
                $flashType = 'error';
            } else {
                $licenseV = $license !== '' ? $license : null;
                $specV = $specialization !== '' ? $specialization : null;
                $expV = $experience > 0 ? $experience : null;
                $startV = $startTime !== '' ? $startTime : null;
                $endV = $endTime !== '' ? $endTime : null;
                $bioV = $bio !== '' ? $bio : null;

                $staffStmt = mysqli_prepare(
                    $conn,
                    'UPDATE staff
                     SET DepartmentID = ?, Specialization = ?, LicenseNumber = ?, YearsOfExperience = ?,
                         AvailabilityStatus = ?, ScheduleStart = ?, ScheduleEnd = ?, AssignedResponsibilities = ?
                     WHERE StaffID = ?'
                );
                mysqli_stmt_bind_param(
                    $staffStmt,
                    'ississssi',
                    $departmentId,
                    $specV,
                    $licenseV,
                    $expV,
                    $availabilityStatus,
                    $startV,
                    $endV,
                    $bioV,
                    $staffId
                );

                if (mysqli_stmt_execute($staffStmt)) {
                    mysqli_commit($conn);
                    $flashMessage = 'Doctor account updated.';
                } else {
                    mysqli_rollback($conn);
                    $flashMessage = 'Update failed. Please try again.';
                    $flashType = 'error';
                }
            }
        }
    }
}

$doctors = fetchDoctors($conn);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Management — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_doctor_management.css">
</head>
<body>
<div class="app">

    <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/><path d="M3.22 8.5H9.5l1.5-2 2 4 1.5-2h6.28"/></svg>
      </div>
      <div class="brand-text">
        <div class="brand-title">MediCare</div>
        <div class="brand-sub">Admin Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item">
        <a href="admin_dashboard.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_department.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 21v-6h6v6"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01"/></svg>
          Departments
        </a>
      </li>
      <li class="nav-item active">
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
        <h1>Doctor Management</h1>
        <p id="doctor-count"><?php echo count($doctors); ?> doctors</p>
      </div>
      <button class="notif-bell" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span class="notif-badge">2</span>
      </button>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-head-title" style="font-size:1.15rem;">Doctors</div>
          <div class="panel-head-meta" style="margin-top:4px;">Manage doctor accounts and assignments</div>
        </div>
        <div class="staff-header-actions">
          <button class="btn-checkin-solid" id="add-doctor-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add New Doctor
          </button>
        </div>
      </div>

      <?php if ($flashMessage !== ''): ?>
        <div class="flash-message" style="padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;font-weight:500;border:1px solid;
          <?php echo $flashType === 'error' ? 'background:#fee2e2;color:#991b1b;border-color:#fecaca;' : 'background:#dcfce7;color:#065f46;border-color:#bbf7d0;'; ?>">
          <?php echo htmlspecialchars($flashMessage); ?>
        </div>
      <?php endif; ?>

      <!-- Doctor Table -->
      <div class="table-wrap">
        <table class="staff-table">
          <thead>
            <tr>
              <th>Doctor</th>
              <th>Department</th>
              <th>Specialization</th>
              <th>Schedule</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="doctor-rows">
            <!-- Rendered by JavaScript -->
          </tbody>
        </table>
      </div>

  </main>
</div>

<!-- ================= ADD / EDIT DOCTOR MODAL ================= -->
<div class="modal-overlay hidden" id="doctor-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">Add New Doctor</div>
        <div class="modal-sub" id="modal-sub">Fill in the doctor's details</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <form id="doctor-form" action="admin_doctor_management.php" method="POST">
      <input type="hidden" name="action" id="form-action" value="add" />
      <input type="hidden" name="staff_id" id="form-staff-id" value="" />
      <input type="hidden" name="user_id" id="form-user-id" value="" />

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-name">Full Name <span class="hint">(e.g. Dr. Sarah Mitchell)</span></label>
          <input type="text" id="doctor-name" name="doctor-name" placeholder="Dr. Sarah Mitchell" required />
        </div>
        <div class="form-group">
          <label for="doctor-email">Email Address</label>
          <input type="email" id="doctor-email" name="doctor-email" placeholder="doctor@clinic.example" required />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-phone">Phone Number</label>
          <input type="text" id="doctor-phone" name="doctor-phone" placeholder="(555) 123-4567" />
        </div>
        <div class="form-group">
          <label for="doctor-department">Department</label>
          <select id="doctor-department" name="doctor-department" required>
            <option value="">Select department</option>
            <?php foreach ($departments as $dept): ?>
              <option value="<?php echo (int) $dept['DepartmentID']; ?>"><?php echo htmlspecialchars($dept['DepartmentName']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-license">License Number</label>
          <input type="text" id="doctor-license" name="doctor-license" placeholder="MD-XXXX-001" />
        </div>
        <div class="form-group">
          <label for="doctor-specialization">Specialization</label>
          <input type="text" id="doctor-specialization" name="doctor-specialization" placeholder="e.g. Cardiologist" />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-experience">Years of Experience</label>
          <input type="number" id="doctor-experience" name="doctor-experience" value="0" min="0" />
        </div>
        <div class="form-group">
          <label for="doctor-status">Availability Status</label>
          <select id="doctor-status" name="doctor-status">
            <option value="Available">Available</option>
            <option value="Off Duty">Off Duty</option>
            <option value="On Leave">On Leave</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-start-time">Duty Start Time</label>
          <input type="time" id="doctor-start-time" name="doctor-start-time" value="08:00" />
        </div>
        <div class="form-group">
          <label for="doctor-end-time">Duty End Time</label>
          <input type="time" id="doctor-end-time" name="doctor-end-time" value="17:00" />
        </div>
      </div>

      <div class="form-group">
        <label for="doctor-bio">Bio / Description</label>
        <textarea id="doctor-bio" name="doctor-bio" placeholder="Brief professional bio..."></textarea>
      </div>

      <div class="form-group">
        <label for="doctor-image">Profile Image URL</label>
        <input type="text" id="doctor-image" name="doctor-image" placeholder="https://..." />
      </div>

      <div class="form-group">
        <label class="day-check" style="display:flex; align-items:center; gap:10px; cursor:pointer;">
          <input type="checkbox" id="doctor-active" name="doctor-active" checked />
          <span style="font-weight:600;">Doctor is active</span>
        </label>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Create Doctor
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= DOCTOR DATA (from database) =================
  const doctors = <?php echo json_encode($doctors, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

  // ================= DOM REFS =================
  const grid = document.getElementById('doctor-grid');
  const modal = document.getElementById('doctor-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const formAction = document.getElementById('form-action');
  const formStaffId = document.getElementById('form-staff-id');
  const formUserId = document.getElementById('form-user-id');
  const doctorName = document.getElementById('doctor-name');
  const doctorEmail = document.getElementById('doctor-email');
  const doctorPhone = document.getElementById('doctor-phone');
  const doctorDepartment = document.getElementById('doctor-department');
  const doctorLicense = document.getElementById('doctor-license');
  const doctorSpecialization = document.getElementById('doctor-specialization');
  const doctorExperience = document.getElementById('doctor-experience');
  const doctorStatus = document.getElementById('doctor-status');
  const doctorStartTime = document.getElementById('doctor-start-time');
  const doctorEndTime = document.getElementById('doctor-end-time');
  const doctorBio = document.getElementById('doctor-bio');
  const doctorImage = document.getElementById('doctor-image');
  const doctorActive = document.getElementById('doctor-active');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-doctor-btn');

  // ================= HELPERS =================
  function getInitials(name) {
    const cleaned = name.replace(/^Dr\.?\s+/i, '');
    const words = cleaned.split(/\s+/).filter(Boolean);
    if (!words.length) return 'DR';
    return (words[0][0] + (words[1] ? words[1][0] : words[0][1] || '')).toUpperCase();
  }

  function getStatusClass(status) {
    const map = {
      'Available': 'status-available',
      'Off Duty': 'status-offduty',
      'On Leave': 'status-onleave'
    };
    return map[status] || 'status-available';
  }

  function resetForm() {
    doctorName.value = '';
    doctorEmail.value = '';
    doctorPhone.value = '';
    doctorDepartment.value = '';
    doctorLicense.value = '';
    doctorSpecialization.value = '';
    doctorExperience.value = 0;
    doctorStatus.value = 'Available';
    doctorStartTime.value = '08:00';
    doctorEndTime.value = '17:00';
    doctorBio.value = '';
    doctorImage.value = '';
    doctorActive.checked = true;
    formAction.value = 'add';
    formStaffId.value = '';
    formUserId.value = '';
    modalTitle.textContent = 'Add New Doctor';
    modalSub.textContent = 'Fill in the doctor\'s details';
    saveBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Create Doctor
    `;
  }

  function openModal(doctorData, index) {
    if (doctorData) {
      doctorName.value = doctorData.name || '';
      doctorEmail.value = doctorData.email || '';
      doctorPhone.value = doctorData.phone || '';
      doctorDepartment.value = doctorData.departmentId || '';
      doctorLicense.value = doctorData.license || '';
      doctorSpecialization.value = doctorData.specialization || '';
      doctorExperience.value = doctorData.experience || 0;
      doctorStatus.value = doctorData.status || 'Available';
      doctorStartTime.value = doctorData.startTime || '08:00';
      doctorEndTime.value = doctorData.endTime || '17:00';
      doctorBio.value = doctorData.bio || '';
      doctorImage.value = doctorData.image || '';
      doctorActive.checked = doctorData.active !== undefined ? doctorData.active : true;
      formAction.value = 'update';
      formStaffId.value = doctorData.staffId || '';
      formUserId.value = doctorData.userId || '';
      modalTitle.textContent = `Edit ${doctorData.name}`;
      modalSub.textContent = 'Update doctor information';
      saveBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Update Doctor
      `;
    } else {
      resetForm();
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  // ================= RENDER DOCTOR CARDS =================
  function renderDoctors() {
  const tbody = document.getElementById('doctor-rows');
  const doctorCount = document.getElementById('doctor-count');

  if (doctorCount) {
    doctorCount.textContent = `${doctors.length} doctor${doctors.length === 1 ? '' : 's'}`;
  }

  if (!doctors.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="empty-row">No doctors found. Click "Add New Doctor" to create one.</td></tr>`;
    return;
  }

  let html = '';
  doctors.forEach((d, i) => {
    const statusClass = d.status.toLowerCase().replace(/\s+/g, '-');
    const activeBadge = d.active ? 'Active' : 'Inactive';
    const activeClass = d.active ? 'badge-active' : 'badge-inactive';
    const schedule = `${d.startTime || '08:00'} - ${d.endTime || '17:00'}`;

    html += `
      <tr>
        <td>
          <div class="doctor-name">${d.name}</div>
          <div class="doctor-email">${d.email || ''}</div>
        </td>
        <td>${d.department || '—'}</td>
        <td>${d.specialization || '—'}</td>
        <td><span class="schedule-time">${schedule}</span></td>
        <td>
          <span class="status-badge ${statusClass}">${d.status}</span>
          <span class="active-badge ${activeClass}">${activeBadge}</span>
        </td>

        <td>
          <div class="action-group">
            <button class="action-btn edit-btn" data-index="${i}">
              <svg viewBox="0 0 24 24" ...><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
            </button>
            <form class="toggle-form" action="admin_doctor_management.php" method="POST" style="display:inline;">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="user_id" value="${d.userId}">
              <input type="hidden" name="current_status" value="${d.userStatus}">
              <button type="submit" class="action-btn toggle-btn">${d.active ? 'Deactivate' : 'Activate'}</button>
            </form>
          </div>
        </td>
      </tr>
    `;
  });
  tbody.innerHTML = html;

  // Attach edit events
  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const idx = +btn.dataset.index;
      const doctor = doctors[idx];
      if (doctor) openModal(doctor, idx);
    });
  });
}

  // ================= SAVE DOCTOR (validation only; form POSTs to itself) =================
  function validateDoctorForm() {
    if (!doctorName.value.trim()) {
      alert('Please enter the doctor\'s full name.');
      doctorName.focus();
      return false;
    }
    if (!doctorEmail.value.trim()) {
      alert('Please enter the doctor\'s email address.');
      doctorEmail.focus();
      return false;
    }
    if (formAction.value === 'add' && !doctorDepartment.value) {
      alert('Please select a department.');
      doctorDepartment.focus();
      return false;
    }
    return true;
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

  // Allow native form submission (POST to this page) once client-side checks pass
  document.getElementById('doctor-form').addEventListener('submit', function(e) {
    if (!validateDoctorForm()) {
      e.preventDefault();
    }
  });

  // ================= INIT =================
  renderDoctors();

  // Set today's date
  
</script>
</body>
</html>