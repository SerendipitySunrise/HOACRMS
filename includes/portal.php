<?php

function normalizePortal($portal): ?string
{
    $portal = strtolower(trim((string) $portal));

    $allowed = [
        'patient',
        'staff',
        'doctor',
        'admin'
    ];

    return in_array($portal, $allowed, true)
        ? $portal
        : null;
}

function portalToRoleName(string $portal): string
{
    return match ($portal) {
        'admin' => 'Admin',
        'staff' => 'Staff',
        'doctor' => 'Doctor',
        default => 'Patient',
    };
}

function portalDisplayName(string $portal): string
{
    return match ($portal) {
        'admin' => 'Administrator',
        'staff' => 'Staff',
        'doctor' => 'Doctor',
        default => 'Patient',
    };
}

function portalLoginTitle(string $portal): string
{
    return match ($portal) {
        'admin' => 'Admin sign in',
        'staff' => 'Staff sign in',
        'doctor' => 'Doctor sign in',
        default => 'Patient sign in',
    };
}

function portalRegisterPath(string $portal): string
{
    return match ($portal) {
        'admin' => 'register_admin.php',
        'staff' => 'register_staff.php',
        'doctor' => 'register_doctor.php',
        default => 'signup.php',
    };
}

function registrationInvitationValid(
    string $provided,
    string $expectedKey
): bool {
    return $provided !== ''
        && $expectedKey !== ''
        && hash_equals($expectedKey, $provided);
}