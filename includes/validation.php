<?php

function validatePasswordStrength(string $password): ?string
{
    if (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[\W]/', $password)
    ) {
        return 'Password must be at least 8 characters and contain uppercase, lowercase, number, and special character.';
    }

    return null;
}

function validatePhilippinePhone(string $phone): ?string
{
    if (!preg_match('/^09\d{9}$/', $phone)) {
        return 'Please enter a valid Philippine phone number (11 digits, starting with 09).';
    }

    return null;
}

/**
 * Accepts common Philippine mobile-number formats and normalizes them to
 * the canonical 11-digit form "09XXXXXXXXX".
 *
 * Accepted formats:
 *  - 09XXXXXXXXX
 *  - +63XXXXXXXXX (+63 912 345 6789, +639123456789)
 *  - 9XXXXXXXXX (10-digit local mobile)
 *  - digits with spaces, dashes, parentheses (e.g. 0912-345-6789)
 *
 * Returns the normalized "09..." string, or null if the input is not a
 * valid Philippine mobile number.
 */
function normalizePhilippinePhone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if (str_starts_with($digits, '63')) {
        $digits = substr($digits, 2);
    } elseif (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    if (strlen($digits) !== 9) {
        return null;
    }

    if ($digits[0] !== '9') {
        return null;
    }

    return '09' . $digits;
}

/**
 * Validates a date of birth is not in the future and falls within a
 * reasonable age bracket (inclusive of the bounds).
 *
 * @param string $dob  Date in Y-m-d format.
 * @param int    $minAge
 * @param int    $maxAge
 *
 * @return string|null Error message, or null when valid.
 */
function validateAgeInRange(string $dob, int $minAge = 0, int $maxAge = 120): ?string
{
    $dobTs = strtotime($dob);

    if ($dobTs === false) {
        return 'Please enter a valid date of birth.';
    }

    $today = new DateTimeImmutable('today');
    $birth = (new DateTimeImmutable('@' . $dobTs))->setTime(0, 0, 0);

    if ($birth > $today) {
        return 'Date of birth cannot be in the future.';
    }

    $age = $today->diff($birth)->y;

    if ($age < $minAge || $age > $maxAge) {
        return "Patient age must be between {$minAge} and {$maxAge} years.";
    }

    return null;
}

/**
 * Sanitizes a free-text value for safe storage + later output:
 * trims surrounding whitespace, strips HTML/script tags, collapses
 * control characters, and caps the length.
 */
function sanitizeTextInput(string $value, int $maxLen = 500): string
{
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    $value = trim($value);

    if (mb_strlen($value) > $maxLen) {
        $value = mb_substr($value, 0, $maxLen);
    }

    return $value;
}

/**
 * Builds a safe slug from a person's name for use inside a generated
 * email local-part (lowercase alphanumeric + dots).
 */
function sanitizeNameForEmail(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z0-9]+/i', '', $name) ?? '';

    return $name;
}
