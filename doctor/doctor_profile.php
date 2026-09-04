<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($conn) || !$conn) {
    die('Database connection is not available.');
}

$userID = $_SESSION['UserID'] ?? $_SESSION['user_id'] ?? $_SESSION['userid'] ?? null;
if (!$userID) {
    header('Location: ../auth/login.php?portal=doctor');
    exit;
}
$userID = (int)$userID;

$roleName = $_SESSION['RoleName'] ?? $_SESSION['role'] ?? $_SESSION['user_role'] ?? null;
if ($roleName !== null && strcasecmp(trim((string)$roleName), 'Doctor') !== 0) {
    header('Location: ../portal-select.php?action=login');
    exit;
}

// CSRF token (generate once per session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// Fetch doctor + user info
$doctorStmt = mysqli_prepare(
    $conn,
    "SELECT s.StaffID, s.EmployeeID, s.DepartmentID, s.StaffRole, s.Suffix,
            s.Specialization, s.LicenseNumber, s.YearsOfExperience,
            s.AvailabilityStatus, s.DateHired, s.AssignedDays, s.AssignedResponsibilities,
            d.DepartmentName,
            u.UserID, u.FirstName, u.MiddleName, u.LastName, u.Email,
            u.Sex, u.DateOfBirth, u.ContactNumber, u.Address,
            u.ProfilePhoto, u.LastLogin, u.CreatedAt AS DateRegistered,
            u.Status AS AccountStatus
     FROM staff s
     INNER JOIN users u ON s.UserID = u.UserID
     INNER JOIN departments d ON s.DepartmentID = d.DepartmentID
     WHERE s.UserID = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($doctorStmt, 'i', $userID);
mysqli_stmt_execute($doctorStmt);
$doctorResult = mysqli_stmt_get_result($doctorStmt);
$doctor = mysqli_fetch_assoc($doctorResult);
mysqli_stmt_close($doctorStmt);

if (!$doctor) {
    session_destroy();
    header('Location: ../auth/login.php?portal=doctor');
    exit;
}

$initials = strtoupper(substr($doctor['FirstName'], 0, 1) . substr($doctor['LastName'], 0, 1));
$displayName = 'Dr. ' . trim($doctor['FirstName'] . ' ' . $doctor['LastName']);
$plainName = trim($doctor['FirstName'] . ' ' . $doctor['LastName']);
$staffIdFormatted = 'DOC-' . str_pad($doctor['StaffID'], 3, '0', STR_PAD_LEFT);

$dobFormatted = !empty($doctor['DateOfBirth'])
    ? (new DateTime($doctor['DateOfBirth']))->format('Y-m-d')
    : '';
$dobDisplay = !empty($doctor['DateOfBirth'])
    ? (new DateTime($doctor['DateOfBirth']))->format('F j, Y')
    : '';

$lastLoginDisplay = !empty($doctor['LastLogin'])
    ? (new DateTime($doctor['LastLogin']))->format('F j, Y, g:i A')
    : 'Not available';

$dateRegisteredDisplay = !empty($doctor['DateRegistered'])
    ? (new DateTime($doctor['DateRegistered']))->format('F j, Y')
    : '';

$yearsExp = !empty($doctor['YearsOfExperience']) ? $doctor['YearsOfExperience'] : null;
$yearsExpDisplay = $yearsExp !== null ? $yearsExp . ' year' . ($yearsExp != 1 ? 's' : '') : '';

$accountStatus = !empty($doctor['AccountStatus']) ? $doctor['AccountStatus'] : 'Active';

/**
 * Renders a value, or a visually distinct "Not provided" placeholder if empty.
 * Returns raw HTML — do not wrap in htmlspecialchars() again at the call site.
 */
function field($value, $fallback = 'Not provided') {
    $value = trim((string) $value);
    if ($value !== '') {
        return htmlspecialchars($value);
    }
    return '<span class="pinfo-empty">' . htmlspecialchars($fallback) . '</span>';
}

// Fetch department schedules
$scheduleStmt = mysqli_prepare(
    $conn,
    "SELECT DayOfWeek, SessionName, StartTime, EndTime, PatientSlots
     FROM department_schedules
     WHERE DepartmentID = ?
     ORDER BY DayOfWeek, FIELD(SessionName, 'Morning', 'Afternoon')"
);
mysqli_stmt_bind_param($scheduleStmt, 'i', $doctor['DepartmentID']);
mysqli_stmt_execute($scheduleStmt);
$scheduleResult = mysqli_stmt_get_result($scheduleStmt);
$schedules = [];
while ($row = mysqli_fetch_assoc($scheduleResult)) {
    $schedules[] = $row;
}
mysqli_stmt_close($scheduleStmt);

$dayNames = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday'
];

