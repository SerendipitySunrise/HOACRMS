<?php

/**
 * admin/admin_account_management.php
 *
 * Admin-only Account Management console. This is the single authorised place
 * to create Admin / Doctor / Staff accounts and to view, edit, deactivate,
 * reactivate, reset the password of, or delete an account.
 *
 * Creating an account e-mails a random temporary password; the administrator
 * never sees or chooses it and the account owner must replace it at first
 * sign-in. The actual mutations are performed by api/admin/accounts.php
 * (RBAC + CSRF + audit logging); this page renders the list and the forms.
 *
 * Styles live in assets/css/admin/admin_account_management.css.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/account_service.php';

requireRole('Admin');

// The console renders account details, so keep it out of caches.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$currentUserId  = (int) ($_SESSION['UserID'] ?? 0);
$activeAdminCount = accountCountActiveAdmins($conn);

$departments = [];
$deptResult = mysqli_query($conn, 'SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentName');
if ($deptResult) {
    while ($row = mysqli_fetch_assoc($deptResult)) {
        $departments[] = $row;
    }
}

// ---- Tabs / search / pagination -------------------------------------------
$tabs = [
    0 => 'All',
    1 => 'Admins',
    4 => 'Doctors',
    2 => 'Staff',
    3 => 'Patients',
];

$search     = trim((string) ($_GET['q'] ?? ''));
$roleFilter = (int) ($_GET['role'] ?? 0);
if (!array_key_exists($roleFilter, $tabs)) {
    $roleFilter = 0;
}
$perPage    = 25;
$page       = max(1, (int) ($_GET['page'] ?? 1));
$offset     = ($page - 1) * $perPage;

$filters = ['q' => $search, 'role' => $roleFilter];
$total   = accountListCount($conn, $filters);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}
$accounts = accountList($conn, $filters + ['limit' => $perPage, 'offset' => $offset]);

// Count shown on each role tab (independent of the search box).
$tabCounts = [];
foreach ($tabs as $rid => $rlabel) {
    $tabCounts[$rid] = accountListCount($conn, ['q' => '', 'role' => $rid]);
}

// ---- One-time flash (set by api/admin/accounts.php) -----------------------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashStatus  = is_array($flash) ? (string) ($flash['status'] ?? '') : '';
$flashMessage = is_array($flash) ? (string) ($flash['message'] ?? '') : '';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Status pill for an account. Accounts still holding an administrator-issued
 * temporary password are flagged, and an expired one is called out so the
 * administrator knows to issue a fresh password.
 */
function accountStatusBadge(string $status, bool $mustChange = false, ?string $tempExpiresAt = null): string
{
    if ($mustChange) {
        $expired = $tempExpiresAt !== null && $tempExpiresAt !== ''
            && strtotime($tempExpiresAt) < time();

        return $expired
            ? '<span class="badge badge-expired">Expired temp password</span>'
            : '<span class="badge badge-pending">Must change password</span>';
    }

    if (strcasecmp($status, 'Active') === 0) {
        return '<span class="badge badge-active">Active</span>';
    }
    if (strcasecmp($status, 'Pending') === 0) {
        return '<span class="badge badge-pending">Pending</span>';
    }

    return '<span class="badge badge-inactive">Inactive</span>';
}

/** "Temp password expires in 5h" helper text, or '' when not applicable. */
function accountTempExpiryNote(?string $tempExpiresAt): string
{
    if ($tempExpiresAt === null || $tempExpiresAt === '') {
        return '';
    }
    $seconds = strtotime($tempExpiresAt) - time();
    if ($seconds <= 0) {
        return '';
    }
    $hours = (int) ceil($seconds / 3600);

    return 'Temp password expires in ' . $hours . 'h';
}

function accountLastLogin($value): string
{
    if ($value === null || $value === '' || strtotime((string) $value) === false) {
        return 'Never';
    }

    return date('M j, g:i A', strtotime((string) $value));
}

/**
 * One editable row of a doctor's weekly schedule (day, session, hours, slots).
 * $index is the array index used in the field names (schedule[<index>][...]).
 */
function accountScheduleRow(string $index, array $row, array $weekDays): string
{
    $day     = (string) ($row['DayOfWeek'] ?? '');
    $session = (string) ($row['Session'] ?? 'Morning');
    $start   = substr((string) ($row['StartTime'] ?? ''), 0, 5);
    $end     = substr((string) ($row['EndTime'] ?? ''), 0, 5);
    $max     = (string) ($row['MaxPatients'] ?? '');
    $n       = 'schedule[' . $index . ']';

    $html  = '<tr>';
    $html .= '<td><select name="' . $n . '[day]" aria-label="Day">';
    foreach ($weekDays as $d) {
        $html .= '<option' . ($d === $day ? ' selected' : '') . '>' . h($d) . '</option>';
    }
    $html .= '</select></td>';
    $html .= '<td><select name="' . $n . '[session]" aria-label="Session">';
    foreach (['Morning', 'Afternoon'] as $s) {
        $html .= '<option' . ($s === $session ? ' selected' : '') . '>' . $s . '</option>';
    }
    $html .= '</select></td>';
    $html .= '<td><input type="time" name="' . $n . '[start]" value="' . h($start) . '" aria-label="Start time"></td>';
    $html .= '<td><input type="time" name="' . $n . '[end]" value="' . h($end) . '" aria-label="End time"></td>';
    $html .= '<td><input type="number" min="1" max="200" name="' . $n . '[max]" value="' . h($max) . '" aria-label="Max patients per session"></td>';
    $html .= '<td><button type="button" class="btn-mini danger" data-remove-row aria-label="Remove this day">&times;</button></td>';
    $html .= '</tr>';

    return $html;
}

