<?php

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'hoacrms';

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die('Database connection failed.');
}

// Restore PHP 7 style error reporting: mysqli functions return FALSE on
// failure instead of throwing mysqli_sql_exception (PHP 8.1+ default).
mysqli_report(MYSQLI_REPORT_OFF);

mysqli_set_charset($conn, 'utf8mb4');
