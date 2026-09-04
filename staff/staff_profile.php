<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!isset($conn) || !$conn) {
    die('Database connection is not available.');
}

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

// CSRF token (generate once per session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// Fetch full staff + user info
// TEMPORARY: EmergencyContactName / EmergencyContactNo / EmergencyRelation do NOT
// exist yet on `users` (confirmed by a fatal error on first deploy) — removed from
// this query so the page loads. The form below still shows those three inputs,
// but they will NOT be saved until the underlying columns exist. See the note
// further down near the UPDATE query for the SQL to add them once you confirm
// where you'd like this data stored.
$staffStmt = mysqli_prepare(
    $conn,
    "SELECT s.StaffID, s.EmployeeID, s.DepartmentID, s.StaffRole, s.Specialization,
            s.ScheduleStart, s.ScheduleEnd, s.AvailabilityStatus,
            s.DateHired, s.AssignedDays, s.AssignedResponsibilities,
            d.DepartmentName,
            u.UserID, u.FirstName, u.MiddleName, u.LastName, u.Email,
            u.Sex, u.DateOfBirth, u.ContactNumber, u.Address,
            u.ProfilePhoto, u.LastLogin, u.Status AS AccountStatus
     FROM staff s
     INNER JOIN users u ON s.UserID = u.UserID
     INNER JOIN departments d ON s.DepartmentID = d.DepartmentID
     WHERE s.UserID = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($staffStmt, 'i', $userId);
mysqli_stmt_execute($staffStmt);
$staffResult = mysqli_stmt_get_result($staffStmt);
$staff = mysqli_fetch_assoc($staffResult);
mysqli_stmt_close($staffStmt);

if (!$staff) {
    session_destroy();
    header('Location: ../auth/login.php?portal=staff');
    exit();
}

$initials = strtoupper(substr($staff['FirstName'], 0, 1) . substr($staff['LastName'], 0, 1));
$displayName = trim($staff['FirstName'] . ' ' . $staff['LastName']);
$staffIdFormatted = 'STF-' . str_pad($staff['StaffID'], 3, '0', STR_PAD_LEFT);
$employeeIdFormatted = !empty($staff['EmployeeID'])
    ? $staff['EmployeeID']
    : 'EMP-' . date('Y') . '-' . str_pad($staff['StaffID'], 3, '0', STR_PAD_LEFT);

$dobFormatted = !empty($staff['DateOfBirth'])
    ? (new DateTime($staff['DateOfBirth']))->format('Y-m-d')
    : '';
$dobDisplay = !empty($staff['DateOfBirth'])
    ? (new DateTime($staff['DateOfBirth']))->format('F j, Y')
    : '';

$dateHiredDisplay = !empty($staff['DateHired'])
    ? (new DateTime($staff['DateHired']))->format('F j, Y')
    : '';

$lastLoginDisplay = !empty($staff['LastLogin'])
    ? (new DateTime($staff['LastLogin']))->format('F j, Y, g:i A')
    : 'Not available';

$employmentStatus = !empty($staff['AvailabilityStatus']) ? $staff['AvailabilityStatus'] : 'Active';
$accountStatus = !empty($staff['AccountStatus']) ? $staff['AccountStatus'] : 'Active';

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
    'FirstName'             => $staff['FirstName'],
    'MiddleName'            => $staff['MiddleName'] ?? '',
    'LastName'              => $staff['LastName'],
    'Sex'                   => $staff['Sex'] ?? '',
    'DateOfBirth'           => $dobFormatted,
    'ContactNumber'         => $staff['ContactNumber'] ?? '',
    'Address'               => $staff['Address'] ?? '',
    // These three aren't backed by real columns yet — see note near the
    // UPDATE query below. Defaulting to blank until that's resolved.
    'EmergencyContactName'  => '',
    'EmergencyContactNo'    => '',
    'EmergencyRelation'     => '',
];

