<?php

/**
 * Reads and normalizes system settings for the appointment booking module.
 *
 * All numbers marked (setting) in the rules MUST come from the system_settings
 * table. If a setting is missing (should not happen after the migration), the
 * default in this file is used.
 */

require_once __DIR__ . '/db.php';

function bookingSetting(mysqli $conn, string $key, string $default = ''): string
{
    if ($key === '') {
        return $default;
    }

    $stmt = mysqli_prepare($conn, 'SELECT SettingValue FROM system_settings WHERE SettingKey = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }

    mysqli_stmt_bind_param($stmt, 's', $key);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $default;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if ($row && array_key_exists('SettingValue', $row) && $row['SettingValue'] !== null) {
        return (string) $row['SettingValue'];
    }

    return $default;
}

function bookingSettingInt(mysqli $conn, string $key, int $default = 0): int
{
    $v = bookingSetting($conn, $key, (string) $default);
    if ($v === '') {
        return $default;
    }
    if (!ctype_digit($v) && !is_numeric($v)) {
        return $default;
    }
    $n = (int) $v;
    return $n < 0 ? 0 : $n;
}

function bookingSettingBool(mysqli $conn, string $key, bool $default = false): bool
{
    $v = strtolower(trim(bookingSetting($conn, $key, $default ? '1' : '0')));
    if ($v === '1' || $v === 'true' || $v === 'on' || $v === 'yes') {
        return true;
    }
    if ($v === '0' || $v === 'false' || $v === 'off' || $v === 'no') {
        return false;
    }
    return $default;
}

function bookingAcceptedProofTypes(mysqli $conn): array
{
    $raw = bookingSetting($conn, 'accepted_proof_types', 'Valid ID,Birth certificate,Barangay clearance');
    $parts = preg_split('/[,;\n\r]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $t = trim($p);
        if ($t === '') {
            continue;
        }
        $out[] = $t;
    }
    if (empty($out)) {
        $out[] = 'Valid ID';
    }
    return $out;
}
