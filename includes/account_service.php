<?php

/**
 * Account Management service.
 *
 * Single source of truth for administrator-driven account creation and
 * lifecycle management. The public website may only create PATIENT accounts
 * (auth/signup.php); every staff-level account (Admin / Doctor / Staff) is
 * created here, behind the Admin RBAC guard.
 *
 * Onboarding e-mails a random temporary password (see includes/temp_password.php):
 * the account is created Active with users.MustChangePassword = 1 and the
 * password is never chosen, displayed or logged by an administrator. The user
 * must replace it at first sign-in.
 *
 * All functions return an array shaped like:
 *   ['success' => bool, 'message' => string, ...extra]
 */

require_once __DIR__ . '/security_config.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_pdo.php';
require_once __DIR__ . '/audit_helper.php';
require_once __DIR__ . '/session_liveness.php';
require_once __DIR__ . '/temp_password.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/app_url.php';

/** Roles an administrator is allowed to create/manage here. */
function accountManagedRoles(): array
{
    return [
        1 => 'Admin',
        2 => 'Staff',
        4 => 'Doctor',
    ];
}

function accountRoleValid(int $roleId): bool
{
    return array_key_exists($roleId, accountManagedRoles());
}

function accountRoleName(int $roleId): string
{
    return accountManagedRoles()[$roleId] ?? ('Role ' . $roleId);
}

/** True when the role has a staff profile with a department. */
function accountRoleIsClinical(int $roleId): bool
{
    return $roleId === 2 || $roleId === 4;
}

/** The acting administrator, from the session. */
function accountActorId(): int
{
    return (int) ($_SESSION['UserID'] ?? 0);
}

/** E-mail uniqueness check (users.Email is UNIQUE, so this mirrors the DB rule). */
function accountEmailExists(mysqli $conn, string $email, int $excludeUserId = 0): bool
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return false;
    }

    $stmt = mysqli_prepare($conn, 'SELECT UserID FROM users WHERE Email = ? AND UserID <> ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'si', $email, $excludeUserId);
    mysqli_stmt_execute($stmt);
    $exists = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);

    return $exists;
}

/** The domain part of an e-mail address, lower-cased. */
function accountEmailDomain(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return '';
    }

    return strtolower(substr($email, $at + 1));
}

/**
 * Placeholder / non-deliverable addresses created by older walk-in flows.
 * Password-reset and setup links must never be sent to them.
 */
function accountEmailIsPlaceholder(string $email): bool
{
    $domain = accountEmailDomain($email);
    if ($domain === '') {
        return true;
    }
    if (in_array($domain, ['mediacare.local', 'medicare.local', 'curora.local'], true)) {
        return true;
    }

    return str_ends_with($domain, '.local');
}

/**
 * Domains allowed for the privileged roles (Admin / Doctor). An empty list
 * means "no restriction". Editable from System Settings.
 *
 * @return string[]
 */
function accountAllowedEmailDomains(mysqli $conn): array
{
    $raw = '';
    $stmt = mysqli_prepare(
        $conn,
        "SELECT SettingValue FROM system_settings WHERE SettingKey = 'allowed_account_email_domains' LIMIT 1"
    );
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $raw = (string) ($row['SettingValue'] ?? '');
    }
    if (trim($raw) === '') {
        $raw = (string) (getenv('CURORA_ALLOWED_EMAIL_DOMAINS') ?: '');
    }

    $out = [];
    foreach (explode(',', $raw) as $domain) {
        $domain = ltrim(strtolower(trim($domain)), '@');
        if ($domain !== '') {
            $out[] = $domain;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Full server-side e-mail validation used before anything is created:
 * syntax, length, uniqueness, MX reachability and (optionally) an allow-list
 * for Administrator / Doctor addresses.
 *
 * @return string|null Error message, or null when the address is acceptable.
 */
function accountValidateEmail(mysqli $conn, string $email, int $roleId, int $excludeUserId = 0): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid e-mail address.';
    }

    $domain = accountEmailDomain($email);
    if ($domain === '' || strpos($domain, '.') === false) {
        return 'Please enter a valid e-mail address.';
    }

    if (accountEmailExists($conn, $email, $excludeUserId)) {
        return 'A user with this e-mail address already exists.';
    }

    if (ACCOUNT_EMAIL_MX_CHECK && !accountEmailIsPlaceholder($email)) {
        if (!checkdnsrr($domain, 'MX')) {
            return 'The e-mail domain does not appear to accept mail. Please double-check the address.';
        }
    }

    if (in_array($roleId, [1, 4], true)) {
        $allowed = accountAllowedEmailDomains($conn);
        if (!empty($allowed) && !in_array($domain, $allowed, true)) {
            return 'Administrator and Doctor accounts must use one of the approved e-mail domains: '
                . implode(', ', $allowed) . '.';
        }
    }

    return null;
}

/** True when a clinical department id exists. */
function accountDepartmentExists(mysqli $conn, int $departmentId): bool
{
    if ($departmentId <= 0) {
        return false;
    }
    $stmt = mysqli_prepare($conn, 'SELECT DepartmentID FROM departments WHERE DepartmentID = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $departmentId);
    mysqli_stmt_execute($stmt);
    $ok = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    mysqli_stmt_close($stmt);

    return $ok;
}

/** Verifies the acting administrator's password (re-authentication). */
function accountReauthenticate(mysqli $conn, string $password): bool
{
    $userId = accountActorId();
    if ($userId <= 0 || $password === '') {
        return false;
    }

    $stmt = mysqli_prepare($conn, 'SELECT Password FROM users WHERE UserID = ? AND DeletedAt IS NULL LIMIT 1');
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row !== null && password_verify($password, (string) $row['Password']);
}

function accountCountActiveAdmins(mysqli $conn): int
{
    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS c FROM users WHERE RoleID = 1 AND Status = 'Active' AND DeletedAt IS NULL"
    );
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return (int) ($row['c'] ?? 0);
}

function accountFetch(mysqli $conn, int $userId): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT u.*, r.RoleName,
                s.StaffID, s.DepartmentID, s.StaffRole, s.Specialization,
                s.LicenseNumber, s.YearsOfExperience, s.AvailabilityStatus,
                s.ScheduleStart, s.ScheduleEnd, s.AssignedResponsibilities,
                a.AdminID,
                p.PatientID, p.CivilStatus, p.Religion, p.BloodType, p.Allergies,
                p.PastMedicalCondition, p.CurrentMedication, p.FamilyMedicalHistory,
                p.IsPWD, p.DisabilityType,
                p.EmergencyContactName, p.EmergencyContactNo, p.EmergencyRelation
         FROM users u
         INNER JOIN roles r ON r.RoleID = u.RoleID
         LEFT JOIN staff s ON s.UserID = u.UserID
         LEFT JOIN admin a ON a.UserID = u.UserID
         LEFT JOIN patients p ON p.UserID = u.UserID
         WHERE u.UserID = ? AND u.DeletedAt IS NULL
         LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return $row ?: null;
}

/**
 * Builds the shared WHERE clause for the account list / count queries.
 * Only a fixed set of columns is touched, so no user input reaches the SQL
 * structure itself. Soft-deleted accounts are always excluded.
 *
 * @return array{0:string,1:array,2:string}
 */
function accountListFilter(array $filters): array
{
    $where = ['u.DeletedAt IS NULL'];
    $params = [];
    $types = '';

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        // The typed search also matches the account's department name, so
        // typing e.g. "Cardiology" lists every doctor (and staff member) of
        // that department. Requires the departments join in the caller's SQL.
        $where[] = '(u.FirstName LIKE ? OR u.MiddleName LIKE ? OR u.LastName LIKE ?'
                 . ' OR u.Email LIKE ? OR d.DepartmentName LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sssss';
    }

    $role = $filters['role'] ?? '';
    if ($role !== '' && $role !== null && (int) $role > 0) {
        $where[] = 'u.RoleID = ?';
        $params[] = (int) $role;
        $types .= 'i';
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && in_array($status, ['Active', 'Inactive', 'Pending'], true)) {
        $where[] = 'u.Status = ?';
        $params[] = $status;
        $types .= 's';
    }

    return ['WHERE ' . implode(' AND ', $where), $params, $types];
}

