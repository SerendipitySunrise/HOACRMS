<?php
session_start();

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

function respond(bool $success, string $message): void
{
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);

    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

if (!isset($_SESSION['UserID'])) {
    respond(false, 'Your session has expired. Please log in again.');
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    respond(false, 'Unauthorized access.');
}

$userID = (int) $_SESSION['UserID'];

$receiveReminders = isset($_POST['ReceiveReminders']) ? 1 : 0;

$preference = trim($_POST['ReminderPreference'] ?? '');

if (!in_array($preference, ['email', 'push'], true)) {
    $preference = 'email';
}

$stmt = mysqli_prepare(
    $conn,
    'UPDATE users
     SET ReminderPreference = ?, ReceiveReminders = ?
     WHERE UserID = ?'
);

if (!$stmt) {
    respond(false, 'Unable to update reminder preferences.');
}

mysqli_stmt_bind_param($stmt, 'sii', $preference, $receiveReminders, $userID);

if (!mysqli_stmt_execute($stmt)) {
    respond(false, 'Failed to update reminder preferences.');
}

mysqli_stmt_close($stmt);

respond(true, 'Reminder preferences updated successfully.');