<?php
session_start();

$_SESSION_BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

if (!isset($_SESSION['UserID'])) {
    header('Location: ' . $_SESSION_BASE . '/portal-select.php?action=login');
    exit();
}

$timeout = 1800;

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
    session_unset();
    session_destroy();
    header('Location: ' . $_SESSION_BASE . '/auth/login.php?expired=1&portal=patient');
    exit();
}

$_SESSION['LAST_ACTIVITY'] = time();

function requireRole(string $expectedRoleName): void
{
    global $_SESSION_BASE;
    $role = $_SESSION['RoleName'] ?? '';
    if ($role !== $expectedRoleName) {
        header('Location: ' . $_SESSION_BASE . '/portal-select.php?action=login');
        exit();
    }
}
