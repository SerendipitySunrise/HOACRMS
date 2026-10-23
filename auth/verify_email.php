<?php

/**
 * auth/verify_email.php
 *
 * Consumes a one-time patient e-mail verification token. Tokens are stored
 * hashed-by-nothing (random 32-byte hex) with a short expiry and are single
 * use: the column is cleared on success.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/audit_helper.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$message = 'The verification link is invalid or has already been used.';
$messageType = 'error';

$token = trim((string) ($_GET['token'] ?? ''));

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT UserID, Email
           FROM users
          WHERE EmailVerificationToken = ?
            AND EmailVerificationExpiry > NOW()
          LIMIT 1'
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $token);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($row) {
            $upd = mysqli_prepare(
                $conn,
                'UPDATE users
                    SET EmailVerified = 1,
                        EmailVerificationToken = NULL,
                        EmailVerificationExpiry = NULL
                  WHERE UserID = ?'
            );
            if ($upd) {
                mysqli_stmt_bind_param($upd, 'i', $row['UserID']);
                if (mysqli_stmt_execute($upd)) {
                    mysqli_stmt_close($upd);

                    logAudit(db_pdo(), 'VERIFY_EMAIL', 'users', (int) $row['UserID'], null, (string) $row['Email']);

                    $message = 'Your e-mail address has been verified. You can now sign in.';
                    $messageType = 'success';
                } else {
                    mysqli_stmt_close($upd);
                }
            }
        }
    }
}

$safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
$accent = $messageType === 'success' ? '#149385' : '#dc2626';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify e-mail — Curora</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <style>
        body { font-family: Inter, system-ui, -apple-system, sans-serif; background:#f8fafc; margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { background:#fff; border-radius:16px; padding:36px 34px; max-width:460px; width:100%; text-align:center; box-shadow:0 10px 30px rgba(15,23,42,.08); }
        .logo img { height:42px; margin-bottom:18px; }
        h1 { font-size:1.35rem; color:#0f172a; margin:0 0 12px; }
        p.msg { color:<?php echo $accent; ?>; font-weight:600; margin:0 0 20px; }
        a.btn { display:inline-block; background:#149385; color:#fff; text-decoration:none; font-weight:600; padding:12px 26px; border-radius:9px; }
        a.home { display:block; margin-top:18px; color:#64748b; text-decoration:none; font-size:13px; }
    </style>
</head>
<body>
    <main style="max-width:520px;width:100%;">
        <div class="brand-header" style="text-align:center;margin-bottom:18px;">
            <img src="../assets/images/curora-logo-full.png" alt="Curora" style="height:46px;">
        </div>
        <div style="background:#fff;border-radius:14px;padding:26px;box-shadow:0 8px 24px rgba(15,23,42,.06);text-align:center;">
            <p style="font-weight:600;color:<?php echo $messageType === 'success' ? '#0f766e' : '#b91c1c'; ?>;margin:0 0 18px;">
                <?php echo $safeMessage; ?>
            </p>
            <a href="login.php?portal=patient" style="display:inline-block;background:#149385;color:#fff;text-decoration:none;font-weight:600;padding:12px 26px;border-radius:9px;">
                Go to sign in
            </a>
        </div>
    </main>
</body>
</html>
