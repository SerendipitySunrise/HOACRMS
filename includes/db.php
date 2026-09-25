<?php

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
