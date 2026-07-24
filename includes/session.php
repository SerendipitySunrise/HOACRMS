<?php
session_start();

if (!isset($_SESSION['UserID'])) {
    header("Location: login.php");
    exit();
}

$timeout = 1800;

if (isset($_SESSION['LAST_ACTIVITY'])) {
    if ((time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {
        session_unset();
        session_destroy();

        header("Location: login.php?expired=1");
        exit();
    }
}

$_SESSION['LAST_ACTIVITY'] = time();
?>
