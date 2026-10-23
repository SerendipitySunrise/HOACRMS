<?php

/**
 * tests/account_security_tests.php
 *
 * End-to-end security tests for the account-management restructure.
 * Run with:
 *
 *   php tests/account_security_tests.php
 *
 * The harness drives the live application over HTTP (so RBAC/CSRF/session
 * middleware is exercised for real) and inspects the database to confirm the
 * outcome. Mail is captured via the dry-run outbox (no SMTP required).
 *
 * Covered:
 *   (g) An administrator without TOTP is routed to enrolment and cannot reach
 *       admin pages until a valid 6-digit code is submitted.
 *   (h) A non-admin gets HTTP 403 from every account endpoint.
 *   (a) Account creation: Active + MustChangePassword + TempPasswordExpiresAt;
 *       the plaintext temporary password never appears in any response, URL,
 *       flash message or audit entry.
 *   (b) An expired temporary password is rejected with a generic message.
 *   (c) Resend and admin reset both invalidate the old temporary password.
 *   (d) MustChangePassword = 1 blocks every page except change_password.php.
 *   (e) The new password cannot equal the temporary one and must meet the
 *       12-character policy.
 *   (f) After the change the old password is rejected and other sessions are
 *       revoked.
 *   (i) Self-service "Forgot password" does NOT change the current password;
 *       the response is identical for existing and unknown e-mail addresses.
 *   (j) The reset token is not consumed on GET and cannot be reused.
 *   (k) Public self-registration is patient-only (role cannot be forced).
 *   (l) An admin cannot deactivate or delete their own account.
 *   (m) Update cannot escalate an account's role.
 *   (n) Delete is refused when the account has linked records.
 *   (o) Deactivation revokes live sessions.
 *   (p) Anti-clickjacking headers on admin pages.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/totp.php';

$BASE = 'http://localhost/HOACRMS_Lagaban';

$pass = 0;
$fail = 0;
$failures = [];

function check(string $label, bool $ok): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        $failures[] = $label;
        echo "  FAIL  $label\n";
    }
}

function section(string $title): void
{
    echo "\n=== $title ===\n";
}

/* ---- HTTP helpers -------------------------------------------------------- */

function req(string $method, string $url, array $post = [], array $jar = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_COOKIEJAR      => null,
        CURLOPT_COOKIEFILE     => null,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
    ]);
    if (!empty($jar)) {
        $cookieStr = [];
        foreach ($jar as $k => $v) {
            $cookieStr[] = "$k=$v";
        }
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookieStr));
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    $body    = substr($raw, $headerSize);
    return ['code' => $code, 'headers' => $headers, 'body' => $body];
}

function sessionCookies(array $resp): array
{
    preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;\r\n]*)/mi', $resp['headers'], $m);
    $jar = [];
    foreach ($m[1] as $i => $name) {
        $jar[$name] = $m[2][$i];
    }
    return $jar;
}

function csrfFrom(string $html): string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/value="([^"]+)"\s+name="csrf_token"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

/* ---- Database helpers ---------------------------------------------------- */

function scalar(string $sql, ...$params)
{
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return null;
    if ($params) mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? $row[0] : null;
}

function execSql(string $sql, ...$params): bool
{
    global $conn;
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    if ($params) mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

function userIdByEmail(string $email): int
{
    return (int) scalar('SELECT UserID FROM users WHERE Email = ? AND DeletedAt IS NULL', $email);
}

/* ---- Outbox helpers (dry-run mail capture) -------------------------------- */

function outboxDir(): string
{
    // The web server writes dry-run e-mails to CURORA_MAIL_OUTBOX_DIR (set via
    // .htaccess). Use the same path so the test can read them.
    $env = getenv('CURORA_MAIL_OUTBOX_DIR');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return 'C:/wamp64/tmp/curora_mail_outbox';
}

function outboxClear(): void
{
    $dir = outboxDir();
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
}

function outboxLatestFor(string $to): ?array
{
    $dir = outboxDir();
    if (!is_dir($dir)) return null;
    $files = glob($dir . '/*.json') ?: [];
    // Sort by mtime, then by filename as a tie-breaker: filemtime only has
    // one-second granularity and two mails can land in the same second.
    usort($files, function ($a, $b) {
        $ta = @filemtime($a);
        $tb = @filemtime($b);
        if ($ta !== $tb) return $tb <=> $ta;
        return strcmp((string) basename($b), (string) basename($a));
    });
    foreach ($files as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && strtolower(trim((string) ($data['to'] ?? ''))) === strtolower($to)) {
            return $data;
        }
    }
    return null;
}

/** Returns the list of outbox files currently present (for snapshot-diffing). */
function outboxFiles(): array
{
    $dir = outboxDir();
    if (!is_dir($dir)) return [];
    $files = glob($dir . '/*.json') ?: [];
    sort($files);
    return $files;
}

/**
 * Returns the newest e-mail addressed to $to among files NOT present in
 * $before. Unlike outboxLatestFor() this never confuses two mails written in
 * the same second, because the caller snapshots before triggering the send.
 */
function outboxNewFor(array $before, string $to): ?array
{
    $dir = outboxDir();
    if (!is_dir($dir)) return null;
    $newFiles = array_diff(outboxFiles(), $before);
    foreach ($newFiles as $file) {
        $data = json_decode((string) file_get_contents($file), true);
        if (is_array($data) && strtolower(trim((string) ($data['to'] ?? ''))) === strtolower($to)) {
            return $data;
        }
    }
    return null;
}

function extractTempPassword(array $email): string
{
    $html = (string) ($email['html'] ?? '');
    // The password sits in a <p style="font-size:18px…">…</p> block. The
    // mailer HTML-encodes it (the alphabet contains '&'), so decode entities.
    if (preg_match('/font-size:18px[^"]*">([^<]+)<\/p>/', $html, $m)) {
        return trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

function extractResetToken(array $email): string
{
    $html = (string) ($email['html'] ?? '');
    if (preg_match('/reset_password\.php\?token=([a-f0-9]{64})/', $html, $m)) {
        return $m[1];
    }
    return '';
}

/* ---- Login / TOTP helpers ------------------------------------------------- */

/**
 * Logs in as an account and returns the cookie jar.
 * Handles the MustChangePassword redirect and the TOTP-enrolment redirect.
 */
function login(string $email, string $password, ?string $totpCode = null): array
{
    global $BASE;

    $loginPage = req('GET', '/auth/login.php');
    $jar = sessionCookies($loginPage);
    $csrf = csrfFrom($loginPage['body']);

    $resp = req('POST', '/auth/login.php', [
        'email'    => $email,
        'password' => $password,
        'csrf_token' => $csrf,
    ], $jar);

    $jar2 = array_merge($jar, sessionCookies($resp));
    $loc = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $resp['headers'], $m)) {
        $loc = trim($m[1]);
    }

    return ['jar' => $jar2, 'redirect' => $loc, 'resp' => $resp];
}

