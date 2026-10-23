<?php

/**
 * auth/change_password.php
 *
 * Password change screen for a signed-in user. It is the forced destination
 * for accounts issued a temporary password (users.MustChangePassword = 1);
 * clearing the flag on success lets the session proceed normally.
 */

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/totp.php';

$userId = (int) ($_SESSION['UserID'] ?? 0);
$roleName = (string) ($_SESSION['RoleName'] ?? '');
$forced = !empty($_SESSION['MustChangePassword']);

$message = '';
$messageType = 'error';

$dashboard = match ($roleName) {
    'Admin' => '../admin/admin_dashboard.php',
    'Doctor' => '../doctor/doctor_dashboard.php',
    'Staff', 'Nurse' => '../staff/staff_dashboard.php',
    default => '../patient/patient_dashboard.php',
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
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
        $stmt = mysqli_prepare(
            $conn,
            'SELECT Password, RoleID, TOTPEnabled, TOTPSecret FROM users WHERE UserID = ? AND DeletedAt IS NULL LIMIT 1'
        );
        $ok = false;
        $freshUser = null;

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            $freshUser = $row ?: null;

            if ($row && password_verify($current, (string) $row['Password'])) {
                $hash = password_hash($new, CURORA_PASSWORD_ALGO, CURORA_PASSWORD_OPTIONS);
                $upd = mysqli_prepare(
                    $conn,
                    'UPDATE users SET Password = ?, MustChangePassword = 0, TempPasswordExpiresAt = NULL WHERE UserID = ?'
                );
                if ($upd) {
                    mysqli_stmt_bind_param($upd, 'si', $hash, $userId);
                    $ok = mysqli_stmt_execute($upd);
                    mysqli_stmt_close($upd);
                }
            } else {
                $message = 'Your current password is incorrect.';
            }
        }

        if ($ok) {
            $_SESSION['MustChangePassword'] = false;

            // The temporary password is gone: sign out every other device so a
            // leaked copy cannot be reused, while this session stays active.
            sessionRevokeOthersFor($conn, $userId);

            logAudit(db_pdo(), 'CHANGE_PASSWORD', 'users', $userId, null, null);

            // An Administrator who has not enrolled an authenticator does so
            // right after their first password change.
            $isAdmin = (int) ($freshUser['RoleID'] ?? 0) === 1;
            $totpEnabled = (int) ($freshUser['TOTPEnabled'] ?? 0) === 1;

            if ($isAdmin && !$totpEnabled) {
                if (trim((string) ($freshUser['TOTPSecret'] ?? '')) === '') {
                    $newSecret = totpGenerateSecret();
                    $secStmt = mysqli_prepare($conn, 'UPDATE users SET TOTPSecret = ? WHERE UserID = ?');
                    if ($secStmt) {
                        mysqli_stmt_bind_param($secStmt, 'si', $newSecret, $userId);
                        mysqli_stmt_execute($secStmt);
                        mysqli_stmt_close($secStmt);
                    }
                }

                $_SESSION['MustSetupTotp'] = true;
                header('Location: setup_totp.php');
                exit();
            }

            header('Location: ' . $dashboard);
            exit();
        } elseif ($message === '') {
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
    <title>Change password — Curora</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/auth/login.css">
</head>
<body>
    <div class="main-container">
        <div class="brand-header">
            <div class="logo"><img src="../assets/images/curora-logo-full.png" alt="Curora Logo" class="logo-img"></div>
            <h1><?php echo $forced ? 'Set a new password' : 'Change password'; ?></h1>
            <p class="subtitle">
                <?php echo $forced
                    ? 'Your account was created with a temporary password. Please choose a new one to continue.'
                    : 'Choose a new password for your account.'; ?>
            </p>
        </div>

        <?php if ($message !== ''): ?>
            <div style="background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:18px;text-align:center;">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form class="form" action="change_password.php" method="POST">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label for="current_password" class="form-label">Current password</label>
                <div class="input-wrapper">
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                </div>
            </div>
            <div class="form-group">
                <label for="new_password" class="form-label">New password</label>
                <div class="input-wrapper">
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                </div>
            </div>
            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm new password</label>
                <div class="input-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                </div>
            </div>
            <p style="font-size:13px;color:#64748b;margin:0 0 16px;">
                At least <?php echo (int) ACCOUNT_PASSWORD_MIN_LENGTH; ?> characters with an uppercase letter, a lowercase letter, a number, and a special character.
            </p>
            <button type="submit">Update password</button>
        </form>
    </div>
</body>
</html>
