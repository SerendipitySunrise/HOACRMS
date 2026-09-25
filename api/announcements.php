<?php

session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/audit_helper.php';

header('Content-Type: application/json; charset=UTF-8');

/**
 * Send a JSON response and stop execution.
 */
function respond(bool $success, string $message, ?array $extra = null): void
{
    $payload = ['success' => $success, 'message' => $message];
    if (is_array($extra)) {
        $payload = array_merge($payload, $extra);
    }
    echo json_encode($payload);
    exit();
}

/* ------------------------------------------------------------
   1. AUTHENTICATION (API uses JSON responses, not redirects)
   ------------------------------------------------------------ */

if (!isset($_SESSION['UserID']) || ($_SESSION['RoleName'] ?? '') !== 'Admin') {
    respond(false, 'Unauthorized access.');
}

/* ------------------------------------------------------------
   2. CSRF PROTECTION
   ------------------------------------------------------------ */

$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($csrfToken) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    respond(false, 'Invalid security token. Please refresh the page and try again.');
}

/* ------------------------------------------------------------
   3. ROUTING
   ------------------------------------------------------------ */

$pdo = db_pdo();

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

switch ($action) {
    case 'create':
        createAnnouncement($pdo);
        break;

    case 'update':
        updateAnnouncement($pdo);
        break;

    case 'delete':
        deleteAnnouncement($pdo);
        break;

    default:
        respond(false, 'Unknown action.');
}

/* ------------------------------------------------------------
   4. HANDLERS
   ------------------------------------------------------------ */

function normalizeInput(array $post): array
{
    $priority = strtoupper(trim((string) ($post['priority'] ?? 'MEDIUM')));
    if (!in_array($priority, ANNOUNCEMENT_PRIORITIES, true)) {
        $priority = 'MEDIUM';
    }

    $audience = trim((string) ($post['audience'] ?? 'All Staff'));
    if (!in_array($audience, ANNOUNCEMENT_AUDIENCES, true)) {
        $audience = 'All Staff';
    }

    return [
        'title'    => trim((string) ($post['title'] ?? '')),
        'priority' => $priority,
        'audience' => $audience,
        'content'  => trim((string) ($post['content'] ?? '')),
    ];
}

function validateAnnouncement(array $data, ?string &$error): bool
{
    if ($data['title'] === '') {
        $error = 'Please enter an announcement title.';
        return false;
    }
    if (mb_strlen($data['title']) > 255) {
        $error = 'Announcement title must be 255 characters or fewer.';
        return false;
    }
    if ($data['content'] === '') {
        $error = 'Please enter announcement content.';
        return false;
    }
    return true;
}

function currentAuthorName(): string
{
    $name = trim((string) ($_SESSION['FirstName'] ?? '') . ' ' . (string) ($_SESSION['LastName'] ?? ''));
    return $name !== '' ? $name : 'Administration';
}

function announcementExistsById(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM announcements WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    return (bool) $stmt->fetchColumn();
}

function createAnnouncement(PDO $pdo): void
{
    $data = normalizeInput($_POST);

    $error = '';
    if (!validateAnnouncement($data, $error)) {
        respond(false, $error);
    }

    $createdAt = trim((string) ($_POST['created_at'] ?? ''));
    $dateObject = $createdAt !== '' ? date_create($createdAt) : false;
    $createdAtSql = $dateObject ? $dateObject->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO announcements (title, priority, audience, content, author, created_at)
         VALUES (:title, :priority, :audience, :content, :author, :created_at)'
    );

    $stmt->execute([
        'title'      => $data['title'],
        'priority'   => $data['priority'],
        'audience'   => $data['audience'],
        'content'    => $data['content'],
        'author'     => currentAuthorName(),
        'created_at' => $createdAtSql,
    ]);

    $newId = (int) $pdo->lastInsertId();

    logAudit(
        $pdo,
        'CREATE',
        'announcements',
        $newId,
        null,
        json_encode(['title' => $data['title'], 'audience' => $data['audience'], 'priority' => $data['priority']], JSON_UNESCAPED_SLASHES)
    );

    respond(true, 'Announcement published successfully.', ['id' => $newId]);
}

function updateAnnouncement(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || !announcementExistsById($pdo, $id)) {
        respond(false, 'Announcement not found.');
    }

    $data = normalizeInput($_POST);

    $error = '';
    if (!validateAnnouncement($data, $error)) {
        respond(false, $error);
    }

    $stmt = $pdo->prepare(
        'UPDATE announcements
         SET title = :title, priority = :priority, audience = :audience,
             content = :content
         WHERE id = :id'
    );

    $stmt->execute([
        'title'    => $data['title'],
        'priority' => $data['priority'],
        'audience' => $data['audience'],
        'content'  => $data['content'],
        'id'       => $id,
    ]);

    logAudit(
        $pdo,
        'UPDATE',
        'announcements',
        $id,
        null,
        json_encode(['title' => $data['title'], 'audience' => $data['audience'], 'priority' => $data['priority']], JSON_UNESCAPED_SLASHES)
    );

    respond(true, 'Announcement updated successfully.', ['id' => $id]);
}

function deleteAnnouncement(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0 || !announcementExistsById($pdo, $id)) {
        respond(false, 'Announcement not found.');
    }

    $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = :id');
    $stmt->execute(['id' => $id]);

    logAudit($pdo, 'DELETE', 'announcements', $id, null, null);

    respond(true, 'Announcement deleted successfully.', ['id' => $id]);
}