<?php

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'hoacrms';

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die('Database connection failed.');
}

mysqli_set_charset($conn, 'utf8mb4');