$roleLabels = [1 => 'Administrator', 2 => 'Staff', 3 => 'Patient', 4 => 'Doctor'];
// Single source of truth for the roles the backend actually accepts.
$createRoleLabels = accountManagedRoles();
$defaultCreateRole = array_key_exists(4, $createRoleLabels) ? 4 : (int) array_key_first($createRoleLabels);

$tabUrl = function (int $role) use ($search): string {
    $q = ['role' => $role];
    if ($search !== '') {
        $q['q'] = $search;
    }

    return 'admin_account_management.php?' . http_build_query($q);
};

$baseQuery = [];
if ($search !== '') {
    $baseQuery['q'] = $search;
}
if ($roleFilter > 0) {
    $baseQuery['role'] = $roleFilter;
}
$pageUrl = function (int $p) use ($baseQuery): string {
    $q = $baseQuery;
    $q['page'] = $p;

    return 'admin_account_management.php?' . http_build_query($q);
};

$firstShown = $total === 0 ? 0 : $offset + 1;
$lastShown  = min($total, $offset + count($accounts));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Management — Curora Admin Portal</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_staff_management.css">
<link rel="stylesheet" href="../assets/css/admin/admin_notifications.css">
<link rel="stylesheet" href="../assets/css/admin/admin_account_management.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon"><img src="../assets/images/curora-icon.png" alt="Curora"></div>
      <div class="brand-text"><div class="brand-title">Curora</div><div class="brand-sub">Admin Portal</div></div>
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
      <li class="nav-item active">
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

