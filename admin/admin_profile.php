<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
requireRole('Admin');

$userId = (int) $_SESSION['UserID'];

// CSRF token (generate once per session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function verifyCsrf(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// Fetch the admin account from `users` (admins have no row in `staff`)
$adminStmt = mysqli_prepare(
    $conn,
    'SELECT UserID, FirstName, MiddleName, LastName, Email, Sex, DateOfBirth,
            ContactNumber, Address, ProfilePhoto, LastLogin, Status AS AccountStatus, CreatedAt
     FROM users
     WHERE UserID = ?
     LIMIT 1'
);
mysqli_stmt_bind_param($adminStmt, 'i', $userId);
mysqli_stmt_execute($adminStmt);
$adminResult = mysqli_stmt_get_result($adminStmt);
$admin = mysqli_fetch_assoc($adminResult);
mysqli_stmt_close($adminStmt);

if (!$admin) {
    session_destroy();
    header('Location: ../auth/login.php?portal=admin');
    exit();
}

$initials = strtoupper(substr($admin['FirstName'], 0, 1) . substr($admin['LastName'], 0, 1));
$displayName = trim($admin['FirstName'] . ' ' . $admin['LastName']);
$adminIdFormatted = 'ADM-' . str_pad($admin['UserID'], 3, '0', STR_PAD_LEFT);

$dobFormatted = !empty($admin['DateOfBirth'])
    ? (new DateTime($admin['DateOfBirth']))->format('Y-m-d')
    : '';
$dobDisplay = !empty($admin['DateOfBirth'])
    ? (new DateTime($admin['DateOfBirth']))->format('F j, Y')
    : '';

$lastLoginDisplay = !empty($admin['LastLogin'])
    ? (new DateTime($admin['LastLogin']))->format('F j, Y, g:i A')
    : 'Not available';

$createdAtDisplay = !empty($admin['CreatedAt'])
    ? (new DateTime($admin['CreatedAt']))->format('F j, Y')
    : 'Not available';

$accountStatus = !empty($admin['AccountStatus']) ? $admin['AccountStatus'] : 'Active';

