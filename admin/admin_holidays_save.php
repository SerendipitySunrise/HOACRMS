<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_helper.php';

requireRole('Admin');
if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: admin_holidays.php'); exit; }
csrfValidate($_POST['csrf_token'] ?? null) or exit('Invalid CSRF');
$date=trim($_POST['holiday_date']??''); $name=trim($_POST['name']??'');
if($date===''||$name==='') { header('Location: admin_holidays.php?err=1'); exit; }
$dt=DateTime::createFromFormat('Y-m-d',$date);
if(!$dt||$dt->format('Y-m-d')!==$date) { header('Location: admin_holidays.php?err=2'); exit; }
$stmt=mysqli_prepare($conn,'INSERT IGNORE INTO hospital_holidays (HolidayDate,Name,CreatedBy) VALUES (?,?,?)');
mysqli_stmt_bind_param($stmt,'ssi',$date,$name,$_SESSION['UserID']);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
header('Location: admin_holidays.php?ok=1'); exit;
