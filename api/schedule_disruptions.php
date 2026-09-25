<?php

session_start();
require_once __DIR__ . '/../includes/db_pdo.php';
require_once __DIR__ . '/../includes/schedule_disruption_service.php';

header('Content-Type: application/json; charset=utf-8');

function disruptionResponse(bool $success, string $message = '', array $data = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

$role = (string) ($_SESSION['RoleName'] ?? '');
if (!in_array($role, ['Admin', 'Doctor'], true)) {
    disruptionResponse(false, 'Unauthorized.', [], 401);
}
$csrf = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    disruptionResponse(false, 'Invalid security token. Refresh the page and try again.', [], 419);
}

$pdo = db_pdo();
$action = (string) ($_POST['action'] ?? '');
try {
    if ($action === 'preview') {
        $doctorId = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($role === 'Doctor') {
            $doctorStmt = $pdo->prepare('SELECT StaffID FROM staff WHERE UserID = :user_id AND StaffRole = "Doctor" LIMIT 1');
            $doctorStmt->execute(['user_id' => (int) ($_SESSION['UserID'] ?? 0)]);
            $doctorId = (int) ($doctorStmt->fetchColumn() ?: 0);
            $departmentId = null;
        }
        $appointments = disruptionAffectedAppointments($pdo, $doctorId, $departmentId, (string) $_POST['start_date'], (string) $_POST['end_date']);
        disruptionResponse(true, '', ['appointments' => $appointments]);
    }

    if ($action === 'create') {
        $doctorId = filter_var($_POST['doctor_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        if ($role === 'Doctor') {
            $doctorStmt = $pdo->prepare('SELECT StaffID FROM staff WHERE UserID = :user_id AND StaffRole = "Doctor" LIMIT 1');
            $doctorStmt->execute(['user_id' => (int) ($_SESSION['UserID'] ?? 0)]);
            $doctorId = (int) ($doctorStmt->fetchColumn() ?: 0);
            $departmentId = null;
        }
        $unavailabilityId = createDoctorUnavailability($pdo, $doctorId, $departmentId, (string) $_POST['start_date'], (string) $_POST['end_date'], trim((string) $_POST['reason']), !empty($_POST['is_emergency']), (int) $_SESSION['UserID']);
        $appointments = disruptionAffectedAppointments($pdo, $doctorId, $departmentId, (string) $_POST['start_date'], (string) $_POST['end_date']);
        disruptionResponse(true, 'Disruption saved. Review the affected appointments before sending.', ['unavailability_id' => $unavailabilityId, 'appointments' => $appointments]);
    }

    if ($action === 'execute') {
        $appointmentIds = array_values(array_filter(array_map('intval', (array) ($_POST['appointment_ids'] ?? []))));
        $result = executeDisruption(
            $pdo,
            (int) ($_POST['unavailability_id'] ?? 0),
            $appointmentIds,
            'cancel',
            null,
            null,
            array_values(array_intersect(['in_app', 'email'], (array) ($_POST['channels'] ?? []))),
            trim((string) ($_POST['template'] ?? '')),
            (int) $_SESSION['UserID']
        );
        disruptionResponse(true, 'Appointments updated and notification attempts recorded.', $result);
    }

    disruptionResponse(false, 'Unknown action.', [], 400);
} catch (Throwable $e) {
    error_log('Schedule disruption error: ' . $e->getMessage());
    disruptionResponse(false, $e->getMessage(), [], 422);
}
