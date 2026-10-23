<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';

requireRole('Admin');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Hospital Holidays | Curora Admin</title></head>
<body><h2>Hospital Holidays</h2>
<form method="POST" action="admin_holidays_save.php">
  <?php echo csrfField(); ?>
  <div>Date <input type="date" name="holiday_date"></div>
  <div>Name <input name="name"></div>
  <button type="submit">Add Holiday</button>
</form>
</body></html>