/** Runs a prepared statement, binding a dynamic argument list safely. */
function accountRunPrepared(mysqli $conn, string $sql, string $types, array $params): mysqli_result|false
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return false;
    }

    if ($types !== '') {
        $args = [$types];
        foreach ($params as $k => $v) {
            $args[] = &$params[$k];
        }
        call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt], $args));
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    mysqli_stmt_close($stmt);

    return $result;
}

function accountList(mysqli $conn, array $filters): array
{
    [$where, $params, $types] = accountListFilter($filters);

    $sql = 'SELECT u.UserID, u.FirstName, u.MiddleName, u.LastName, u.Email,
                u.ContactNumber, u.Sex, u.Status, u.RoleID, u.LastLogin,
                u.MustChangePassword, u.TempPasswordExpiresAt, u.EmailVerified,
                u.TOTPEnabled, u.Address, u.DateOfBirth,
                (u.TOTPSecret IS NOT NULL AND u.TOTPSecret <> "") AS TOTPEnrolled,
                r.RoleName,
                s.StaffID, s.DepartmentID, s.StaffRole, s.Specialization,
                s.LicenseNumber, s.YearsOfExperience, s.AvailabilityStatus,
                s.ScheduleStart, s.ScheduleEnd, s.AssignedResponsibilities,
                (s.StaffID IS NOT NULL) AS IsClinical,
                COALESCE(d.DepartmentName, "") AS DepartmentName,
                p.PatientID, p.CivilStatus, p.Religion, p.BloodType, p.Allergies,
                p.PastMedicalCondition, p.CurrentMedication, p.FamilyMedicalHistory,
                p.IsPWD, p.DisabilityType,
                p.EmergencyContactName, p.EmergencyContactNo, p.EmergencyRelation
          FROM users u
          INNER JOIN roles r ON r.RoleID = u.RoleID
          LEFT JOIN staff s ON s.UserID = u.UserID
          LEFT JOIN departments d ON d.DepartmentID = s.DepartmentID
          LEFT JOIN patients p ON p.UserID = u.UserID
          ' . $where . '
          ORDER BY u.LastName, u.FirstName, u.UserID';

    if (isset($filters['limit']) && (int) $filters['limit'] > 0) {
        $sql .= ' LIMIT ' . (int) $filters['limit']
            . ' OFFSET ' . max(0, (int) ($filters['offset'] ?? 0));
    }

    $result = accountRunPrepared($conn, $sql, $types, $params);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;
}

function accountListCount(mysqli $conn, array $filters): int
{
    [$where, $params, $types] = accountListFilter($filters);

    // DISTINCT because a user may (in legacy data) have more than one staff
    // row; joins mirror accountList() so the shared WHERE stays valid.
    $sql = 'SELECT COUNT(DISTINCT u.UserID) AS total
          FROM users u
          INNER JOIN roles r ON r.RoleID = u.RoleID
          LEFT JOIN staff s ON s.UserID = u.UserID
          LEFT JOIN departments d ON d.DepartmentID = s.DepartmentID
          ' . $where;

    $result = accountRunPrepared($conn, $sql, $types, $params);
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);

    return (int) ($row['total'] ?? 0);
}

/**
 * Issues a fresh random temporary password, e-mails it and forces a change at
 * the next sign-in. The plaintext is never returned to the caller, so it can
 * never be displayed or logged by the console.
 *
 * Issuing a new password invalidates the previous one immediately and signs the
 * account out everywhere. A send failure is reported so the administrator can
 * correct the address and try again; the account keeps the issued password.
 *
 * @param int  $userId  Target account (Admin, Staff, Doctor or Patient).
 * @param bool $isReset True for a password reset, false for a resend.
 *
 * @return array{success:bool,message:string,mailSent?:bool,name?:string,email?:string,roleId?:int}
 */
function accountIssueTemporaryPassword(mysqli $conn, int $userId, bool $isReset): array
{
    $user = accountFetch($conn, $userId);
    if (!$user) {
        return ['success' => false, 'message' => 'Account not found.'];
    }
    if (!in_array((int) $user['RoleID'], [1, 2, 3, 4], true)) {
        return ['success' => false, 'message' => 'This account is not managed here.'];
    }

    $roleId = (int) $user['RoleID'];
    $email  = strtolower(trim((string) $user['Email']));

    if ($email === '' || accountEmailIsPlaceholder($email)) {
        return [
            'success' => false,
            'message' => 'This account has no deliverable e-mail address. Correct the address and try again.',
        ];
    }

    $password  = generateTemporaryPassword();
    $hash      = tempPasswordHash($password);
    $ttl       = tempPasswordTtlHours($roleId);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl * 3600);

    // A legacy "Pending" account is activated by issuing a temporary password;
    // an intentionally deactivated account stays deactivated.
    $status = strcasecmp((string) $user['Status'], 'Pending') === 0 ? 'Active' : (string) $user['Status'];

    $upd = mysqli_prepare(
        $conn,
        'UPDATE users SET Password = ?, MustChangePassword = 1, TempPasswordExpiresAt = ?, Status = ? WHERE UserID = ?'
    );
    if (!$upd) {
        return ['success' => false, 'message' => 'Unable to issue a temporary password.'];
    }
    mysqli_stmt_bind_param($upd, 'sssi', $hash, $expiresAt, $status, $userId);
    $ok = mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
    if (!$ok) {
        return ['success' => false, 'message' => 'Unable to issue a temporary password.'];
    }

    // The previous password is now unusable; sign the account out everywhere.
    sessionRevokeAllFor($conn, $userId);

    $name = trim((string) $user['FirstName'] . ' ' . (string) $user['LastName']);
    $mailSent = false;
    if (function_exists('sendTemporaryPasswordEmail')) {
        $mailSent = sendTemporaryPasswordEmail($email, $name, $password, accountRoleName($roleId), $isReset, $ttl);
    }
    // Drop the plaintext reference as soon as it is no longer needed.
    $password = '';

    logAudit(
        db_pdo(),
        $isReset ? 'RESET_PASSWORD' : 'RESEND_TEMP_PASSWORD',
        'users',
        $userId,
        null,
        json_encode([
            'email'      => $email,
            'role'       => accountRoleName($roleId),
            'delivered'  => (bool) $mailSent,
            'expires_at' => $expiresAt,
        ])
    );

    $message = $mailSent
        ? ($isReset
            ? 'A new temporary password was e-mailed to ' . $email . '.'
            : 'A temporary password was e-mailed to ' . $email . '.')
        : 'A new temporary password was generated but could not be e-mailed. Correct the address and try again.';

    return [
        'success'  => true,
        'message'  => $message,
        'mailSent' => $mailSent,
        'name'     => $name,
        'email'    => $email,
        'roleId'   => $roleId,
    ];
}

/**
 * Creates an Admin / Doctor / Staff account and e-mails a random temporary
 * password. The account is Active with MustChangePassword = 1; no password is
 * chosen, displayed or returned to the caller.
 *
 * @return array{success:bool,message:string,userId?:int,mailSent?:bool}
 */