/**
 * Enrols TOTP for a session whose MustSetupTotp flag is set.
 * Returns the cookie jar after successful enrolment.
 */
function enrolTotp(array $jar, string $email): array
{
    $page = req('GET', '/auth/setup_totp.php', [], $jar);
    $csrf = csrfFrom($page['body']);

    // Extract the base32 secret from the page.
    $secret = '';
    if (preg_match('/<div class="secret">([A-Z2-7]+)<\/div>/', $page['body'], $m)) {
        $secret = $m[1];
    }
    if ($secret === '') {
        return $jar;
    }

    // Submit a valid code.
    $slice = (int) floor(time() / 30);
    $code = totpCodeAt($secret, $slice);
    $resp = req('POST', '/auth/setup_totp.php', [
        'code' => $code,
        'csrf_token' => $csrf,
    ], $jar);

    return array_merge($jar, sessionCookies($resp));
}

/* ---- Cleanup helpers ------------------------------------------------------ */

function cleanupAccount(int $userId): void
{
    if ($userId <= 0) return;
    execSql('DELETE FROM account_setup_tokens WHERE UserID = ?', $userId);
    execSql('DELETE FROM audit_trail WHERE UserID = ?', $userId);
    execSql('DELETE FROM notifications WHERE UserID = ?', $userId);
    execSql('DELETE FROM patients WHERE UserID = ?', $userId);
    execSql('DELETE FROM staff WHERE UserID = ?', $userId);
    execSql('DELETE FROM admin WHERE UserID = ?', $userId);
    execSql('DELETE FROM users WHERE UserID = ?', $userId);
}

/* ========================================================================== */
/*  SETUP                                                                    */
/* ========================================================================== */

section('SETUP');

execSql('TRUNCATE TABLE auth_rate_limits');
outboxClear();

// Reset the primary admin's TOTP state so the enrolment flow can be tested.
execSql("UPDATE users SET TOTPSecret = NULL, TOTPEnabled = 0, MustChangePassword = 0, TempPasswordExpiresAt = NULL WHERE Email = 'admin@curoraportal.com'");
$primaryAdminId = userIdByEmail('admin@curoraportal.com');
check('Primary admin fixture exists', $primaryAdminId > 0);

$primaryAdminPw = 'Password123!';

// Truncate any leftover reset tokens.
execSql("DELETE FROM account_setup_tokens WHERE Kind = 'reset'");

/* ========================================================================== */
/*  (g) ADMIN WITHOUT TOTP -> ROUTED TO ENROLMENT                            */
/* ========================================================================== */

section('(g) Admin 2FA enrolment routing');

$loginResult = login('admin@curoraportal.com', $primaryAdminPw);
$adminJar = $loginResult['jar'];

check(
    'Admin login redirects to setup_totp.php (not a dashboard)',
    str_contains($loginResult['redirect'], 'setup_totp.php')
);

// Try to reach the admin dashboard without enrolling.
$dashResp = req('GET', '/admin/admin_dashboard.php', [], $adminJar);
$dashLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $dashResp['headers'], $m)) {
    $dashLoc = trim($m[1]);
}
check(
    'Admin cannot reach admin_dashboard.php before enrolling 2FA',
    str_contains($dashLoc, 'setup_totp.php') || $dashResp['code'] === 302
);

// Try to reach the account-management page without enrolling.
$acctResp = req('GET', '/admin/admin_account_management.php', [], $adminJar);
$acctLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $acctResp['headers'], $m)) {
    $acctLoc = trim($m[1]);
}
check(
    'Admin cannot reach admin_account_management.php before enrolling 2FA',
    str_contains($acctLoc, 'setup_totp.php') || $acctResp['code'] === 302
);

// GET setup_totp.php shows the secret.
$setupPage = req('GET', '/auth/setup_totp.php', [], $adminJar);
check('GET setup_totp.php -> 200', $setupPage['code'] === 200);
check(
    'setup_totp.php shows a base32 secret',
    preg_match('/<div class="secret">[A-Z2-7]{16,}<\/div>/', $setupPage['body']) === 1
);

// Submit an invalid code.
$setupCsrf = csrfFrom($setupPage['body']);
$badResp = req('POST', '/auth/setup_totp.php', [
    'code' => '000000',
    'csrf_token' => $setupCsrf,
], $adminJar);
check('Invalid TOTP code is rejected', $badResp['code'] === 200 && str_contains($badResp['body'], 'not valid'));

// Submit a valid code.
$slice = (int) floor(time() / 30);
preg_match('/<div class="secret">([A-Z2-7]+)<\/div>/', $setupPage['body'], $secMatch);
$adminSecret = $secMatch[1] ?? '';
$goodCode = totpCodeAt($adminSecret, $slice);
$goodResp = req('POST', '/auth/setup_totp.php', [
    'code' => $goodCode,
    'csrf_token' => $setupCsrf,
], $adminJar);
$goodLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $goodResp['headers'], $m)) {
    $goodLoc = trim($m[1]);
}
check(
    'Valid TOTP code enrols the admin and redirects to the dashboard',
    str_contains($goodLoc, 'admin_dashboard.php')
);
$adminJar = array_merge($adminJar, sessionCookies($goodResp));

// Confirm TOTPEnabled is now 1.
check(
    'TOTPEnabled is 1 after enrolment',
    (int) scalar('SELECT TOTPEnabled FROM users WHERE UserID = ?', $primaryAdminId) === 1
);

// A later login requires the 6-digit code.
execSql('TRUNCATE TABLE auth_rate_limits');
$relogin = login('admin@curoraportal.com', $primaryAdminPw);
$reloginLoc = $relogin['redirect'];
check(
    'A later login without a TOTP code is redirected to verify',
    str_contains($reloginLoc, 'verify_totp') || str_contains($reloginLoc, 'totp')
);

// Complete the TOTP verification.
$verifyPage = req('GET', '/auth/totp_verify.php', [], $relogin['jar']);
$verifyCsrf = csrfFrom($verifyPage['body']);
$verifyCode = totpCodeAt($adminSecret, $slice);
$verifyResp = req('POST', '/auth/totp_verify.php', [
    'code' => $verifyCode,
    'csrf_token' => $verifyCsrf,
], $relogin['jar']);
$verifyLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $verifyResp['headers'], $m)) {
    $verifyLoc = trim($m[1]);
}
check('Valid TOTP code completes login', str_contains($verifyLoc, 'admin_dashboard.php'));
$adminJar = array_merge($relogin['jar'], sessionCookies($verifyResp));