<main class="main">
  <div class="am-header">
    <div class="page-header">
      <h1>Account Management</h1>
      <p>Create and manage every login. Patients self-register from the public site.</p>
    </div>
    <a class="btn-primary" href="#create-account">+ Create account</a>
  </div>

  <?php if ($flashMessage !== ''): ?>
    <div class="account-panel">
      <div class="flash <?php echo $flashStatus === 'success' ? 'flash-success' : 'flash-error'; ?>" role="status">
        <?php echo h($flashMessage); ?>
      </div>
    </div>
  <?php endif; ?>

  <section class="account-panel" id="create-account">
    <h2>Create account</h2>
    <p class="hint">A random temporary password is e-mailed to the new user. They must choose a new password the first time they sign in. The temporary password is never shown here.</p>

    <form method="POST" action="../api/admin/accounts.php" id="create-form" autocomplete="off">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="create">

      <div class="field create-role-field">
        <label for="create_role_id">Role</label>
        <select id="create_role_id" name="role_id" required>
          <?php foreach ($createRoleLabels as $rid => $rlabel): ?>
            <option value="<?php echo (int) $rid; ?>" <?php echo (int) $rid === $defaultCreateRole ? 'selected' : ''; ?>><?php echo h($rlabel); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-grid">
        <div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" required></div>
        <div class="field"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name"></div>
        <div class="field"><label for="last_name">Last name</label><input id="last_name" name="last_name" required></div>
        <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required></div>
        <div class="field" data-role="1">
          <label for="confirm_email">Confirm email <span class="field-hint">(required)</span></label>
          <input type="email" id="confirm_email" name="confirm_email" required>
        </div>
        <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" title="11 digits starting with 09" placeholder="09XXXXXXXXX"></div>
        <div class="field">
          <label for="sex">Sex</label>
          <select id="sex" name="sex">
            <?php foreach (['Not Specified', 'Male', 'Female'] as $sx): ?>
              <option><?php echo $sx; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" data-role="2,4">
          <label for="department_id">Department <span class="field-hint">(required)</span></label>
          <select id="department_id" name="department_id">
            <option value="0">Select department…</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?php echo (int) $d['DepartmentID']; ?>"><?php echo h($d['DepartmentName']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" data-role="2">
          <label for="staff_role">Staff role <span class="field-hint">(required)</span></label>
          <input id="staff_role" name="staff_role" placeholder="e.g. Nurse, Receptionist">
        </div>
        <div class="field" data-role="2,4">
          <label for="specialization">Specialization</label><input id="specialization" name="specialization">
        </div>
        <div class="field" data-role="4">
          <label for="license_number">License number <span class="field-hint">(required)</span></label><input id="license_number" name="license_number">
        </div>
        <div class="field" data-role="4">
          <label for="years_of_experience">Years of experience</label><input id="years_of_experience" type="number" min="0" name="years_of_experience">
        </div>
        <div class="field" data-role="1">
          <label for="create_reauth">Confirm your password <span class="field-hint">(required)</span></label>
          <input type="password" id="create_reauth" name="reauth_password" autocomplete="off" placeholder="Your own password" required>
        </div>
      </div>
      <div class="row-actions">
        <button type="button" class="btn-primary" id="preview-btn" hidden>Review and create</button>
        <button type="submit" class="btn-primary" id="create-submit" hidden>Create account</button>
      </div>
    </form>

    <div class="preview-box" id="preview-panel" hidden>
      <h2>Confirm before sending</h2>
      <p class="hint">A temporary password will be e-mailed to the address shown below.</p>
      <dl id="preview-details"></dl>
      <div class="row-actions">
        <button type="button" class="btn-mini" id="preview-back">&larr; Edit details</button>
        <button type="submit" form="create-form" class="btn-primary">Confirm &amp; send</button>
      </div>
    </div>
  </section>

  <section class="account-panel">
    <h2>Accounts (<?php echo (int) $total; ?>)</h2>

    <nav class="tab-bar" aria-label="Filter by role">
      <?php foreach ($tabs as $rid => $rlabel): ?>
        <a class="tab-link <?php echo $roleFilter === $rid ? 'active' : ''; ?>"
           href="<?php echo h($tabUrl((int) $rid)); ?>"><?php echo h($rlabel); ?><span class="tab-count"><?php echo (int) ($tabCounts[$rid] ?? 0); ?></span></a>
      <?php endforeach; ?>
    </nav>

    <form class="filter-bar" method="GET" action="admin_account_management.php">
      <?php if ($roleFilter > 0): ?>
        <input type="hidden" name="role" value="<?php echo (int) $roleFilter; ?>">
      <?php endif; ?>
      <div class="field">
        <label for="q">Search</label>
        <input id="q" name="q" value="<?php echo h($search); ?>" placeholder="Name, e-mail or department">
      </div>
      <div class="filter-actions">
        <button type="submit" class="btn-mini">Search</button>
        <?php if ($search !== '' || $roleFilter > 0): ?>
          <a class="btn-mini" href="admin_account_management.php">Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="table-scroll">
    <table class="data-table">
      <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Staff role</th><th>Status</th><th>2FA</th><th>Last login</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php if (empty($accounts)): ?>
        <tr><td colspan="9" class="empty-row">No accounts match the current filter.</td></tr>
      <?php endif; ?>
      <?php foreach ($accounts as $a): ?>
        <?php
          $isManaged = accountRoleValid((int) $a['RoleID']);
          $uid = (int) $a['UserID'];
          $isActive = strcasecmp((string) $a['Status'], 'Active') === 0;
          $isPending = strcasecmp((string) $a['Status'], 'Pending') === 0;
          $isSelf = $uid === $currentUserId;
          $isAdmin = (int) $a['RoleID'] === 1;
          $hasTempPassword = (int) ($a['MustChangePassword'] ?? 0) === 1;
          $fullName = trim((string) $a['FirstName'] . ' ' . (string) $a['LastName']);
          $tempNote = $hasTempPassword ? accountTempExpiryNote($a['TempPasswordExpiresAt'] ?? null) : '';
          $canResend = $isManaged && !$isPending && $hasTempPassword;
        ?>
        <tr>
          <td>
            <?php echo h($fullName); ?>
            <?php if ($isSelf): ?><span class="self-chip">You</span><?php endif; ?>
          </td>
          <td><?php echo h($a['Email']); ?></td>
          <td><span class="role-chip role-<?php echo (int) $a['RoleID']; ?>"><?php echo h($roleLabels[(int) $a['RoleID']] ?? $a['RoleName']); ?></span></td>
          <td><?php echo h((isset($a['DepartmentName']) && $a['DepartmentName'] !== '') ? $a['DepartmentName'] : '—'); ?></td>
          <td><?php echo h((($a['StaffRole'] ?? '') !== '') ? $a['StaffRole'] : '—'); ?></td>
          <td>
            <?php echo accountStatusBadge((string) $a['Status'], $hasTempPassword, $a['TempPasswordExpiresAt'] ?? null); ?>
            <?php if ($tempNote !== ''): ?><span class="cell-sub"><?php echo h($tempNote); ?></span><?php endif; ?>
            <?php if ((int) $a['RoleID'] === 3 && (int) ($a['EmailVerified'] ?? 1) === 0): ?><span class="cell-warn">email unverified</span><?php endif; ?>
          </td>
          <td>
            <?php if ($isAdmin): ?>
              <?php if ((int) ($a['TOTPEnabled'] ?? 0) === 1): ?>Enabled<?php else: ?><span class="cell-warn">Not enrolled</span><?php endif; ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?php echo h(accountLastLogin($a['LastLogin'] ?? null)); ?></td>
          <td>
            <?php if ($isManaged): ?>
              <?php if ($canResend): ?>
                <form class="inline-form" method="POST" action="../api/admin/accounts.php" onsubmit="return confirm(<?php echo h(json_encode('E-mail a new temporary password to ' . $fullName . '? The previous password will stop working.')); ?>);">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="resend_password">
                  <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
                  <button type="submit" class="btn-mini">Resend password</button>
                </form>
              <?php endif; ?>
              <button type="button" class="btn-mini" data-open-drawer="drawer-<?php echo $uid; ?>"><?php echo $isSelf ? 'Edit profile' : 'Manage'; ?></button>
            <?php elseif ((int) $a['RoleID'] === 3): ?>
              <button type="button" class="btn-mini" data-open-drawer="drawer-<?php echo $uid; ?>">Manage</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <nav class="pagination" aria-label="Pages">
      <span class="page-summary">Showing <?php echo (int) $firstShown; ?>–<?php echo (int) $lastShown; ?> of <?php echo (int) $total; ?></span>
      <?php if ($totalPages > 1): ?>
        <?php if ($page > 1): ?>
          <a href="<?php echo h($pageUrl($page - 1)); ?>">Previous</a>
        <?php endif; ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <?php if ($p === $page): ?>
            <span class="page current" aria-current="page"><?php echo $p; ?></span>
          <?php else: ?>
            <a href="<?php echo h($pageUrl($p)); ?>"><?php echo $p; ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <a href="<?php echo h($pageUrl($page + 1)); ?>">Next</a>
        <?php endif; ?>
      <?php endif; ?>
    </nav>
  </section>
</main>
</div>

