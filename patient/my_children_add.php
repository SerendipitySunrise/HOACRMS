<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/guardian_helper.php';
require_once __DIR__ . '/../includes/booking_rules.php';
require_once __DIR__ . '/../includes/audit_helper.php';

requireRole('Patient');
if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: my_children.php'); exit; }
csrfValidate($_POST['csrf_token'] ?? null) or exit('Invalid CSRF');
$userID=(int)$_SESSION['UserID'];

$first=trim($_POST['first_name']??'');
$last=trim($_POST['last_name']??'');
$dob=trim($_POST['date_of_birth']??'');
$rel=trim($_POST['relationship']??'');
$phone=trim($_POST['phone']??'');
$ecname=trim($_POST['emergency_contact_name']??'');
$ecno=trim($_POST['emergency_contact_no']??'');

if($first===''||$last===''||$dob===''||$rel===''||$phone===''||$ecname===''||$ecno==='') { header('Location: my_children.php?err=1'); exit; }
$dt=DateTime::createFromFormat('Y-m-d',$dob);
if(!$dt||$dt->format('Y-m-d')!==$dob) { header('Location: my_children.php?err=2'); exit; }
if(!patientIsMinor($dob)) { header('Location: my_children.php?err=notminor'); exit; }

mysqli_begin_transaction($conn);
try {
    $middle = '';
    $insP=mysqli_prepare($conn,'INSERT INTO patients (FirstName,MiddleName,LastName,DateOfBirth,EmergencyContactName,EmergencyContactNo,Status,CreatedAt) VALUES (?,?,?,?,?,?, "Active", NOW())');
    mysqli_stmt_bind_param($insP,'ssssss',$first,$middle,$last,$dob,$ecname,$ecno);
    if(!mysqli_stmt_execute($insP)) throw new Exception('p');
    $pid=(int)mysqli_insert_id($conn);
    mysqli_stmt_close($insP);

    $maxG=bookingSettingInt($conn,'max_guardians_per_child',2);
    $cnt=countGuardians($conn,$pid);
    if($cnt>=1) { mysqli_rollback($conn); header('Location: my_children.php?err=max'); exit; }

    $insG=mysqli_prepare($conn,'INSERT INTO guardian_links (PatientID,GuardianUserID,Relationship,Phone,IsPrimary,Status,CreatedBy,CreatedAt) VALUES (?,?,?,?,1,"Unverified",?,NOW())');
    mysqli_stmt_bind_param($insG,'iissi',$pid,$userID,$rel,$phone,$userID);
    if(!mysqli_stmt_execute($insG)) throw new Exception('g');
    mysqli_stmt_close($insG);

    mysqli_commit($conn);
    header('Location: my_children.php?ok=1');
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    header('Location: my_children.php?err=3');
    exit;
}
