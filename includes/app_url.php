<?php

/**
 * Absolute-URL helper.
 *
 * Security: links that are e-mailed to a user (verification, setup, password
 * reset) must never be built from the client-controlled Host header, or an
 * attacker can poison them ("host header injection"). We therefore prefer,
 * in order:
 *
 *   1. CURORA_BASE_URL (an explicit deployment setting), e.g.
 *      "https://curora.example.org";
 *   2. CURORA_ALLOWED_HOSTS (comma separated) validated against HTTP_HOST;
 *   3. the web-server-controlled SERVER_NAME (not attacker controlled);
 *   4. finally HTTP_HOST (local development only).
 *
 * The application base path is always appended so links work both when the app
 * is served from a domain root and from a sub-directory.
 */

require_once __DIR__ . '/session_liveness.php';

/** @return string[] Hosts that may appear in generated links. */
function curoraAllowedHosts(): array
{
    $raw = (string) (getenv('CURORA_ALLOWED_HOSTS') ?: '');
    if ($raw === '') {
        return [];
    }

    $hosts = array_map('trim', explode(',', $raw));
    $hosts = array_map(static fn (string $h): string => strtolower($h), $hosts);

    return array_values(array_filter($hosts, static fn (string $h): bool => $h !== ''));
}

/**
 * The absolute base URL of the application, without a trailing slash.
 * Examples: "https://curora.example.org", "http://localhost/HOACRMS_Lagaban".
 */
function curoraBaseUrl(): string
{
    $configured = trim((string) (getenv('CURORA_BASE_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        ? 'https'
        : 'http';

    $allowed = curoraAllowedHosts();
    $host = '';
    if (!empty($allowed)) {
        $candidate = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (in_array($candidate, $allowed, true)) {
            $host = $candidate;
        } else {
            $host = $allowed[0];
        }
    } else {
        // SERVER_NAME is web-server controlled; HTTP_HOST is client supplied.
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        if (strpos($host, ':') === false && $port > 0 && $port !== 80 && $port !== 443) {
            $host .= ':' . $port;
        }
    }

    return $scheme . '://' . $host . appBasePath();
}

/** Builds an absolute URL for an application-relative path (e.g. "/auth/reset_password.php"). */
function curoraAbsoluteUrl(string $path): string
{
    return curoraBaseUrl() . '/' . ltrim($path, '/');
}