<?php /* ---- Detail drawers: one per managed account on this page ---------- */ ?>
<div class="drawer-backdrop" id="drawer-backdrop" hidden></div>
<template id="schedule-row-template"><?php echo accountScheduleRow('__I__', [], ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']); ?></template>
<?php foreach ($accounts as $a): ?>
  <?php
    $uid = (int) $a['UserID'];
    $rid = (int) $a['RoleID'];
    $isPatient = $rid === 3;
    if (!$isPatient && !accountRoleValid($rid)) {
        continue;
    }
    $isActive = strcasecmp((string) $a['Status'], 'Active') === 0;
    $isPending = strcasecmp((string) $a['Status'], 'Pending') === 0;
    $isSelf = $uid === $currentUserId;
    $isAdmin = $rid === 1;
    $hasTempPassword = (int) ($a['MustChangePassword'] ?? 0) === 1;
    $fullName = trim((string) $a['FirstName'] . ' ' . (string) $a['LastName']);
    $title = $rid === 4 ? 'Dr. ' . $fullName : $fullName;

    // The current admin may not lock themselves out, and the final
    // active administrator can never be deactivated or deleted.
    $canToggle = !$isSelf && !$isPending && !($isActive && $isAdmin && $activeAdminCount <= 1);
    $canReset  = !$isSelf;
    $canDelete = !$isPatient && !$isSelf && !($isAdmin && $isActive && $activeAdminCount <= 1);
  ?>
  <aside class="drawer" id="drawer-<?php echo $uid; ?>" role="dialog" aria-modal="true" aria-labelledby="drawer-title-<?php echo $uid; ?>" hidden>
    <div class="drawer-section">
      <div class="drawer-head">
        <div>
          <h2 class="drawer-title" id="drawer-title-<?php echo $uid; ?>"><?php echo h($title); ?></h2>
          <div class="drawer-chips">
            <span class="role-chip role-<?php echo $rid; ?>"><?php echo h($roleLabels[$rid] ?? $a['RoleName']); ?></span>
            <?php echo accountStatusBadge((string) $a['Status'], $hasTempPassword, $a['TempPasswordExpiresAt'] ?? null); ?>
          </div>
        </div>
        <button type="button" class="btn-mini" data-close-drawer>Close</button>
      </div>
    </div>

    <?php if ($isPatient): ?>
    <?php
    $patientRow = $a;
    $dob = (string) ($patientRow['DateOfBirth'] ?? '');
    $ageText = '';
    if ($dob !== '' && strtotime($dob) !== false) {
        $ageText = (new DateTime($dob))->diff(new DateTime('today'))->y . ' years old';
    }
    $patientCode = isset($patientRow['PatientID']) && $patientRow['PatientID'] !== '' ? sprintf('PT-%05d', (int) $patientRow['PatientID']) : '';
    $civilStatuses = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Other'];
    ?>
    <form method="POST" action="../api/admin/accounts.php" autocomplete="off">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="update_patient">
      <input type="hidden" name="user_id" value="<?php echo $uid; ?>">

      <div class="drawer-section">
        <h3>Basic information</h3>
        <div class="form-grid">
          <div class="field"><label for="f-<?php echo $uid; ?>-fn">First name</label><input id="f-<?php echo $uid; ?>-fn" name="first_name" value="<?php echo h($a['FirstName']); ?>" required></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-mn">Middle name</label><input id="f-<?php echo $uid; ?>-mn" name="middle_name" value="<?php echo h($a['MiddleName']); ?>"></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-ln">Last name</label><input id="f-<?php echo $uid; ?>-ln" name="last_name" value="<?php echo h($a['LastName']); ?>" required></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-pid">Patient ID</label><input id="f-<?php echo $uid; ?>-pid" value="<?php echo h($patientCode !== '' ? $patientCode : '—'); ?>" disabled></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-dob">Date of birth</label><input id="f-<?php echo $uid; ?>-dob" type="date" name="date_of_birth" value="<?php echo h($dob); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-age">Age</label><input id="f-<?php echo $uid; ?>-age" value="<?php echo h($ageText !== '' ? $ageText : '—'); ?>" disabled></div>
          <div class="field">
            <label for="f-<?php echo $uid; ?>-sx">Sex</label>
            <select id="f-<?php echo $uid; ?>-sx" name="sex">
              <?php foreach (['Not Specified', 'Male', 'Female'] as $sx): ?>
                <option <?php echo ($a['Sex'] ?? '') === $sx ? 'selected' : ''; ?>><?php echo $sx; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label for="f-<?php echo $uid; ?>-ph">Contact number</label><input id="f-<?php echo $uid; ?>-ph" name="phone" value="<?php echo h($a['ContactNumber'] ?? ''); ?>" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" title="11 digits starting with 09" placeholder="09XXXXXXXXX"></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-em">Email address</label><input id="f-<?php echo $uid; ?>-em" type="email" name="email" value="<?php echo h($a['Email']); ?>" required></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-rl">Religion</label><input id="f-<?php echo $uid; ?>-rl" name="religion" value="<?php echo h($a['Religion'] ?? ''); ?>"></div>
          <div class="field">
            <label for="f-<?php echo $uid; ?>-cs">Civil status</label>
            <select id="f-<?php echo $uid; ?>-cs" name="civil_status">
              <option value="">Not specified</option>
              <?php foreach ($civilStatuses as $cs): ?>
                <option <?php echo ($a['CivilStatus'] ?? '') === $cs ? 'selected' : ''; ?>><?php echo $cs; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field wide"><label for="f-<?php echo $uid; ?>-ad">Address</label><textarea id="f-<?php echo $uid; ?>-ad" name="address" rows="2" maxlength="255"><?php echo h($a['Address'] ?? ''); ?></textarea></div>
        </div>
      </div>

      <div class="drawer-section">
        <h3>Emergency contact</h3>
        <div class="form-grid">
          <div class="field"><label for="f-<?php echo $uid; ?>-ecn">Contact name</label><input id="f-<?php echo $uid; ?>-ecn" name="emergency_contact_name" value="<?php echo h($a['EmergencyContactName'] ?? ''); ?>" maxlength="100"></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-ecr">Relationship</label><input id="f-<?php echo $uid; ?>-ecr" name="emergency_contact_relationship" value="<?php echo h($a['EmergencyRelation'] ?? ''); ?>" maxlength="50" placeholder="e.g. Mother, Spouse"></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-ecp">Contact number</label><input id="f-<?php echo $uid; ?>-ecp" name="emergency_contact_phone" value="<?php echo h($a['EmergencyContactNo'] ?? ''); ?>" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" title="11 digits starting with 09" placeholder="09XXXXXXXXX"></div>
        </div>
      </div>

      <div class="drawer-section">
        <h3>Health information</h3>
        <div class="form-grid">
          <div class="field">
            <label for="f-<?php echo $uid; ?>-bt">Blood type</label>
            <select id="f-<?php echo $uid; ?>-bt" name="blood_type">
              <option value="">Select</option>
              <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt): ?>
                <option value="<?php echo $bt; ?>" <?php echo ($a['BloodType'] ?? '') === $bt ? 'selected' : ''; ?>><?php echo $bt; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label for="f-<?php echo $uid; ?>-al">Allergies</label><input id="f-<?php echo $uid; ?>-al" name="allergies" value="<?php echo h($a['Allergies'] ?? ''); ?>" maxlength="2000" placeholder="e.g. Penicillin, Seafood"></div>
          <div class="field wide"><label for="f-<?php echo $uid; ?>-fmh">Family medical history <span class="hint">(one entry per line)</span></label><textarea id="f-<?php echo $uid; ?>-fmh" name="family_medical_history" rows="3" maxlength="4000"><?php echo h($a['FamilyMedicalHistory'] ?? ''); ?></textarea></div>
          <div class="field wide"><label for="f-<?php echo $uid; ?>-pmc">Past medical conditions <span class="hint">(one entry per line)</span></label><textarea id="f-<?php echo $uid; ?>-pmc" name="past_medical_condition" rows="3" maxlength="4000"><?php echo h($a['PastMedicalCondition'] ?? ''); ?></textarea></div>
          <div class="field wide"><label for="f-<?php echo $uid; ?>-cm">Current medications <span class="hint">(one entry per line)</span></label><textarea id="f-<?php echo $uid; ?>-cm" name="current_medication" rows="3" maxlength="4000"><?php echo h($a['CurrentMedication'] ?? ''); ?></textarea></div>
          <div class="field">
            <label for="f-<?php echo $uid; ?>-pwd">Person with disability (PWD)</label>
            <input id="f-<?php echo $uid; ?>-pwd" type="checkbox" name="is_pwd" value="1" <?php echo (int) ($a['IsPWD'] ?? 0) === 1 ? 'checked' : ''; ?> onchange="document.getElementById('f-<?php echo $uid; ?>-dt').disabled = !this.checked;">
          </div>
          <div class="field"><label for="f-<?php echo $uid; ?>-dt">Disability type</label><input id="f-<?php echo $uid; ?>-dt" name="disability_type" value="<?php echo h($a['DisabilityType'] ?? ''); ?>" maxlength="100" placeholder="e.g. Visual impairment" <?php echo (int) ($a['IsPWD'] ?? 0) === 1 ? '' : 'disabled'; ?>></div>
        </div>
      </div>

      <div class="drawer-section">
        <button type="submit" class="btn-primary">Save changes</button>
        <p class="drawer-note">The same details the patient sees on their profile; health fields are stored on the patient record.</p>
      </div>
    </form>
    <?php else: ?>
    <form method="POST" action="../api/admin/accounts.php" autocomplete="off">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
      <?php if (!$isPending): ?>
        <input type="hidden" name="status" value="<?php echo $isActive ? 'Active' : 'Inactive'; ?>">
      <?php endif; ?>

      <?php
        $roleCode = '';
        if ($rid === 4 && isset($a['DoctorID']) && $a['DoctorID'] !== '') {
            $roleCode = sprintf('DOC-%03d', (int) $a['DoctorID']);
        } elseif ($rid === 2 && isset($a['StaffID']) && $a['StaffID'] !== '') {
            $roleCode = sprintf('STF-%03d', (int) $a['StaffID']);
        }
        $weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
      ?>
      <div class="drawer-section">
        <h3>Basic information</h3>
        <div class="form-grid">
          <div class="field"><label for="f-<?php echo $uid; ?>-fn">First name</label><input id="f-<?php echo $uid; ?>-fn" name="first_name" value="<?php echo h($a['FirstName']); ?>" required></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-mn">Middle name</label><input id="f-<?php echo $uid; ?>-mn" name="middle_name" value="<?php echo h($a['MiddleName']); ?>"></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-ln">Last name</label><input id="f-<?php echo $uid; ?>-ln" name="last_name" value="<?php echo h($a['LastName']); ?>" required></div>
          <?php if ($rid === 4): ?>
            <div class="field"><label for="f-<?php echo $uid; ?>-pt">Professional title</label><input id="f-<?php echo $uid; ?>-pt" name="professional_title" value="<?php echo h($a['ProfessionalTitle'] ?? ''); ?>" placeholder="e.g. Doctor of Medicine" maxlength="100"></div>
          <?php endif; ?>
          <?php if ($roleCode !== ''): ?>
            <div class="field"><label for="f-<?php echo $uid; ?>-code"><?php echo $rid === 4 ? 'Doctor ID' : 'Staff ID'; ?></label><input id="f-<?php echo $uid; ?>-code" value="<?php echo h($roleCode); ?>" disabled></div>
          <?php endif; ?>
          <div class="field"><label for="f-<?php echo $uid; ?>-dob">Date of birth</label><input id="f-<?php echo $uid; ?>-dob" type="date" name="date_of_birth" value="<?php echo h($a['DateOfBirth'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>"></div>
          <div class="field">
            <label for="f-<?php echo $uid; ?>-sx">Sex</label>
            <select id="f-<?php echo $uid; ?>-sx" name="sex">
              <?php foreach (['Not Specified', 'Male', 'Female'] as $sx): ?>
                <option <?php echo $a['Sex'] === $sx ? 'selected' : ''; ?>><?php echo $sx; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label for="f-<?php echo $uid; ?>-ph">Contact number</label><input id="f-<?php echo $uid; ?>-ph" name="phone" value="<?php echo h($a['ContactNumber']); ?>" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" title="11 digits starting with 09" placeholder="09XXXXXXXXX"></div>
          <div class="field"><label for="f-<?php echo $uid; ?>-em">Email address</label><input id="f-<?php echo $uid; ?>-em" type="email" name="email" value="<?php echo h($a['Email']); ?>" required></div>
          <div class="field">
            <label for="f-<?php echo $uid; ?>-role">Role</label>
            <input id="f-<?php echo $uid; ?>-role" value="<?php echo h($roleLabels[$rid] ?? $a['RoleName']); ?>" disabled>
          </div>
          <?php if ($rid === 2 || $rid === 4): ?>
            <div class="field wide"><label for="f-<?php echo $uid; ?>-ad">Address</label><textarea id="f-<?php echo $uid; ?>-ad" name="address" rows="2" maxlength="255"><?php echo h($a['Address'] ?? ''); ?></textarea></div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($rid === 4): ?>
        <div class="drawer-section">
          <h3>Professional information</h3>
          <div class="form-grid">
            <div class="field">
              <label for="f-<?php echo $uid; ?>-dp">Department</label>
              <select id="f-<?php echo $uid; ?>-dp" name="department_id">
                <option value="0">Select department…</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int) $d['DepartmentID']; ?>" <?php echo (int) ($a['DepartmentID'] ?? 0) === (int) $d['DepartmentID'] ? 'selected' : ''; ?>><?php echo h($d['DepartmentName']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label for="f-<?php echo $uid; ?>-sp">Specialization</label><input id="f-<?php echo $uid; ?>-sp" name="specialization" value="<?php echo h($a['Specialization'] ?? ''); ?>"></div>
            <div class="field"><label for="f-<?php echo $uid; ?>-li">License number</label><input id="f-<?php echo $uid; ?>-li" name="license_number" value="<?php echo h($a['LicenseNumber'] ?? ''); ?>" placeholder="MD-XXXX-001"></div>
            <div class="field"><label for="f-<?php echo $uid; ?>-ye">Years of experience</label><input id="f-<?php echo $uid; ?>-ye" type="number" min="0" name="years_of_experience" value="<?php echo h($a['YearsOfExperience'] ?? ''); ?>"></div>
            <div class="field">
              <label for="f-<?php echo $uid; ?>-av">Availability status</label>
              <select id="f-<?php echo $uid; ?>-av" name="availability_status">
                <?php foreach (['Available', 'Off Duty', 'On Leave'] as $s): ?>
                  <option <?php echo (string) ($a['AvailabilityStatus'] ?? '') === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field wide"><label for="f-<?php echo $uid; ?>-bio">Bio / description</label><textarea id="f-<?php echo $uid; ?>-bio" name="bio" rows="3" maxlength="1000" placeholder="Brief professional bio..."><?php echo h($a['Bio'] ?? ''); ?></textarea></div>
            <div class="field wide"><label for="f-<?php echo $uid; ?>-img">Profile image URL</label><input id="f-<?php echo $uid; ?>-img" type="url" name="profile_image_url" value="<?php echo h($a['ProfileImageURL'] ?? ''); ?>" placeholder="https://..." pattern="https?://.*"></div>
          </div>
        </div>

        <?php $scheduleRows = function_exists('accountDoctorSchedule') ? accountDoctorSchedule($conn, $uid) : []; ?>
        <div class="drawer-section">
          <h3>Schedule</h3>
          <p class="drawer-note drawer-note-top">One row per working day. Days without a row are not bookable.</p>
          <div class="table-scroll">
            <table class="schedule-table">
              <thead><tr><th>Day</th><th>Session</th><th>Start</th><th>End</th><th>Max patients</th><th><span class="sr-only">Remove</span></th></tr></thead>
              <tbody id="sched-<?php echo $uid; ?>" data-next="<?php echo count($scheduleRows); ?>">
                <?php foreach (array_values($scheduleRows) as $i => $row): ?>
                  <?php echo accountScheduleRow((string) $i, (array) $row, $weekDays); ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn-mini schedule-add" data-add-schedule="<?php echo $uid; ?>">+ Add day</button>
        </div>
      <?php endif; ?>

      <?php if ($rid === 2): ?>
        <?php
          $assignedRaw = (string) ($a['AssignedSchedule'] ?? '');
          $assignedDays = array_filter(array_map('trim', explode(',', $assignedRaw)));
        ?>
        <div class="drawer-section">
          <h3>Employment information</h3>
          <div class="form-grid">
            <div class="field"><label for="f-<?php echo $uid; ?>-ec">Employee ID</label><input id="f-<?php echo $uid; ?>-ec" value="<?php echo h(($a['EmployeeCode'] ?? '') !== '' ? $a['EmployeeCode'] : '—'); ?>" disabled></div>
            <div class="field"><label for="f-<?php echo $uid; ?>-sr">Position</label><input id="f-<?php echo $uid; ?>-sr" name="staff_role" value="<?php echo h($a['StaffRole'] ?? ''); ?>" placeholder="e.g. Nurse, Receptionist"></div>
            <div class="field">
              <label for="f-<?php echo $uid; ?>-dp">Department</label>
              <select id="f-<?php echo $uid; ?>-dp" name="department_id">
                <option value="0">Select department…</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?php echo (int) $d['DepartmentID']; ?>" <?php echo (int) ($a['DepartmentID'] ?? 0) === (int) $d['DepartmentID'] ? 'selected' : ''; ?>><?php echo h($d['DepartmentName']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label for="f-<?php echo $uid; ?>-sp">Specialization</label><input id="f-<?php echo $uid; ?>-sp" name="specialization" value="<?php echo h($a['Specialization'] ?? ''); ?>" placeholder="e.g. Scrub Nurse"></div>
            <div class="field">
              <label for="f-<?php echo $uid; ?>-av">Employment status</label>
              <select id="f-<?php echo $uid; ?>-av" name="availability_status">
                <?php foreach (['Available', 'Off Duty', 'On Leave'] as $s): ?>
                  <option <?php echo (string) ($a['AvailabilityStatus'] ?? '') === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label for="f-<?php echo $uid; ?>-hd">Date hired</label><input id="f-<?php echo $uid; ?>-hd" type="date" name="date_hired" value="<?php echo h($a['DateHired'] ?? ''); ?>"></div>
            <div class="field wide"><label for="f-<?php echo $uid; ?>-rs">Assigned responsibilities</label><textarea id="f-<?php echo $uid; ?>-rs" name="responsibilities" rows="3" maxlength="1000" placeholder="e.g. Front desk, appointment booking"><?php echo h($a['Responsibilities'] ?? ''); ?></textarea></div>
          </div>
        </div>

        <div class="drawer-section">
          <h3>Work information</h3>
          <fieldset class="day-picker">
            <legend>Assigned schedule</legend>
            <?php foreach ($weekDays as $day): ?>
              <label class="day-option"><input type="checkbox" name="assigned_days[]" value="<?php echo h($day); ?>" <?php echo in_array($day, $assignedDays, true) ? 'checked' : ''; ?>> <?php echo h(substr($day, 0, 3)); ?></label>
            <?php endforeach; ?>
          </fieldset>
          <div class="form-grid">
            <div class="field"><label for="f-<?php echo $uid; ?>-ss">Shift start</label><input id="f-<?php echo $uid; ?>-ss" type="time" name="duty_start_time" value="<?php echo h(substr((string) ($a['DutyStartTime'] ?? ''), 0, 5)); ?>"></div>
            <div class="field"><label for="f-<?php echo $uid; ?>-se">Shift end</label><input id="f-<?php echo $uid; ?>-se" type="time" name="duty_end_time" value="<?php echo h(substr((string) ($a['DutyEndTime'] ?? ''), 0, 5)); ?>"></div>
          </div>
        </div>
      <?php endif; ?>

      <div class="drawer-section">
        <button type="submit" class="btn-primary">Save changes</button>
        <p class="drawer-note">Role cannot be changed here.</p>
      </div>
    </form>
    <?php endif; ?>

    <div class="drawer-section">
      <h3>Account</h3>
      <dl class="drawer-facts">
        <dt>Last login</dt><dd><?php echo h(accountLastLogin($a['LastLogin'] ?? null)); ?></dd>
        <?php if ($isAdmin): ?>
          <dt>2FA</dt><dd><?php echo (int) ($a['TOTPEnabled'] ?? 0) === 1 ? 'Enabled' : 'Not enrolled'; ?></dd>
        <?php endif; ?>
      </dl>
      <div class="drawer-actions">
        <?php if ($canReset): ?>
          <form class="inline-form js-reauth" method="POST" action="../api/admin/accounts.php" onsubmit="return reauthAction(this, <?php echo h(json_encode('E-mail a new temporary password to ' . $fullName . '? Their current password will stop working immediately.')); ?>);">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
            <input type="hidden" name="reauth_password" value="">
            <button type="submit" class="btn-mini">Send new temporary password</button>
          </form>
        <?php endif; ?>
        <?php if ($canToggle): ?>
          <form class="inline-form" method="POST" action="../api/admin/accounts.php" onsubmit="return confirm(<?php echo h(json_encode(($isActive ? 'Deactivate ' : 'Reactivate ') . $fullName . '?')); ?>);">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="<?php echo $isPatient ? 'toggle_patient' : 'toggle'; ?>">
            <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
            <button type="submit" class="btn-mini warn"><?php echo $isActive ? 'Deactivate' : 'Reactivate'; ?></button>
          </form>
        <?php endif; ?>
      </div>
      <p class="drawer-note">A new random password is e-mailed, the old one stops working, and the user must change it at next login.</p>
    </div>

    <?php if ($canDelete): ?>
      <div class="drawer-section drawer-danger">
        <h3>Danger zone</h3>
        <p>Deleting hides the account. It is refused if the account has linked appointments or records; deactivate instead.</p>
        <form class="inline-form js-reauth" method="POST" action="../api/admin/accounts.php" onsubmit="return reauthAction(this, <?php echo h(json_encode('Delete ' . $title . '? Their account will be deactivated and hidden.')); ?>);">
          <?php echo csrfField(); ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="user_id" value="<?php echo $uid; ?>">
          <input type="hidden" name="reauth_password" value="">
          <button type="submit" class="btn-mini danger">Delete <?php echo h($title); ?></button>
        </form>
      </div>
    <?php endif; ?>
  </aside>
