<?php

/**
 * Role-based access control helpers.
 *
 * HTML pages use includes/session.php (requireRole) which redirects. API
 * endpoints and staff-creation handlers use these helpers so that a
 * non-administrator receives an explicit HTTP 403 instead of a redirect.
 */

function rbacCurrentRole(): string
{
    return trim((string) ($_SESSION['RoleName'] ?? ''));
}

function rbacIsAuthenticated(): bool
{
    return !empty($_SESSION['UserID']);
}

function rbacIsAdmin(): bool
{
    return rbacIsAuthenticated() && strcasecmp(rbacCurrentRole(), 'Admin') === 0;
}

/** Sends a 403 JSON body and ends the request. */
function rbacForbiddenJson(string $message = 'Forbidden: administrator access required.'): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

function rbacWantsJson(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return stripos($accept, 'application/json') !== false
        || $xhr === 'xmlhttprequest'
        || (($_POST['format'] ?? '') === 'json')
        || (($_SERVER['CONTENT_TYPE'] ?? '') !== '' && stripos((string) $_SERVER['CONTENT_TYPE'], 'application/json') !== false);
}

function rbacRespond(bool $success, string $message, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit();
}