// Confirm the admin page is now reachable.
$finalDash = req('GET', '/admin/admin_dashboard.php', [], $adminJar);
check('Admin dashboard is reachable after enrolling 2FA', $finalDash['code'] === 200);

/* ========================================================================== */
/*  (h) NON-ADMIN GETS 403 ON EVERY ENDPOINT                                 */
/* ========================================================================== */

section('(h) Non-admin 403 on account endpoints');

// Create a Staff account directly in the database with a known password.
$staffEmail = 'test-staff-' . bin2hex(random_bytes(4)) . '@example.com';
$staffPw    = 'StaffTemp123!';
$staffHash  = password_hash($staffPw, PASSWORD_BCRYPT, ['cost' => 12]);
$deptId     = (int) scalar('SELECT DepartmentID FROM departments ORDER BY DepartmentID LIMIT 1');

execSql(
    "INSERT INTO users (FirstName, LastName, Email, Password, RoleID, Status, MustChangePassword, Sex)
     VALUES ('Test', 'Staff', ?, ?, 2, 'Active', 1, 'Not Specified')",
    $staffEmail,
    $staffHash
);
$staffId = userIdByEmail($staffEmail);
check('Staff fixture created', $staffId > 0);
execSql('INSERT INTO staff (UserID, DepartmentID, StaffRole) VALUES (?, ?, "Staff")', $staffId, $deptId);

$staffLogin = login($staffEmail, $staffPw);
$staffJar = $staffLogin['jar'];
check('Staff can log in', $staffLogin['redirect'] !== '' || $staffLogin['resp']['code'] === 200);

// Staff is blocked by MustChangePassword, so we can't reach the API yet.
// That itself is a check (see section d). For the 403 test we need a
// fully-authenticated staff session, so clear the flag temporarily.
execSql('UPDATE users SET MustChangePassword = 0 WHERE UserID = ?', $staffId);

// Re-login.
execSql('TRUNCATE TABLE auth_rate_limits');
$staffLogin = login($staffEmail, $staffPw);
$staffJar = $staffLogin['jar'];
check('Staff re-login after flag cleared', $staffLogin['redirect'] !== '');

$staffCsrfPage = req('GET', '/auth/change_password.php', [], $staffJar);
$staffCsrf = csrfFrom($staffCsrfPage['body']);

$actions = ['create', 'update', 'toggle', 'resend_password', 'reset_password', 'delete'];
foreach ($actions as $act) {
    $resp = req('POST', '/api/admin/accounts.php', [
        'action'    => $act,
        'format'    => 'json',
        'user_id'   => (string) $primaryAdminId,
        'role_id'   => '1',
        'email'     => 'x@example.com',
        'csrf_token' => $staffCsrf,
    ], $staffJar);
    check("Staff POST action='$act' -> 403", $resp['code'] === 403);
}

/* ========================================================================== */
/*  (a) ACCOUNT CREATION — TEMP PASSWORD SECRECY                             */
/* ========================================================================== */

section('(a) Account creation and temp-password secrecy');

execSql('TRUNCATE TABLE auth_rate_limits');
outboxClear();

// Log in as the (now-enrolled) admin for API calls.
execSql('TRUNCATE TABLE auth_rate_limits');
$adminLogin = login('admin@curoraportal.com', $primaryAdminPw);
$adminJar = $adminLogin['jar'];

// If redirected to verify_totp, complete it.
if (str_contains($adminLogin['redirect'], 'verify_totp') || str_contains($adminLogin['redirect'], 'totp')) {
    $vPage = req('GET', '/auth/totp_verify.php', [], $adminJar);
    $vCsrf = csrfFrom($vPage['body']);
    $vCode = totpCodeAt($adminSecret, (int) floor(time() / 30));
    $vResp = req('POST', '/auth/totp_verify.php', [
        'code' => $vCode,
        'csrf_token' => $vCsrf,
    ], $adminJar);
    $adminJar = array_merge($adminJar, sessionCookies($vResp));
}

// Fetch a CSRF token from an admin page.
$mgmtPage = req('GET', '/admin/admin_account_management.php', [], $adminJar);
check('Admin can reach admin_account_management.php', $mgmtPage['code'] === 200);
$adminCsrf = csrfFrom($mgmtPage['body']);
check('Admin page has a CSRF token', $adminCsrf !== '');

// Create a Staff account via the API.
$createEmail = 'test-staff-new-' . bin2hex(random_bytes(4)) . '@example.com';
$createResp = req('POST', '/api/admin/accounts.php', [
    'action'        => 'create',
    'format'    => 'json',
    'role_id'       => '2',
    'first_name'    => 'New',
    'last_name'     => 'Staff',
    'email'         => $createEmail,
    'confirm_email' => $createEmail,
    'phone'         => '09171234567',
    'sex'           => 'Male',
    'department_id' => (string) $deptId,
    'staff_role'    => 'Receptionist',
    'csrf_token'    => $adminCsrf,
], $adminJar);

$createJson = json_decode($createResp['body'], true);
check('Create Staff -> 200', $createResp['code'] === 200);
check('Create Staff -> success', !empty($createJson['success']));
$createUserId = userIdByEmail($createEmail);
check('Staff account exists in DB', $createUserId > 0);

// DB state.
$row = null;
$r = mysqli_query($conn, "SELECT Status, MustChangePassword, TempPasswordExpiresAt FROM users WHERE UserID = $createUserId");
if ($r) $row = mysqli_fetch_assoc($r);
check('New account Status is Active', ($row['Status'] ?? '') === 'Active');
check('New account MustChangePassword is 1', (int) ($row['MustChangePassword'] ?? 0) === 1);
check('New account TempPasswordExpiresAt is set', !empty($row['TempPasswordExpiresAt']));

// Read the temp password from the outbox FIRST, then prove it appears
// nowhere else (response, admin page, audit trail).
$staffMail = outboxLatestFor($createEmail);
check('Temporary password e-mail was captured in the outbox', $staffMail !== null);
$staffTempPw = $staffMail ? extractTempPassword($staffMail) : '';
check('Temporary password is 16+ chars', strlen($staffTempPw) >= 16);

check('Response body does not contain the temp password',
    $staffTempPw !== '' && !str_contains($createResp['body'], $staffTempPw)
);

// The admin page (with any flash) must not display the password either.
$flashPage = req('GET', '/admin/admin_account_management.php', [], $adminJar);
check('Admin page does not contain the temp password',
    $staffTempPw !== '' && !str_contains($flashPage['body'], $staffTempPw)
);

