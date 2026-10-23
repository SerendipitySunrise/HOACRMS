<?php

/**
 * auth/setup_totp.php
 *
 * Mandatory two-factor enrolment. An Administrator who has just replaced a
 * temporary password (or a legacy administrator with no authenticator) lands
 * here and must confirm a 6-digit code from an authenticator app before
 * reaching any dashboard. The guard redirects every other request here while
 * $_SESSION['MustSetupTotp'] is set (see includes/session.php).
 *
 * The secret is displayed as text (RFC 6238 / base32) for manual entry, so the
 * page needs no third-party QR image service or JavaScript library.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/rate_limit.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$userId = (int) ($_SESSION['UserID'] ?? 0);

// Only administrators enrol here.
if (($_SESSION['RoleName'] ?? '') !== 'Admin') {
    header('Location: ' . $_SESSION_BASE . '/auth/login.php');
    exit();
}

// Load the account.
$stmt = mysqli_prepare($conn, 'SELECT Email, TOTPSecret, TOTPEnabled FROM users WHERE UserID = ? AND DeletedAt IS NULL LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $_SESSION_BASE . '/auth/login.php');
    exit();
}

// Already enrolled: nothing to do here.
if ((int) $user['TOTPEnabled'] === 1) {
    unset($_SESSION['MustSetupTotp']);
    header('Location: ' . $_SESSION_BASE . '/admin/admin_dashboard.php');
    exit();
}

$secret = trim((string) ($user['TOTPSecret'] ?? ''));
if ($secret === '') {
    // Provision a secret on the fly (covers accounts captured by the
    // CURORA_FORCE_ADMIN_TOTP policy that had none).
    $secret = totpGenerateSecret();
    $upd = mysqli_prepare($conn, 'UPDATE users SET TOTPSecret = ?, TOTPEnabled = 0 WHERE UserID = ?');
    mysqli_stmt_bind_param($upd, 'si', $secret, $userId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
}

$issuer  = 'Curora';
$account = (string) $user['Email'];
$uri     = totpProvisioningUri($secret, $account, $issuer);

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $code     = (string) ($_POST['code'] ?? '');

    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Please refresh the page and try again.';
    } elseif (!rateLimitAllowed($conn, 'totp_setup', $clientIp, RATE_LIMIT_LOGIN_PER_15MIN, 900, 900)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        rateLimitRecord($conn, 'totp_setup', $clientIp, RATE_LIMIT_LOGIN_PER_15MIN, 900, 900);

        if (totpVerify($secret, $code)) {
            $upd = mysqli_prepare($conn, 'UPDATE users SET TOTPEnabled = 1 WHERE UserID = ?');
            mysqli_stmt_bind_param($upd, 'i', $userId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            unset($_SESSION['MustSetupTotp']);
            $_SESSION['TOTPVerified'] = true;

            logAudit(db_pdo(), 'ENABLE_2FA', 'users', $userId, null, json_encode(['method' => 'totp']));

            header('Location: ' . $_SESSION_BASE . '/admin/admin_dashboard.php');
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
<title>Set up two-factor authentication · Curora</title>
<link rel="icon" type="image/png" href="../assets/images/curora-logo-full.png">
<style>
  :root { --brand:#149385; --ink:#0f172a; --muted:#64748b; --line:#cbd5e1; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
         background:#f1f5f9; color:var(--ink); display:flex; min-height:100vh;
         align-items:center; justify-content:center; padding:24px; }
  .card { width:100%; max-width:520px; background:#fff; border:1px solid #e2e8f0;
          border-radius:14px; padding:28px; box-shadow:0 10px 30px rgba(15,23,42,.08); }
  h1 { font-size:20px; margin:0 0 8px; }
  p { color:var(--muted); font-size:14px; line-height:1.5; }
  .secret { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:16px; letter-spacing:2px;
            background:#0f172a; color:#e2e8f0; padding:12px 14px; border-radius:9px;
            text-align:center; word-break:break-all; margin:12px 0; }
  .uri { font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11.5px; color:#334155;
         background:#f8fafc; border:1px dashed var(--line); border-radius:9px; padding:10px;
         word-break:break-all; }
  label { display:block; font-size:13px; font-weight:600; margin:18px 0 6px; }
  input[type=text] { width:100%; padding:12px; border:1px solid var(--line); border-radius:9px;
            font-size:20px; letter-spacing:6px; text-align:center; }
  input[type=text]:focus { outline:2px solid var(--brand); border-color:var(--brand); }
  .alert-error { background:#fee2e2; color:#991b1b; padding:12px 14px; border-radius:9px;
                 font-size:14px; margin-bottom:12px; }
  button { width:100%; margin-top:20px; padding:12px 16px; border:0; border-radius:9px;
           background:var(--brand); color:#fff; font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { background:#0f7c70; }
  .foot { text-align:center; margin-top:18px; }
  .foot a { color:var(--brand); font-size:13px; text-decoration:none; }
</style>
</head>
<body>
<main class="card">
  <h1>Secure your administrator account</h1>
  <p>Administrator accounts require two-factor authentication. Add Curora to an
     authenticator app (Google Authenticator, Microsoft Authenticator, Authy, …),
     then enter the 6-digit code it shows.</p>

  <ol style="color:var(--muted); font-size:14px; line-height:1.6; padding-left:20px;">
    <li>Open your authenticator app and choose “Add account”.</li>
    <li>Enter the setup key below (choose “time based” if asked).</li>
    <li>Enter the current 6-digit code.</li>
  </ol>

  <div class="secret"><?php echo h($secret); ?></div>
  <p style="font-size:12px; margin-top:0;">Setup key for <strong><?php echo h($account); ?></strong> (issuer <?php echo h($issuer); ?>)</p>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo h($error); ?></div>
  <?php endif; ?>

  <form method="POST" action="setup_totp.php" autocomplete="off">
    <?php echo csrfField(); ?>
    <label for="code">Verification code</label>
    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}"
           maxlength="6" placeholder="000000" required autofocus>
    <button type="submit">Enable two-factor authentication</button>
  </form>

  <details style="margin-top:16px;">
    <summary style="cursor:pointer; color:var(--muted); font-size:12.5px;">Can’t scan / advanced</summary>
    <p class="uri"><?php echo h($uri); ?></p>
  </details>

  <div class="foot"><a href="logout.php">Sign out</a></div>
</main>
</body>
</html>