function accountCreate(mysqli $conn, array $in): array
{
    $roleId         = (int) ($in['role_id'] ?? 0);
    $firstName      = trim((string) ($in['first_name'] ?? ''));
    $middleName     = trim((string) ($in['middle_name'] ?? ''));
    $lastName       = trim((string) ($in['last_name'] ?? ''));
    $email          = strtolower(trim((string) ($in['email'] ?? '')));
    $confirmEmail   = strtolower(trim((string) ($in['confirm_email'] ?? '')));
    $phone          = trim((string) ($in['phone'] ?? ''));
    $sex            = trim((string) ($in['sex'] ?? 'Not Specified'));

    if (!accountRoleValid($roleId)) {
        return ['success' => false, 'message' => 'Invalid role. Only Admin, Doctor or Staff accounts may be created here.'];
    }
    if ($firstName === '' || $lastName === '') {
        return ['success' => false, 'message' => 'First and last name are required.'];
    }
    if (mb_strlen($firstName) > 100 || mb_strlen($middleName) > 100 || mb_strlen($lastName) > 100) {
        return ['success' => false, 'message' => 'Names must be 100 characters or fewer.'];
    }

    // E-mail: syntax, length, MX reachability, uniqueness and (for the
    // privileged roles) the optional approved-domain list.
    if (($emailError = accountValidateEmail($conn, $email, $roleId)) !== null) {
        return ['success' => false, 'message' => $emailError];
    }

    // Administrators must retype the address. Doctor and Staff accounts show a
    // confirm-on-screen preview instead, so the second box is optional for them.
    if ($roleId === 1) {
        if ($confirmEmail === '' || !hash_equals($email, $confirmEmail)) {
            return ['success' => false, 'message' => 'The e-mail addresses do not match. Please retype the address.'];
        }
    } elseif ($confirmEmail !== '' && !hash_equals($email, $confirmEmail)) {
        return ['success' => false, 'message' => 'The e-mail addresses do not match. Please retype the address.'];
    }

    if ($phone !== '' && ($phoneError = validatePhilippinePhone($phone)) !== null) {
        return ['success' => false, 'message' => $phoneError];
    }

    // Role-specific fields. Anything that does not belong to the selected role
    // is ignored outright, so a crafted request cannot smuggle it in.
    $departmentId   = 0;
    $staffRole      = '';
    $specialization = '';
    $license        = '';
    $experience     = null;
    $availability   = 'Available';

    if (accountRoleIsClinical($roleId)) {
        $departmentId = (int) ($in['department_id'] ?? 0);
        if ($departmentId <= 0) {
            return ['success' => false, 'message' => 'Please select a department.'];
        }
        if (!accountDepartmentExists($conn, $departmentId)) {
            return ['success' => false, 'message' => 'The selected department does not exist.'];
        }

        if ($roleId === 4) {
            $license = trim((string) ($in['license_number'] ?? ''));
            if ($license === '') {
                return ['success' => false, 'message' => 'A license number is required for a doctor.'];
            }
            $specialization = trim((string) ($in['specialization'] ?? ''));
            $years = (int) ($in['years_of_experience'] ?? 0);
            $experience = $years > 0 ? $years : null;
            $staffRole = 'Doctor';
        } else {
            $staffRole = trim((string) ($in['staff_role'] ?? ''));
            if ($staffRole === '') {
                return ['success' => false, 'message' => 'Please enter the staff role.'];
            }
            if (in_array(strtolower($staffRole), ['doctor', 'administrator', 'admin'], true)) {
                return ['success' => false, 'message' => 'Use the Doctor or Administrator role for that account instead.'];
            }
            if (mb_strlen($staffRole) > 50) {
                return ['success' => false, 'message' => 'The staff role must be 50 characters or fewer.'];
            }
            $specialization = trim((string) ($in['specialization'] ?? ''));
        }
    }

    $sex = in_array($sex, ['Male', 'Female', 'Not Specified'], true) ? $sex : 'Not Specified';
    // A random temporary password: the administrator never chooses, sees or
    // types it. The account signs in once with it and must then change it.
    $tempPassword  = generateTemporaryPassword();
    $hash          = tempPasswordHash($tempPassword);
    $ttl           = tempPasswordTtlHours($roleId);
    $tempExpiresAt = date('Y-m-d H:i:s', time() + $ttl * 3600);
    $contact = $phone !== '' ? $phone : null;
    $middle = $middleName !== '' ? $middleName : null;
    $spec = $specialization !== '' ? $specialization : null;
    $lic  = $license !== '' ? $license : null;

    mysqli_begin_transaction($conn);

    $userStmt = mysqli_prepare(
        $conn,
        'INSERT INTO users
            (RoleID, FirstName, MiddleName, LastName, Email, Password, Sex, ContactNumber, Status, MustChangePassword, TempPasswordExpiresAt, EmailVerified)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Active", 1, ?, 1)'
    );

    if (!$userStmt) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => 'Unable to prepare account creation.'];
    }

    mysqli_stmt_bind_param(
        $userStmt,
        'issssssss',
        $roleId,
        $firstName,
        $middle,
        $lastName,
        $email,
        $hash,
        $sex,
        $contact,
        $tempExpiresAt
    );

    if (!mysqli_stmt_execute($userStmt)) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => 'Unable to create the account.'];
    }

    $userId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($userStmt);

    if ($roleId === 1) {
        $profileStmt = mysqli_prepare($conn, 'INSERT INTO admin (UserID) VALUES (?)');
    } elseif ($roleId === 4) {
        $profileStmt = mysqli_prepare(
            $conn,
            'INSERT INTO staff
                (UserID, DepartmentID, StaffRole, Specialization, LicenseNumber, YearsOfExperience, AvailabilityStatus)
             VALUES (?, ?, "Doctor", ?, ?, ?, ?)'
        );
    } else {
        $profileStmt = mysqli_prepare(
            $conn,
            'INSERT INTO staff
                (UserID, DepartmentID, StaffRole, Specialization, AvailabilityStatus)
             VALUES (?, ?, ?, ?, ?)'
        );
    }

    if (!$profileStmt) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => 'Unable to prepare the profile record.'];
    }

    if ($roleId === 1) {
        mysqli_stmt_bind_param($profileStmt, 'i', $userId);
    } elseif ($roleId === 4) {
        mysqli_stmt_bind_param($profileStmt, 'iissis', $userId, $departmentId, $spec, $lic, $experience, $availability);
    } else {
        mysqli_stmt_bind_param($profileStmt, 'iisss', $userId, $departmentId, $staffRole, $spec, $availability);
    }

    if (!mysqli_stmt_execute($profileStmt)) {
        mysqli_rollback($conn);
        mysqli_stmt_close($profileStmt);
        return ['success' => false, 'message' => 'Unable to create the profile record.'];
    }
    mysqli_stmt_close($profileStmt);

    mysqli_commit($conn);

    logAudit(
        db_pdo(),
        'CREATE',
        'users',
        $userId,
        null,
        json_encode([
            'role'   => accountRoleName($roleId),
            'email'  => $email,
            'status' => 'Active',
        ])
    );

    // E-mail the temporary password outside the transaction: a mail failure
    // must not roll back the account. The plaintext is never returned to the
    // caller, so it can never be displayed or logged by the console.
    $roleName = accountRoleName($roleId);
    $userName = trim($firstName . ' ' . $lastName);
    $mailSent = false;

    if (function_exists('sendTemporaryPasswordEmail')) {
        $mailSent = sendTemporaryPasswordEmail($email, $userName, $tempPassword, $roleName, false, $ttl);
    }
    $tempPassword = '';

    $message = $mailSent
        ? $roleName . ' account created. A temporary password was e-mailed to ' . $email . '.'
        : 'Account created but the temporary password could not be e-mailed. Correct the e-mail address and use "Resend temporary password".';

    return [
        'success'  => true,
        'message'  => $message,
        'userId'   => $userId,
        'roleId'   => $roleId,
        'name'     => $userName,
        'email'    => $email,
        'mailSent' => $mailSent,
    ];
}

