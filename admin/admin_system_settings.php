<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/audit_helper.php';
requireRole('Admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db_pdo();

/* ------------------------------------------------------------------
   Role access grid data
------------------------------------------------------------------ */
$rolesStmt = $pdo->query('SELECT RoleID, RoleName FROM roles ORDER BY RoleName');
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

$enabledPerRole = [];
$permStmt = $pdo->query(
    'SELECT RoleID, PermissionKey, IsEnabled
     FROM role_permissions
     WHERE IsEnabled = 1'
);
foreach ($permStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $enabledPerRole[(int) $row['RoleID']][$row['PermissionKey']] = true;
}

function roleDefaults(string $roleName): array
{
    return ROLE_PERMISSION_DEFAULTS[$roleName] ?? [];
}

function isPermissionEnabled(array $enabledMap, int $roleId, string $key, string $roleName): bool
{
    if (isset($enabledMap[$roleId][$key])) {
        return (bool) $enabledMap[$roleId][$key];
    }
    return in_array($key, roleDefaults($roleName), true);
}

/** Professional SVG icon for each role, matching the design language. */
function roleIcon(string $roleName): string
{
    $icons = [
        'Admin'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>',
        'Doctor'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6v1"/><path d="M14 10a4 4 0 0 0 4 4v1"/></svg>',
        'Nurse'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M10 3v6"/><path d="M14 3v6"/><path d="M10 6h4"/><path d="M7.5 16h3"/><path d="M13.5 16h3"/><path d="M12 21c-2.5 0-5-1.5-5-4v-5h10v5c0 2.5-2.5 4-5 4Z"/></svg>',
        'Receptionist' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
        'Patient'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
        'Staff'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    ];
    return $icons[$roleName] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>';
}

/** Professional SVG icon for each audit action type. */
function auditActionIcon(string $action): string
{
    $stroke = 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"';
    $icons = [
        'LOGIN'  => '<svg viewBox="0 0 24 24" ' . $stroke . '><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>',
        'DELETE' => '<svg viewBox="0 0 24 24" ' . $stroke . '><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        'CREATE' => '<svg viewBox="0 0 24 24" ' . $stroke . '><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2Z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
        'SEND'   => '<svg viewBox="0 0 24 24" ' . $stroke . '><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        'APPROVE'=> '<svg viewBox="0 0 24 24" ' . $stroke . '><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'UPDATE' => '<svg viewBox="0 0 24 24" ' . $stroke . '><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
    ];
    return $icons[$action] ?? '<svg viewBox="0 0 24 24" ' . $stroke . '><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/></svg>';
}

/* ------------------------------------------------------------------
   Save handler (POST) — persists the permission matrix
------------------------------------------------------------------ */
$flash = ['type' => '', 'text' => ''];

if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'text' => 'Role permissions saved successfully.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        $flash = ['type' => 'error', 'text' => 'Invalid security token. Please try again.'];
    } else {
        $validRoleIds = array_column($roles, 'RoleID');
        $roleNameById = [];
        foreach ($roles as $role) {
            $roleNameById[(int) $role['RoleID']] = (string) $role['RoleName'];
        }
        $validKeys = array_keys(ROLE_PERMISSION_CATALOG);
        $submitted = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];

        try {
            $pdo->beginTransaction();

            foreach ($validRoleIds as $roleId) {
                $roleId = (int) $roleId;
                $incoming = $submitted[$roleId] ?? [];
                $isAdminRole = ($roleNameById[$roleId] ?? '') === 'Admin';
                $granted = [];
                foreach ($validKeys as $key) {
                    if ($isAdminRole) {
                        $granted[$key] = true;
                    } else {
                        $granted[$key] = isset($incoming[$key]) && (int) $incoming[$key] === 1;
                    }
                }

                $pdo->prepare('DELETE FROM role_permissions WHERE RoleID = :rid')
                    ->execute(['rid' => $roleId]);

                $ins = $pdo->prepare(
                    'INSERT INTO role_permissions (RoleID, PermissionKey, PermissionLabel, IsEnabled, UpdatedBy)
                     VALUES (:rid, :key, :label, :val, :by)'
                );
                foreach ($granted as $key => $isGranted) {
                    $ins->execute([
                        'rid' => $roleId,
                        'key' => $key,
                        'label' => ROLE_PERMISSION_CATALOG[$key],
                        'val' => $isGranted ? 1 : 0,
                        'by' => (int) ($_SESSION['UserID'] ?? 0),
                    ]);
                }

                logAudit(
                    $pdo,
                    'UPDATE',
                    'role_permissions',
                    $roleId,
                    null,
                    json_encode($granted, JSON_UNESCAPED_SLASHES)
                );
            }

            $pdo->commit();
            $flash = ['type' => 'success', 'text' => 'Role permissions saved successfully.'];
            header('Location: admin_system_settings.php?saved=1');
            exit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flash = ['type' => 'error', 'text' => 'Failed to save permissions. Please try again.'];
        }
    }
}

