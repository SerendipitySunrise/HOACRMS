<?php

/**
 * Very small "common password" denylist.
 *
 * The change-password page (auth/change_password.php) and the self-service
 * reset page (auth/reset_password.php) run this in addition to the
 * length/complexity rules so that a 12-character password like
 * "password1234" cannot be used. The list is deliberately short but covers
 * the passwords that dominate credential-stuffing lists; it can be swapped for
 * a full dictionary without touching the callers.
 */

function commonPasswords(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }

    $list = [
        '123456', '123456789', '12345678', 'password', 'qwerty', '12345',
        '123123', 'abc123', 'football', 'monkey', 'letmein', 'dragon',
        'baseball', 'iloveyou', 'trustno1', 'sunshine', 'master', 'welcome',
        'shadow', 'ashley', 'football1', 'jesus', 'michael', 'ninja',
        'mustang', 'password1', 'password123', 'password1234', 'admin',
        'administrator', 'root', 'toor', 'guest', 'user', 'login',
        'passw0rd', 'p@ssword', 'p@ssw0rd', 'qwerty123', 'qwertyuiop',
        '1q2w3e4r', '1qaz2wsx', 'zaq12wsx', 'asdfghjkl', 'zxcvbnm',
        '0987654321', '0987654321', '121212', '111111', '000000',
        '654321', '666666', '888888', '666666666', '11111111', '00000000',
        'qazwsx', 'zxcvbnm', 'asdf1234', 'asdfasdf', 'asdf1234',
        'qwerty12345', 'password!', 'Password1', 'Password123',
        'Welcome1', 'Welcome123', 'Welcome@123', 'Changeme1', 'ChangeMe123',
        'letmein123', 'welcome1', 'iloveyou1', 'monkey123', 'dragon123',
        'sunshine1', 'princess', 'superman', 'batman', 'starwars',
        'computer', 'internet', 'samsung', 'google', 'facebook',
        'curora', 'curora123', 'hospital', 'clinic', 'doctor', 'doctor123',
        'nurse', 'nurse123', 'patient', 'admin123', 'admin1234',
        'admin12345', 'admin@123', 'Admin@123', 'Admin1234', 'Admin12345',
        'changeme', 'changeme123', 'secret', 'secret123', 'default',
        'temp1234', 'temporary', 'temporary123', 'welcome123', 'welcome1',
        'abcd1234', 'a1b2c3d4', 'aaaa1111', 'zzzz1111', '0000aaaa',
        'iloveyou1', 'princess1', 'sunshine1', 'football1', 'baseball1',
        'starwars1', 'superman1', 'batman1', 'trustno1', 'whatever',
        'qwerty1', 'qwerty12', 'letmein1', 'shadow1', 'master1',
    ];

    return $list;
}

/**
 * True when the (candidate) password is a well-known / trivially guessable one.
 * Comparison is case-insensitive and ignores surrounding whitespace.
 */
function isCommonPassword(string $password): bool
{
    $normalized = strtolower(trim($password));
    if ($normalized === '') {
        return true;
    }

    $set = array_fill_keys(array_map('strtolower', commonPasswords()), true);
    if (isset($set[$normalized])) {
        return true;
    }

    // Also reject when a very common stem is used with a trivial suffix, e.g.
    // "password12345" or "Welcome123!".
    $stem = preg_replace('/[^a-z].*$/', '', $normalized) ?? '';
    $trivial = ['password', 'passw0rd', 'qwerty', 'admin', 'welcome', 'letmein',
        'changeme', 'secret', 'iloveyou', 'monkey', 'dragon', 'master', 'abc'];

    return $stem !== '' && in_array($stem, $trivial, true) && strlen($normalized) <= 16;
}
