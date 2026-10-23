<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session_liveness.php';

// Anti-clickjacking / MIME-sniffing protection for every admin page that
// includes this guard.
header('X-Frame-Options: DENY');
header('Content-Security-Policy: frame-ancestors \'none\'');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Protected dashboards live in role subdirectories (for example, /admin).
// Redirects must point to the application root, where auth/login.php lives.
$_SESSION_BASE = appBasePath();

if (!isset($_SESSION['UserID'])) {
    header('Location: ' . $_SESSION_BASE . '/auth/login.php');
    exit();
}

// A deactivated account (or a revoked password reset) must not keep using an
// already-open session.
sessionEnforceLiveness($conn);

$timeout = 1800;

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header('Location: ' . $_SESSION_BASE . '/auth/login.php?expired=1');
    exit();
}

$_SESSION['LAST_ACTIVITY'] = time();

// Accounts issued a temporary password must change it before using the app.
// change_password.php itself is allowed through so the redirect can't loop.
if (!empty($_SESSION['MustChangePassword'])) {
    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($currentScript !== 'change_password.php') {
        header('Location: ' . $_SESSION_BASE . '/auth/change_password.php');
        exit();
    }
}

// Administrators who have not confirmed an authenticator must enrol (TOTP)
// before reaching any dashboard. Enrolment only starts once the temporary
// password has been replaced, and setup_totp.php itself is allowed through so
// the redirect cannot loop.
if (empty($_SESSION['MustChangePassword']) && !empty($_SESSION['MustSetupTotp'])) {
    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($currentScript !== 'setup_totp.php') {
        header('Location: ' . $_SESSION_BASE . '/auth/setup_totp.php');
        exit();
    }
}

function requireRole(string $expectedRoleName): void
{
    global $_SESSION_BASE;
    $role = $_SESSION['RoleName'] ?? '';
    if ($role !== $expectedRoleName) {
        header('Location: ' . $_SESSION_BASE . '/auth/login.php');
        exit();
    }
}
