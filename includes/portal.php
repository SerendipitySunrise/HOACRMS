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

/*
|--------------------------------------------------------------------------
| Self-service registration
|--------------------------------------------------------------------------
| The public website may only create PATIENT accounts. Administrator,
| Doctor, and Staff accounts are created by an authenticated administrator
| from Account Management, so the old portal -> registration-path mapping
| and invitation-code helper have been removed.
|
| The only self-service sign-up route is auth/signup.php (Patient role).
*/