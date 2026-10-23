<?php

/**
 * api/admin/accounts.php
 *
 * Administrator-only endpoint for staff-level account management
 * (create / update / toggle / resend-password / reset-password / delete).
 *
 * Security:
 *   - RBAC: any non-Admin caller receives HTTP 403 (not a redirect).
 *   - CSRF: every state-changing request must carry a valid token.
 *   - Re-authentication: creating another Admin, resetting another account's
 *     password or deleting an account requires the acting admin's current
 *     password.
 *   - Role whitelist: the role is validated server-side; the public
 *     registration form can never reach this endpoint.
 *
 * No password is ever returned to the caller: issued temporary passwords are
 * e-mailed to the account owner only.
 *
 * The admin UI (admin/admin_account_management.php) submits normal forms to
 * this endpoint and is redirected back with a one-time session flash. Machine
 * callers can request JSON with "Accept: application/json" or "format=json".
 */

session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/security_config.php';
require_once __DIR__ . '/../../includes/admin_notifications.php';
require_once __DIR__ . '/../../includes/account_service.php';
require_once __DIR__ . '/../../includes/auth_rbac.php';
require_once __DIR__ . '/../../includes/session_liveness.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/rate_limit.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ---- 1. Method guard ---------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rbacRespond(false, 'Method not allowed.', [], 405);
}

/* ---- 2. RBAC middleware: 403 for non-admins ----------------------------- */
if (!rbacIsAdmin()) {
    rbacForbiddenJson();
}

/* ---- 2b. The session must still be live (not deactivated/revoked) ------- */
if (sessionLivenessReason($conn) !== null) {
    rbacRespond(false, 'Your session is no longer valid. Please sign in again.', [], 403);
}

/* ---- 3. CSRF ------------------------------------------------------------ */
$token = $_POST['csrf_token'] ?? null;
if (!csrfValidate(is_string($token) ? $token : null)) {
    rbacRespond(false, 'Your form session expired. Please refresh the page and try again.', [], 419);
}

$action = (string) ($_POST['action'] ?? '');
$userId = (int) ($_POST['user_id'] ?? 0);

$result = ['success' => false, 'message' => 'Unknown action.'];
$rateLimited = false;

/* ---- 3b. Throttle administrator-driven e-mail sends --------------------- */
// "Resend temporary password" and "Reset password" both e-mail a credential to
// an external mailbox. Bound the rate per target account (so one mailbox
// cannot be flooded) and per acting administrator (so a single admin session
// cannot spray many accounts).
if (in_array($action, ['resend_password', 'reset_password'], true) && $userId > 0) {
    $actorId   = (int) ($_SESSION['UserID'] ?? 0);
    $perTarget = 'admin_resend_target_' . $userId;
    $perActor  = 'admin_resend_actor_' . $actorId;

    $targetAllowed = rateLimitAllowed(
        $conn,
        $perTarget,
        'send',
        (int) RATE_LIMIT_ADMIN_RESEND_PER_ACCOUNT_PER_HOUR,
        3600,
        900
    );
    $actorAllowed = rateLimitAllowed(
        $conn,
        $perActor,
        'send',
        (int) RATE_LIMIT_ADMIN_RESEND_PER_ACTOR_PER_HOUR,
        3600,
        900
    );

    if (!$targetAllowed || !$actorAllowed) {
        $rateLimited = true;
        $result = [
            'success' => false,
            'message' => 'Too many password e-mails have been sent recently. Please wait a few minutes and try again.',
        ];
    } else {
        rateLimitRecord($conn, $perTarget, 'send', (int) RATE_LIMIT_ADMIN_RESEND_PER_ACCOUNT_PER_HOUR, 3600, 900);
        rateLimitRecord($conn, $perActor, 'send', (int) RATE_LIMIT_ADMIN_RESEND_PER_ACTOR_PER_HOUR, 3600, 900);
    }
}

/* ---- 4. Re-authentication for the most sensitive actions ---------------- */
$needsReauth = in_array($action, ['delete', 'reset_password'], true)
    || ($action === 'create' && (int) ($_POST['role_id'] ?? 0) === 1);

