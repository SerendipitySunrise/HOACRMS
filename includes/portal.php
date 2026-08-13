<?php

function normalizePortal(?string $portal): ?string
{
    $portal = strtolower(trim((string) $portal));
    $allowed = ['patient', 'staff', 'admin'];

    return in_array($portal, $allowed, true) ? $portal : null;
}

function portalToRoleName(string $portal): string
{
    return match ($portal) {
        'admin' => 'Admin',
        'staff' => 'Doctor',
        default => 'Patient',
    };
}

function portalDisplayName(string $portal): string
{
    return match ($portal) {
        'admin' => 'Administrator',
        'staff' => 'Staff',
        default => 'Patient',
    };
}

function portalLoginTitle(string $portal): string
{
    return match ($portal) {
        'admin' => 'Admin sign in',
        'staff' => 'Staff sign in',
        default => 'Patient sign in',
    };
}

function portalRegisterPath(string $portal): string
{
    return match ($portal) {
        'admin' => 'register_admin.php',
        'staff' => 'register_staff.php',
        default => 'signup.php',
    };
}

function registrationInvitationValid(string $provided, string $expectedKey): bool
{
    return is_string($provided) && $provided !== '' && hash_equals($expectedKey, $provided);
}