/** Updates identity fields (and staff profile details when present). */
function accountUpdate(mysqli $conn, int $userId, array $in): array
{
    // A client-supplied role is ignored on purpose: an account's role is fixed
    // at creation time and cannot be escalated through the edit form.
    unset($in['role_id'], $in['RoleID']);

    $existing = accountFetch($conn, $userId);
    if (!$existing) {
        return ['success' => false, 'message' => 'Account not found.'];
    }

    $firstName  = trim((string) ($in['first_name'] ?? ''));
    $middleName = trim((string) ($in['middle_name'] ?? ''));
    $lastName   = trim((string) ($in['last_name'] ?? ''));
    $email      = strtolower(trim((string) ($in['email'] ?? '')));
    $phone      = trim((string) ($in['phone'] ?? ''));
    $sex        = trim((string) ($in['sex'] ?? 'Not Specified'));
    $status     = trim((string) ($in['status'] ?? $existing['Status']));

    if ($firstName === '' || $lastName === '') {
        return ['success' => false, 'message' => 'First and last name are required.'];
    }
    if (mb_strlen($firstName) > 100 || mb_strlen($middleName) > 100 || mb_strlen($lastName) > 100) {
        return ['success' => false, 'message' => 'Names must be 100 characters or fewer.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid e-mail address.'];
    }

    // Full e-mail validation (uniqueness, MX, approved domains) only when the
    // address actually changes, so editing a legacy account whose domain has no
    // MX record is not blocked by the stricter checks.
    if (strcasecmp($email, (string) $existing['Email']) !== 0) {
        $emailError = accountValidateEmail($conn, $email, (int) $existing['RoleID'], (int) $existing['UserID']);
        if ($emailError !== null) {
            return ['success' => false, 'message' => $emailError];
        }
    }

    if ($phone !== '' && ($pe = validatePhilippinePhone($phone)) !== null) {
        return ['success' => false, 'message' => $pe];
    }

    // The status dropdown only offers Active/Inactive. A Pending account stays
    // Pending until it uses its setup link, so an update must not silently
    // activate it.
    $currentStatus = (string) $existing['Status'];
    if (strcasecmp($currentStatus, 'Pending') === 0) {
        $status = 'Pending';
    } elseif (!in_array($status, ['Active', 'Inactive'], true)) {
        $status = $currentStatus;
    }

    if ((int) $existing['RoleID'] === 4) {
        $license = trim((string) ($in['license_number'] ?? ($existing['LicenseNumber'] ?? '')));
        if ($license === '') {
            return ['success' => false, 'message' => 'A license number is required for a doctor.'];
        }
    }

    if ((int) $existing['RoleID'] === 1 && $status === 'Inactive') {
        $activeUserId = accountActorId();
        if ((int) $existing['UserID'] === $activeUserId || accountCountActiveAdmins($conn) <= 1) {
            return ['success' => false, 'message' => 'You cannot deactivate this administrator account.'];
        }
    }

    $sex = in_array($sex, ['Male', 'Female', 'Not Specified'], true) ? $sex : 'Not Specified';
    $contact = $phone !== '' ? $phone : null;
    $middle = $middleName !== '' ? $middleName : null;

    // Date of birth and profile photo are optional. When the field is absent
    // the existing value is preserved; an empty string clears it.
    if (array_key_exists('date_of_birth', $in)) {
        $dobRaw = trim((string) $in['date_of_birth']);
        $dob = ($dobRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobRaw) === 1) ? $dobRaw : null;
    } else {
        $dobRaw = (string) ($existing['DateOfBirth'] ?? '');
        $dob = $dobRaw !== '' ? $dobRaw : null;
    }

    if (array_key_exists('profile_photo', $in)) {
        $photoRaw = trim((string) $in['profile_photo']);
        $photo = $photoRaw !== '' ? $photoRaw : null;
    } else {
        $photoRaw = (string) ($existing['ProfilePhoto'] ?? '');
        $photo = $photoRaw !== '' ? $photoRaw : null;
    }

    mysqli_begin_transaction($conn);

    $userStmt = mysqli_prepare(
        $conn,
        'UPDATE users SET FirstName = ?, MiddleName = ?, LastName = ?, Email = ?, Sex = ?, ContactNumber = ?, DateOfBirth = ?, ProfilePhoto = ?, Status = ? WHERE UserID = ?'
    );
    if (!$userStmt) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => 'Unable to prepare the update.'];
    }
    mysqli_stmt_bind_param(
        $userStmt,
        'sssssssssi',
        $firstName,
        $middle,
        $lastName,
        $email,
        $sex,
        $contact,
        $dob,
        $photo,
        $status,
        $existing['UserID']
    );
    if (!mysqli_stmt_execute($userStmt)) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => 'Unable to update the account.'];
    }
    mysqli_stmt_close($userStmt);

    if (!empty($existing['StaffID'])) {
        $departmentId = (int) ($in['department_id'] ?? 0);
        $staffRole    = trim((string) ($in['staff_role'] ?? ($existing['StaffRole'] ?? '')));
        $spec         = trim((string) ($in['specialization'] ?? ''));
        $license      = trim((string) ($in['license_number'] ?? ''));
        $experience   = (int) ($in['years_of_experience'] ?? 0);
        $availability = trim((string) ($in['availability_status'] ?? $existing['AvailabilityStatus']));
        $start        = trim((string) ($in['schedule_start'] ?? ''));
        $end          = trim((string) ($in['schedule_end'] ?? ''));
        $resp         = trim((string) ($in['responsibilities'] ?? ''));

        if ($departmentId <= 0) {
            $departmentId = (int) $existing['DepartmentID'];
        }
        if ((int) $existing['RoleID'] === 4) {
            $staffRole = 'Doctor';
            if ($license === '') {
                $license = (string) ($existing['LicenseNumber'] ?? '');
            }
        }
        $availability = in_array($availability, ['Available', 'Off Duty', 'On Leave'], true) ? $availability : 'Available';
        $specV  = $spec !== '' ? $spec : null;
        $licV   = $license !== '' ? $license : null;
        $expV   = $experience > 0 ? $experience : null;
        $startV = $start !== '' ? $start : null;
        $endV   = $end !== '' ? $end : null;
        $respV  = $resp !== '' ? $resp : null;

        $staffStmt = mysqli_prepare(
            $conn,
            'UPDATE staff SET DepartmentID = ?, StaffRole = ?, Specialization = ?, LicenseNumber = ?, YearsOfExperience = ?, AvailabilityStatus = ?, ScheduleStart = ?, ScheduleEnd = ?, AssignedResponsibilities = ? WHERE UserID = ?'
        );
        if (!$staffStmt) {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => 'Unable to prepare the profile update.'];
        }
        mysqli_stmt_bind_param(
            $staffStmt,
            'isssissssi',
            $departmentId,
            $staffRole,
            $specV,
            $licV,
            $expV,
            $availability,
            $startV,
            $endV,
            $respV,
            $existing['UserID']
        );
        if (!mysqli_stmt_execute($staffStmt)) {
            mysqli_rollback($conn);
            mysqli_stmt_close($staffStmt);
            return ['success' => false, 'message' => 'Unable to update the profile record.'];
        }
        mysqli_stmt_close($staffStmt);
    }

    mysqli_commit($conn);

    // Moving an account to Inactive must sign its live sessions out.
    if (strcasecmp($currentStatus, 'Active') === 0 && $status === 'Inactive') {
        sessionRevokeAllFor($conn, (int) $existing['UserID']);
    }

    logAudit(
        db_pdo(),
        'UPDATE',
        'users',
        (int) $existing['UserID'],
        json_encode(['email' => $existing['Email'], 'status' => $currentStatus]),
        json_encode(['email' => $email, 'status' => $status])
    );

    return ['success' => true, 'message' => 'Account updated successfully.'];
}