// Handle profile update
$updateMessage = '';
$updateSuccess = false;

// Controls whether each edit panel should render open (e.g. after a failed submit)
$personalEditOpen = false;
$accountEditOpen = false;

// Values used to populate the personal-info form. Default to DB values;
// overridden with submitted values below if validation fails, so the user
// never loses what they typed.
$personalFormData = [
    'FirstName'     => $doctor['FirstName'],
    'MiddleName'    => $doctor['MiddleName'] ?? '',
    'LastName'      => $doctor['LastName'],
    'Sex'           => $doctor['Sex'] ?? '',
    'DateOfBirth'   => $dobFormatted,
    'ContactNumber' => $doctor['ContactNumber'] ?? '',
    'Address'       => $doctor['Address'] ?? '',
];

$accountFormData = [
    'Email' => $doctor['Email'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!verifyCsrf()) {
        $updateMessage = 'Your session expired. Please try again.';
    } elseif ($_POST['action'] === 'update_personal') {
        $firstName = trim($_POST['FirstName'] ?? '');
        $middleName = trim($_POST['MiddleName'] ?? '');
        $lastName = trim($_POST['LastName'] ?? '');
        $sex = trim($_POST['Sex'] ?? '');
        $dob = trim($_POST['DateOfBirth'] ?? '');
        $contact = trim($_POST['ContactNumber'] ?? '');
        $address = trim($_POST['Address'] ?? '');

        // Keep whatever the user typed in the form in case we need to redisplay it
        $personalFormData = [
            'FirstName'     => $firstName,
            'MiddleName'    => $middleName,
            'LastName'      => $lastName,
            'Sex'           => $sex,
            'DateOfBirth'   => $dob,
            'ContactNumber' => $contact,
            'Address'       => $address,
        ];

        // Convert empty date to NULL
        $dobForDb = ($dob === '') ? null : $dob;

        if (empty($firstName) || empty($lastName)) {
            $updateMessage = 'First name and last name are required.';
            $personalEditOpen = true;
        } elseif ($contact !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contact)) {
            $updateMessage = 'Please enter a valid contact number.';
            $personalEditOpen = true;
        } else {

            $uStmt = mysqli_prepare(
                $conn,
                'UPDATE users SET
                    FirstName=?,
                    MiddleName=?,
                    LastName=?,
                    Sex=?,
                    DateOfBirth=?,
                    ContactNumber=?,
                    Address=?
                WHERE UserID=?'
            );

            mysqli_stmt_bind_param(
                $uStmt,
                'sssssssi',
                $firstName,
                $middleName,
                $lastName,
                $sex,
                $dobForDb,
                $contact,
                $address,
                $userID
            );

            mysqli_stmt_execute($uStmt);
            mysqli_stmt_close($uStmt);

            header('Location: doctor_profile.php?updated=1');
            exit();
        }
    }

    if ($_POST['action'] === 'update_account') {
        $email = trim($_POST['Email'] ?? '');
        $newPassword = trim($_POST['NewPassword'] ?? '');
        $confirmPassword = trim($_POST['ConfirmPassword'] ?? '');

        $accountFormData['Email'] = $email;

        if (empty($email)) {
            $updateMessage = 'Email is required.';
            $accountEditOpen = true;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $updateMessage = 'Please enter a valid email address.';
            $accountEditOpen = true;
        } elseif ($newPassword !== '' && $newPassword !== $confirmPassword) {
            $updateMessage = 'Passwords do not match.';
            $accountEditOpen = true;
        } elseif ($newPassword !== '' && strlen($newPassword) < 8) {
            $updateMessage = 'Password must be at least 8 characters.';
            $accountEditOpen = true;
        } else {
            // Make sure no other account is already using this email
            $dupStmt = mysqli_prepare($conn, 'SELECT UserID FROM users WHERE Email = ? AND UserID != ? LIMIT 1');
            mysqli_stmt_bind_param($dupStmt, 'si', $email, $userID);
            mysqli_stmt_execute($dupStmt);
            $dupResult = mysqli_stmt_get_result($dupStmt);
            $duplicate = mysqli_fetch_assoc($dupResult);
            mysqli_stmt_close($dupStmt);

            if ($duplicate) {
                $updateMessage = 'That email address is already in use by another account.';
                $accountEditOpen = true;
            } else {
                $uStmt = mysqli_prepare($conn, 'UPDATE users SET Email=? WHERE UserID=?');
                mysqli_stmt_bind_param($uStmt, 'si', $email, $userID);
                mysqli_stmt_execute($uStmt);
                mysqli_stmt_close($uStmt);

                if ($newPassword !== '') {
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pStmt = mysqli_prepare($conn, 'UPDATE users SET Password=? WHERE UserID=?');
                    mysqli_stmt_bind_param($pStmt, 'si', $hashed, $userID);
                    mysqli_stmt_execute($pStmt);
                    mysqli_stmt_close($pStmt);
                }

                header('Location: doctor_profile.php?updated=1');
                exit();
            }
        }
    }
}

