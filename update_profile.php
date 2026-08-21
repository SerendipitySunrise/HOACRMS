<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validation.php';

header('Content-Type: application/json');

function respond(bool $success, string $message): void
{
    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);

    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

if (!isset($_SESSION['UserID'])) {
    respond(false, 'Your session has expired. Please log in again.');
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    respond(false, 'Unauthorized access.');
}

$userID = (int) $_SESSION['UserID'];

// Fetch patient record
$patientStmt = mysqli_prepare(
    $conn,
    'SELECT p.PatientID
     FROM patients p
     WHERE p.UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param($patientStmt, 'i', $userID);
mysqli_stmt_execute($patientStmt);
$patientResult = mysqli_stmt_get_result($patientStmt);
$patient = mysqli_fetch_assoc($patientResult);

if (!$patient) {
    respond(false, 'Patient profile not found.');
}

$patientID = (int) $patient['PatientID'];

// Sanitize inputs
$firstName = trim($_POST['FirstName'] ?? '');
$middleName = trim($_POST['MiddleName'] ?? '');
$lastName = trim($_POST['LastName'] ?? '');
$email = trim($_POST['Email'] ?? '');
$sex = trim($_POST['Sex'] ?? '');
$contactNumber = trim($_POST['ContactNumber'] ?? '');
$address = trim($_POST['Address'] ?? '');
$religion = trim($_POST['Religion'] ?? '');
$civilStatus = trim($_POST['CivilStatus'] ?? '');
$dateOfBirth = trim($_POST['DateOfBirth'] ?? '');

// Health info
$bloodType = trim($_POST['BloodType'] ?? '');
$allergies = trim($_POST['Allergies'] ?? '');
$familyMedicalHistory = trim($_POST['FamilyMedicalHistory'] ?? '');
$pastMedicalCondition = trim($_POST['PastMedicalCondition'] ?? '');
$currentMedication = trim($_POST['CurrentMedication'] ?? '');
$emergencyContactName = trim($_POST['EmergencyContactName'] ?? '');
$emergencyContactNo = trim($_POST['EmergencyContactNo'] ?? '');
$emergencyRelation = trim($_POST['EmergencyRelation'] ?? '');

// Validation
$errors = [];

if (empty($firstName)) {
    $errors[] = 'First name is required.';
}

if (empty($lastName)) {
    $errors[] = 'Last name is required.';
}

if (empty($email)) {
    $errors[] = 'Email is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please enter a valid email address.';
}

if (!empty($contactNumber)) {
    $phoneError = validatePhilippinePhone($contactNumber);
    if ($phoneError) {
        $errors[] = $phoneError;
    }
}

if (!empty($dateOfBirth)) {
    $dob = DateTime::createFromFormat('Y-m-d', $dateOfBirth);
    if (!$dob || $dob->format('Y-m-d') !== $dateOfBirth) {
        $errors[] = 'Please enter a valid date of birth.';
    }
}

if (!empty($errors)) {
    respond(false, implode(' ', $errors));
}

// Update users table
$userStmt = mysqli_prepare(
    $conn,
    'UPDATE users
     SET FirstName = ?, MiddleName = ?, LastName = ?, Email = ?,
         Sex = ?, DateOfBirth = ?, ContactNumber = ?, Address = ?
     WHERE UserID = ?'
);

mysqli_stmt_bind_param(
    $userStmt,
    'ssssssssi',
    $firstName,
    $middleName,
    $lastName,
    $email,
    $sex,
    $dateOfBirth,
    $contactNumber,
    $address,
    $userID
);

if (!mysqli_stmt_execute($userStmt)) {
    respond(false, 'Failed to update basic information: ' . mysqli_error($conn));
}

// Update patients table
$patientStmt = mysqli_prepare(
    $conn,
    'UPDATE patients
     SET CivilStatus = ?, Religion = ?, BloodType = ?,
         Allergies = ?, PastMedicalCondition = ?, CurrentMedication = ?,
         FamilyMedicalHistory = ?, EmergencyContactName = ?,
         EmergencyContactNo = ?, EmergencyRelation = ?
     WHERE PatientID = ?'
);

mysqli_stmt_bind_param(
    $patientStmt,
    'ssssssssssi',
    $civilStatus,
    $religion,
    $bloodType,
    $allergies,
    $pastMedicalCondition,
    $currentMedication,
    $familyMedicalHistory,
    $emergencyContactName,
    $emergencyContactNo,
    $emergencyRelation,
    $patientID
);

if (!mysqli_stmt_execute($patientStmt)) {
    respond(false, 'Failed to update health information: ' . mysqli_error($conn));
}

respond(true, 'Profile updated successfully.');