function accountSetStatus(mysqli $conn, int $userId, string $status): array
{
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        return ['success' => false, 'message' => 'Invalid status.'];
    }
    $user = accountFetch($conn, $userId);
    if (!$user) {
        return ['success' => false, 'message' => 'Account not found.'];
    }
    if (strcasecmp((string) $user['Status'], 'Pending') === 0) {
        return ['success' => false, 'message' => 'This account has not finished setup. Resend the setup link instead.'];
    }

    if ((int) $user['RoleID'] === 1 && $status === 'Inactive') {
        $activeUserId = accountActorId();
        if ((int) $user['UserID'] === $activeUserId || accountCountActiveAdmins($conn) <= 1) {
            return ['success' => false, 'message' => 'You cannot deactivate this administrator account.'];
        }
    }

    $stmt = mysqli_prepare($conn, 'UPDATE users SET Status = ? WHERE UserID = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to update the account.'];
    }
    mysqli_stmt_bind_param($stmt, 'si', $status, $userId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        return ['success' => false, 'message' => 'Unable to update the account.'];
    }

    // Sign the account out of every open session when it is deactivated.
    if ($status === 'Inactive') {
        sessionRevokeAllFor($conn, $userId);
    }

    logAudit(
        db_pdo(),
        $status === 'Active' ? 'ACTIVATE' : 'DEACTIVATE',
        'users',
        $userId,
        json_encode(['status' => $user['Status']]),
        json_encode(['status' => $status])
    );

    return ['success' => true, 'message' => 'Account ' . strtolower($status === 'Active' ? 'reactivated' : 'deactivated') . '.'];
}

/** Counts rows in $table whose $column equals $id. Names are internal only. */
function accountReferenceCount(mysqli $conn, string $table, string $column, int $id): int
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?');
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return (int) ($row[0] ?? 0);
}

/**
 * Human labels for the history that would be orphaned if the account were
 * removed. A soft delete is refused while such records exist so appointments,
 * consultations, vitals and audit history keep their attribution.
 *
 * @return string[]
 */
function accountDeleteBlockers(mysqli $conn, int $userId, ?int $staffId): array
{
    $blockers = [];

    $userRefs = [
        'audit history'        => ['audit_trail', 'UserID'],
        'notifications'        => ['notifications', 'UserID'],
        'reports'              => ['reports', 'GeneratedBy'],
        'no-show records'      => ['no_shows', 'MarkedBy'],
        'unavailability records' => ['doctorunavailability', 'CreatedBy'],
        'announcement reads'   => ['announcement_reads', 'UserID'],
        'patient records'      => ['patients', 'UserID'],
    ];
    foreach ($userRefs as $label => [$table, $column]) {
        if (accountReferenceCount($conn, $table, $column, $userId) > 0) {
            $blockers[] = $label;
        }
    }

    if ($staffId !== null && $staffId > 0) {
        $staffRefs = [
            'appointments' => ['appointments', 'StaffID'],
            'consultations' => ['consultations', 'StaffID'],
            'vitals records' => ['vitals', 'StaffID'],
            'doctor unavailability records' => ['doctorunavailability', 'DoctorID'],
        ];
        foreach ($staffRefs as $label => [$table, $column]) {
            if (accountReferenceCount($conn, $table, $column, $staffId) > 0) {
                $blockers[] = $label;
            }
        }
    }

    return $blockers;
}

/**
 * Soft-deletes an account (Status = Inactive, DeletedAt = now) instead of
 * removing the row. Refused when the account is referenced by clinical or
 * audit history, or when it is the acting admin / the last active admin.
 */
function accountDelete(mysqli $conn, int $userId): array
{
    $user = accountFetch($conn, $userId);
    if (!$user) {
        return ['success' => false, 'message' => 'Account not found.'];
    }

    if ((int) $user['UserID'] === accountActorId()) {
        return ['success' => false, 'message' => 'You cannot delete your own account.'];
    }
    if ((int) $user['RoleID'] === 1 && accountCountActiveAdmins($conn) <= 1
        && strcasecmp((string) $user['Status'], 'Active') === 0) {
        return ['success' => false, 'message' => 'You cannot delete the last active administrator.'];
    }
    if ((int) $user['RoleID'] === 3) {
        return ['success' => false, 'message' => 'Patient accounts are managed from Patient Management.'];
    }

    // Refuse when the account is referenced by clinical or audit history so
    // nothing is orphaned (deactivate instead).
    $staffId = !empty($user['StaffID']) ? (int) $user['StaffID'] : null;
    $blockers = accountDeleteBlockers($conn, $userId, $staffId);
    if (!empty($blockers)) {
        return [
            'success' => false,
            'message' => 'This account has linked records (' . implode(', ', $blockers) . ') and cannot be deleted. Deactivate it instead.',
        ];
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE users SET DeletedAt = NOW(), Status = 'Inactive', SessionVersion = SessionVersion + 1 WHERE UserID = ?"
    );
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to delete the account.'];
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        return ['success' => false, 'message' => 'Unable to delete the account.'];
    }

    logAudit(
        db_pdo(),
        'DELETE',
        'users',
        $userId,
        json_encode([
            'name'   => trim((string) $user['FirstName'] . ' ' . (string) $user['LastName']),
            'email'  => (string) $user['Email'],
            'role'   => accountRoleName((int) $user['RoleID']),
            'status' => (string) $user['Status'],
        ]),
        json_encode(['deleted' => true, 'soft' => true])
    );

    return ['success' => true, 'message' => 'Account deleted (deactivated and retained for audit).'];
}

/* =========================================================================
 *  Walk-in patients (no login) and portal invitations
 *
 *  A walk-in patient exists as a clinical record (patients row) with no
 *  users row and therefore no password. They can later be invited to the
 *  portal, which creates the login and issues a one-time setup link.
 * ========================================================================= */

/** The Patient role id (from the roles table, never hard-coded). */
function accountPatientRoleId(mysqli $conn): int
{
    $result = mysqli_query($conn, "SELECT RoleID FROM roles WHERE RoleName = 'Patient' LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;

    return $row ? (int) $row['RoleID'] : 0;
}

/** A YYYY-MM-DD string or null. */
function accountNormalizeDate(string $value): ?string
{
    $value = trim($value);

    return ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) ? $value : null;
}

/**
 * Creates a walk-in patient record. When $in['delivery'] = 'email' (or the
 * legacy $in['invite'] flag is truthy) the patient is also invited to the
 * portal: a login is created and a temporary password is e-mailed. The default
 * of 'none' stores the clinical record only, with no login.
 *
 * @return array{success:bool,message:string,patientId?:int,userId?:int,mailSent?:bool}
 */
