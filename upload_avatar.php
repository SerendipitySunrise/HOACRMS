<?php
session_start();

require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

function respond(bool $success, string $message, string $url = ''): void
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'url'     => $url
    ]);

    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

if (!isset($_SESSION['UserID'])) {
    respond(false, 'Your session has expired. Please log in again.');
}

$userID = (int) $_SESSION['UserID'];

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $_FILES['avatar']['error'] ?? -1;
    if ($errorCode === UPLOAD_ERR_INI_SIZE || $errorCode === UPLOAD_ERR_FORM_SIZE) {
        respond(false, 'File is too large. Maximum size is 2MB.');
    } elseif ($errorCode === UPLOAD_ERR_NO_FILE) {
        respond(false, 'No file was uploaded.');
    } else {
        respond(false, 'Upload error (code: ' . $errorCode . ').');
    }
}

$file = $_FILES['avatar'];

// Validate MIME type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes, true)) {
    respond(false, 'Only JPG, PNG, GIF, and WebP images are allowed.');
}

// Validate file size (max 2MB)
if ($file['size'] > 2 * 1024 * 1024) {
    respond(false, 'Image must be 2MB or smaller.');
}

// Generate unique filename
$ext = match($mimeType) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    default      => 'jpg'
};

$filename = 'avatar_' . $userID . '_' . time() . '.' . $ext;
$uploadDir = __DIR__ . '/avatars';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$destination = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    respond(false, 'Failed to save the uploaded file.');
}

// Delete old avatar if exists
$checkStmt = mysqli_prepare($conn, 'SELECT ProfilePhoto FROM users WHERE UserID = ?');
mysqli_stmt_bind_param($checkStmt, 'i', $userID);
mysqli_stmt_execute($checkStmt);
$checkResult = mysqli_stmt_get_result($checkStmt);
$row = mysqli_fetch_assoc($checkResult);
mysqli_stmt_close($checkStmt);

if ($row && !empty($row['ProfilePhoto'])) {
    $oldFile = __DIR__ . '/' . $row['ProfilePhoto'];
    if (file_exists($oldFile)) {
        unlink($oldFile);
    }
}

// Update database
$photoPath = 'avatars/' . $filename;

$updateStmt = mysqli_prepare($conn, 'UPDATE users SET ProfilePhoto = ? WHERE UserID = ?');
mysqli_stmt_bind_param($updateStmt, 'si', $photoPath, $userID);

if (mysqli_stmt_execute($updateStmt)) {
    $_SESSION['ProfilePhoto'] = $photoPath;
    respond(true, 'Profile photo updated successfully.', $photoPath);
} else {
    respond(false, 'Failed to update profile photo in database.');
}