// The audit trail must not contain the plaintext password.
$auditRows = [];
$ar = mysqli_query($conn, "SELECT Details FROM audit_trail WHERE UserID = $createUserId ORDER BY AuditID DESC LIMIT 5");
if ($ar) while ($a = mysqli_fetch_assoc($ar)) $auditRows[] = $a['Details'];
$auditStr = implode(' ', $auditRows);
check('Audit trail does not contain the temp password',
    $auditStr === '' || ($staffTempPw !== '' && !str_contains($auditStr, $staffTempPw))
);

// Create a Doctor account.
$docEmail = 'test-doc-' . bin2hex(random_bytes(4)) . '@example.com';
$docResp = req('POST', '/api/admin/accounts.php', [
    'action'         => 'create',
    'format'    => 'json',
    'role_id'        => '4',
    'first_name'     => 'Test',
    'last_name'      => 'Doctor',
    'email'          => $docEmail,
    'confirm_email'  => $docEmail,
    'department_id'  => (string) $deptId,
    'license_number' => 'DOC-' . bin2hex(random_bytes(3)),
    'csrf_token'     => $adminCsrf,
], $adminJar);
$docJson = json_decode($docResp['body'], true);
check('Create Doctor -> success', !empty($docJson['success']));
$docId = userIdByEmail($docEmail);
check('Doctor account exists', $docId > 0);

// Create an Admin account (requires re-authentication).
$outboxBefore = count(glob(outboxDir() . '/*.json') ?: []);
$adminEmail = 'test-admin-' . bin2hex(random_bytes(4)) . '@example.com';
$admResp = req('POST', '/api/admin/accounts.php', [
    'action'          => 'create',
    'format'    => 'json',
    'role_id'         => '1',
    'first_name'       => 'Test',
    'last_name'        => 'Admin',
    'email'            => $adminEmail,
    'confirm_email'    => $adminEmail,
    'reauth_password'  => $primaryAdminPw,
    'csrf_token'       => $adminCsrf,
], $adminJar);
$admJson = json_decode($admResp['body'], true);
check('Create Admin (with re-auth) -> success', !empty($admJson['success']));
$admId = userIdByEmail($adminEmail);
check('Admin account exists', $admId > 0);

// Create-Admin WITHOUT re-auth must fail.
$admEmail2 = 'test-admin2-' . bin2hex(random_bytes(4)) . '@example.com';
$admResp2 = req('POST', '/api/admin/accounts.php', [
    'action'        => 'create',
    'format'    => 'json',
    'role_id'       => '1',
    'first_name'    => 'Test',
    'last_name'     => 'Admin2',
    'email'         => $admEmail2,
    'confirm_email' => $admEmail2,
    'csrf_token'    => $adminCsrf,
], $adminJar);
$admJson2 = json_decode($admResp2['body'], true);
check('Create Admin without re-auth is refused', empty($admJson2['success']));
check('No Admin2 account was created', userIdByEmail($admEmail2) === 0);

/* ========================================================================== */
/*  (b) EXPIRED TEMP PASSWORD REJECTED GENERICALLY                           */
/* ========================================================================== */

section('(b) Expired temporary password rejected');

// Expire the staff account's temp password.
execSql("UPDATE users SET TempPasswordExpiresAt = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE UserID = ?", $createUserId);

execSql('TRUNCATE TABLE auth_rate_limits');
$expiredLogin = login($createEmail, $staffTempPw);
$expiredBody = $expiredLogin['resp']['body'];
check(
    'Expired temp password shows a generic error (not a temp-password-specific one)',
    str_contains($expiredBody, 'Invalid email or password')
);
check(
    'Expired temp password does NOT mention "temporary password" or "expired"',
    stripos($expiredBody, 'temporary password') === false && stripos($expiredBody, 'expired') === false
);

// A wrong password shows the same message.
execSql('TRUNCATE TABLE auth_rate_limits');
$wrongLogin = login($createEmail, 'definitely-wrong-password');
$wrongBody = $wrongLogin['resp']['body'];
check(
    'Wrong password shows the same generic message',
    str_contains($wrongBody, 'Invalid email or password')
);

// Restore the expiry.
execSql("UPDATE users SET TempPasswordExpiresAt = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE UserID = ?", $createUserId);

/* ========================================================================== */
/*  (c) RESEND AND RESET INVALIDATE THE OLD TEMP PASSWORD                    */
/* ========================================================================== */

section('(c) Resend / reset invalidates the old temp password');

$oldHash = (string) scalar('SELECT Password FROM users WHERE UserID = ?', $createUserId);
check('Staff account has a password hash', $oldHash !== '');

// Resend.
execSql('TRUNCATE TABLE auth_rate_limits');
outboxClear();
$outboxBefore1 = outboxFiles();
$resendResp = req('POST', '/api/admin/accounts.php', [
    'action'    => 'resend_password',
    'format'    => 'json',
    'user_id'   => (string) $createUserId,
    'csrf_token' => $adminCsrf,
], $adminJar);
$resendJson = json_decode($resendResp['body'], true);
check('Resend temporary password -> success', !empty($resendJson['success']));

$newHash1 = (string) scalar('SELECT Password FROM users WHERE UserID = ?', $createUserId);
check('Resend changed the password hash', $newHash1 !== $oldHash);
check(
    'Resend set MustChangePassword back to 1',
    (int) scalar('SELECT MustChangePassword FROM users WHERE UserID = ?', $createUserId) === 1
);

// The new temp password e-mail was sent.
$newMail = outboxNewFor($outboxBefore1, $createEmail);
check('New temp password e-mail was captured', $newMail !== null);
$newTempPw = $newMail ? extractTempPassword($newMail) : '';
check('New temp password differs from the old one', $newTempPw !== '' && $newTempPw !== $staffTempPw);

// Reset password (also invalidates).
execSql('TRUNCATE TABLE auth_rate_limits');
$outboxBefore2 = outboxFiles();
$resetResp = req('POST', '/api/admin/accounts.php', [
    'action'      => 'reset_password',
    'format'    => 'json',
    'user_id'     => (string) $createUserId,
    'reauth_password' => $primaryAdminPw,
    'csrf_token'  => $adminCsrf,
], $adminJar);
$resetJson = json_decode($resetResp['body'], true);
check('Reset password -> success', !empty($resetJson['success']));

$newHash2 = (string) scalar('SELECT Password FROM users WHERE UserID = ?', $createUserId);
check('Reset changed the password hash again', $newHash2 !== $newHash1);

