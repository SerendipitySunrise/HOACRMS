<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/booking_settings_ui.php';
requireRole('Admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$pdo = db_pdo();
$conn = $conn ?? null;
if (!isset($conn) || !$conn) { $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME); }

/* Role access grid */
$rolesStmt = $pdo->query('SELECT RoleID, RoleName FROM roles ORDER BY RoleName');
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);
$enabledPerRole = [];
$permStmt = $pdo->query('SELECT RoleID, PermissionKey, IsEnabled FROM role_permissions WHERE IsEnabled = 1');
foreach ($permStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $enabledPerRole[(int)$row['RoleID']][$row['PermissionKey']] = true;
}
function roleDefaults(string $roleName): array { return ROLE_PERMISSION_DEFAULTS[$roleName] ?? []; }
function isPermissionEnabled(array $enabledMap, int $roleId, string $key, string $roleName): bool {
    if (isset($enabledMap[$roleId][$key])) return (bool)$enabledMap[$roleId][$key];
    return in_array($key, roleDefaults($roleName), true);
}

/* Handle role permissions save */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        $flash = ['type'=>'error','text'=>'Invalid CSRF token.'];
    } else {
        $selected = $_POST['permissions'] ?? [];
        if (!is_array($selected)) $selected = [];
        try {
            $pdo->beginTransaction();
            foreach ($roles as $role) {
                $rid = (int)$role['RoleID'];
                $pdo->prepare('DELETE FROM role_permissions WHERE RoleID = ?')->execute([$rid]);
                foreach (ROLE_PERMISSION_CATALOG as $perm) {
                    $key = $perm['key'];
                    $label = $perm['label'];
                    $enabled = in_array($key, $selected[$rid] ?? [], true) ? 1 : 0;
                    if ($enabled) {
                        $pdo->prepare('INSERT INTO role_permissions (RoleID, PermissionKey, PermissionLabel, IsEnabled, UpdatedBy) VALUES (?, ?, ?, 1, ?)')
                            ->execute([$rid, $key, $label, (int)$_SESSION['UserID']]);
                    }
                }
                $granted = [];
                foreach (ROLE_PERMISSION_CATALOG as $perm) {
                    if (in_array($perm['key'], $selected[$rid] ?? [], true)) $granted[] = $perm['key'];
                }
                logAudit($pdo, 'UPDATE', 'role_permissions', $rid, null, json_encode($granted));
            }
            $pdo->commit();
            header('Location: admin_system_settings.php?saved=1'); exit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $flash = ['type'=>'error','text'=>'Failed to save permissions.'];
        }
    }
}

$activeTab = (string)($_GET['tab'] ?? 'roles');
$activeTab = in_array($activeTab, ['roles','audit-logs','booking'], true) ? $activeTab : 'roles';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>System Settings | Curora Admin</title></head>
<body>
<div>
  <a href="?tab=roles">Roles</a> | <a href="?tab=audit-logs">Audit Logs</a> | <a href="?tab=booking">Booking</a>
</div>
<?php if ($activeTab==='booking'): ?>
  <?php require_once __DIR__.'/../includes/booking_settings_ui.php'; echo bookingSettingsHtml($conn ?? $GLOBALS['conn']); ?>
<?php endif; ?>
</body></html>
