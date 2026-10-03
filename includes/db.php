<?php

/*
| Clinic timezone.
|
| php.ini pins date.timezone to UTC, but MySQL inherits the host clock
| (@@time_zone = SYSTEM), which is UTC+8 for this clinic. The two therefore
| disagreed by 8 hours, and between 00:00 and 08:00 local time a page using
| date('Y-m-d') would look up "today" as yesterday while CURDATE() in SQL
| already returned the new day. That produced check-ins whose queue.QueueDate
| did not match the appointment's AppointmentDate.
|
| Setting this explicitly makes PHP agree with the database and with the
| clinic's actual local time. It is set in code rather than php.ini on
| purpose so it travels with the repository and survives a redeploy.
|
| Asia/Manila is UTC+8 with no daylight saving, matching the host clock.
*/
if (!ini_get('date.timezone') || strtolower(ini_get('date.timezone')) === 'utc') {
    date_default_timezone_set('Asia/Manila');
}

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'hoacrms';

// Immutable copies of the DB credentials. Some pages reuse the global
// variable names ($password, $username, ...) for unrelated form data,
// which would otherwise corrupt the mysqli/PDO connection settings.
define('DB_HOST', $host);
define('DB_USER', $username);
define('DB_PASS', $password);
define('DB_NAME', $database);

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die('Database connection failed.');
}

// Restore PHP 7 style error reporting: mysqli functions return FALSE on
// failure instead of throwing mysqli_sql_exception (PHP 8.1+ default).
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_set_charset($conn, 'utf8mb4');