// The old temp password no longer works.
execSql('TRUNCATE TABLE auth_rate_limits');
$oldPwLogin = login($createEmail, $newTempPw);
check(
    'The pre-reset temp password is rejected after reset',
    str_contains($oldPwLogin['resp']['body'], 'Invalid email or password')
);

// The latest temp password does work.
$latestMail = outboxNewFor($outboxBefore2, $createEmail);
$latestTempPw = $latestMail ? extractTempPassword($latestMail) : '';
check('Latest temp password is available', $latestTempPw !== '');

/* ========================================================================== */
/*  (d) MustChangePassword BLOCKS EVERY PAGE                                 */
/* ========================================================================== */

section('(d) MustChangePassword gate');

execSql('TRUNCATE TABLE auth_rate_limits');
$forcedLogin = login($createEmail, $latestTempPw);
$forcedJar = $forcedLogin['jar'];
check(
    'Login with temp password redirects to change_password.php',
    str_contains($forcedLogin['redirect'], 'change_password.php')
);

$blockedPages = [
    '/admin/admin_dashboard.php',
    '/admin/admin_account_management.php',
    '/staff/staff_dashboard.php',
    '/patient/patient_dashboard.php',
    '/doctor/doctor_dashboard.php',
    '/auth/setup_totp.php',
    '/auth/totp_verify.php',
];
foreach ($blockedPages as $page) {
    $resp = req('GET', $page, [], $forcedJar);
    $loc = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $resp['headers'], $m)) {
        $loc = trim($m[1]);
    }
    check("MustChangePassword blocks GET $page", str_contains($loc, 'change_password.php') || $resp['code'] === 302);
}

// change_password.php itself is reachable.
$cpPage = req('GET', '/auth/change_password.php', [], $forcedJar);
check('change_password.php is reachable while MustChangePassword is set', $cpPage['code'] === 200);

/* ========================================================================== */
/*  (e) NEW PASSWORD POLICY                                                   */
/* ========================================================================== */

section('(e) Password policy on change');

$cpCsrf = csrfFrom($cpPage['body']);

// Try to set the new password to the temp password.
$resp1 = req('POST', '/auth/change_password.php', [
    'current_password' => $latestTempPw,
    'new_password'     => $latestTempPw,
    'confirm_password' => $latestTempPw,
    'csrf_token'       => $cpCsrf,
], $forcedJar);
check(
    'New password cannot equal the current (temp) password',
    str_contains($resp1['body'], 'must be different from your current password')
);

// Try a short password.
$resp2 = req('POST', '/auth/change_password.php', [
    'current_password' => $latestTempPw,
    'new_password'     => 'Ab1!',
    'confirm_password' => 'Ab1!',
    'csrf_token'       => $cpCsrf,
], $forcedJar);
check('Short password is rejected', str_contains($resp2['body'], 'at least 12'));

// Try a 12-char password with no symbol.
$resp3 = req('POST', '/auth/change_password.php', [
    'current_password' => $latestTempPw,
    'new_password'     => 'Abcdefghij12',
    'confirm_password' => 'Abcdefghij12',
    'csrf_token'       => $cpCsrf,
], $forcedJar);
check('Password without a symbol is rejected', str_contains($resp3['body'], 'special character'));

// Try a common password.
$resp4 = req('POST', '/auth/change_password.php', [
    'current_password' => $latestTempPw,
    'new_password'     => 'Password1234!',
    'confirm_password' => 'Password1234!',
    'csrf_token'       => $cpCsrf,
], $forcedJar);
check('Common password is rejected', str_contains($resp4['body'], 'too common') || str_contains($resp4['body'], 'different'));

/* ========================================================================== */
/*  (f) POST-CHANGE SECURITY                                                  */
/* ========================================================================== */

section('(f) Post-change security');

// Open a SECOND session for the same account (another "device").
execSql('TRUNCATE TABLE auth_rate_limits');
$secondLogin = login($createEmail, $latestTempPw);
$secondJar = $secondLogin['jar'];
check('Second session can log in with the temp password', $secondLogin['redirect'] !== '');

// Change the password from the FIRST session.
$newPermanent = 'BrandNewPass99!';
$changeResp = req('POST', '/auth/change_password.php', [
    'current_password' => $latestTempPw,
    'new_password'     => $newPermanent,
    'confirm_password' => $newPermanent,
    'csrf_token'       => $cpCsrf,
], $forcedJar);
$changeLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $changeResp['headers'], $m)) {
    $changeLoc = trim($m[1]);
}
check('Password change succeeds', str_contains($changeLoc, 'dashboard') || $changeResp['code'] === 302);

// MustChangePassword is now cleared.
check(
    'MustChangePassword is cleared after change',
    (int) scalar('SELECT MustChangePassword FROM users WHERE UserID = ?', $createUserId) === 0
);
check(
    'TempPasswordExpiresAt is cleared after change',
    scalar('SELECT TempPasswordExpiresAt FROM users WHERE UserID = ?', $createUserId) === null
);

// The old temp password no longer works.
execSql('TRUNCATE TABLE auth_rate_limits');
$oldLogin2 = login($createEmail, $latestTempPw);
check(
    'Old temp password is rejected after change',
    str_contains($oldLogin2['resp']['body'], 'Invalid email or password')
);

// The second session is revoked.
$secondDash = req('GET', '/staff/staff_dashboard.php', [], $secondJar);
$secondLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $secondDash['headers'], $m)) {
    $secondLoc = trim($m[1]);
}
check(
    'Second session is revoked after password change',
    str_contains($secondLoc, 'login.php') || $secondDash['code'] === 302
);

// The new password works.
execSql('TRUNCATE TABLE auth_rate_limits');
$newLogin = login($createEmail, $newPermanent);
check('New password works after change', $newLogin['redirect'] !== '' && !str_contains($newLogin['resp']['body'], 'Invalid email or password'));

/* ========================================================================== */
/*  (i) FORGOT PASSWORD DOES NOT CHANGE THE CURRENT PASSWORD                 */
/* ========================================================================== */

section('(i) Self-service forgot password safety');

$hashBefore = (string) scalar('SELECT Password FROM users WHERE UserID = ?', $createUserId);
$mcpBefore  = (int) scalar('SELECT MustChangePassword FROM users WHERE UserID = ?', $createUserId);

execSql('TRUNCATE TABLE auth_rate_limits');
outboxClear();

