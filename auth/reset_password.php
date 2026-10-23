<?php
/**
 * auth/reset_password.php
 *
 * Consumes a single-use password-reset token e-mailed by
 * auth/forgotpassword.php and lets the owner choose a new password.
 *
 * GET renders the form and never burns the token (so link previews, proxies
 * and refreshes keep it valid). POST consumes the token atomically
 * (UPDATE ... SET UsedAt = NOW() WHERE TokenHash = ? AND UsedAt IS NULL AND
 * ExpiresAt > NOW()); the change only proceeds when exactly one row was
 * updated, so the link is genuinely single-use even under concurrency.
 *
 * Any bad, expired or already-used token shows the same generic message.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/password_reset_tokens.php';
require_once __DIR__ . '/../includes/session_liveness.php';
require_once __DIR__ . '/../includes/audit_helper.php';

// A PHP session backs the CSRF token on this form.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$rawToken = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$tokenRow = $rawToken !== '' ? passwordResetFind($conn, $rawToken) : null;
$tokenValid = $tokenRow !== null;

$message = '';
$messageType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = (string) ($_POST['current_password'] ?? '');
    $new      = (string) ($_POST['new_password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    if (!$tokenValid) {
        $message = 'This password reset link is invalid or has expired. Please request a new one.';
    } elseif (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $message = 'Your form session expired. Please refresh the page and try again.';
    } elseif ($current === '' || $new === '' || $confirm === '') {
        $message = 'Please complete every field.';
    } elseif ($new !== $confirm) {
        $message = 'The new passwords do not match.';
    } elseif ($new === $current) {
        $message = 'Your new password must be different from your current password.';
    } elseif (($pwdError = validateSetupPassword($new)) !== null) {
        $message = $pwdError;
    } else {
        // Load the account and verify the "current password" field really is
        // the account's existing password (defence in depth: the token alone
        // would be enough, but this stops a stolen link from being used
        // without also knowing the old password).
        $userId = (int) $tokenRow['UserID'];
        $stmt = mysqli_prepare(
            $conn,
            'SELECT Password, RoleID FROM users WHERE UserID = ? AND DeletedAt IS NULL LIMIT 1'
        );
        $user = null;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            mysqli_stmt_execute($stmt);
            $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
        }

        if (!$user || !password_verify($current, (string) $user['Password'])) {
            $message = 'Your current password is incorrect.';
        } elseif (!passwordResetConsume($conn, $rawToken)) {
            // The token was used or expired between the GET check and now.
            $message = 'This password reset link is invalid or has expired. Please request a new one.';
        } else {
            $hash = password_hash($new, CURORA_PASSWORD_ALGO, CURORA_PASSWORD_OPTIONS);
            $upd = mysqli_prepare(
                $conn,
                'UPDATE users SET Password = ?, MustChangePassword = 0, TempPasswordExpiresAt = NULL WHERE UserID = ?'
            );
            $ok = false;
            if ($upd) {
                mysqli_stmt_bind_param($upd, 'si', $hash, $userId);
                $ok = mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);
            }

            if ($ok) {
                // The old password is gone: sign out every other device.
                sessionRevokeOthersFor($conn, $userId);

                // A successful change invalidates any other outstanding links.
                passwordResetInvalidateForUser($conn, $userId);

                logAudit(db_pdo(), 'RESET_PASSWORD_SELF', 'users', $userId, null, null);

                header('Location: login.php?reset=1');
                exit();
            }

            $message = 'Unable to update your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose a New Password - Curora</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/auth/forgotpassword.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .main-container { max-width: 480px; margin: 40px auto; padding: 24px; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(15,23,42,.08); }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 6px; font-size: 14px; font-weight: 600; color: #0f172a; }
        .input-wrapper { display: flex; align-items: center; border: 1px solid #cbd5e1; border-radius: 8px; padding: 0 10px; }
        .input-wrapper input { flex: 1; border: none; outline: none; padding: 10px 6px; font-size: 14px; }
        .hint { font-size: 13px; color: #64748b; margin: -8px 0 16px; }
        .message { padding: 10px 12px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
        .message.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .message.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        #reset-btn { width: 100%; padding: 11px; border: none; border-radius: 8px; background: #0d9488; color: #fff; font-weight: 600; cursor: pointer; }
        #reset-btn:hover { background: #0f766e; }
        .invalid-token { text-align: center; padding: 24px 8px; }
        .invalid-token a { color: #0d9488; }
    </style>
</head>
<body>

<div class="main-container">
    <div class="brand-header">
        <div class="logo">
            <img src="../assets/images/curora-logo-full.png" alt="Curora Logo" class="logo-img">
        </div>
        <h1>Choose a New Password</h1>
    </div>

    <?php if (!$tokenValid): ?>
        <div class="invalid-token">
            <div class="message error">
                <i class="fas fa-exclamation-circle"></i>
                This password reset link is invalid or has expired. Please request a new one.
            </div>
            <p><a href="forgotpassword.php">Request a new reset link</a></p>
        </div>
    <?php else: ?>
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo h($message); ?>
            </div>
        <?php endif; ?>

        <form class="form" method="POST" action="" autocomplete="off">
            <?php echo csrfField(); ?>
            <input type="hidden" name="token" value="<?php echo h($rawToken); ?>">

            <div class="form-group">
                <label for="current_password" class="form-label">Current password</label>
                <div class="input-wrapper">
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
            </div>

            <div class="form-group">
                <label for="new_password" class="form-label">New password</label>
                <div class="input-wrapper">
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                </div>
            </div>

            <p class="hint">At least <?php echo (int) ACCOUNT_PASSWORD_MIN_LENGTH; ?> characters with an uppercase letter, a lowercase letter, a number, and a special character.</p>

            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm new password</label>
                <div class="input-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
            </div>

            <button type="submit" id="reset-btn">
                <i class="fas fa-key"></i> Set New Password
            </button>
        </form>
    <?php endif; ?>

    <div class="form-footer">
        <i class="fas fa-chevron-left"></i>
        <a href="login.php" class="signin-link">Remember your password? <strong>Sign in</strong></a>
    </div>
</div>

</body>
</html>
