<?php

require_once __DIR__ . '/includes/session.php';
requireRole('Doctor');

$displayName = htmlspecialchars(trim(($_SESSION['FirstName'] ?? '') . ' ' . ($_SESSION['LastName'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - MediCare</title>
</head>
<body>
    <h1>Staff Dashboard</h1>
    <p>Welcome, <?php echo $displayName; ?>.</p>
    <p>You are signed in as <strong>Staff</strong> (Doctor role in the system).</p>
    <p><a href="logout.php">Logout</a></p>
</body>
</html>