// Request a reset for the Staff account.
$fpPage = req('GET', '/auth/forgotpassword.php');
$fpJar = sessionCookies($fpPage);
$fpCsrf = csrfFrom($fpPage['body']);
$fpResp = req('POST', '/auth/forgotpassword.php', [
    'email'      => $createEmail,
    'csrf_token' => $fpCsrf,
], $fpJar);
$fpJson = json_decode($fpResp['body'], true);
$fpMessage = $fpJson['message'] ?? '';
if ($fpMessage === '' && preg_match('/class="message[^"]*">\s*<i[^>]*><\/i>\s*([^<]+)/', $fpResp['body'], $m)) {
    $fpMessage = trim($m[1]);
}

$hashAfter = (string) scalar('SELECT Password FROM users WHERE UserID = ?', $createUserId);
$mcpAfter  = (int) scalar('SELECT MustChangePassword FROM users WHERE UserID = ?', $createUserId);

check('Forgot password does NOT change the password hash', $hashBefore === $hashAfter);
check('Forgot password does NOT set MustChangePassword', $mcpBefore === $mcpAfter);
check('Forgot password returns the generic success message',
    str_contains($fpResp['body'], 'If the email is registered')
);

// The same request for an unknown address must produce an identical response.
execSql('TRUNCATE TABLE auth_rate_limits');
$fpResp2 = req('POST', '/auth/forgotpassword.php', [
    'email'      => 'nobody-here-' . bin2hex(random_bytes(4)) . '@example.com',
    'csrf_token' => $fpCsrf,
], $fpJar);
check('Unknown e-mail produces the same generic message',
    str_contains($fpResp2['body'], 'If the email is registered')
);

// The reset link e-mail was captured.
$resetMail = outboxLatestFor($createEmail);
check('Reset-link e-mail was captured in the outbox', $resetMail !== null);
$resetToken = $resetMail ? extractResetToken($resetMail) : '';
check('Reset token is a 64-char hex string', preg_match('/^[a-f0-9]{64}$/', $resetToken) === 1);

// Rate limiting: the per-IP and per-email throttles kick in. The limit is 3
// per hour, so the fourth consecutive request must be refused.
$fpResp3 = req('POST', '/auth/forgotpassword.php', [
    'email'      => $createEmail,
    'csrf_token' => $fpCsrf,
], $fpJar);
$fpResp4 = req('POST', '/auth/forgotpassword.php', [
    'email'      => $createEmail,
    'csrf_token' => $fpCsrf,
], $fpJar);
$fpResp5 = req('POST', '/auth/forgotpassword.php', [
    'email'      => $createEmail,
    'csrf_token' => $fpCsrf,
], $fpJar);
$fpResp6 = req('POST', '/auth/forgotpassword.php', [
    'email'      => $createEmail,
    'csrf_token' => $fpCsrf,
], $fpJar);
check('Fourth consecutive forgot-password request is rate-limited',
    str_contains($fpResp6['body'], 'Too many requests')
);

// Each successful request above re-issued (and therefore invalidated) the
// earlier link, so ask for one final fresh link and use that token below.
execSql('TRUNCATE TABLE auth_rate_limits');
outboxClear();
$fpFresh = req('POST', '/auth/forgotpassword.php', [
    'email'      => $createEmail,
    'csrf_token' => $fpCsrf,
], $fpJar);
check('Fresh forgot-password request succeeds after the rate-limit window is cleared',
    str_contains($fpFresh['body'], 'If the email is registered')
);

// Re-capture the newest link (the one issued by the fresh request).
$resetMail = outboxLatestFor($createEmail);
check('Reset-link e-mail was captured in the outbox', $resetMail !== null);
$resetToken = $resetMail ? extractResetToken($resetMail) : '';
check('Reset token is a 64-char hex string', preg_match('/^[a-f0-9]{64}$/', $resetToken) === 1);

/* ========================================================================== */
/*  (j) RESET TOKEN SINGLE-USE                                               */
/* ========================================================================== */

section('(j) Reset token single-use');

// GET does not consume the token.
$resetGet = req('GET', '/auth/reset_password.php?token=' . $resetToken);
$resetJar = sessionCookies($resetGet);
check('GET reset_password.php -> 200', $resetGet['code'] === 200);
check('GET reset_password.php shows the form', str_contains($resetGet['body'], 'Choose a New Password') || str_contains($resetGet['body'], 'new_password'));

// Token is still valid (not consumed by GET).
$stillValid = (int) scalar(
    "SELECT COUNT(*) FROM account_setup_tokens WHERE TokenHash = ? AND Kind = 'reset' AND UsedAt IS NULL",
    hash('sha256', $resetToken)
);
check('GET did NOT consume the token', $stillValid === 1);

// POST with mismatched passwords does not consume the token.
$resetCsrf = csrfFrom($resetGet['body']);
$badPost = req('POST', '/auth/reset_password.php', [
    'token'            => $resetToken,
    'current_password' => $newPermanent,
    'new_password'     => 'MismatchOne1!',
    'confirm_password' => 'MismatchTwo1!',
    'csrf_token'       => $resetCsrf,
], $resetJar);
check('Mismatched new/confirm is rejected', str_contains($badPost['body'], 'do not match'));

$stillValid = (int) scalar(
    "SELECT COUNT(*) FROM account_setup_tokens WHERE TokenHash = ? AND Kind = 'reset' AND UsedAt IS NULL",
    hash('sha256', $resetToken)
);
check('Failed POST did NOT consume the token', $stillValid === 1);

// POST with a wrong current password does not consume the token.
$badPost2 = req('POST', '/auth/reset_password.php', [
    'token'            => $resetToken,
    'current_password' => 'totally-wrong-password',
    'new_password'     => 'BrandNewPass99!!',
    'confirm_password' => 'BrandNewPass99!!',
    'csrf_token'       => $resetCsrf,
], $resetJar);
check('Wrong current password is rejected', str_contains($badPost2['body'], 'current password is incorrect'));

$stillValid = (int) scalar(
    "SELECT COUNT(*) FROM account_setup_tokens WHERE TokenHash = ? AND Kind = 'reset' AND UsedAt IS NULL",
    hash('sha256', $resetToken)
);
check('Wrong-current-password POST did NOT consume the token', $stillValid === 1);

// POST with the correct current password consumes the token and changes the password.
$finalPw = 'FinalResetPass77!';
$goodPost = req('POST', '/auth/reset_password.php', [
    'token'            => $resetToken,
    'current_password' => $newPermanent,
    'new_password'     => $finalPw,
    'confirm_password' => $finalPw,
    'csrf_token'       => $resetCsrf,
], $resetJar);
$goodLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $goodPost['headers'], $m)) {
    $goodLoc = trim($m[1]);
}
check('Valid reset POST redirects to login', str_contains($goodLoc, 'login.php'));

