<?php

/**
 * Session liveness / revocation.
 *
 * PHP cannot sign one user out of every device by itself. Instead we keep a
 * per-account counter (users.SessionVersion). The value is copied into the
 * session at sign-in; when an administrator deactivates an account or resets
 * its password the counter is incremented, so every existing session for that
 * account fails the check on its next request and is destroyed.
 *
 * The helper is deliberately DB-injected (it never opens its own connection)
 * so it can run from both the admin pages (includes/session.php) and the role
 * dashboards (includes/password_gate.php).
 */

/**
 * Application base path, e.g. "/HOACRMS_Lagaban", derived from the running
 * script. Works from the application root and from one or two directory
 * levels deep (e.g. /api/admin/accounts.php).
 */
function appBasePath(): string
{
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

    while ($dir !== '' && preg_match('#/(admin|doctor|patient|staff|auth|api)(/[a-z0-9_]+)?$#i', $dir)) {
        $dir = rtrim(str_replace('\\', '/', dirname($dir)), '/');
    }

    return $dir === '/' ? '' : $dir;
}

/**
 * Why the current session is no longer valid, or null when it is still good.
 * Anonymous visitors return null; authentication is handled by the callers.
 *
 * @return string|null one of 'removed', 'inactive', 'revoked', or null
 */
function sessionLivenessReason(mysqli $conn): ?string
{
    if (empty($_SESSION['UserID'])) {
        return null;
    }

    $stmt = mysqli_prepare($conn, 'SELECT Status, SessionVersion FROM users WHERE UserID = ? LIMIT 1');
    if (!$stmt) {
        // Fail open on a broken query rather than locking every user out.
        return null;
    }

    $userId = (int) $_SESSION['UserID'];
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        return 'removed';
    }
    if (strcasecmp((string) $row['Status'], 'Active') !== 0) {
        return 'inactive';
    }
    if ((int) $row['SessionVersion'] !== (int) ($_SESSION['SessionVersion'] ?? 0)) {
        return 'revoked';
    }

    return null;
}

/** Increments an account's session counter, invalidating its live sessions. */
function sessionRevokeAllFor(mysqli $conn, int $userId): void
{
    $stmt = mysqli_prepare($conn, 'UPDATE users SET SessionVersion = SessionVersion + 1 WHERE UserID = ?');
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Destroys a stale/revoked session and redirects to the sign-in page.
 * Must be called before any output is produced.
 */
function sessionEnforceLiveness(mysqli $conn): void
{
    if (sessionLivenessReason($conn) === null) {
        return;
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    header('Location: ' . appBasePath() . '/auth/login.php?expired=1');
    exit();
}

/**
 * Revokes every *other* session for the account by bumping SessionVersion, then
 * re-syncs the caller's session to the new value. Used after a forced password
 * change so other devices are signed out while the user stays signed in here.
 */
function sessionRevokeOthersFor(mysqli $conn, int $userId): void
{
    $stmt = mysqli_prepare($conn, 'UPDATE users SET SessionVersion = SessionVersion + 1 WHERE UserID = ?');
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $verStmt = mysqli_prepare($conn, 'SELECT SessionVersion FROM users WHERE UserID = ? LIMIT 1');
    if ($verStmt) {
        mysqli_stmt_bind_param($verStmt, 'i', $userId);
        mysqli_stmt_execute($verStmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($verStmt));
        mysqli_stmt_close($verStmt);
        if ($row) {
            $_SESSION['SessionVersion'] = (int) $row['SessionVersion'];
        }
    }
}