if (isset($_GET['updated'])) {
    $updateMessage = 'Profile updated successfully.';
    $updateSuccess = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — MediCare Doctor Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/doctor/doctor_dashboard.css">
<style>
/* ===== Profile Page Overrides ===== */
/* ===== Profile Banner (matches patient portal) ===== */
.profile-banner {
  background: var(--color-primary-tint);
  border: 1px solid var(--color-primary-tint-border);
  border-radius: 14px;
  padding: 20px 24px;
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 24px;
}
.profile-banner-info {
  display: flex;
  align-items: center;
  gap: 14px;
  flex: 1;
}
.btn-edit-profile {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: var(--color-primary);
  color: #fff;
  border: none;
  padding: 10px 18px;
  border-radius: 9px;
  font-family: var(--font-family);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
  white-space: nowrap;
}
.btn-edit-profile:hover { background: var(--color-primary-hover); }
.btn-edit-profile svg { width: 15px; height: 15px; }
.profile-avatar {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: var(--color-primary);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.95rem;
  flex-shrink: 0;
}
.profile-avatar-photo { object-fit: cover; }
.profile-banner-name { font-weight: 700; font-size: 1rem; color: var(--color-ink); }
.profile-banner-meta {
  font-size: 0.85rem;
  color: #475569;
  margin-top: 2px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.profile-banner-meta .sep { color: #94a3b8; }
.id-pill {
  background: var(--color-primary);
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
}

/* Avatar upload */
.avatar-upload-wrapper {
  position: relative;
  width: 46px;
  height: 46px;
  flex-shrink: 0;
  cursor: pointer;
}
.avatar-upload-wrapper .profile-avatar,
.avatar-upload-wrapper .profile-avatar-photo {
  width: 46px;
  height: 46px;
}
.avatar-hover-overlay {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: rgba(0,0,0,0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity 0.2s;
}
.avatar-hover-overlay svg { width: 18px; height: 18px; color: #fff; }
.avatar-upload-wrapper:hover .avatar-hover-overlay { opacity: 1; }

/* Profile columns */
.profile-columns {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-top: 20px;
  margin-bottom: 24px;
  align-items: start;
}
.profile-card {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  height: 100%;
  display: flex;
  flex-direction: column;
  padding: 22px 24px;
}
/* NOTE: previously there was a ".profile-card + .profile-card { margin-top: 20px; }"
   rule here. It matched the two sibling cards inside .profile-columns (a grid)
   and pushed the second card down, breaking top alignment. Removed — the grid's
   own `gap` already provides spacing. Vertical stacks (e.g. the account column)
   add their own margin-top explicitly where needed instead. */
.pcard-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 1rem;
  font-weight: 700;
  color: var(--color-ink);
  margin-bottom: 18px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--color-border);
}
.pcard-title svg { width: 18px; height: 18px; color: var(--color-primary); }

/* Info grid */
.pinfo-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 24px;
}
.pinfo-item { display: flex; flex-direction: column; gap: 3px; }
.pinfo-item.full-width { grid-column: 1 / -1; }
.pinfo-label {
  font-size: 0.78rem;
  font-weight: 600;
  color: var(--color-ink-soft);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.pinfo-value {
  font-size: 0.92rem;
  color: var(--color-ink);
  font-weight: 500;
}
.pinfo-empty {
  color: var(--color-ink-soft);
  font-style: italic;
  font-weight: 400;
}
.pinfo-divider {
  border: none;
  border-top: 1px solid var(--color-border);
  margin: 4px 0;
}
.pinfo-hint {
  font-size: 0.8rem;
  color: var(--color-ink-soft);
  margin-top: 4px;
}

/* Chips */
.chip {
  display: inline-block;
  padding: 3px 12px;
  border-radius: 20px;
  font-size: 0.8rem;
  font-weight: 600;
  background: var(--color-primary-tint);
  color: var(--color-primary-hover);
}
.chip.green { background: #ecfdf5; color: #059669; }
.chip.red { background: #fef2f2; color: #dc2626; }
.chip.yellow { background: #fffbeb; color: #d97706; }

/* Action bar */
.action-bar {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid var(--color-border);
}
.btn-cancel {
  padding: 10px 20px;
  border-radius: 9px;
  border: 1px solid var(--color-border);
  background: #fff;
  color: var(--color-ink);
  font-family: var(--font-family);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.btn-cancel:hover { background: var(--color-bg); }
.btn-save {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 20px;
  border-radius: 9px;
  border: none;
  background: var(--color-primary);
  color: #fff;
  font-family: var(--font-family);
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.15s;
}
.btn-save:hover { background: var(--color-primary-hover); }
.btn-save svg { width: 15px; height: 15px; }

/* Form grid */
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px 24px;
}
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-group.full-width { grid-column: 1 / -1; }
.form-group label {
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--color-ink);
}
.form-group label small { font-weight: 400; color: var(--color-ink-soft); }
.form-group input,
.form-group select,
.form-group textarea {
  padding: 10px 14px;
  border: 1px solid var(--color-border);
  border-radius: 9px;
  font-family: var(--font-family);
  font-size: 0.88rem;
  color: var(--color-ink);
  background: #fff;
  transition: border-color 0.15s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
  outline: none;
  border-color: var(--color-primary);
  box-shadow: 0 0 0 3px rgba(20,147,133,0.1);
}

/* Alert message */
.alert-msg {
  padding: 12px 18px;
  border-radius: 10px;
  font-size: 0.88rem;
  font-weight: 500;
  margin-bottom: 18px;
}
.alert-msg.success { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
.alert-msg.error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

/* Schedule table */
.schedule-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  margin-top: 12px;
}
.schedule-table th,
.schedule-table td {
  padding: 10px 14px;
  text-align: left;
  font-size: 0.85rem;
  border-bottom: 1px solid var(--color-border);
}
.schedule-table th {
  font-weight: 600;
  color: var(--color-ink-soft);
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  background: var(--color-bg);
}
.schedule-table tr:last-child td { border-bottom: none; }
.schedule-table td { color: var(--color-ink); font-weight: 500; }
.schedule-table .session-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 20px;
  font-size: 0.78rem;
  font-weight: 600;
}
.session-badge.morning { background: #fef3c7; color: #92400e; }
.session-badge.afternoon { background: #dbeafe; color: #1e40af; }

/* Full width card */
.profile-card.full-width { grid-column: 1 / -1; }

@media (max-width: 900px) {
  .profile-columns { grid-template-columns: 1fr; }
  .profile-banner { flex-direction: column; gap: 16px; align-items: flex-start; }
  .form-grid { grid-template-columns: 1fr; }
  .pinfo-grid { grid-template-columns: 1fr; }
}
</style>
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
        <div class="brand-sub">Doctor Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item">
        <a href="doctor_dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
          Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a href="doctor_queue.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
          Queue
        </a>
      </li>
      <li class="nav-item">
        <a href="records.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Records
        </a>
      </li>
      <li class="nav-item">
        <a href="search_patient.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Search Patient
        </a>
      </li>
      <li class="nav-item active">
        <a href="doctor_profile.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <?php if (!empty($doctor['ProfilePhoto'])): ?>
        <div class="user-avatar"><img src="../<?php echo htmlspecialchars($doctor['ProfilePhoto']); ?>" alt="Photo" style="width:36px;height:36px;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($displayName); ?></div>
          <div class="user-role"><?php echo htmlspecialchars($doctor['DepartmentName']); ?></div>
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
      <h1>My Profile</h1>
      <p>Manage your personal and professional information</p>
    </div>

    <?php if ($updateMessage): ?>
    <div class="alert-msg <?php echo $updateSuccess ? 'success' : 'error'; ?>" style="margin-top:18px;">
      <?php echo htmlspecialchars($updateMessage); ?>
    </div>
    <?php endif; ?>

    <!-- Profile banner -->
    <div class="profile-banner">
      <div class="profile-banner-info">
        <div class="avatar-upload-wrapper" id="avatarArea">
          <?php if (!empty($doctor['ProfilePhoto'])): ?>
          <img class="profile-avatar profile-avatar-photo" id="avatarImg" src="../<?php echo htmlspecialchars($doctor['ProfilePhoto']); ?>" alt="Photo">
          <?php else: ?>
          <div class="profile-avatar" id="avatarImg"><?php echo htmlspecialchars($initials); ?></div>
          <?php endif; ?>
          <div class="avatar-hover-overlay">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          </div>
          <input type="file" id="avatarInput" accept="image/*" style="display:none;">
        </div>
        <div>
          <div class="profile-banner-name"><?php echo htmlspecialchars($displayName); ?></div>
          <div class="profile-banner-meta">
            <?php echo htmlspecialchars($doctor['Email']); ?>
            <span class="sep">|</span>
            <span class="id-pill"><?php echo htmlspecialchars($staffIdFormatted); ?></span>
          </div>
        </div>
      </div>
      <button class="btn-edit-profile" type="button" onclick="togglePersonalEdit(true)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
        Edit Profile
      </button>
    </div>

    <!-- ROW 1: Basic Information / Professional Information -->
    <div class="profile-columns" id="profile-readonly" style="display:<?php echo $personalEditOpen ? 'none' : 'grid'; ?>;">

      <!-- Section 1: Basic Information -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Basic Information
        </div>
        <div class="pinfo-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Full Name</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($displayName); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Professional Title</div>
            <div class="pinfo-value">Doctor of Medicine</div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Date of Birth</div>
            <div class="pinfo-value"><?php echo field($dobDisplay); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Sex</div>
            <div class="pinfo-value"><?php echo field($doctor['Sex']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Contact Number</div>
            <div class="pinfo-value"><?php echo field($doctor['ContactNumber']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Email Address</div>
            <div class="pinfo-value"><?php echo field($doctor['Email']); ?></div>
          </div>
          <div class="pinfo-item full-width">
            <div class="pinfo-label">Address</div>
            <div class="pinfo-value"><?php echo field($doctor['Address']); ?></div>
          </div>
        </div>
      </div>

      <!-- Section 2: Professional Information -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
          Professional Information
        </div>
        <div class="pinfo-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Doctor/Staff ID</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($staffIdFormatted); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Specialization</div>
            <div class="pinfo-value"><?php echo field($doctor['Specialization']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Department</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($doctor['DepartmentName']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">License Number</div>
            <div class="pinfo-value"><?php echo field($doctor['LicenseNumber']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Years of Experience</div>
            <div class="pinfo-value"><?php echo field($yearsExpDisplay); ?></div>
          </div>
        </div>
        <p style="margin-top:auto;padding-top:16px;font-size:0.78rem;color:var(--color-ink-soft);">
          Professional details are managed by administration. Contact your administrator if any of this information needs to be corrected.
        </p>
      </div>
    </div>

    <!-- Personal Information (edit form, hidden unless open) -->
    <div id="personal-edit" style="display:<?php echo $personalEditOpen ? 'block' : 'none'; ?>;margin-top:20px;margin-bottom:24px;">
      <form method="POST" id="personalForm">
        <input type="hidden" name="action" value="update_personal">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="profile-card">
          <div class="pcard-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
            Edit Personal Information
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label for="FirstName">First Name *</label>
              <input type="text" id="FirstName" name="FirstName" value="<?php echo htmlspecialchars($personalFormData['FirstName']); ?>" required>
            </div>
            <div class="form-group">
              <label for="MiddleName">Middle Name</label>
              <input type="text" id="MiddleName" name="MiddleName" value="<?php echo htmlspecialchars($personalFormData['MiddleName']); ?>">
            </div>
            <div class="form-group">
              <label for="LastName">Last Name *</label>
              <input type="text" id="LastName" name="LastName" value="<?php echo htmlspecialchars($personalFormData['LastName']); ?>" required>
            </div>
            <div class="form-group">
              <label for="Sex">Sex</label>
              <select id="Sex" name="Sex">
                <option value="">Select</option>
                <option value="Male" <?php echo ($personalFormData['Sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo ($personalFormData['Sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
              </select>
            </div>
            <div class="form-group">
              <label for="DateOfBirth">Date of Birth</label>
              <input type="date" id="DateOfBirth" name="DateOfBirth" value="<?php echo htmlspecialchars($personalFormData['DateOfBirth']); ?>">
            </div>
            <div class="form-group">
              <label for="ContactNumber">Contact Number</label>
              <input type="tel" id="ContactNumber" name="ContactNumber" value="<?php echo htmlspecialchars($personalFormData['ContactNumber']); ?>" placeholder="09XXXXXXXXX">
            </div>
            <div class="form-group full-width">
              <label for="Address">Address</label>
              <textarea id="Address" name="Address" rows="2"><?php echo htmlspecialchars($personalFormData['Address']); ?></textarea>
            </div>
          </div>
          <div class="action-bar">
            <button class="btn-cancel" type="button" onclick="togglePersonalEdit(false)">Cancel</button>
            <button class="btn-save" type="submit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Save Changes
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- ROW 2: Schedule / Account Information -->
    <div class="profile-columns" id="profile-readonly-2">

      <!-- Section 3: Schedule -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
          Schedule
        </div>
        <div class="pinfo-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Assigned Department</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($doctor['DepartmentName']); ?></div>
          </div>
        </div>

        <?php if (!empty($schedules)): ?>
        <table class="schedule-table" style="margin-top:16px;">
          <thead>
            <tr>
              <th>Day</th>
              <th>Session</th>
              <th>Hours</th>
              <th>Slots</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($schedules as $sch): ?>
            <tr>
              <td><?php echo htmlspecialchars($dayNames[$sch['DayOfWeek']] ?? 'Unknown'); ?></td>
              <td>
                <span class="session-badge <?php echo strtolower($sch['SessionName']); ?>">
                  <?php echo htmlspecialchars($sch['SessionName']); ?>
                </span>
              </td>
              <td><?php echo date('g:i A', strtotime($sch['StartTime'])) . ' – ' . date('g:i A', strtotime($sch['EndTime'])); ?></td>
              <td><?php echo htmlspecialchars($sch['PatientSlots']); ?> patients/session</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <p style="margin-top:12px;font-size:0.88rem;color:var(--color-ink-soft);">No schedule assigned yet.</p>
        <?php endif; ?>
      </div>

      <!-- Section 4: Account Information -->
      <div>
        <div class="profile-card" id="account-readonly" style="display:<?php echo $accountEditOpen ? 'none' : 'block'; ?>;">
          <div class="pcard-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Account Information
          </div>
          <div class="pinfo-grid">
            <div class="pinfo-item">
              <div class="pinfo-label">Username / Email</div>
              <div class="pinfo-value"><?php echo htmlspecialchars($doctor['Email']); ?></div>
            </div>
            <div class="pinfo-item">
              <div class="pinfo-label">Account Role</div>
              <div class="pinfo-value"><span class="chip">Doctor</span></div>
            </div>
            <div class="pinfo-item">
              <div class="pinfo-label">Account Status</div>
              <div class="pinfo-value">
                <?php if ($accountStatus === 'Active'): ?>
                <span class="chip green">Active</span>
                <?php else: ?>
                <span class="chip red"><?php echo htmlspecialchars($accountStatus); ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="pinfo-item">
              <div class="pinfo-label">Last Login</div>
              <div class="pinfo-value"><?php echo htmlspecialchars($lastLoginDisplay); ?></div>
            </div>
            <div class="pinfo-item full-width">
              <div class="pinfo-label">Date Registered</div>
              <div class="pinfo-value"><?php echo field($dateRegisteredDisplay); ?></div>
            </div>
          </div>
          <p class="pinfo-hint">For security, your password isn't displayed here. Use the button below to change it.</p>
          <div class="action-bar">
            <button class="btn-edit" type="button" onclick="toggleAccountEdit(true)" style="background:var(--color-primary);color:#fff;border:none;padding:10px 20px;border-radius:9px;font-family:var(--font-family);font-size:0.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
              Change Password
            </button>
          </div>
        </div>

        <div id="account-edit" style="display:<?php echo $accountEditOpen ? 'block' : 'none'; ?>;margin-top:20px;">
          <form method="POST" id="accountForm">
            <input type="hidden" name="action" value="update_account">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div class="profile-card">
              <div class="pcard-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
                Edit Account Settings
              </div>
              <div class="form-grid">
                <div class="form-group full-width">
                  <label for="Email">Email Address *</label>
                  <input type="email" id="Email" name="Email" value="<?php echo htmlspecialchars($accountFormData['Email']); ?>" required>
                </div>
                <hr class="pinfo-divider" style="grid-column:1/-1;">
                <div class="form-group full-width">
                  <label for="NewPassword">New Password <small>(leave blank to keep current)</small></label>
                  <input type="password" id="NewPassword" name="NewPassword" placeholder="Min. 8 characters" minlength="8">
                </div>
                <div class="form-group full-width">
                  <label for="ConfirmPassword">Confirm New Password</label>
                  <input type="password" id="ConfirmPassword" name="ConfirmPassword" placeholder="Re-enter new password">
                </div>
              </div>
              <div class="action-bar">
                <button class="btn-cancel" type="button" onclick="toggleAccountEdit(false)">Cancel</button>
                <button class="btn-save" type="submit">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  Save Changes
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

  </main>
</div>

<script>
function togglePersonalEdit(editing) {
  document.getElementById('profile-readonly').style.display = editing ? 'none' : 'grid';
  document.getElementById('personal-edit').style.display = editing ? 'block' : 'none';
  if (editing) {
    document.getElementById('personal-edit').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

function toggleAccountEdit(editing) {
  document.getElementById('account-readonly').style.display = editing ? 'none' : 'block';
  document.getElementById('account-edit').style.display = editing ? 'block' : 'none';
  if (editing) {
    document.getElementById('account-edit').scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

<?php if ($personalEditOpen): ?>
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('personal-edit').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>
<?php if ($accountEditOpen): ?>
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('account-edit').scrollIntoView({ behavior: 'smooth', block: 'center' });
});
<?php endif; ?>

// Avatar upload
document.getElementById('avatarArea').addEventListener('click', function() {
  document.getElementById('avatarInput').click();
});

document.getElementById('avatarInput').addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;

  if (file.size > 2 * 1024 * 1024) {
    alert('Image must be 2MB or smaller.');
    return;
  }

  const reader = new FileReader();
  reader.onload = function(e) {
    const avatarEl = document.getElementById('avatarImg');
    if (avatarEl.tagName === 'IMG') {
      avatarEl.src = e.target.result;
    } else {
      const img = document.createElement('img');
      img.id = 'avatarImg';
      img.className = 'profile-avatar profile-avatar-photo';
      img.src = e.target.result;
      img.alt = 'Photo';
      avatarEl.replaceWith(img);
    }
  };
  reader.readAsDataURL(file);

  const formData = new FormData();
  formData.append('avatar', file);

  fetch('../upload_avatar.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success && data.url) {
      const img = document.getElementById('avatarImg');
      if (img.tagName === 'IMG') {
        img.src = '../' + data.url + '?t=' + Date.now();
      }
    } else {
      alert(data.message || 'Failed to upload photo.');
    }
  })
  .catch(() => {
    alert('An error occurred while uploading.');
  });
});

// Password confirm validation
document.getElementById('accountForm').addEventListener('submit', function(e) {
  const pw = document.getElementById('NewPassword').value;
  const confirm = document.getElementById('ConfirmPassword').value;

  if (pw !== '' && pw !== confirm) {
    e.preventDefault();
    alert('Passwords do not match.');
  }
});
</script>
</body>
</html>