/* ------------------------------------------------------------------
   Audit logs — server-side filtering + pagination
------------------------------------------------------------------ */
$activeTab = (string) ($_GET['tab'] ?? 'roles');
$activeTab = in_array($activeTab, ['roles', 'audit-logs'], true) ? $activeTab : 'roles';

$auditType = (string) ($_GET['type'] ?? '');
$query     = trim((string) ($_GET['q'] ?? ''));
$dateFrom  = (string) ($_GET['from'] ?? '');
$dateTo    = (string) ($_GET['to'] ?? '');
$page      = max(1, (int) ($_GET['page'] ?? 1));

const AUDIT_PAGE_SIZE = 15;
$VALID_AUDIT_TYPES = ['LOGIN', 'CREATE', 'UPDATE', 'DELETE', 'SEND', 'APPROVE'];

$auditResults = [];
$auditTotal = 0;
$auditPageCount = 1;

if ($activeTab === 'audit-logs') {
    $where  = [];
    $params = [];
    $paramNo = 0;

    if ($query !== '') {
        $like = '%' . $query . '%';
        for ($i = 1; $i <= 5; $i++) {
            $paramNo++;
            $where[] = 'at.TableName LIKE :p' . $paramNo
                . ' OR at.Action LIKE :p' . $paramNo
                . ' OR at.NewValue LIKE :p' . $paramNo
                . ' OR at.OldValue LIKE :p' . $paramNo
                . ' OR u.Email LIKE :p' . $paramNo;
            $params['p' . $paramNo] = $like;
        }
    }

    if (in_array($auditType, $VALID_AUDIT_TYPES, true)) {
        $paramNo++;
        $where[] = 'at.Action = :p' . $paramNo;
        $params['p' . $paramNo] = $auditType;
    }

    $validFromInput = $dateFrom !== '' && (bool) strtotime($dateFrom);
    $validToInput   = $dateTo !== '' && (bool) strtotime($dateTo);

    if ($validFromInput) {
        $paramNo++;
        $where[] = 'at.ActionTimestamp >= :p' . $paramNo;
        $params['p' . $paramNo] = date('Y-m-d 00:00:00', strtotime($dateFrom));
    }
    if ($validToInput) {
        $paramNo++;
        $where[] = 'at.ActionTimestamp <= :p' . $paramNo;
        $params['p' . $paramNo] = date('Y-m-d 23:59:59', strtotime($dateTo));
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM audit_trail at
         JOIN users u ON u.UserID = at.UserID
         ' . $whereSql
    );
    $countStmt->execute($params);
    $auditTotal = (int) $countStmt->fetchColumn();

    $auditPageCount = $auditTotal > 0 ? (int) ceil($auditTotal / AUDIT_PAGE_SIZE) : 1;
    $page = min($page, $auditPageCount);
    $offset = ($page - 1) * AUDIT_PAGE_SIZE;

    $listStmt = $pdo->prepare(
        'SELECT at.AuditID, at.UserID, at.Action, at.TableName, at.RecordID,
                at.OldValue, at.NewValue, at.IPAddress, at.ActionTimestamp,
                u.Email AS ActorEmail, u.FirstName AS ActorFirst, u.LastName AS ActorLast
         FROM audit_trail at
         JOIN users u ON u.UserID = at.UserID
         ' . $whereSql . '
         ORDER BY at.ActionTimestamp DESC
         LIMIT ' . AUDIT_PAGE_SIZE . ' OFFSET ' . $offset
    );
    $listStmt->execute($params);
    $auditResults = $listStmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Escape output safely. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$baseFilter = [];
if ($query !== '') $baseFilter['q'] = $query;
if ($auditType !== '') $baseFilter['type'] = $auditType;
if ($dateFrom !== '') $baseFilter['from'] = $dateFrom;
if ($dateTo !== '') $baseFilter['to'] = $dateTo;

function filterQuery(array $base): string
{
    $out = [];
    foreach ($base as $k => $v) {
        $out[] = urlencode($k) . '=' . urlencode($v);
    }
    return implode('&', $out);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Settings — CarePath Admin Portal</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_system_settings.css">
</head>
<body>
<div class="app">

  <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <img src="../assets/images/carepath-icon.png" alt="CarePath">
      </div>
      <div class="brand-text">
        <div class="brand-title">Care<strong>Path</strong></div>
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
      <li class="nav-item active">
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
      <h1>System Settings</h1>
      <p>Manage role-based access control and review audit activity</p>
    </div>

    <?php if ($flash['type'] !== ''): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['text']) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn <?= $activeTab === 'roles' ? 'active' : '' ?>" data-tab="roles">Access Control</button>
      <button class="tab-btn <?= $activeTab === 'audit-logs' ? 'active' : '' ?>" data-tab="audit-logs">Audit Logs</button>
    </div>

    <!-- ================= ACCESS CONTROL TAB ================= -->
    <div class="tab-content <?= $activeTab === 'roles' ? 'active' : '' ?>" id="tab-roles">
      <form method="POST" action="admin_system_settings.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

        <div class="role-access-grid">
          <?php foreach ($roles as $role): ?>
            <?php
              $roleId = (int) $role['RoleID'];
              $roleName = (string) $role['RoleName'];
              $defaults = roleDefaults($roleName);
              $enabledCount = 0;
            ?>
            <div class="role-card">
              <div class="role-header">
                <div class="role-icon"><?= roleIcon($roleName) ?></div>
                <div class="role-info">
                  <div class="role-name"><?= e($roleName) ?></div>
                  <span class="role-badge"><?= count(ROLE_PERMISSION_CATALOG) ?> capabilities</span>
                </div>
              </div>

              <ul class="permission-list">
                <?php $isAdminRole = ($roleName === 'Admin'); ?>
                <?php foreach (ROLE_PERMISSION_CATALOG as $key => $label): ?>
                  <?php
                    $checked = $isAdminRole || isPermissionEnabled($enabledPerRole, $roleId, $key, $roleName);
                    if ($checked) {
                        $enabledCount++;
                    }
                  ?>
                  <li class="permission-item">
                    <label class="permission-toggle">
                      <input type="checkbox"
                             class="perm-checkbox"
                             name="permissions[<?= $roleId ?>][<?= e($key) ?>]"
                             value="1"
                             <?= $checked ? 'checked' : '' ?>
                             <?= $isAdminRole ? 'disabled' : '' ?>>
                      <span class="toggle-track"><span class="toggle-thumb"></span></span>
                      <span class="permission-label"><?= e($label) ?></span>
                    </label>
                  </li>
                <?php endforeach; ?>
              </ul>

              <div class="matrix-actions" style="justify-content:space-between;">
                <span class="audit-summary" style="align-self:center;"><?= $enabledCount ?> of <?= count(ROLE_PERMISSION_CATALOG) ?> enabled</span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="matrix-actions">
          <button type="submit" name="save_permissions" value="1" class="btn-primary-solid">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Changes
          </button>
        </div>
      </form>
    </div>

    <!-- ================= AUDIT LOGS TAB ================= -->
    <div class="tab-content <?= $activeTab === 'audit-logs' ? 'active' : '' ?>" id="tab-audit-logs">
      <div class="audit-logs-container">

        <form method="GET" action="admin_system_settings.php">
          <input type="hidden" name="tab" value="audit-logs" />

          <div class="audit-filters">
            <div class="filter-group">
              <label for="audit-search">Search</label>
              <input type="text" id="audit-search" name="q" value="<?= e($query) ?>"
                     placeholder="Search table, action, value, or email..." maxlength="60">
            </div>

            <div class="filter-group">
              <label for="audit-type">Action Type</label>
              <select id="audit-type" name="type">
                <option value="">All Types</option>
                <?php foreach ($VALID_AUDIT_TYPES as $t): ?>
                  <option value="<?= e($t) ?>" <?= $auditType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-group filter-date">
              <label for="audit-from">From</label>
              <input type="date" id="audit-from" name="from" value="<?= e($dateFrom) ?>">
            </div>

            <div class="filter-group filter-date">
              <label for="audit-to">To</label>
              <input type="date" id="audit-to" name="to" value="<?= e($dateTo) ?>">
            </div>

            <div class="filter-actions">
              <button type="submit" class="btn-primary-solid btn-filter">Apply Filters</button>
              <a href="admin_system_settings.php?tab=audit-logs" class="btn-outline">Reset</a>
            </div>
          </div>
        </form>

        <?php if ($auditTotal > 0): ?>
          <?php
            $first = ($page - 1) * AUDIT_PAGE_SIZE + 1;
            $last = min($page * AUDIT_PAGE_SIZE, $auditTotal);
          ?>
          <div class="audit-summary">Showing <?= $first ?>–<?= $last ?> of <?= $auditTotal ?></div>

          <div class="audit-list">
            <?php foreach ($auditResults as $row): ?>
              <?php
                $actorName = trim((string) ($row['ActorFirst'] ?? '') . ' ' . (string) ($row['ActorLast'] ?? ''));
                $actorLabel = $actorName !== '' ? $actorName . ' (' . e($row['ActorEmail']) . ')' : e($row['ActorEmail']);
                $newValue = (string) ($row['NewValue'] ?? '');
                if ($newValue !== '') {
                    $valueLabel = strlen($newValue) > 120 ? substr($newValue, 0, 117) . '...' : $newValue;
                }
                $ts = strtotime((string) $row['ActionTimestamp']);
                $timeLabel = $ts ? date('M j, Y · g:i A', $ts) : e($row['ActionTimestamp']);
              ?>
              <div class="audit-item">
                <div class="audit-icon"><?= auditActionIcon((string) $row['Action']) ?></div>
                <div class="audit-details">
                  <div class="audit-header">
                    <span class="audit-type"><?= e($row['Action']) ?></span>
                    <span class="audit-user"><?= $actorLabel ?></span>
                  </div>
                  <div class="audit-action">
                    <?= e($row['TableName']) ?>
                    <?= $row['RecordID'] !== null ? '· record #' . (int) $row['RecordID'] : '' ?>
                  </div>
                  <div class="audit-meta">
                    <span class="audit-timestamp"><?= e($timeLabel) ?></span>
                    <?php if (!empty($row['IPAddress'])): ?>
                      <span class="audit-ip">IP <?= e($row['IPAddress']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($valueLabel)): ?>
                      <span class="audit-value"><?= e($valueLabel) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($auditPageCount > 1): ?>
            <div class="pagination">
              <?php if ($page > 1): ?>
                <a class="page-btn" href="admin_system_settings.php?tab=audit-logs&page=<?= $page - 1 ?>&<?= e(filterQuery($baseFilter)) ?>">&larr; Prev</a>
              <?php else: ?>
                <span class="page-btn disabled">&larr; Prev</span>
              <?php endif; ?>
              <span class="page-info">Page <?= $page ?> of <?= $auditPageCount ?></span>
              <?php if ($page < $auditPageCount): ?>
                <a class="page-btn" href="admin_system_settings.php?tab=audit-logs&page=<?= $page + 1 ?>&<?= e(filterQuery($baseFilter)) ?>">Next &rarr;</a>
              <?php else: ?>
                <span class="page-btn disabled">Next &rarr;</span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

        <?php else: ?>
          <div class="empty-state">No audit logs found matching your criteria.</div>
        <?php endif; ?>

      </div>
    </div>

  </main>
</div>

<script>
  // ================= TABS =================
  const tabs = document.querySelectorAll('.tab-btn');
  const contents = {
    'roles': document.getElementById('tab-roles'),
    'audit-logs': document.getElementById('tab-audit-logs')
  };

  function activateTab(name) {
    tabs.forEach(btn => {
      btn.classList.toggle('active', btn.dataset.tab === name);
    });
    for (const key in contents) {
      contents[key].classList.toggle('active', key === name);
    }
    const url = new URL(window.location.href);
    url.searchParams.set('tab', name);
    url.searchParams.delete('page');
    url.searchParams.delete('q');
    url.searchParams.delete('type');
    url.searchParams.delete('from');
    url.searchParams.delete('to');
    window.history.replaceState({}, '', url);
  }

  tabs.forEach(btn => {
    btn.addEventListener('click', () => activateTab(btn.dataset.tab));
  });
</script>
</body>
</html>