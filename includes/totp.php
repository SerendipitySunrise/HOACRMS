<?php

/**
 * Minimal RFC 6238 (TOTP) implementation, no external dependency.
 *
 * Used to require a second factor for Admin accounts. Codes are 6 digits,
 * SHA-1, 30-second time step and a +/-1 step drift window - compatible with
 * Google Authenticator, Authy, 1Password, etc.
 */

/** Base32 alphabet used by every authenticator app. */
function totpAlphabet(): string
{
    return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
}

/** Generates a fresh, base32-encoded shared secret (default 160 bits). */
function totpGenerateSecret(int $bytes = 20): string
{
    return totpBase32Encode(random_bytes($bytes));
}

function totpBase32Encode(string $data): string
{
    $map  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len  = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $map[bindec($chunk)];
    }

    return $out;
}

function totpBase32Decode(string $encoded): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $encoded = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $encoded) ?? '');

    $bits = '';
    $len = strlen($encoded);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($map, $encoded[$i]);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }

    return $out;
}

/** The 6-digit code for a given time slice. */
function totpCodeAt(string $secret, int $timeSlice): string
{
    $key  = totpBase32Decode($secret);
    $data = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $data, $key, true);

    $offset = ord($hash[19]) & 0x0f;
    $value  = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);

    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Verifies a submitted code, allowing +/- $window 30-second steps of clock drift. */
function totpVerify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6 || $secret === '') {
        return false;
    }

    $slice = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCodeAt($secret, $slice + $i), $code)) {
            return true;
        }
    }

    return false;
}

/** otpauth:// URI for authenticator apps (manual entry / QR generation). */
function totpProvisioningUri(string $secret, string $account, string $issuer = 'Curora'): string
{
    $label = rawurlencode($issuer . ':' . $account);

    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
