<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/booking_rules_core.php';
require_once __DIR__ . '/../../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');
function respond(bool $s, string $m, array $e=[]){ echo json_encode(array_merge(['success'=>$s,'message'=>$m],$e)); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST') respond(false,'Invalid');
if (!isset($_SESSION['UserID'])) respond(false,'Session expired');
if (($_SESSION['RoleName']??'')!=='Patient') respond(false,'Unauthorized');
$patientStmt=mysqli_prepare($conn,'SELECT PatientID FROM patients WHERE UserID=? LIMIT 1');
mysqli_stmt_bind_param($patientStmt,'i',$_SESSION['UserID']);
mysqli_stmt_execute($patientStmt);
$r=mysqli_fetch_assoc(mysqli_stmt_get_result($patientStmt));
mysqli_stmt_close($patientStmt);
if(!$r) respond(false,'No patient record');
$patientId=(int)$r['PatientID'];
$dept=(int)($_POST['department_id']??0); $date=trim($_POST['appointment_date']??''); $time=trim($_POST['appointment_time']??'');
if(strpos($time,':')!==false&&strlen($time)===5)$time.=':00';
list($ok,$data)=bookingValidateCore($conn,['patient_id'=>$patientId,'department_id'=>$dept,'appointment_date'=>$date,'appointment_time'=>$time],null,false);
if(!$ok) respond(false,(string)$data);
else respond(true,'OK',['doctor_id'=>$data['doctor_id']]);