// Token is now consumed.
$consumed = (int) scalar(
    "SELECT COUNT(*) FROM account_setup_tokens WHERE TokenHash = ? AND Kind = 'reset' AND UsedAt IS NULL",
    hash('sha256', $resetToken)
);
check('Token is consumed after successful reset', $consumed === 0);

// Replay is rejected.
$replay = req('POST', '/auth/reset_password.php', [
    'token'            => $resetToken,
    'current_password' => $finalPw,
    'new_password'     => 'AnotherPass88!',
    'confirm_password' => 'AnotherPass88!',
    'csrf_token'       => $resetCsrf,
], $resetJar);
check('Replayed token is rejected',
    str_contains($replay['body'], 'invalid or has expired') || $replay['code'] === 302
);

// GET with the consumed token shows the generic error.
$replayGet = req('GET', '/auth/reset_password.php?token=' . $resetToken);
check('GET with a consumed token shows the generic error',
    str_contains($replayGet['body'], 'invalid or has expired')
);

// The new password works.
execSql('TRUNCATE TABLE auth_rate_limits');
$finalLogin = login($createEmail, $finalPw);
check('New password after reset works', !str_contains($finalLogin['resp']['body'], 'Invalid email or password'));

// MustChangePassword is cleared by the reset.
check(
    'MustChangePassword is 0 after self-service reset',
    (int) scalar('SELECT MustChangePassword FROM users WHERE UserID = ?', $createUserId) === 0
);

/* ========================================================================== */
/*  (k) PUBLIC REGISTRATION IS PATIENT-ONLY                                  */
/* ========================================================================== */

section('(k) Public registration is patient-only');

$regPage = req('GET', '/auth/signup.php');
$regCsrf = csrfFrom($regPage['body']);
$regEmail = 'test-reg-' . bin2hex(random_bytes(4)) . '@example.com';
$regResp = req('POST', '/auth/signup.php', [
    'first_name'    => 'Test',
    'last_name'     => 'Reg',
    'email'         => $regEmail,
    'confirm_email' => $regEmail,
    'password'      => 'PatientPass123!',
    'confirm_password' => 'PatientPass123!',
    'role_id'       => '1', // try to force Admin
    'csrf_token'    => $regCsrf,
]);
$regId = userIdByEmail($regEmail);
if ($regId > 0) {
    $regRole = (int) scalar('SELECT RoleID FROM users WHERE UserID = ?', $regId);
    check('Self-registration cannot force Admin role', $regRole === 3);
} else {
    check('Self-registration cannot force Admin role (account rejected)', true);
}

/* ========================================================================== */
/*  (l) SELF-DEACTIVATE / SELF-DELETE REFUSED                                */
/* ========================================================================== */

section('(l) Self-deactivate and self-delete refused');

execSql('TRUNCATE TABLE auth_rate_limits');
outboxClear();

// Re-login as admin (may need TOTP).
$adminLogin2 = login('admin@curoraportal.com', $primaryAdminPw);
$adminJar2 = $adminLogin2['jar'];
if (str_contains($adminLogin2['redirect'], 'verify_totp') || str_contains($adminLogin2['redirect'], 'totp')) {
    $vPage2 = req('GET', '/auth/totp_verify.php', [], $adminJar2);
    $vCsrf2 = csrfFrom($vPage2['body']);
    $vCode2 = totpCodeAt($adminSecret, (int) floor(time() / 30));
    $vResp2 = req('POST', '/auth/totp_verify.php', [
        'code' => $vCode2,
        'csrf_token' => $vCsrf2,
    ], $adminJar2);
    $adminJar2 = array_merge($adminJar2, sessionCookies($vResp2));
}

$mgmtPage2 = req('GET', '/admin/admin_account_management.php', [], $adminJar2);
$adminCsrf2 = csrfFrom($mgmtPage2['body']);

// Try to deactivate own account.
$toggleSelf = req('POST', '/api/admin/accounts.php', [
    'action'     => 'toggle',
    'format'    => 'json',
    'user_id'    => (string) $primaryAdminId,
    'csrf_token' => $adminCsrf2,
], $adminJar2);
$toggleSelfJson = json_decode($toggleSelf['body'], true);
check('Admin cannot deactivate own account', empty($toggleSelfJson['success']));

// Try to delete own account.
$deleteSelf = req('POST', '/api/admin/accounts.php', [
    'action'          => 'delete',
    'format'    => 'json',
    'user_id'         => (string) $primaryAdminId,
    'reauth_password' => $primaryAdminPw,
    'csrf_token'      => $adminCsrf2,
], $adminJar2);
$deleteSelfJson = json_decode($deleteSelf['body'], true);
check('Admin cannot delete own account', empty($deleteSelfJson['success']));

/* ========================================================================== */
/*  (m) UPDATE CANNOT ESCALATE ROLE                                           */
/* ========================================================================== */

section('(m) Update cannot escalate role');

$escEmail = 'test-esc-' . bin2hex(random_bytes(4)) . '@example.com';
$escHash  = password_hash('TempEscPass123!', PASSWORD_BCRYPT, ['cost' => 12]);
execSql(
    "INSERT INTO users (FirstName, LastName, Email, Password, RoleID, Status, MustChangePassword, Sex)
     VALUES ('Esc', 'Test', ?, ?, 2, 'Active', 0, 'Not Specified')",
    $escEmail,
    $escHash
);
$escId = userIdByEmail($escEmail);
check('Escalation fixture created', $escId > 0);

$escUpdate = req('POST', '/api/admin/accounts.php', [
    'action'     => 'update',
    'format'    => 'json',
    'user_id'    => (string) $escId,
    'role_id'    => '1', // try to promote to Admin
    'first_name' => 'Esc',
    'last_name'  => 'Test',
    'email'      => $escEmail,
    'csrf_token' => $adminCsrf2,
], $adminJar2);
$escRole = (int) scalar('SELECT RoleID FROM users WHERE UserID = ?', $escId);
check('Update cannot escalate role', $escRole === 2);

/* ========================================================================== */
/*  (n) DELETE REFUSED WITH LINKED RECORDS                                   */
/* ========================================================================== */

section('(n) Delete refused with linked records');

// Create a patient with a linked appointment so delete is blocked.
$linkedEmail = 'test-linked-' . bin2hex(random_bytes(4)) . '@example.com';
$linkedHash  = password_hash('LinkedTemp123!', PASSWORD_BCRYPT, ['cost' => 12]);
execSql(
    "INSERT INTO users (FirstName, LastName, Email, Password, RoleID, Status, MustChangePassword, Sex)
     VALUES ('Linked', 'Test', ?, ?, 2, 'Active', 0, 'Not Specified')",
    $linkedEmail,
    $linkedHash
);
$linkedId = userIdByEmail($linkedEmail);
execSql('INSERT INTO staff (UserID, DepartmentID, StaffRole) VALUES (?, ?, "Staff")', $linkedId, $deptId);
$staffRowId = (int) scalar('SELECT StaffID FROM staff WHERE UserID = ?', $linkedId);