<?php endforeach; ?>

<script>
// ---------------------------------------------------------------------------
// Role-aware create form.
//
// Choosing a role shows only that role's fields, disables the hidden ones so a
// crafted request cannot smuggle them in, and swaps the submit control:
//   * Administrator -> direct submit, with confirm-email and re-authentication
//   * Doctor / Staff -> a confirm step ("a temporary password will be sent to")
// ---------------------------------------------------------------------------
(function () {
  var roleSelect = document.getElementById('create_role_id');
  var form       = document.getElementById('create-form');
  var previewBtn = document.getElementById('preview-btn');
  var submitBtn  = document.getElementById('create-submit');
  var previewBox = document.getElementById('preview-details');
  var panel      = document.getElementById('preview-panel');

  if (!roleSelect || !form) return;

  function fieldLabel(field) {
    var wrap = field.closest ? field.closest('.field') : null;
    var label = wrap ? wrap.querySelector('label') : null;
    if (!label) return field.name;
    return label.textContent.replace(/\s*\(required\)\s*$/i, '').trim();
  }

  function hidePreview() {
    if (panel) panel.hidden = true;
    form.hidden = false;
  }

  function applyRole(role) {
    Array.prototype.slice.call(form.querySelectorAll('[data-role]')).forEach(function (wrap) {
      var roles = String(wrap.getAttribute('data-role')).split(',');
      var show = roles.indexOf(role) !== -1;
      wrap.hidden = !show;
      Array.prototype.slice.call(wrap.querySelectorAll('input, select, textarea')).forEach(function (field) {
        field.disabled = !show;
      });
    });

    var isAdmin = role === '1';
    if (previewBtn) previewBtn.hidden = isAdmin;
    if (submitBtn) submitBtn.hidden = !isAdmin;

    hidePreview();
  }

  roleSelect.addEventListener('change', function () {
    applyRole(roleSelect.value);
  });

  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      if (!form.reportValidity()) return;

      previewBox.innerHTML = '';

      var roleDt = document.createElement('dt');
      roleDt.textContent = 'Role';
      var roleDd = document.createElement('dd');
      roleDd.textContent = roleSelect.options[roleSelect.selectedIndex].text;
      previewBox.appendChild(roleDt);
      previewBox.appendChild(roleDd);

      Array.prototype.slice.call(form.querySelectorAll('input, select, textarea')).forEach(function (field) {
        if (field.disabled || !field.name || field.name === 'csrf_token' || field.name === 'action' || field.name === 'role_id') {
          return;
        }
        if (field.value === '' || field.value === '0') return;

        var dt = document.createElement('dt');
        dt.textContent = fieldLabel(field);
        var dd = document.createElement('dd');
        dd.textContent = field.type === 'password' ? '••••••••' : field.value;
        previewBox.appendChild(dt);
        previewBox.appendChild(dd);
      });

      form.hidden = true;
      if (panel) panel.hidden = false;
    });
  }

  var backBtn = document.getElementById('preview-back');
  if (backBtn) {
    backBtn.addEventListener('click', hidePreview);
  }

  applyRole(roleSelect.value);
})();