/**
 * Renders a value, or a visually distinct "Not provided" placeholder if empty.
 * Returns raw HTML - do not wrap in htmlspecialchars() again at the call site.
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

$personalEditOpen = false;
$accountEditOpen = false;

$personalFormData = [
    'FirstName'     => $admin['FirstName'],
    'MiddleName'    => $admin['MiddleName'] ?? '',
    'LastName'      => $admin['LastName'],
    'Sex'           => $admin['Sex'] ?? '',
    'DateOfBirth'   => $dobFormatted,
    'ContactNumber' => $admin['ContactNumber'] ?? '',
    'Address'       => $admin['Address'] ?? '',
];

$accountFormData = [
    'Email' => $admin['Email'],
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

        $personalFormData = [
            'FirstName'     => $firstName,
            'MiddleName'    => $middleName,
            'LastName'      => $lastName,
            'Sex'           => $sex,
            'DateOfBirth'   => $dob,
            'ContactNumber' => $contact,
            'Address'       => $address,
        ];

        $dobForDb = ($dob === '') ? null : $dob;

        if (empty($firstName) || empty($lastName)) {
            $updateMessage = 'First name and last name are required.';
            $personalEditOpen = true;
        } elseif ($contact !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contact)) {
            $updateMessage = 'Please enter a valid contact number.';
            $personalEditOpen = true;
        } else {
            $uStmt = mysqli_prepare($conn,
                'UPDATE users SET FirstName=?, MiddleName=?, LastName=?,
                 Sex=?, DateOfBirth=?, ContactNumber=?, Address=?
                 WHERE UserID=?');
            mysqli_stmt_bind_param($uStmt, 'sssssssi',
                $firstName, $middleName, $lastName, $sex, $dobForDb, $contact, $address, $userId);
            mysqli_stmt_execute($uStmt);
            mysqli_stmt_close($uStmt);

            // Keep the sidebar footer in sync with the new name
            $_SESSION['FirstName'] = $firstName;
            $_SESSION['MiddleName'] = $middleName;
            $_SESSION['LastName'] = $lastName;

            header('Location: admin_profile.php?updated=1');
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

                header('Location: admin_profile.php?updated=1');
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
<title>My Profile — Curora Admin Portal</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_profile.css">
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
      <li class="nav-item active">
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
    <div class="page-header">
      <h1>My Profile</h1>
      <p>Manage your personal information and account settings</p>
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
          <?php if (!empty($admin['ProfilePhoto'])): ?>
          <img class="profile-avatar profile-avatar-photo" id="avatarImg" src="../<?php echo htmlspecialchars($admin['ProfilePhoto']); ?>" alt="Photo">
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
            <span class="id-pill"><?php echo htmlspecialchars($adminIdFormatted); ?></span>
            <span class="sep">|</span>
            <?php echo htmlspecialchars($admin['Email']); ?>
            <span class="sep">|</span>
            Admin
          </div>
        </div>
      </div>
      <button class="btn-edit-profile" type="button" onclick="togglePersonalEdit(true)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
        Edit Profile
      </button>
    </div>

    <!-- ROW 1: Basic Information / Admin Information -->
    <div class="profile-columns" id="profile-readonly" style="display:<?php echo $personalEditOpen ? 'none' : 'grid'; ?>;">

      <!-- Basic Information (readonly) -->
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
            <div class="pinfo-label">Admin ID</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($adminIdFormatted); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Date of Birth</div>
            <div class="pinfo-value"><?php echo field($dobDisplay); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Sex</div>
            <div class="pinfo-value"><?php echo field($admin['Sex']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Contact Number</div>
            <div class="pinfo-value"><?php echo field($admin['ContactNumber']); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Email Address</div>
            <div class="pinfo-value"><?php echo field($admin['Email']); ?></div>
          </div>
          <div class="pinfo-item full-width">
            <div class="pinfo-label">Address</div>
            <div class="pinfo-value"><?php echo field($admin['Address']); ?></div>
          </div>
        </div>
      </div>

      <!-- Admin Information (account-level, readonly) -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Account Information
        </div>
        <div class="pinfo-grid">
          <div class="pinfo-item">
            <div class="pinfo-label">Role</div>
            <div class="pinfo-value">Administrator</div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Account Status</div>
            <div class="pinfo-value">
              <?php if (strcasecmp($accountStatus, 'Active') === 0): ?>
              <span class="chip green"><?php echo htmlspecialchars($accountStatus); ?></span>
              <?php else: ?>
              <span class="chip red"><?php echo htmlspecialchars($accountStatus); ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Member Since</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($createdAtDisplay); ?></div>
          </div>
          <div class="pinfo-item">
            <div class="pinfo-label">Last Login</div>
            <div class="pinfo-value"><?php echo htmlspecialchars($lastLoginDisplay); ?></div>
          </div>
        </div>
        <p class="pinfo-hint" style="margin-top:auto;padding-top:16px;">
          For security, your password isn't displayed here. Use the "Edit account" card below to change it.
        </p>
      </div>
    </div>

    <!-- Basic Information (edit form, hidden unless open) -->
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

    <!-- ROW 2: Account Settings / Security -->
    <div class="profile-columns">

      <!-- Account Settings -->
      <div>
        <div class="profile-card" id="account-readonly" style="display:<?php echo $accountEditOpen ? 'none' : 'block'; ?>;">
          <div class="pcard-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Account Settings
          </div>
          <div class="pinfo-grid">
            <div class="pinfo-item full-width">
              <div class="pinfo-label">Login Email</div>
              <div class="pinfo-value"><?php echo htmlspecialchars($admin['Email']); ?></div>
            </div>
          </div>
          <div class="action-bar">
            <button class="btn-save" type="button" onclick="toggleAccountEdit(true)">
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
              Change Email / Password
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
                  <label for="Email">Login Email *</label>
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

      <!-- Security Tips -->
      <div class="profile-card">
        <div class="pcard-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
          Security Tips
        </div>
        <ul style="padding-left:18px;margin:0;display:flex;flex-direction:column;gap:10px;">
          <li style="font-size:0.88rem;color:var(--color-ink);line-height:1.5;">Use a password of at least 8 characters, mixing letters, numbers, and symbols.</li>
          <li style="font-size:0.88rem;color:var(--color-ink);line-height:1.5;">Avoid reusing the same password across multiple accounts.</li>
          <li style="font-size:0.88rem;color:var(--color-ink);line-height:1.5;">Never share your login credentials with anyone. Admin actions are tracked in system audit logs.</li>
          <li style="font-size:0.88rem;color:var(--color-ink);line-height:1.5;">Sign out when leaving your workstation, especially on shared computers.</li>
        </ul>
      </div>
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