function accountCreatePatient(mysqli $conn, array $in): array
{
    $firstName  = trim((string) ($in['first_name'] ?? ''));
    $middleName = trim((string) ($in['middle_name'] ?? ''));
    $lastName   = trim((string) ($in['last_name'] ?? ''));
    $email      = strtolower(trim((string) ($in['email'] ?? '')));
    $phone      = trim((string) ($in['phone'] ?? ''));
    $sex        = trim((string) ($in['sex'] ?? 'Not Specified'));
    $dob        = accountNormalizeDate((string) ($in['date_of_birth'] ?? ''));
    $delivery   = strtolower(trim((string) ($in['delivery'] ?? 'none')));
    if (!in_array($delivery, ['none', 'email'], true)) {
        $delivery = 'none';
    }

    if ($firstName === '' || $lastName === '') {
        return ['success' => false, 'message' => 'First and last name are required.'];
    }
    if (mb_strlen($firstName) > 100 || mb_strlen($middleName) > 100 || mb_strlen($lastName) > 100) {
        return ['success' => false, 'message' => 'Names must be 100 characters or fewer.'];
    }
    if ($phone !== '' && ($pe = validatePhilippinePhone($phone)) !== null) {
        return ['success' => false, 'message' => $pe];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid e-mail address, or leave it blank.'];
    }

    // Inviting requires a deliverable address up front.
    if ($delivery === 'email') {
        if ($email === '') {
            return ['success' => false, 'message' => 'An e-mail address is required to send a portal invitation.'];
        }
        $emailError = accountValidateEmail($conn, $email, accountPatientRoleId($conn));
        if ($emailError !== null) {
            return ['success' => false, 'message' => $emailError];
        }
    }

    $sex = in_array($sex, ['Male', 'Female', 'Not Specified'], true) ? $sex : 'Not Specified';
    $middle = $middleName !== '' ? $middleName : null;
    $emailV = $email !== '' ? $email : null;
    $phoneV = $phone !== '' ? $phone : null;

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO patients
            (UserID, FirstName, MiddleName, LastName, Email, DateOfBirth, Sex, ContactNumber, Status)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, "Active")'
    );
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to prepare the patient record.'];
    }
    mysqli_stmt_bind_param($stmt, 'sssssss', $firstName, $middle, $lastName, $emailV, $dob, $sex, $phoneV);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return ['success' => false, 'message' => 'Unable to create the patient record.'];
    }
    $patientId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    logAudit(
        db_pdo(),
        'CREATE',
        'patients',
        $patientId,
        null,
        json_encode([
            'name'    => trim($firstName . ' ' . $lastName),
            'email'   => $emailV,
            'login'   => false,
            'invited' => $delivery === 'email',
        ])
    );

    $result = [
        'success'   => true,
        'message'   => 'Patient record created (no portal login).',
        'patientId' => $patientId,
    ];

    if ($delivery === 'none') {
        return $result;
    }

    // Create the login and issue a temporary password.
    $invite = accountInvitePatient($conn, $patientId, $email);
    if (!$invite['success']) {
        // The clinical record is kept; only the invitation failed.
        $result['message'] = 'Patient record created, but the portal invitation could not be sent. '
            . $invite['message'];

        return $result;
    }

    $result['message']  = $invite['message'];
    $result['userId']   = $invite['userId'];
    $result['mailSent'] = (bool) ($invite['mailSent'] ?? false);

    return $result;
}

/**
 * Invites an existing (login-less) patient to the portal, or issues a fresh
 * temporary password when the patient already has a login.
 *
 * @return array{success:bool,message:string,userId?:int,mailSent?:bool}
 */
function accountInvitePatient(mysqli $conn, int $patientId, string $email = ''): array
{
    $patient = accountPatientFetch($conn, $patientId);
    if (!$patient) {
        return ['success' => false, 'message' => 'Patient record not found.'];
    }

    // Already has a login: just (re)issue a temporary password.
    if (!empty($patient['UserID'])) {
        return accountIssueTemporaryPassword($conn, (int) $patient['UserID'], false);
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        $email = strtolower(trim((string) ($patient['Email'] ?? '')));
    }
    if ($email === '') {
        return ['success' => false, 'message' => 'An e-mail address is required to invite this patient.'];
    }

    $roleId = accountPatientRoleId($conn);
    $emailError = accountValidateEmail($conn, $email, $roleId);
    if ($emailError !== null) {
        return ['success' => false, 'message' => $emailError];
    }

    $firstName  = trim((string) ($patient['FirstName'] ?? ''));
    $middleName = trim((string) ($patient['MiddleName'] ?? ''));
    $lastName   = trim((string) ($patient['LastName'] ?? ''));
    $sex        = (string) ($patient['Sex'] ?? 'Not Specified');
    $dob        = accountNormalizeDate((string) ($patient['DateOfBirth'] ?? ''));
    $phone      = (string) ($patient['ContactNumber'] ?? '');

    $tempPassword  = generateTemporaryPassword();
    $hash          = tempPasswordHash($tempPassword);
    $ttl           = tempPasswordTtlHours($roleId);
    $tempExpiresAt = date('Y-m-d H:i:s', time() + $ttl * 3600);

    mysqli_begin_transaction($conn);

    $userStmt = mysqli_prepare(
        $conn,
        'INSERT INTO users
            (RoleID, FirstName, MiddleName, LastName, Email, Password, Sex, DateOfBirth, ContactNumber, Status, MustChangePassword, TempPasswordExpiresAt, EmailVerified)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Active", 1, ?, 1)'
    );
    if (!$userStmt) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => 'Unable to prepare the portal invitation.'];
    }
    $middle = $middleName !== '' ? $middleName : null;
    $phoneV = $phone !== '' ? $phone : null;
    mysqli_stmt_bind_param(
        $userStmt,
        'ssssssssss',
        $roleId,
        $firstName,
        $middle,
        $lastName,
        $email,
        $hash,
        $sex,
        $dob,
        $phoneV,
        $tempExpiresAt
    );
    if (!mysqli_stmt_execute($userStmt)) {
        mysqli_rollback($conn);
        mysqli_stmt_close($userStmt);
        return ['success' => false, 'message' => 'Unable to create the patient login.'];
    }
    $userId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($userStmt);

    $linkStmt = mysqli_prepare($conn, 'UPDATE patients SET UserID = ?, Email = ? WHERE PatientID = ?');
    if ($linkStmt) {
        mysqli_stmt_bind_param($linkStmt, 'isi', $userId, $email, $patientId);
        mysqli_stmt_execute($linkStmt);
        mysqli_stmt_close($linkStmt);
    }

    mysqli_commit($conn);

    logAudit(
        db_pdo(),
        'INVITE',
        'patients',
        $patientId,
        null,
        json_encode(['email' => $email, 'userId' => $userId])
    );

    $mailSent = false;
    if (function_exists('sendTemporaryPasswordEmail')) {
        $mailSent = sendTemporaryPasswordEmail(
            $email,
            trim($firstName . ' ' . $lastName),
            $tempPassword,
            accountRoleName($roleId),
            false,
            $ttl
        );
    }
    $tempPassword = '';

    return [
        'success'  => true,
        'message'  => $mailSent
            ? 'Portal invitation sent to ' . $email . '.'
            : 'Patient login created but the temporary password could not be e-mailed. Correct the address and resend.',
        'userId'   => $userId,
        'mailSent' => $mailSent,
    ];
}

/**
 * Updates a patient-linked account. The login identity (name, e-mail, sex,
 * date of birth, contact number, address) lives on the `users` row, while the
 * clinical demographics (civil status, religion, emergency contact) live on
 * the linked `patients` row. Both are updated together so the Account
 * Management drawer shows, and writes back, the same data the patient portal
 * uses. A missing `patients` row is created when the account is linked.
 *
 * @param array<string,mixed> $in
 * @return array{success:bool,message:string}
 */
/**
 * Returns the PatientID of the clinical record linked to a login account,
 * or 0 when the account has no linked patient row yet. Patient identity is
 * always resolved server-side from the user id — a client-posted patient_id
 * is never trusted, so edits and toggles can never touch another patient's
 * record or create a duplicate row.
 */
function accountPatientIdForUser(mysqli $conn, int $userId): int
{
    $stmt = mysqli_prepare($conn, 'SELECT PatientID FROM patients WHERE UserID = ? ORDER BY PatientID LIMIT 1');
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return (int) ($row['PatientID'] ?? 0);
}