// ---------------------------------------------------------------------------
// Account detail drawer: opens from a row's Manage button, closes from its
// Close button, the backdrop, or the Escape key, and returns focus.
// ---------------------------------------------------------------------------
(function () {
  var backdrop = document.getElementById('drawer-backdrop');
  var openDrawer = null;
  var opener = null;

  function close() {
    if (!openDrawer) return;
    openDrawer.hidden = true;
    if (backdrop) backdrop.hidden = true;
    document.body.classList.remove('drawer-open');
    if (opener) opener.focus();
    openDrawer = null;
    opener = null;
  }

  function open(id, trigger) {
    var drawer = document.getElementById(id);
    if (!drawer) return;
    close();
    drawer.hidden = false;
    if (backdrop) backdrop.hidden = false;
    document.body.classList.add('drawer-open');
    openDrawer = drawer;
    opener = trigger;
    var closeBtn = drawer.querySelector('[data-close-drawer]');
    if (closeBtn) closeBtn.focus();
  }

  Array.prototype.slice.call(document.querySelectorAll('[data-open-drawer]')).forEach(function (btn) {
    btn.addEventListener('click', function () {
      open(btn.getAttribute('data-open-drawer'), btn);
    });
  });
  Array.prototype.slice.call(document.querySelectorAll('[data-close-drawer]')).forEach(function (btn) {
    btn.addEventListener('click', close);
  });
  if (backdrop) backdrop.addEventListener('click', close);
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') close();
  });
})();