$accountFormData = [
    'Email' => $staff['Email'],
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
        $emergName = trim($_POST['EmergencyContactName'] ?? '');
        $emergNo = trim($_POST['EmergencyContactNo'] ?? '');
        $emergRel = trim($_POST['EmergencyRelation'] ?? '');

        // Keep whatever the user typed in case we need to redisplay the form
        $personalFormData = [
            'FirstName'             => $firstName,
            'MiddleName'            => $middleName,
            'LastName'              => $lastName,
            'Sex'                   => $sex,
            'DateOfBirth'           => $dob,
            'ContactNumber'         => $contact,
            'Address'               => $address,
            'EmergencyContactName'  => $emergName,
            'EmergencyContactNo'    => $emergNo,
            'EmergencyRelation'     => $emergRel,
        ];

        // Empty Date of Birth should be stored as NULL
        $dobForDb = ($dob === '') ? null : $dob;

        if (empty($firstName) || empty($lastName)) {
            $updateMessage = 'First name and last name are required.';
            $personalEditOpen = true;
        } elseif ($contact !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contact)) {
            $updateMessage = 'Please enter a valid contact number.';
            $personalEditOpen = true;
        } elseif ($emergNo !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $emergNo)) {
            $updateMessage = 'Please enter a valid emergency contact number.';
            $personalEditOpen = true;
        } else {
            // TEMPORARY: $emergName / $emergNo / $emergRel are collected from the
            // form above but NOT saved yet — there's no EmergencyContactName /
            // EmergencyContactNo / EmergencyRelation column on `users` (or wherever
            // you'd like this stored). Once you confirm the real table/columns,
            // e.g.:
            //   ALTER TABLE users
            //     ADD COLUMN EmergencyContactName VARCHAR(150) NULL,
            //     ADD COLUMN EmergencyContactNo VARCHAR(20) NULL,
            //     ADD COLUMN EmergencyRelation VARCHAR(50) NULL;
            // add them back into this UPDATE (and the SELECT above) and this will
            // start persisting correctly.
            $uStmt = mysqli_prepare($conn,
                'UPDATE users SET FirstName=?, MiddleName=?, LastName=?,
                 Sex=?, DateOfBirth=?, ContactNumber=?, Address=?
                 WHERE UserID=?');
            mysqli_stmt_bind_param($uStmt, 'sssssssi',
                $firstName, $middleName, $lastName, $sex, $dobForDb, $contact, $address, $userId);
            mysqli_stmt_execute($uStmt);
            mysqli_stmt_close($uStmt);

            header('Location: staff_profile.php?updated=1');
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
            mysqli_stmt_bind_param($dupStmt, 'si', $email, $userId);
            mysqli_stmt_execute($dupStmt);
            $dupResult = mysqli_stmt_get_result($dupStmt);
            $duplicate = mysqli_fetch_assoc($dupResult);
            mysqli_stmt_close($dupStmt);

            if ($duplicate) {
                $updateMessage = 'That email address is already in use by another account.';
                $accountEditOpen = true;
            } else {
                $uStmt = mysqli_prepare($conn, 'UPDATE users SET Email=? WHERE UserID=?');
                mysqli_stmt_bind_param($uStmt, 'si', $email, $userId);
                mysqli_stmt_execute($uStmt);
                mysqli_stmt_close($uStmt);

                if ($newPassword !== '') {
                    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                    $pStmt = mysqli_prepare($conn, 'UPDATE users SET Password=? WHERE UserID=?');
                    mysqli_stmt_bind_param($pStmt, 'si', $hashed, $userId);
                    mysqli_stmt_execute($pStmt);
                    mysqli_stmt_close($pStmt);
                }

                header('Location: staff_profile.php?updated=1');
                exit();
            }
        }
    }
}

if (isset($_GET['updated'])) {
    $updateMessage = 'Profile updated successfully.';
    $updateSuccess = true;
}

