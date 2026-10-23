<?php

/**
 * Self-service password-reset tokens.
 *
 * Replaces the retired setup-link flow. A token is a random 32-byte value
 * generated with random_int() (via random_bytes) and e-mailed to the account
 * owner; only its SHA-256 hash is stored, so a database dump never reveals a
 * usable link. The token expires after one hour and can be consumed exactly
 * once. The account's current password stays valid until the owner sets a new
 * one, so a third party who merely knows an e-mail address cannot lock the
 * account out.
 *
 * Backed by the account_setup_tokens table (repurposed for reset-only use).
 * The Kind column is set to 'reset' so any legacy setup-link rows can never
 * match, and CreatedBy is set to the account owner for self-service requests.
 */

require_once __DIR__ . '/security_config.php';
require_once __DIR__ . '/session_liveness.php';

/** TTL of a self-service password-reset link, in seconds (one hour). */
if (!defined('PASSWORD_RESET_TTL_SECONDS')) {
    define('PASSWORD_RESET_TTL_SECONDS', 3600);
}

/**
 * Issues a single-use password-reset token for an account. Any previous unused
 * tokens for the same account are invalidated first, so only the newest link
 * works.
 *
 * @return array{success:bool,message:string,token?:string,url?:string}
 */
function passwordResetIssue(mysqli $conn, int $userId): array
{
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'Account not found.'];
    }

    // The account must still be usable; never mint a token for a deleted or
    // deactivated account.
    $stmt = mysqli_prepare(
        $conn,
        "SELECT UserID FROM users
          WHERE UserID = ? AND DeletedAt IS NULL AND Status <> 'Inactive'
          LIMIT 1"
    );
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to prepare the password reset.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);
    if (!$exists) {
        return ['success' => false, 'message' => 'Account not found.'];
    }

    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $ttl   = max(300, (int) PASSWORD_RESET_TTL_SECONDS);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    // Only the newest link is valid.
    passwordResetInvalidateForUser($conn, $userId);

    $ins = mysqli_prepare(
        $conn,
        "INSERT INTO account_setup_tokens (UserID, TokenHash, Kind, ExpiresAt, UsedAt, CreatedBy)
         VALUES (?, ?, 'reset', ?, NULL, ?)"
    );
    if (!$ins) {
        return ['success' => false, 'message' => 'Unable to prepare the password reset.'];
    }
    mysqli_stmt_bind_param($ins, 'issi', $userId, $hash, $expiresAt, $userId);
    $ok = mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
    if (!$ok) {
        return ['success' => false, 'message' => 'Unable to issue a password reset.'];
    }

    return [
        'success' => true,
        'message' => 'Password reset issued.',
        'token'   => $token,
        'url'     => passwordResetUrl($token),
    ];
}

/**
 * Validates a raw reset token and returns the row (without the hash) when it
 * is still valid (unused, unexpired). Returns null otherwise.
 */
function passwordResetFind(mysqli $conn, string $rawToken): ?array
{
    if (!passwordResetTokenLooksValid($rawToken)) {
        return null;
    }

    $hash = hash('sha256', $rawToken);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT TokenID, UserID, ExpiresAt, UsedAt
           FROM account_setup_tokens
          WHERE TokenHash = ? AND Kind = 'reset'
          LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        return null;
    }
    if (!empty($row['UsedAt'])) {
        return null;
    }
    $expiresAt = strtotime((string) $row['ExpiresAt']);
    if ($expiresAt === false || $expiresAt < time()) {
        return null;
    }

    return $row;
}

/**
 * Atomically consumes a reset token. Returns true only when exactly one row
 * was updated (the token existed, was still unused and had not expired). This
 * single UPDATE ... WHERE UsedAt IS NULL AND ExpiresAt > NOW() guarantees the
 * token cannot be replayed, even by two concurrent requests.
 */
function passwordResetConsume(mysqli $conn, string $rawToken): bool
{
    if (!passwordResetTokenLooksValid($rawToken)) {
        return false;
    }

    $hash = hash('sha256', $rawToken);
    $stmt = mysqli_prepare(
        $conn,
        "UPDATE account_setup_tokens
            SET UsedAt = NOW()
          WHERE TokenHash = ?
            AND Kind = 'reset'
            AND UsedAt IS NULL
            AND ExpiresAt > NOW()"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $consumed = mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);

    return $consumed;
}

/** Removes any outstanding reset tokens for an account (after a change). */
function passwordResetInvalidateForUser(mysqli $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM account_setup_tokens WHERE UserID = ? AND Kind = 'reset'"
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/** Builds the absolute URL for the reset page. */
function passwordResetUrl(string $rawToken): string
{
    // appBasePath() is web-server controlled, so this never trusts Host.
    $base = function_exists('appBasePath') ? appBasePath() : '';
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        ? 'https'
        : 'http';
    $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    if (strpos($host, ':') === false && $port > 0 && $port !== 80 && $port !== 443) {
        $host .= ':' . $port;
    }

    return $scheme . '://' . $host . $base . '/auth/reset_password.php?token=' . urlencode($rawToken);
}

/** Cheap shape check before hitting the database. */
function passwordResetTokenLooksValid(string $rawToken): bool
{
    return $rawToken !== '' && preg_match('/^[a-f0-9]{64}$/', $rawToken) === 1;
}