// ---------------------------------------------------------------------------
// Doctor schedule rows: add a day, remove a day.
// ---------------------------------------------------------------------------
(function () {
  var template = document.getElementById('schedule-row-template');
  Array.prototype.slice.call(document.querySelectorAll('[data-add-schedule]')).forEach(function (btn) {
    btn.addEventListener('click', function () {
      var body = document.getElementById('sched-' + btn.getAttribute('data-add-schedule'));
      if (!body || !template) return;
      var next = parseInt(body.getAttribute('data-next') || '0', 10);
      body.setAttribute('data-next', String(next + 1));
      body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__I__/g, String(next)));
    });
  });
  document.addEventListener('click', function (event) {
    var target = event.target;
    if (target && target.closest && target.closest('[data-remove-row]')) {
      var row = target.closest('tr');
      if (row) row.parentNode.removeChild(row);
    }
  });
})();

// Sensitive actions (reset password, delete) require the acting admin to
// re-confirm their own password. The value is placed in a hidden field and the
// server verifies it; it is never logged.
function reauthAction(form, message) {
  if (message && !window.confirm(message)) {
    return false;
  }
  var field = form.querySelector('input[name="reauth_password"]');
  var password = window.prompt('For your security, enter YOUR password to confirm this action:');
  if (password === null || password === '') {
    return false;
  }
  if (field) {
    field.value = password;
  }
  return true;
}
</script>
</body>
</html>