function accountUpdatePatient(mysqli $conn, int $userId, array $in): array
{
    $user = accountFetch($conn, $userId);
    if (!$user) {
        return ['success' => false, 'message' => 'Account not found.'];
    }
    if ((int) $user['RoleID'] !== accountPatientRoleId($conn)) {
        return ['success' => false, 'message' => 'That account is not a patient.'];
    }

    $firstName  = trim((string) ($in['first_name'] ?? ''));
    $middleName = trim((string) ($in['middle_name'] ?? ''));
    $lastName   = trim((string) ($in['last_name'] ?? ''));
    $email      = strtolower(trim((string) ($in['email'] ?? '')));
    $sex        = trim((string) ($in['sex'] ?? 'Not Specified'));
    $phone      = trim((string) ($in['phone'] ?? ''));
    $address    = trim((string) ($in['address'] ?? ''));
    $dobRaw     = trim((string) ($in['date_of_birth'] ?? ''));
    $civil      = trim((string) ($in['civil_status'] ?? ''));
    $religion   = trim((string) ($in['religion'] ?? ''));
    $ecName     = trim((string) ($in['emergency_contact_name'] ?? ''));
    $ecRelation = trim((string) ($in['emergency_contact_relationship'] ?? ''));
    $ecPhone    = trim((string) ($in['emergency_contact_phone'] ?? ''));

    if ($firstName === '' || $lastName === '') {
        return ['success' => false, 'message' => 'First and last name are required.'];
    }
    if (mb_strlen($firstName) > 100 || mb_strlen($middleName) > 100 || mb_strlen($lastName) > 100) {
        return ['success' => false, 'message' => 'Names must be 100 characters or fewer.'];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid e-mail address.'];
    }
    if (strcasecmp($email, (string) $user['Email']) !== 0) {
        $emailError = accountValidateEmail($conn, $email, (int) $user['RoleID'], $userId);
        if ($emailError !== null) {
            return ['success' => false, 'message' => $emailError];
        }
    }
    if ($phone !== '' && ($pe = validatePhilippinePhone($phone)) !== null) {
        return ['success' => false, 'message' => $pe];
    }
    if ($ecPhone !== '' && ($pe = validatePhilippinePhone($ecPhone)) !== null) {
        return ['success' => false, 'message' => 'Emergency contact: ' . $pe];
    }

    $sex    = in_array($sex, ['Male', 'Female', 'Not Specified'], true) ? $sex : 'Not Specified';
    $dob    = ($dobRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobRaw) === 1) ? $dobRaw : null;
    $civilStatuses = ['Single', 'Married', 'Widowed', 'Separated', 'Divorced', 'Other'];
    $civil = in_array($civil, $civilStatuses, true) ? $civil : '';

    $middleV  = $middleName !== '' ? $middleName : null;
    $phoneV   = $phone !== '' ? $phone : null;
    $addressV = $address !== '' ? $address : null;
    $civilV   = $civil !== '' ? $civil : null;
    $religionV = $religion !== '' ? $religion : null;
    $ecNameV  = $ecName !== '' ? $ecName : null;
    $ecRelV   = $ecRelation !== '' ? $ecRelation : null;
    $ecPhoneV = $ecPhone !== '' ? $ecPhone : null;

    // Health information shown on the patient profile (patients table).
    $bloodOptions = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    $bloodType = trim((string) ($in['blood_type'] ?? ''));
    $bloodType = in_array($bloodType, $bloodOptions, true) ? $bloodType : '';
    $allergies   = mb_substr(trim((string) ($in['allergies'] ?? '')), 0, 2000);
    $familyHist  = mb_substr(trim((string) ($in['family_medical_history'] ?? '')), 0, 4000);
    $pastCond    = mb_substr(trim((string) ($in['past_medical_condition'] ?? '')), 0, 4000);
    $currentMed  = mb_substr(trim((string) ($in['current_medication'] ?? '')), 0, 4000);
    $disability  = mb_substr(trim((string) ($in['disability_type'] ?? '')), 0, 100);
    $isPwd       = !empty($in['is_pwd']) ? 1 : 0;
    if ($isPwd === 0) {
        $disability = ''; // Disability type only applies to PWD patients.
    }

    $bloodV     = $bloodType !== '' ? $bloodType : null;
    $allergiesV = $allergies !== '' ? $allergies : null;
    $familyV    = $familyHist !== '' ? $familyHist : null;
    $pastV      = $pastCond !== '' ? $pastCond : null;
    $currentV   = $currentMed !== '' ? $currentMed : null;
    $disabV     = $disability !== '' ? $disability : null;

    mysqli_begin_transaction($conn);

    $userStmt = mysqli_prepare(
        $conn,
        'UPDATE users
            SET FirstName = ?, MiddleName = ?, LastName = ?, Email = ?,
                Sex = ?, ContactNumber = ?, DateOfBirth = ?, Address = ?
          WHERE UserID = ?'
    );
    if (!$userStmt) {
        mysqli_rollback($conn);
        return ['success' => false, 'message' => 'Unable to prepare the update.'];
    }
    mysqli_stmt_bind_param(
        $userStmt,
        'ssssssssi',
        $firstName,
        $middleV,
        $lastName,
        $email,
        $sex,
        $phoneV,
        $dob,
        $addressV,
        $userId
    );
    if (!mysqli_stmt_execute($userStmt)) {
        mysqli_rollback($conn);
        mysqli_stmt_close($userStmt);
        return ['success' => false, 'message' => 'Unable to update the account.'];
    }
    mysqli_stmt_close($userStmt);

    // Resolve the clinical record server-side from the acting account.
    $patientId = accountPatientIdForUser($conn, $userId);

    if ($patientId > 0) {
        $patStmt = mysqli_prepare(
            $conn,
            'UPDATE patients
                SET FirstName = ?, MiddleName = ?, LastName = ?, Email = ?,
                    Sex = ?, ContactNumber = ?, DateOfBirth = ?,
                    CivilStatus = ?, Religion = ?, BloodType = ?, Allergies = ?,
                    PastMedicalCondition = ?, CurrentMedication = ?,
                    FamilyMedicalHistory = ?, DisabilityType = ?,
                    EmergencyContactName = ?, EmergencyContactNo = ?,
                    EmergencyRelation = ?, IsPWD = ?
              WHERE PatientID = ?'
        );
        if (!$patStmt) {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => 'Unable to prepare the patient update.'];
        }
        mysqli_stmt_bind_param(
            $patStmt,
            'ssssssssssssssssssii',
            $firstName,
            $middleV,
            $lastName,
            $email,
            $sex,
            $phoneV,
            $dob,
            $civilV,
            $religionV,
            $bloodV,
            $allergiesV,
            $pastV,
            $currentV,
            $familyV,
            $disabV,
            $ecNameV,
            $ecPhoneV,
            $ecRelV,
            $isPwd,
            $patientId
        );
    } else {
        $patStmt = mysqli_prepare(
            $conn,
            'INSERT INTO patients
                (UserID, IsPWD, FirstName, MiddleName, LastName, Email, Sex,
                 ContactNumber, DateOfBirth, CivilStatus, Religion, BloodType,
                 Allergies, PastMedicalCondition, CurrentMedication,
                 FamilyMedicalHistory, DisabilityType, EmergencyContactName,
                 EmergencyContactNo, EmergencyRelation, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "Active")'
        );
        if (!$patStmt) {
            mysqli_rollback($conn);
            return ['success' => false, 'message' => 'Unable to prepare the patient record.'];
        }
        mysqli_stmt_bind_param(
            $patStmt,
            'iissssssssssssssssss',
            $userId,
            $isPwd,
            $firstName,
            $middleV,
            $lastName,
            $email,
            $sex,
            $phoneV,
            $dob,
            $civilV,
            $religionV,
            $bloodV,
            $allergiesV,
            $pastV,
            $currentV,
            $familyV,
            $disabV,
            $ecNameV,
            $ecPhoneV,
            $ecRelV
        );
    }

    if (!mysqli_stmt_execute($patStmt)) {
        mysqli_rollback($conn);
        mysqli_stmt_close($patStmt);
        return ['success' => false, 'message' => 'Unable to update the patient record.'];
    }
    $patientId = $patientId > 0 ? $patientId : (int) mysqli_insert_id($conn);
    mysqli_stmt_close($patStmt);

    mysqli_commit($conn);

    logAudit(
        db_pdo(),
        'UPDATE',
        'patients',
        $patientId,
        json_encode(['email' => $user['Email']]),
        json_encode(['email' => $email, 'firstName' => $firstName, 'lastName' => $lastName])
    );

    return ['success' => true, 'message' => 'Patient details updated successfully.'];
}

