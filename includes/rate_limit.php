<?php

/**
 * Small database-backed rate limiter.
 *
 * Used to slow down credential stuffing (login) and mass account creation
 * (registration). If the backing table is missing the limiter fails open so
 * it can never take the whole site down.
 */

function rateLimitIdentifier(string $value): string
{
    return substr($value === '' ? 'unknown' : $value, 0, 191);
}

function rateLimitWindowStart(array $row): int
{
    $ts = strtotime((string) ($row['WindowStart'] ?? ''));
    return $ts === false ? 0 : $ts;
}

/** Returns true when the caller is still allowed to try. */
function rateLimitAllowed(
    mysqli $conn,
    string $scope,
    string $identifier,
    int $maxAttempts,
    int $windowSeconds,
    int $lockSeconds = 0
): bool {
    $scope = substr($scope, 0, 100);
    $identifier = rateLimitIdentifier($identifier);

    $stmt = mysqli_prepare(
        $conn,
        'SELECT Attempts, WindowStart, LockedUntil
           FROM auth_rate_limits
          WHERE Scope = ? AND Identifier = ?
          LIMIT 1'
    );
    if (!$stmt) {
        return true;
    }

    mysqli_stmt_bind_param($stmt, 'ss', $scope, $identifier);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return true;
    }

    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        return true;
    }

    $now = time();

    if (!empty($row['LockedUntil']) && strtotime((string) $row['LockedUntil']) > $now) {
        return false;
    }

    if ($windowSeconds > 0 && (rateLimitWindowStart($row) + $windowSeconds) <= $now) {
        return true; // window rolled over
    }

    return (int) ($row['Attempts'] ?? 0) < $maxAttempts;
}

/** Records one attempt and locks the identifier when the quota is reached. */
function rateLimitRecord(
    mysqli $conn,
    string $scope,
    string $identifier,
    int $maxAttempts,
    int $windowSeconds,
    int $lockSeconds = 0
): void {
    $scope = substr($scope, 0, 100);
    $identifier = rateLimitIdentifier($identifier);

    $stmt = mysqli_prepare(
        $conn,
        'SELECT RateLimitID, Attempts, WindowStart FROM auth_rate_limits WHERE Scope = ? AND Identifier = ? LIMIT 1'
    );
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $scope, $identifier);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return;
    }
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $now = time();

    if (!$row) {
        $ins = mysqli_prepare(
            $conn,
            'INSERT INTO auth_rate_limits (Scope, Identifier, Attempts, WindowStart, LockedUntil) VALUES (?, ?, 1, NOW(), NULL)'
        );
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'ss', $scope, $identifier);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
        return;
    }

    $windowExpired = $windowSeconds <= 0
        || (rateLimitWindowStart($row) + $windowSeconds) <= $now;

    $attempts = $windowExpired ? 1 : ((int) $row['Attempts'] + 1);
    $windowStart = $windowExpired
        ? date('Y-m-d H:i:s', $now)
        : (string) $row['WindowStart'];

    $lockedUntil = null;
    if ($lockSeconds > 0 && $attempts >= $maxAttempts) {
        $lockedUntil = date('Y-m-d H:i:s', $now + $lockSeconds);
    }

    $upd = mysqli_prepare(
        $conn,
        'UPDATE auth_rate_limits SET Attempts = ?, WindowStart = ?, LockedUntil = ? WHERE RateLimitID = ?'
    );
    if ($upd) {
        mysqli_stmt_bind_param($upd, 'issi', $attempts, $windowStart, $lockedUntil, $row['RateLimitID']);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);
    }
}

/** Clears the counter (called after a successful, legitimate action). */
function rateLimitClear(mysqli $conn, string $scope, string $identifier): void
{
    $scope = substr($scope, 0, 100);
    $identifier = rateLimitIdentifier($identifier);

    $stmt = mysqli_prepare($conn, 'DELETE FROM auth_rate_limits WHERE Scope = ? AND Identifier = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $scope, $identifier);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
