<?php

/**
 * Central security feature flags for Curora.
 *
 * Safe defaults for the outpatient-clinic deployment. Override them in a
 * local, non-committed file if a different policy is required (for example,
 * turning off e-mail verification while demononstrating the system).
 */

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}

/** Patient self-registration must confirm the e-mail before the first login. */
if (!defined('REQUIRE_EMAIL_VERIFICATION')) {
    define('REQUIRE_EMAIL_VERIFICATION', true);
}

/** Lifetime of an e-mail verification link. */
if (!defined('EMAIL_VERIFICATION_TTL_HOURS')) {
    define('EMAIL_VERIFICATION_TTL_HOURS', 48);
}

/**
 * Lifetime of an administrator-issued temporary password. Administrators get a
 * much shorter window than day-to-day staff because their account is the most
 * privileged. After the window passes the temporary password is refused and a
 * fresh one must be issued.
 */
if (!defined('TEMP_PASSWORD_TTL_HOURS_ADMIN')) {
    define('TEMP_PASSWORD_TTL_HOURS_ADMIN', 1);
}
if (!defined('TEMP_PASSWORD_TTL_HOURS_OTHER')) {
    define('TEMP_PASSWORD_TTL_HOURS_OTHER', 24);
}

/**
 * Length of an administrator-issued temporary password. Long enough that it is
 * not worth guessing between issue and first sign-in.
 */
if (!defined('TEMP_PASSWORD_LENGTH')) {
    define('TEMP_PASSWORD_LENGTH', 16);
}

/** Minimum length of the permanent password a user chooses for themselves. */
if (!defined('ACCOUNT_PASSWORD_MIN_LENGTH')) {
    define('ACCOUNT_PASSWORD_MIN_LENGTH', 12);
}


/** Password hashing policy (bcrypt, cost 12). */
if (!defined('CURORA_PASSWORD_ALGO')) {
    define('CURORA_PASSWORD_ALGO', PASSWORD_BCRYPT);
}
if (!defined('CURORA_PASSWORD_OPTIONS')) {
    define('CURORA_PASSWORD_OPTIONS', ['cost' => 12]);
}

/** Account lock-out after repeated failed logins. */
if (!defined('LOGIN_MAX_ATTEMPTS')) {
    define('LOGIN_MAX_ATTEMPTS', 5);
}
if (!defined('LOGIN_LOCK_MINUTES')) {
    define('LOGIN_LOCK_MINUTES', 15);
}

/** Per-IP throttling. */
if (!defined('RATE_LIMIT_LOGIN_PER_15MIN')) {
    define('RATE_LIMIT_LOGIN_PER_15MIN', 20);
}
if (!defined('RATE_LIMIT_REGISTER_PER_HOUR')) {
    define('RATE_LIMIT_REGISTER_PER_HOUR', 10);
}
if (!defined('RATE_LIMIT_SETUP_PER_15MIN')) {
    define('RATE_LIMIT_SETUP_PER_15MIN', 15);
}
if (!defined('RATE_LIMIT_TOTP_PER_15MIN')) {
    define('RATE_LIMIT_TOTP_PER_15MIN', 20);
}

/**
 * Number of one-time recovery codes issued when an administrator finishes
 * two-factor enrolment.
 */
if (!defined('ACCOUNT_RECOVERY_CODE_COUNT')) {
    define('ACCOUNT_RECOVERY_CODE_COUNT', 8);
}

/**
 * Administrator two-factor enforcement.
 *
 * ON by default: every Administrator must enrol an authenticator app. A brand
 * new administrator enrols right after choosing their permanent password; a
 * legacy administrator without a secret is sent to the enrolment page on their
 * next sign-in. The enrolment page stays reachable while the gate is set, so an
 * administrator is never locked out of enabling 2FA. Set
 * CURORA_FORCE_ADMIN_TOTP=0 to relax this temporarily during a migration.
 */
if (!defined('CURORA_FORCE_ADMIN_TOTP')) {
    $force = getenv('CURORA_FORCE_ADMIN_TOTP');
    define(
        'CURORA_FORCE_ADMIN_TOTP',
        $force === false || !in_array(strtolower((string) $force), ['0', 'false', 'no', 'off'], true)
    );
}

/**
 * Reject an e-mail address whose domain publishes no MX record. On by default;
 * set CURORA_EMAIL_MX_CHECK=0 for an isolated/offline install (or a test box
 * with no DNS) where the lookup cannot succeed.
 */
if (!defined('ACCOUNT_EMAIL_MX_CHECK')) {
    $mxCheck = getenv('CURORA_EMAIL_MX_CHECK');
    define(
        'ACCOUNT_EMAIL_MX_CHECK',
        $mxCheck === false || !in_array(strtolower((string) $mxCheck), ['0', 'false', 'no', 'off'], true)
    );
}

/** Per-IP throttle for short activation codes (entered at /auth/activate.php). */
if (!defined('RATE_LIMIT_ACTIVATE_PER_15MIN')) {
    define('RATE_LIMIT_ACTIVATE_PER_15MIN', 20);
}

/**
 * Self-service password-reset throttles. The per-identifier limit stops one
 * mailbox being spammed; the per-IP limit stops an attacker spraying many
 * addresses from a single source.
 */
if (!defined('RATE_LIMIT_FORGOT_EMAIL_PER_HOUR')) {
    define('RATE_LIMIT_FORGOT_EMAIL_PER_HOUR', 3);
}
if (!defined('RATE_LIMIT_FORGOT_IP_PER_HOUR')) {
    define('RATE_LIMIT_FORGOT_IP_PER_HOUR', 10);
}

/**
 * Administrator-driven "Resend temporary password" / "Reset password"
 * throttles. The per-target limit stops an administrator (or a compromised
 * admin session) flooding one mailbox; the per-actor limit stops a spray across
 * many accounts.
 */
if (!defined('RATE_LIMIT_ADMIN_RESEND_PER_ACCOUNT_PER_HOUR')) {
    define('RATE_LIMIT_ADMIN_RESEND_PER_ACCOUNT_PER_HOUR', 5);
}
if (!defined('RATE_LIMIT_ADMIN_RESEND_PER_ACTOR_PER_HOUR')) {
    define('RATE_LIMIT_ADMIN_RESEND_PER_ACTOR_PER_HOUR', 30);
}

/** A short activation code expires after this many minutes. */
if (!defined('ACTIVATION_CODE_TTL_MINUTES')) {
    define('ACTIVATION_CODE_TTL_MINUTES', 20);
}

/** A short activation code is burned after this many wrong attempts. */
if (!defined('ACTIVATION_CODE_MAX_ATTEMPTS')) {
    define('ACTIVATION_CODE_MAX_ATTEMPTS', 5);
}
