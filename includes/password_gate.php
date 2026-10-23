<?php

/**
 * Shared gate for role dashboards (Doctor, Staff, Patient).
 *
 * Admin pages get both the anti-clickjacking headers and the session-liveness
 * check automatically from includes/session.php. Doctor / Staff / Patient
 * pages use their own inline auth guards, so they call passwordGateEnforce()
 * right after confirming the session; it adds the same protections plus the
 * forced password change for accounts still carrying a temporary password.
 *
 * Requires $conn (includes/db.php) to be loaded first.
 */

require_once __DIR__ . '/session_liveness.php';

function passwordGateEnforce(): void
{
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('Content-Security-Policy: frame-ancestors \'none\'');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }

    // Sign the user out if the account was deactivated or its password was
    // reset while this session was open.
    global $conn;
    if ($conn instanceof mysqli) {
        sessionEnforceLiveness($conn);
    }

    if (!empty($_SESSION['MustChangePassword'])) {
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'change_password.php') {
            header('Location: ' . appBasePath() . '/auth/change_password.php');
            exit();
        }
    }

    // Administrators who have not confirmed an authenticator must enrol (TOTP)
    // once the temporary password has been replaced.
    if (empty($_SESSION['MustChangePassword']) && !empty($_SESSION['MustSetupTotp'])) {
        if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'setup_totp.php') {
            header('Location: ' . appBasePath() . '/auth/setup_totp.php');
            exit();
        }
    }
}
