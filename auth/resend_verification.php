<?php

/**
 * auth/resend_verification.php
 *
 * Lets an unverified patient request a fresh verification e-mail. Responses
 * are deliberately generic so the page cannot be used to enumerate accounts.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/app_url.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$message = '';
$messageType = 'error';
$verificationLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $message = 'Your form session expired. Please refresh the page and try again.';
    } elseif (!rateLimitAllowed($conn, 'resend_verify', $clientIp, 5, 3600, 3600)) {
        $message = 'Too many requests. Please try again later.';
    } else {
        rateLimitRecord($conn, 'resend_verify', $clientIp, 5, 3600, 3600);

        // Always show the same confirmation to avoid account enumeration.
        $message = 'If that e-mail belongs to an unverified patient account, a new verification link has been sent.';
        $messageType = 'success';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = mysqli_prepare(
                $conn,
                'SELECT UserID, FirstName, Email
                   FROM users
                  WHERE Email = ? AND RoleID = 3 AND EmailVerified = 0
                  LIMIT 1'
            );

            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $email);
                mysqli_stmt_execute($stmt);
                $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($row) {
                    $token = bin2hex(random_bytes(32));
                    $expiry = date('Y-m-d H:i:s', time() + EMAIL_VERIFICATION_TTL_HOURS * 3600);

                    $upd = mysqli_prepare(
                        $conn,
                        'UPDATE users
                            SET EmailVerificationToken = ?, EmailVerificationExpiry = ?
                          WHERE UserID = ?'
                    );
                    if ($upd) {
                        mysqli_stmt_bind_param($upd, 'ssi', $token, $expiry, $row['UserID']);
                        mysqli_stmt_execute($upd);
                        mysqli_stmt_close($upd);
                    }

                    // Build from the configured base URL, never the client Host.
                    $verifyUrl = curoraAbsoluteUrl('auth/verify_email.php')
                        . '?token=' . urlencode($token);

                    $html = '<p>Hi ' . htmlspecialchars((string) $row['FirstName'], ENT_QUOTES, 'UTF-8') . ',</p>'
                        . '<p>Here is a fresh link to verify your Curora patient account:</p>'
                        . '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">Verify my e-mail address</a></p>'
                        . '<p>This link expires in ' . (int) EMAIL_VERIFICATION_TTL_HOURS . ' hours.</p>';

                    if (function_exists('sendCuroraEmail')) {
                        sendCuroraEmail((string) $row['Email'], 'Verify your Curora patient account', $html);
                    }

                    // In development (no SMTP configured) surface the link so
                    // the flow is testable without a mail server.
                    if (curoraMailIsDryRun()) {
                        $verificationLink = $verifyUrl;
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend verification — Curora</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/auth/login.css">
</head>
<body>
    <div class="main-container">
        <div class="brand-header">
            <div class="logo"><img src="../assets/images/curora-logo-full.png" alt="Curora Logo" class="logo-img"></div>
            <h1>Resend verification</h1>
            <p class="subtitle">Enter your e-mail and we will send a new verification link.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div style="background:<?php echo $messageType === 'success' ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $messageType === 'success' ? '#166534' : '#991b1b'; ?>;padding:12px;border-radius:8px;margin-bottom:18px;text-align:center;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($verificationLink !== ''): ?>
                    <br><br>
                    <a href="<?php echo htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8'); ?>" style="color:#166534;font-weight:600;word-break:break-all;">Verify my e-mail now</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form class="form" action="resend_verification.php" method="POST">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-wrapper">
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                </div>
            </div>
            <button type="submit">Send verification link</button>
            <div class="form-footer">
                <a href="login.php?portal=patient" class="signup-link">Back to sign in</a>
            </div>
        </form>
    </div>
</body>
</html>
