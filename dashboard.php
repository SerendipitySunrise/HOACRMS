<?php
session_start();

// User must be logged in
if (!isset($_SESSION['UserID'])) {
    header("Location: login.php");
    exit();
}

// 30 minutes = 1800 seconds
$timeout =1800;

// Check inactivity
if (isset($_SESSION['LAST_ACTIVITY'])) {

    if ((time() - $_SESSION['LAST_ACTIVITY']) > $timeout) {

        session_unset();
        session_destroy();

        header("Location: login.php?expired=1");
        exit();
    }
}

// Update last activity
$_SESSION['LAST_ACTIVITY'] = time();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
</head>
<body>
    <h1>Dashboard</h1>
    <p>Welcome to your dashboard!</p>

    <a href="includes/logout.php">Logout</a>
</body>
</html>