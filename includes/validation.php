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
