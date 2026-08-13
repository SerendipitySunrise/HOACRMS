<?php

require_once __DIR__ . '/includes/session.php';

$role = $_SESSION['RoleName'] ?? '';

switch ($role) {
    case 'Admin':
        header('Location: admin_dashboard.php');
        break;
    case 'Doctor':
        header('Location: staff_dashboard.php');
        break;
    case 'Patient':
        header('Location: patient_dashboard.php');
        break;
    default:
        header('Location: login.php');
        break;
}
<<<<<<< HEAD

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
=======
exit;
>>>>>>> 09fb0676c4a704c23e037e9d6e4464d2bf05591c
