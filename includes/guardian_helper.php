<?php

require_once __DIR__ . '/booking_settings.php';
require_once __DIR__ . '/booking_rules.php';
require_once __DIR__ . '/db.php';

function getPrimaryGuardian(mysqli $conn, int $patientId): ?array
{
    $stmt = mysqli_prepare($conn, 'SELECT g.*, u.Email, u.FirstName, u.LastName FROM guardian_links g JOIN users u ON u.UserID = g.GuardianUserID WHERE g.PatientID = ? AND g.IsPrimary = 1 AND g.Status != "Restricted" LIMIT 1');
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 'i', $patientId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row;
}

function countGuardians(mysqli $conn, int $patientId): int
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) as c FROM guardian_links WHERE PatientID = ?');
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'i', $patientId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['c'] ?? 0);
}

function isPatientChild(mysqli $conn, int $patientId): bool
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(u.DateOfBirth, p.DateOfBirth) AS DateOfBirth
         FROM patients p LEFT JOIN users u ON u.UserID = p.UserID
         WHERE p.PatientID = ? LIMIT 1'
    );
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'i', $patientId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return patientIsMinor($row['DateOfBirth'] ?? null);
}

function canGuardianBookForPatient(mysqli $conn, int $patientId, int $guardianUserId): array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT COALESCE(u.DateOfBirth, p.DateOfBirth) AS DateOfBirth
         FROM patients p LEFT JOIN users u ON u.UserID = p.UserID
         WHERE p.PatientID = ? LIMIT 1'
    );
    if (!$stmt) return [true, null];
    mysqli_stmt_bind_param($stmt, 'i', $patientId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $r = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    $dob = $r['DateOfBirth'] ?? null;
    $isMinor = patientIsMinor($dob);

    $link = null;
    $stmt = mysqli_prepare($conn, 'SELECT * FROM guardian_links WHERE PatientID = ? AND GuardianUserID = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $patientId, $guardianUserId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $link = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
    }

    // For adults (18+), guardian booking requires an existing valid link with
    // recorded consent (GuardianConsentAt not NULL) per spec test 10.
    if (!$isMinor) {
        if (!$link) {
            return [false, 'Only a linked guardian can book for this patient.'];
        }
        if (empty($link['GuardianConsentAt'])) {
            return [false, 'Guardian booking requires consent for this adult patient.'];
        }
        if ($link['Status'] === 'Restricted') {
            return [false, 'Guardian access is restricted for this patient.'];
        }
        if ($link['Status'] === 'Unverified') {
            if (!bookingSettingBool($conn, 'unverified_guardians_can_book', true)) {
                return [false, 'Unverified guardian cannot book online. Staff will verify at check-in.'];
            }
        }
        return [true, null];
    }

    // Minors: require linked guardian, not restricted; unverified policy may apply.
    if (!$link) return [false, 'Only a linked guardian can book for this child.'];
    if ($link['Status'] === 'Restricted') return [false, 'Guardian access is restricted for this child.'];
    if ($link['Status'] === 'Unverified') {
        if (!bookingSettingBool($conn, 'unverified_guardians_can_book', true)) {
            return [false, 'Unverified guardian cannot book online. Staff will verify at check-in.'];
        }
    }
    return [true, null];
}