// Format schedule times
$scheduleStart = $staff['ScheduleStart'] ? date('h:i A', strtotime($staff['ScheduleStart'])) : '';
$scheduleEnd = $staff['ScheduleEnd'] ? date('h:i A', strtotime($staff['ScheduleEnd'])) : '';
$scheduleDisplay = ($staff['ScheduleStart'] && $staff['ScheduleEnd'])
    ? $scheduleStart . ' – ' . $scheduleEnd
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — MediCare Staff Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/staff/staff_dashboard.css">
<style>
/* ===== Profile Page Overrides =====
   Mirrors the banner/card/panel styling used on the patient portal so that
   the staff profile page renders with the same look. */
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
  padding: 22px 24px;
  height: 100%;
  display: flex;
  flex-direction: column;
}
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
        <div class="brand-sub">Staff Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item">
        <a href="staff_dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
          Dashboard
        </a>
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
      <li class="nav-item active">
        <a href="staff_profile.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <?php if (!empty($staff['ProfilePhoto'])): ?>
        <div class="user-avatar"><img src="../<?php echo htmlspecialchars($staff['ProfilePhoto']); ?>" alt="Photo" style="width:36px;height:36px;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($displayName); ?></div>
          <div class="user-role"><?php echo htmlspecialchars($staff['StaffRole']); ?></div>
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
          <?php if (!empty($staff['ProfilePhoto'])): ?>
          <img class="profile-avatar profile-avatar-photo" id="avatarImg" src="../<?php echo htmlspecialchars($staff['ProfilePhoto']); ?>" alt="Photo">
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
            <?php echo htmlspecialchars($staff['Email']); ?>
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

    <!-- ROW 1: Personal Information / Employment Information -->
    <div class="profile-columns" id="profile-readonly" style="display:<?php echo $personalEditOpen ? 'none' : 'grid'; ?>;">

      <!-- Personal Information (readonly) -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Basic Information
        </div>
        <div class="pinfo-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Full Name</div>
            <div class="pinfo-value"><?php echo field($displayName); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Staff ID</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($staffIdFormatted); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Date of Birth</div>
            <div class="pinfo-value"><?php echo field($dobDisplay); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Sex</div>
            <div class="pinfo-value"><?php echo field($staff['Sex']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Contact Number</div>
            <div class="pinfo-value"><?php echo field($staff['ContactNumber']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Email Address</div>
            <div class="pinfo-value"><?php echo field($staff['Email']); ?></div>
          </div>
          <div class="pinfo-item full-width">
            <div class="pinfo-label">Address</div>
            <div class="pinfo-value"><?php echo field($staff['Address']); ?></div>
          </div>
        </div>
      </div>

      <!-- Employment Information (admin-managed, readonly) -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
          Employment Information
        </div>
        <div class="pinfo-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Employee ID</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($employeeIdFormatted); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Position</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($staff['StaffRole']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Department</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($staff['DepartmentName']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Specialization</div>
            <div class="pinfo-value"><?php echo field($staff['Specialization']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Employment Status</div>
            <div class="pinfo-value">
              <?php if (strcasecmp($employmentStatus, 'Active') === 0): ?>
              <span class="chip green"><?php echo htmlspecialchars($employmentStatus); ?></span>
              <?php else: ?>
              <span class="chip"><?php echo htmlspecialchars($employmentStatus); ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Date Hired</div>
            <div class="pinfo-value"><?php echo field($dateHiredDisplay); ?></div>
          </div>
        </div>
        <p style="margin-top:auto;padding-top:16px;font-size:0.78rem;color:var(--color-ink-soft);">
          Employment details are managed by HR/Admin. Contact your administrator if any of this information needs to be corrected.
        </p>
      </div>
    </div>

    <!-- Personal Information (edit form, hidden unless open) -->
    <div id="personal-edit" style="display:<?php echo $personalEditOpen ? 'block' : 'none'; ?>;margin-top:20px;margin-bottom:20px;">
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
            <div class="form-group">
              <label for="EmergencyContactName">Emergency Contact Name</label>
              <input type="text" id="EmergencyContactName" name="EmergencyContactName" value="<?php echo htmlspecialchars($personalFormData['EmergencyContactName']); ?>">
            </div>
            <div class="form-group">
              <label for="EmergencyContactNo">Emergency Contact Phone</label>
              <input type="tel" id="EmergencyContactNo" name="EmergencyContactNo" value="<?php echo htmlspecialchars($personalFormData['EmergencyContactNo']); ?>" placeholder="09XXXXXXXXX">
            </div>
            <div class="form-group">
              <label for="EmergencyRelation">Relationship</label>
              <input type="text" id="EmergencyRelation" name="EmergencyRelation" value="<?php echo htmlspecialchars($personalFormData['EmergencyRelation']); ?>" placeholder="e.g. Spouse, Parent">
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

    <!-- ROW 2: Account Information / Work Information -->
    <div class="profile-columns">

      <!-- Account Information -->
      <div>
        <div class="profile-card" id="account-readonly" style="display:<?php echo $accountEditOpen ? 'none' : 'block'; ?>;">
          <div class="pcard-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Account Information
          </div>
          <div class="pinfo-grid">
            <div class="pinfo-item">
              <div class="pinfo-label">Username / Email</div>
              <div class="pinfo-value"><?php echo htmlspecialchars($staff['Email']); ?></div>
            </div>
            <div class="pinfo-item">
              <div class="pinfo-label">Role</div>
              <div class="pinfo-value"><?php echo htmlspecialchars($staff['StaffRole']); ?></div>
            </div>
            <div class="pinfo-item full-width">
              <div class="pinfo-label">Account Status</div>
              <div class="pinfo-value">
                <?php if (strcasecmp($accountStatus, 'Active') === 0): ?>
                <span class="chip green"><?php echo htmlspecialchars($accountStatus); ?></span>
                <?php else: ?>
                <span class="chip red"><?php echo htmlspecialchars($accountStatus); ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="pinfo-item full-width">
              <div class="pinfo-label">Last Login</div>
              <div class="pinfo-value"><?php echo htmlspecialchars($lastLoginDisplay); ?></div>
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

      <!-- Work Information -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
          Work Information
        </div>
        <div class="pinfo-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Assigned Department</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($staff['DepartmentName']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Assigned Schedule</div>
            <div class="pinfo-value">Monday–Friday</div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Shift</div>
            <div class="pinfo-value"><?php echo field($scheduleDisplay); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Availability Status</div>
            <div class="pinfo-value"><?php echo field($staff['AvailabilityStatus']); ?></div>
          </div>
        </div>
      </div>
      <!-- ^ this </div> above closes .profile-card (Work Information).
           The two below close the row-2 .profile-columns and were
           missing entirely in the original file. -->
    </div>

  </main>
</div>

<script>
// Toggle personal edit
function togglePersonalEdit(editing) {
  document.getElementById('profile-readonly').style.display = editing ? 'none' : 'grid';
  document.getElementById('personal-edit').style.display = editing ? 'block' : 'none';
  if (editing) {
    document.getElementById('personal-edit').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

// Toggle account edit
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