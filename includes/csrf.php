<?php

/**
 * Minimal CSRF protection.
 *
 * Every state-changing form should render csrfField() and the handler should
 * call csrfValidate()/csrfRequire*() before acting on the request.
 */

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrfValidate(?string $token): bool
{
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Ends the request with HTTP 419 when the token is missing/invalid. */
function csrfRequire(?string $token): void
{
    if (csrfValidate($token)) {
        return;
    }

    http_response_code(419);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Your form session expired. Please refresh the page and try again.',
    ]);
    exit();
}
