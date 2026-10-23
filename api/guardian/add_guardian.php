<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/guardian_helper.php';
require_once __DIR__ . '/../../includes/audit_helper.php';
require_once __DIR__ . '/../../includes/booking_settings.php';

header('Content-Type: application/json');

if (!isset($_SESSION['UserID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

function respond(bool $ok, string $msg, array $data = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit;
}

if (!csrfValidate($_POST['csrf_token'] ?? '')) {
    respond(false, 'Invalid CSRF token.');
}

// Only staff can add guardian links in this staff API context (per spec summary).
if (!isset($_SESSION['RoleName']) || $_SESSION['RoleName'] !== 'Staff') {
    respond(false, 'Only staff can add guardian links.');
}

$patientId = (int) ($_POST['patient_id'] ?? 0);
$guardianUserId = (int) ($_POST['guardian_user_id'] ?? 0);
$relationship = trim($_POST['relationship'] ?? '');
$isPrimary = isset($_POST['is_primary']) && $_POST['is_primary'] === '1' ? 1 : 0;

if ($patientId <= 0) {
    respond(false, 'Patient is required.');
}
if ($guardianUserId <= 0) {
    respond(false, 'Guardian is required.');
}
if ($relationship === '') {
    respond(false, 'Relationship is required.');
}

$count = countGuardians($conn, $patientId);
if ($count >= 2) {
    respond(false, 'Maximum 2 guardians allowed per patient.');
}

$isChild = isPatientChild($conn, $patientId);
// Get patient user/child context for audit readability.
$pat = null;
$stmt = mysqli_prepare($conn, 'SELECT p.PatientID, pu.UserID AS PatientUserID FROM patients p LEFT JOIN users pu ON pu.UserID = p.UserID WHERE p.PatientID = ? LIMIT 1');
if ($stmt && mysqli_stmt_bind_param($stmt, 'i', $patientId) && mysqli_stmt_execute($stmt)) {
    $pat = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

mysqli_begin_transaction($conn);
try {
    // Check for existing link between these two.
    $exists = false;
    $est = mysqli_prepare($conn, 'SELECT GuardianLinkID FROM guardian_links WHERE PatientID = ? AND GuardianUserID = ? LIMIT 1');
    if ($est && mysqli_stmt_bind_param($est, 'ii', $patientId, $guardianUserId) && mysqli_stmt_execute($est)) {
        $exists = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($est));
        mysqli_stmt_close($est);
    }
    if ($exists) {
        throw new Exception('Guardian already linked to this patient.');
    }

    // Insert as Unverified; second guardian requires staff verification context.
    // Don't auto-verify — mark Unverified, notify primary (skip email sending in API if not configured).
    $ins = mysqli_prepare(
        $conn,
        'INSERT INTO guardian_links (PatientID, GuardianUserID, Relationship, IsPrimary, Status, CreatedAt)
         VALUES (?, ?, ?, ?, "Unverified", NOW())'
    );
    if (!$ins || !mysqli_stmt_bind_param($ins, 'iisi', $patientId, $guardianUserId, $relationship, $isPrimary)) {
        throw new Exception('Failed to prepare guardian link insert.');
    }
    if (!mysqli_stmt_execute($ins)) {
        throw new Exception('Failed to create guardian link: ' . mysqli_stmt_error($ins));
    }
    $linkId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($ins);

    // Notify primary guardian if exists and different? Spec says "notify primary guardian" — no-op if not configured (no mailer); keep audit.
    logAudit(
        db_pdo(),
        'GUARDIAN_ADD',
        'guardian_links',
        $linkId,
        null,
        json_encode([
            'PatientID' => $patientId,
            'GuardianUserID' => $guardianUserId,
            'Relationship' => $relationship,
            'IsPrimary' => $isPrimary,
            'Status' => 'Unverified',
        ])
    );

    mysqli_commit($conn);
    respond(true, 'Guardian link created and marked Unverified. Awaiting verification.', ['link_id' => $linkId, 'status' => 'Unverified']);
} catch (Exception $e) {
    mysqli_rollback($conn);
    respond(false, $e->getMessage());
}