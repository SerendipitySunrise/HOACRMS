<?php

require_once __DIR__ . '/includes/session.php';
requireRole('Patient');

$displayName = htmlspecialchars(trim(($_SESSION['FirstName'] ?? '') . ' ' . ($_SESSION['LastName'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - MediCare</title>
</head>
<body>
    <h1>Patient Dashboard</h1>
    <p>Welcome, <?php echo $displayName; ?>.</p>
    <p>You are signed in as a <strong>Patient</strong>.</p>
    <p><a href="logout.php">Logout</a></p>
</body>
</html>