/** Loads a single patient record (link + unlinked demographics). */
function accountPatientFetch(mysqli $conn, int $patientId): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT p.PatientID, p.UserID,
                COALESCE(u.FirstName, p.FirstName)       AS FirstName,
                COALESCE(u.MiddleName, p.MiddleName)     AS MiddleName,
                COALESCE(u.LastName, p.LastName)         AS LastName,
                COALESCE(u.Email, p.Email)               AS Email,
                COALESCE(u.DateOfBirth, p.DateOfBirth)   AS DateOfBirth,
                COALESCE(u.Sex, p.Sex)                   AS Sex,
                COALESCE(u.ContactNumber, p.ContactNumber) AS ContactNumber,
                COALESCE(u.Status, p.Status)             AS Status,
                u.LastLogin, u.EmailVerified,
                p.BloodType, p.Allergies
           FROM patients p
           LEFT JOIN users u ON u.UserID = p.UserID
          WHERE p.PatientID = ?
          LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 'i', $patientId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

/**
 * Paginated patient list for the Patients tab: linked portal users and
 * login-less walk-in records, in one table.
 *
 * @return array<int,array<string,mixed>>
 */
function accountPatientList(mysqli $conn, array $filters = []): array
{
    $where  = [];
    $params = [];
    $types  = '';

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(COALESCE(u.FirstName, p.FirstName) LIKE ?
                     OR COALESCE(u.LastName, p.LastName) LIKE ?
                     OR COALESCE(u.Email, p.Email) LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if (in_array($status, ['Active', 'Inactive', 'Pending'], true)) {
        $where[] = 'COALESCE(u.Status, p.Status) = ?';
        $params[] = $status;
        $types .= 's';
    }

    $sql = 'SELECT p.PatientID, p.UserID,
                   COALESCE(u.FirstName, p.FirstName)         AS FirstName,
                   COALESCE(u.MiddleName, p.MiddleName)       AS MiddleName,
                   COALESCE(u.LastName, p.LastName)           AS LastName,
                   COALESCE(u.Email, p.Email)                 AS Email,
                   COALESCE(u.ContactNumber, p.ContactNumber) AS ContactNumber,
                   COALESCE(u.Sex, p.Sex)                     AS Sex,
                   COALESCE(u.DateOfBirth, p.DateOfBirth)     AS DateOfBirth,
                   COALESCE(u.Status, p.Status)               AS Status,
                   u.LastLogin, u.EmailVerified,
                   (SELECT COUNT(*) FROM appointments a WHERE a.PatientID = p.PatientID) AS Visits
              FROM patients p
              LEFT JOIN users u ON u.UserID = p.UserID';

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY LastName, FirstName, p.PatientID';

    if (isset($filters['limit']) && (int) $filters['limit'] > 0) {
        $sql .= ' LIMIT ' . (int) $filters['limit'] . ' OFFSET ' . max(0, (int) ($filters['offset'] ?? 0));
    }

    $result = accountRunPrepared($conn, $sql, $types, $params);
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return $rows;
}

function accountPatientCount(mysqli $conn, array $filters = []): int
{
    $where  = [];
    $params = [];
    $types  = '';

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(COALESCE(u.FirstName, p.FirstName) LIKE ?
                     OR COALESCE(u.LastName, p.LastName) LIKE ?
                     OR COALESCE(u.Email, p.Email) LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if (in_array($status, ['Active', 'Inactive', 'Pending'], true)) {
        $where[] = 'COALESCE(u.Status, p.Status) = ?';
        $params[] = $status;
        $types .= 's';
    }

    $sql = 'SELECT COUNT(*) AS total FROM patients p LEFT JOIN users u ON u.UserID = p.UserID';
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $result = accountRunPrepared($conn, $sql, $types, $params);
    if (!$result) {
        return 0;
    }
    $row = mysqli_fetch_assoc($result);

    return (int) ($row['total'] ?? 0);
}

/** Deactivates or reactivates a patient (linked portal user or walk-in record). */
function accountSetPatientStatus(mysqli $conn, int $patientId, string $status): array
{
    if (!in_array($status, ['Active', 'Inactive'], true)) {
        return ['success' => false, 'message' => 'Invalid status.'];
    }
    $patient = accountPatientFetch($conn, $patientId);
    if (!$patient) {
        return ['success' => false, 'message' => 'Patient record not found.'];
    }

    if (!empty($patient['UserID'])) {
        return accountSetStatus($conn, (int) $patient['UserID'], $status);
    }

    $stmt = mysqli_prepare($conn, 'UPDATE patients SET Status = ? WHERE PatientID = ?');
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to update the patient.'];
    }
    mysqli_stmt_bind_param($stmt, 'si', $status, $patientId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        return ['success' => false, 'message' => 'Unable to update the patient.'];
    }

    logAudit(
        db_pdo(),
        $status === 'Active' ? 'ACTIVATE' : 'DEACTIVATE',
        'patients',
        $patientId,
        json_encode(['status' => $patient['Status']]),
        json_encode(['status' => $status])
    );

    return ['success' => true, 'message' => 'Patient record ' . ($status === 'Active' ? 'reactivated' : 'deactivated') . '.'];
}

/* =========================================================================
 *  Administrator two-factor recovery codes (single-use, hashed)
 * ========================================================================= */

/** Formats a recovery code in a human-friendly XXXX-XXXX shape. */
function recoveryCodeFormat(string $raw): string
{
    $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');

    return implode('-', str_split(substr($clean, 0, 8), 4));
}

/** Normalizes user input for lookup (dashes/spaces removed, upper-cased). */
function recoveryCodeNormalize(string $raw): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
}

function recoveryCodeHash(string $normalized): string
{
    return hash('sha256', $normalized);
}

/**
 * Replaces a user's unused recovery codes with a fresh set and returns the
 * plaintext codes ONCE (they are stored only as hashes).
 *
 * @return string[] the new codes, never stored in clear
 */
function recoveryCodesGenerate(mysqli $conn, int $userId): array
{
    recoveryCodesClear($conn, $userId);

    $count = max(4, (int) ACCOUNT_RECOVERY_CODE_COUNT);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no ambiguous 0/O/1/I
    $plain = [];

    $stmt = mysqli_prepare($conn, 'INSERT INTO admin_recovery_codes (UserID, CodeHash) VALUES (?, ?)');
    if (!$stmt) {
        return [];
    }

    for ($i = 0; $i < $count; $i++) {
        $raw = '';
        for ($j = 0; $j < 8; $j++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $hash = recoveryCodeHash($raw);
        mysqli_stmt_bind_param($stmt, 'is', $userId, $hash);
        if (mysqli_stmt_execute($stmt)) {
            $plain[] = recoveryCodeFormat($raw);
        }
    }
    mysqli_stmt_close($stmt);

    return $plain;
}

function recoveryCodesClear(mysqli $conn, int $userId): void
{
    $stmt = mysqli_prepare($conn, 'DELETE FROM admin_recovery_codes WHERE UserID = ?');
    if (!$stmt) {
        return;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function recoveryCodesRemaining(mysqli $conn, int $userId): int
{
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM admin_recovery_codes WHERE UserID = ? AND UsedAt IS NULL');
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return (int) ($row['c'] ?? 0);
}

/** Consumes a recovery code atomically. Returns true only once per code. */
function recoveryCodeConsume(mysqli $conn, int $userId, string $code): bool
{
    $normalized = recoveryCodeNormalize($code);
    if ($normalized === '') {
        return false;
    }

    $hash = recoveryCodeHash($normalized);
    $stmt = mysqli_prepare(
        $conn,
        'UPDATE admin_recovery_codes SET UsedAt = NOW() WHERE UserID = ? AND CodeHash = ? AND UsedAt IS NULL'
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'is', $userId, $hash);
    mysqli_stmt_execute($stmt);
    $used = mysqli_stmt_affected_rows($stmt) === 1;
    mysqli_stmt_close($stmt);

    return $used;
}
