<?php
session_start();

if (!isset($_SESSION['UserID'])) {
    header('Location: portal-select.php?action=login');
    exit();
}

$timeout = 1800;

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header('Location: login.php?expired=1&portal=patient');
    exit();
}

$_SESSION['LAST_ACTIVITY'] = time();

function requireRole(string $expectedRoleName): void
{
    $role = $_SESSION['RoleName'] ?? '';
    if ($role !== $expectedRoleName) {
        header('Location: portal-select.php?action=login');
        exit();
    }
}
