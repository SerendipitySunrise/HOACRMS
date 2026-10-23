<?php

/**
 * Temporary-password generation.
 *
 * Administrator-issued onboarding and password resets e-mail a random
 * temporary password instead of a single-use setup link. The password is built
 * from a CSPRNG (random_int), never chosen, typed, displayed or logged by an
 * administrator, and must be replaced at first sign-in
 * (users.MustChangePassword = 1).
 *
 * The alphabet deliberately omits visually ambiguous characters (0/O/1/I/l)
 * so a password read from an e-mail cannot be mistyped.
 */

require_once __DIR__ . '/security_config.php';

if (!defined('TEMP_PASSWORD_LENGTH')) {
    define('TEMP_PASSWORD_LENGTH', 16);
}

/** Hashes a temporary password with the application hashing policy. */
function tempPasswordHash(string $password): string
{
    return password_hash($password, CURORA_PASSWORD_ALGO, CURORA_PASSWORD_OPTIONS);
}

/** How long an issued temporary password stays valid, in hours. */
function tempPasswordTtlHours(int $roleId): int
{
    return $roleId === 1
        ? (int) TEMP_PASSWORD_TTL_HOURS_ADMIN
        : (int) TEMP_PASSWORD_TTL_HOURS_OTHER;
}

/**
 * Builds a strong, random, human-transcribable temporary password containing at
 * least one lowercase letter, one uppercase letter, one digit and one symbol.
 */
function generateTemporaryPassword(int $length = 0): string
{
    $length = $length > 0 ? $length : max(16, (int) TEMP_PASSWORD_LENGTH);

    $lower   = 'abcdefghijkmnpqrstuvwxyz';
    $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $symbols = '!@#$%^&*()-_=+';
    $all     = $lower . $upper . $digits . $symbols;

    $chars = [
        $lower[random_int(0, strlen($lower) - 1)],
        $upper[random_int(0, strlen($upper) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    // Fisher–Yates shuffle with the CSPRNG so the guaranteed characters are not
    // always in the first four positions.
    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $chars[$i];
        $chars[$i] = $chars[$j];
        $chars[$j] = $tmp;
    }

    return implode('', $chars);
}