if ($rateLimited) {
    // The throttle already set $result; skip the action entirely.
} elseif ($needsReauth && !accountReauthenticate($conn, (string) ($_POST['reauth_password'] ?? ''))) {
    $result = [
        'success' => false,
        'message' => 'Your password is incorrect. Please confirm your password and try again.',
        'reauthenticate' => true,
    ];
} else {
    switch ($action) {
        case 'create':
            $result = accountCreate($conn, [
                'role_id'             => $_POST['role_id'] ?? 0,
                'first_name'          => $_POST['first_name'] ?? '',
                'middle_name'         => $_POST['middle_name'] ?? '',
                'last_name'           => $_POST['last_name'] ?? '',
                'email'               => $_POST['email'] ?? '',
                'confirm_email'       => $_POST['confirm_email'] ?? '',
                'phone'               => $_POST['phone'] ?? '',
                'sex'                 => $_POST['sex'] ?? 'Not Specified',
                'department_id'       => $_POST['department_id'] ?? 0,
                'staff_role'          => $_POST['staff_role'] ?? '',
                'specialization'      => $_POST['specialization'] ?? '',
                'license_number'      => $_POST['license_number'] ?? '',
                'years_of_experience' => $_POST['years_of_experience'] ?? 0,
            ]);
            break;

        case 'update':
            $result = accountUpdate($conn, $userId, [
                'first_name'          => $_POST['first_name'] ?? '',
                'middle_name'         => $_POST['middle_name'] ?? '',
                'last_name'           => $_POST['last_name'] ?? '',
                'email'               => $_POST['email'] ?? '',
                'phone'               => $_POST['phone'] ?? '',
                'sex'                 => $_POST['sex'] ?? 'Not Specified',
                'date_of_birth'       => $_POST['date_of_birth'] ?? null,
                'profile_photo'       => $_POST['profile_photo'] ?? null,
                'status'              => $_POST['status'] ?? '',
                'department_id'       => $_POST['department_id'] ?? 0,
                'staff_role'          => $_POST['staff_role'] ?? '',
                'specialization'      => $_POST['specialization'] ?? '',
                'license_number'      => $_POST['license_number'] ?? '',
                'years_of_experience' => $_POST['years_of_experience'] ?? 0,
                'availability_status' => $_POST['availability_status'] ?? 'Available',
                'schedule_start'      => $_POST['schedule_start'] ?? '',
                'schedule_end'        => $_POST['schedule_end'] ?? '',
                'responsibilities'    => $_POST['responsibilities'] ?? '',
            ]);
            break;

        case 'toggle':
            $account = accountFetch($conn, $userId);
            if (!$account) {
                $result = ['success' => false, 'message' => 'Account not found.'];
            } else {
                $newStatus = ((string) $account['Status'] === 'Active') ? 'Inactive' : 'Active';
                $result = accountSetStatus($conn, $userId, $newStatus);
            }
            break;

        case 'resend_password':
            $result = accountIssueTemporaryPassword($conn, $userId, false);
            break;

        case 'reset_password':
            $result = accountIssueTemporaryPassword($conn, $userId, true);
            break;

        case 'delete':
            $result = accountDelete($conn, $userId);
            break;

        case 'update_patient':
            $result = accountUpdatePatient($conn, $userId, $_POST);
            break;
            $result = accountCreatePatient($conn, [
                'first_name'    => $_POST['first_name'] ?? '',
                'middle_name'   => $_POST['middle_name'] ?? '',
                'last_name'     => $_POST['last_name'] ?? '',
                'email'         => $_POST['email'] ?? '',
                'phone'         => $_POST['phone'] ?? '',
                'sex'           => $_POST['sex'] ?? 'Not Specified',
                'date_of_birth' => $_POST['date_of_birth'] ?? '',
                'delivery'      => $_POST['delivery'] ?? 'none',
            ]);
            break;

        case 'invite_patient':
            $result = accountInvitePatient(
                $conn,
                (int) ($_POST['patient_id'] ?? 0),
                (string) ($_POST['email'] ?? '')
            );
            break;

        case 'set_patient_status':
            $result = accountSetPatientStatus(
                $conn,
                (int) ($_POST['patient_id'] ?? 0),
                (string) ($_POST['status'] ?? '')
            );
            break;

        case 'toggle_patient':
            // The drawer posts user_id; resolve the clinical record from it
            // server-side (patient_id is accepted for older callers).
            $patientId = (int) ($_POST['patient_id'] ?? 0);
            if ($patientId <= 0 && $userId > 0) {
                $patientId = accountPatientIdForUser($conn, $userId);
            }
            $patient = accountPatientFetch($conn, $patientId);
            if (!$patient) {
                $result = ['success' => false, 'message' => 'Patient record not found.'];
            } else {
                $newStatus = ((string) $patient['Status'] === 'Active') ? 'Inactive' : 'Active';
                $result = accountSetPatientStatus($conn, $patientId, $newStatus);
            }
            break;
    }
}

/* ---- 5. Resolve the target account (for notifications + flash label) ---- */
$targetUserId = $userId;
if ($targetUserId <= 0 && !empty($result['userId'])) {
    $targetUserId = (int) $result['userId'];
}
$target = $targetUserId > 0 ? accountFetch($conn, $targetUserId) : null;

$label = $target
    ? trim((string) ($target['FirstName'] . ' ' . $target['LastName'])) . ' (' . accountRoleName((int) $target['RoleID']) . ')'
    : 'the account';

if (!empty($result['success'])) {
    $actor = trim((string) ($_SESSION['FirstName'] ?? '') . ' ' . (string) ($_SESSION['LastName'] ?? ''));
    $actor = $actor !== '' ? $actor : 'An administrator';

    adminNotificationCreateForActiveAdmins(
        $conn,
        'Account Management',
        $actor . ' performed "' . $action . '" on ' . $label . '.',
        'Security',
        $targetUserId > 0 ? $targetUserId : null,
        'users',
        'Medium'
    );
}

/* ---- 6. Respond --------------------------------------------------------- */
// Issued temporary passwords are e-mailed to the account owner only and are
// never returned in the response, a flash or the URL.
$extra = [];
if (!empty($result['reauthenticate'])) {
    $extra['reauthenticate'] = true;
}

if (rbacWantsJson()) {
    rbacRespond((bool) $result['success'], (string) $result['message'], $extra);
}

/* ---- 7. Redirect back with a one-time session flash -------------------- */
$_SESSION['flash'] = [
    'status'  => !empty($result['success']) ? 'success' : 'error',
    'message' => (string) $result['message'],
];

header('Location: ' . appBasePath() . '/admin/admin_account_management.php');
exit();
