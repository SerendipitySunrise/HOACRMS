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
exit;
