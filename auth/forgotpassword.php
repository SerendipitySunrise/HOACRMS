<?php
/**
 * auth/forgotpassword.php
 *
 * Self-service password reset. Issues a single-use reset LINK by e-mail; the
 * account's current password stays valid until the owner sets a new one on
 * auth/reset_password.php. The response is always the same, so the page cannot
 * be used to discover which e-mail addresses are registered.
 *
 * Administrator-driven resets (api/admin/accounts.php) still e-mail a random
 * temporary password and force a change at next sign-in; this self-service flow
 * deliberately does NOT touch the password so nobody can lock an account by
 * typing its address here.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/password_reset_tokens.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/security_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$message = "";
$messageType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $message = 'Your form session expired. Please refresh the page and try again.';
        $messageType = 'error';
    } elseif (
        !rateLimitAllowed($conn, 'forgot_password_ip', $clientIp, (int) RATE_LIMIT_FORGOT_IP_PER_HOUR, 900, 900)
        || ($email !== '' && !rateLimitAllowed($conn, 'forgot_password_email', $email, (int) RATE_LIMIT_FORGOT_EMAIL_PER_HOUR, 900, 900))
    ) {
        $message = 'Too many requests. Please wait a few minutes and try again.';
        $messageType = 'error';
    } else {
        rateLimitRecord($conn, 'forgot_password_ip', $clientIp, (int) RATE_LIMIT_FORGOT_IP_PER_HOUR, 900, 900);
        if ($email !== '') {
            rateLimitRecord($conn, 'forgot_password_email', $email, (int) RATE_LIMIT_FORGOT_EMAIL_PER_HOUR, 900, 900);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT UserID FROM users
                  WHERE Email = ? AND DeletedAt IS NULL AND Status <> 'Inactive'
                  LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $email);
                mysqli_stmt_execute($stmt);
                $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);

                if ($user) {
                    // Mint a single-use token and e-mail the link. The account's
                    // current password is left untouched.
                    $issued = passwordResetIssue($conn, (int) $user['UserID']);

                    if (!empty($issued['success']) && !empty($issued['url'])) {
                        $resetUrl = (string) $issued['url'];
                        $name = '';
                        $nameStmt = mysqli_prepare(
                            $conn,
                            'SELECT FirstName FROM users WHERE UserID = ? LIMIT 1'
                        );
                        if ($nameStmt) {
                            mysqli_stmt_bind_param($nameStmt, 'i', $user['UserID']);
                            mysqli_stmt_execute($nameStmt);
                            $nameRow = mysqli_fetch_assoc(mysqli_stmt_get_result($nameStmt));
                            mysqli_stmt_close($nameStmt);
                            $name = (string) ($nameRow['FirstName'] ?? '');
                        }

                        $safeName = htmlspecialchars($name !== '' ? $name : $email, ENT_QUOTES, 'UTF-8');
                        $html = '<p>Hi ' . $safeName . ',</p>'
                            . '<p>We received a request to reset your Curora password.</p>'
                            . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">Choose a new password</a></p>'
                            . '<p>This single-use link expires in 1 hour.</p>'
                            . '<p>Your current password stays valid until you choose a new one.</p>'
                            . '<p>If you did not request this, you can safely ignore this message.</p>';

                        sendCuroraEmail($email, 'Reset your Curora password', $html);
                    }

                    logAudit(
                        db_pdo(),
                        'FORGOT_PASSWORD',
                        'users',
                        (int) $user['UserID'],
                        null,
                        json_encode(['email' => $email])
                    );
                }
            }
        }

        // Identical response whether or not the address exists.
        $message = "If the email is registered, a password reset link has been sent.";
        $messageType = "success";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Curora</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/auth/forgotpassword.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="main-container">
    <div class="brand-header">
        <div class="logo">
            <img src="../assets/images/curora-logo-full.png" alt="Curora Logo" class="logo-img">
        </div>
        <h1>Reset Password</h1>
        <p class="subtitle">Enter your email and we'll send you a reset link</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message-container">
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        </div>
    <?php endif; ?>

    <form class="form" method="POST" action="">
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label for="email" class="form-label">Email Address</label>
            <div class="input-wrapper">
                <span class="input-icon-left">
                    <i class="fas fa-envelope"></i>
                </span>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    placeholder="you@example.com" 
                    required
                    autocomplete="email"
                >
            </div>
        </div>

        <button type="submit" id="reset-btn">
            <i class="fas fa-paper-plane"></i> Send Reset Link
        </button>
    </form>

    <div class="form-footer">
        <i class="fas fa-chevron-left"></i>
        <a href="login.php" class="signin-link">Remember your password? <strong>Sign in</strong></a>
    </div>
</div>

<script>
    document.querySelector('.form').addEventListener('submit', function(e) {
        const btn = document.getElementById('reset-btn');
        const originalText = btn.innerHTML;

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        btn.disabled = true;

    });

    document.addEventListener('DOMContentLoaded', function() {
        const successMsg = document.querySelector('.message.success');
        if (successMsg) {
            setTimeout(() => {
                successMsg.style.transition = 'opacity 0.5s ease';
                successMsg.style.opacity = '0';
                setTimeout(() => {
                    successMsg.style.display = 'none';
                }, 500);
            }, 5000);
        }
    });
</script>


<script src="../assets/js/responsive_nav.js"></script>
</body>
</html>