// Insert a patient with an appointment booked with this staff member so the
// account has a linked record that blocks deletion.
$doctorStaffId = (int) scalar('SELECT StaffID FROM staff WHERE StaffRole = "Doctor" LIMIT 1');
$linkedApptId  = 0;
if ($doctorStaffId > 0 && $staffRowId > 0) {
    execSql(
        "INSERT INTO patients (FirstName, LastName, Status) VALUES ('Linked', 'Pat', 'Active')"
    );
    $patId = (int) scalar("SELECT PatientID FROM patients ORDER BY PatientID DESC LIMIT 1");
    if ($patId > 0) {
        execSql(
            "INSERT INTO appointments (PatientID, StaffID, DepartmentID, AppointmentDate, AppointmentTime, Status)
             VALUES (?, ?, ?, CURDATE() + INTERVAL 1 DAY, '10:00:00', 'Pending')",
            $patId,
            $staffRowId,
            $deptId
        );
        $linkedApptId = (int) scalar('SELECT AppointmentID FROM appointments ORDER BY AppointmentID DESC LIMIT 1');
    }
}

$linkedDelete = req('POST', '/api/admin/accounts.php', [
    'action'          => 'delete',
    'format'    => 'json',
    'user_id'         => (string) $linkedId,
    'reauth_password' => $primaryAdminPw,
    'csrf_token'      => $adminCsrf2,
], $adminJar2);
$linkedDeleteJson = json_decode($linkedDelete['body'], true);
check('Delete is refused when the account has linked records', empty($linkedDeleteJson['success']));

/* ========================================================================== */
/*  (o) DEACTIVATION REVOKES SESSIONS                                        */
/* ========================================================================== */

section('(o) Deactivation revokes sessions');

$revokeEmail = 'test-revoke-' . bin2hex(random_bytes(4)) . '@example.com';
$revokePw    = 'RevokePass123!';
$revokeHash  = password_hash($revokePw, PASSWORD_BCRYPT, ['cost' => 12]);
execSql(
    "INSERT INTO users (FirstName, LastName, Email, Password, RoleID, Status, MustChangePassword, Sex)
     VALUES ('Revoke', 'Test', ?, ?, 2, 'Active', 0, 'Not Specified')",
    $revokeEmail,
    $revokeHash
);
$revokeId = userIdByEmail($revokeEmail);
execSql('INSERT INTO staff (UserID, DepartmentID, StaffRole) VALUES (?, ?, "Staff")', $revokeId, $deptId);

execSql('TRUNCATE TABLE auth_rate_limits');
$revokeLogin = login($revokeEmail, $revokePw);
$revokeJar = $revokeLogin['jar'];

// The session works before deactivation.
$preDash = req('GET', '/staff/staff_dashboard.php', [], $revokeJar);
check('Session works before deactivation', $preDash['code'] === 200);

// Deactivate the account.
$deact = req('POST', '/api/admin/accounts.php', [
    'action'     => 'toggle',
    'format'    => 'json',
    'user_id'    => (string) $revokeId,
    'csrf_token' => $adminCsrf2,
], $adminJar2);
$deactJson = json_decode($deact['body'], true);
check('Deactivation succeeds', !empty($deactJson['success']));

// The session is revoked.
$postDash = req('GET', '/staff/staff_dashboard.php', [], $revokeJar);
$postLoc = '';
if (preg_match('/^Location:\s*(.+)$/mi', $postDash['headers'], $m)) {
    $postLoc = trim($m[1]);
}
check('Session is revoked after deactivation', str_contains($postLoc, 'login.php') || $postDash['code'] === 302);

// Reactivate for cleanup.
execSql("UPDATE users SET Status = 'Active' WHERE UserID = ?", $revokeId);

/* ========================================================================== */
/*  (p) ANTI-CLICKJACKING HEADERS                                            */
/* ========================================================================== */

section('(p) Security headers on admin pages');

$hdrPage = req('GET', '/admin/admin_account_management.php', [], $adminJar2);
check('X-Frame-Options: DENY on admin page', stripos($hdrPage['headers'], 'X-Frame-Options: DENY') !== false);
check('X-Content-Type-Options: nosniff on admin page', stripos($hdrPage['headers'], 'X-Content-Type-Options: nosniff') !== false);

$hdrApi = req('GET', '/api/admin/accounts.php', [], $adminJar2);
check('X-Frame-Options: DENY on API', stripos($hdrApi['headers'], 'X-Frame-Options: DENY') !== false);
check('Cache-Control: no-store on API', stripos($hdrApi['headers'], 'Cache-Control: no-store') !== false);

/* ========================================================================== */
/*  SETUP-LINK MACHINERY IS GONE                                             */
/* ========================================================================== */

section('Setup-link machinery removed');

check('setup-password.php returns 404', req('GET', '/setup-password.php')['code'] === 404);
check('includes/setup_tokens.php does not exist', !file_exists(__DIR__ . '/../includes/setup_tokens.php'));
check('No account_setup_tokens rows with Kind=link remain',
    (int) scalar("SELECT COUNT(*) FROM account_setup_tokens WHERE Kind = 'link'") === 0
);

/* ========================================================================== */
/*  CLEANUP                                                                   */
/* ========================================================================== */

section('Cleanup');

foreach ([$createUserId, $docId, $admId, $escId, $linkedId, $revokeId, $staffId] as $uid) {
    cleanupAccount($uid);
}
// Also clean the patient/appointment fixtures.
if ($linkedApptId > 0) {
    execSql('DELETE FROM appointments WHERE AppointmentID = ?', $linkedApptId);
}
execSql("DELETE FROM patients WHERE FirstName = 'Linked' AND LastName = 'Pat'");
execSql("DELETE FROM account_setup_tokens WHERE Kind = 'reset'");
execSql('TRUNCATE TABLE auth_rate_limits');
outboxClear();

check('Cleanup removed the test accounts', (int) scalar("SELECT COUNT(*) FROM users WHERE Email LIKE 'test-%@example.com'") === 0);

/* ========================================================================== */
/*  SUMMARY                                                                   */
/* ========================================================================== */

echo "\n========================================\n";
echo "  PASSED: $pass\n";
echo "  FAILED: $fail\n";
echo "========================================\n";

if ($fail > 0) {
    echo "\nFailed checks:\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "All checks passed.\n";
exit(0);
