<?php

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin_notifications.php';

header('Content-Type: application/json; charset=utf-8');

function adminNotificationJsonResponse(bool $success, string $message = '', array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit;
}

$userId = adminNotificationCurrentUserId();
if ($userId <= 0) {
    adminNotificationJsonResponse(false, 'Unauthorized.', [], 401);
}

$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? (string) ($_GET['action'] ?? 'load')
    : (string) ($_POST['action'] ?? '');

if ($action === 'load') {
    adminNotificationJsonResponse(true, '', [
        'unreadCount' => adminNotificationUnreadCount($conn, $userId),
        'notifications' => adminNotificationRecent($conn, $userId)
    ]);
}

if ($action === 'count') {
    adminNotificationJsonResponse(true, '', [
        'unreadCount' => adminNotificationUnreadCount($conn, $userId)
    ]);
}

if ($action === 'mark_read') {
    $notificationId = (int) ($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        adminNotificationJsonResponse(false, 'Invalid notification.', [], 400);
    }

    if (!adminNotificationMarkRead($conn, $notificationId, $userId)) {
        adminNotificationJsonResponse(false, 'Unable to mark notification as read.', [], 500);
    }

    adminNotificationJsonResponse(true, '', [
        'unreadCount' => adminNotificationUnreadCount($conn, $userId)
    ]);
}

if ($action === 'mark_all_read') {
    if (!adminNotificationMarkAllRead($conn, $userId)) {
        adminNotificationJsonResponse(false, 'Unable to mark notifications as read.', [], 500);
    }

    adminNotificationJsonResponse(true, '', [
        'unreadCount' => adminNotificationUnreadCount($conn, $userId)
    ]);
}

if ($action === 'clear_all') {
    if (!adminNotificationClearAll($conn, $userId)) {
        adminNotificationJsonResponse(false, 'Unable to clear notifications.', [], 500);
    }

    adminNotificationJsonResponse(true, '', [
        'unreadCount' => 0,
        'notifications' => []
    ]);
}

adminNotificationJsonResponse(false, 'Invalid notification action.', [], 400);
