<?php

/**
 * auth/totp_verify.php
 *
 * Second step of the Admin sign-in: after the password has been verified,
 * auth/login.php stores a short-lived "totp_pending" session and redirects
 * here. Only a correct 6-digit code finishes the sign-in and creates the
 * authenticated session.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/session_liveness.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$base   = appBasePath();
$toLogin = $base . '/auth/login.php';

$pending = $_SESSION['totp_pending'] ?? null;

// The pending session is short-lived.
if (!is_array($pending) || (time() - (int) ($pending['started_at'] ?? 0)) > 600) {
    unset($_SESSION['totp_pending']);
    header('Location: ' . $toLogin);
    exit();
}

$userId = (int) ($pending['user_id'] ?? 0);

// Load the secret fresh; the account must still be active and enrolled.
$stmt = mysqli_prepare(
    $conn,
    'SELECT u.UserID, u.FirstName, u.LastName, u.Email, u.RoleID, r.RoleName,
            u.Status, u.MustChangePassword, u.SessionVersion, u.TOTPSecret, u.TOTPEnabled
       FROM users u
       INNER JOIN roles r ON r.RoleID = u.RoleID
      WHERE u.UserID = ? AND u.DeletedAt IS NULL
      LIMIT 1'
);
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user || strcasecmp((string) $user['Status'], 'Active') !== 0
    || (int) $pending['session_version'] !== (int) ($user['SessionVersion'] ?? 0)
    || (int) $user['TOTPEnabled'] !== 1
    || trim((string) $user['TOTPSecret']) === '') {
    unset($_SESSION['totp_pending']);
    session_destroy();
    header('Location: ' . $toLogin . '?expired=1');
    exit();
}

$secretVal = trim((string) ($user['TOTPSecret'] ?? ''));

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $code     = (string) ($_POST['code'] ?? '');

    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please refresh the page and try again.';
    } elseif (!rateLimitAllowed($conn, 'totp_verify', $clientIp, RATE_LIMIT_LOGIN_PER_15MIN, 900, 900)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        rateLimitRecord($conn, 'totp_verify', $clientIp, RATE_LIMIT_LOGIN_PER_15MIN, 900, 900);

        if ($secretVal !== '' && totpVerify($secretVal, $code)) {
            $roleName   = (string) ($pending['role_name'] ?? 'Admin');
            $roleKey    = strtolower(preg_replace('/\s+/', '', $roleName) ?? '');
            $sessionVer = (int) ($pending['session_version'] ?? 0);

            // Finalise: rotate the ID and create the real session.
            session_regenerate_id(true);

            $upd = mysqli_prepare($conn, 'UPDATE users SET LastLogin = NOW() WHERE UserID = ?');
            mysqli_stmt_bind_param($upd, 'i', $userId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            rateLimitClear($conn, 'login', $clientIp);
            rateLimitClear($conn, 'totp_verify', $clientIp);

            logAudit(db_pdo(), 'LOGIN_2FA', 'users', $userId, null, $roleName);

            $_SESSION = [];
            $_SESSION['UserID']       = $userId;
            $_SESSION['FirstName']    = (string) ($pending['first_name'] ?? '');
            $_SESSION['LastName']     = (string) ($pending['last_name'] ?? '');
            $_SESSION['RoleID']       = (int) ($pending['role_id'] ?? 1);
            $_SESSION['RoleName']     = $roleName;
            $_SESSION['SessionVersion'] = $sessionVer;
            $_SESSION['LAST_ACTIVITY']  = time();
            $_SESSION['MustChangePassword'] = false;
            $_SESSION['MustSetupTotp']      = false;
            $_SESSION['TOTPVerified']       = true;

            switch ($roleKey) {
                case 'admin':
                    $target = '/admin/admin_dashboard.php';
                    break;
                case 'doctor':
                    $target = '/doctor/doctor_dashboard.php';
                    break;
                case 'staff':
                case 'nurse':
                    $target = '/staff/staff_dashboard.php';
                    break;
                case 'patient':
                    $target = '/patient/patient_dashboard.php';
                    break;
                default:
                    $target = '/index.php';
                    break;
            }

            header('Location: ' . $base . $target);
            exit();
        }

        $error = 'That code is not valid. Check your authenticator app and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Two-factor verification · Curora</title>
<link rel="icon" type="image/png" href="../assets/images/curora-logo-full.png">
<style>
  :root { --brand:#149385; --ink:#0f172a; --muted:#64748b; --line:#cbd5e1; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
         background:#f1f5f9; color:var(--ink); display:flex; min-height:100vh;
         align-items:center; justify-content:center; padding:24px; }
  .card { width:100%; max-width:400px; background:#fff; border:1px solid #e2e8f0;
          border-radius:14px; padding:28px; box-shadow:0 10px 30px rgba(15,23,42,.08); }
  .brand { text-align:center; margin-bottom:12px; }
  .brand img { max-width:140px; height:auto; }
  h1 { font-size:19px; margin:0 0 6px; text-align:center; }
  p.sub { color:var(--muted); font-size:13.5px; text-align:center; margin:0 0 18px; }
  label { display:block; font-size:13px; font-weight:600; margin:4px 0 6px; }
  input { width:100%; padding:12px; border:1px solid var(--line); border-radius:9px;
          font-size:20px; letter-spacing:6px; text-align:center; }
  input:focus { outline:2px solid var(--brand); border-color:var(--brand); }
  .alert { padding:11px 13px; border-radius:9px; font-size:13.5px; margin-bottom:14px;
           background:#fee2e2; color:#991b1b; text-align:center; }
  button { width:100%; margin-top:18px; padding:12px 16px; border:0; border-radius:9px;
           background:var(--brand); color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { background:#0f7c70; }
  .foot { text-align:center; margin-top:16px; }
  .foot a { color:var(--brand); font-size:13px; text-decoration:none; }
</style>
</head>
<body>
<main class="card">
  <div class="brand"><img src="../assets/images/curora-logo-full.png" alt="Curora"></div>
  <h1>Two-factor verification</h1>
  <p class="sub">Enter the 6-digit code from your authenticator app.</p>

  <?php if ($error !== ''): ?>
    <div class="alert"><?php echo h($error); ?></div>
  <?php endif; ?>

  <form method="POST" action="totp_verify.php" autocomplete="off">
    <?php echo csrfField(); ?>
    <label for="code">Verification code</label>
    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}"
           maxlength="6" placeholder="000000" required autofocus>
    <button type="submit">Verify &amp; sign in</button>
  </form>

  <div class="foot"><a href="login.php">Cancel and return to sign in</a></div>
</main>
</body>
</html>
