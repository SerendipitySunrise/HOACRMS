# Account Creation Restructure — Security Documentation

**Scope:** Restrict **public self-registration to PATIENTS ONLY** and move every
Admin / Doctor / Staff account behind an **authenticated, RBAC-enforced** Admin
workflow.

**Status:** Implemented, migrations applied, automated tests passing (44/44).

> **Update (account-management hardening review):** the temporary password no
> longer travels in the URL (one-time session flash), an admin can no longer
> deactivate/delete their own or the last active admin account, deactivation and
> password resets revoke live sessions, and every admin page sends
> anti-clickjacking headers. See §2.4.

---

## 1. Current flow (before) and its vulnerabilities

### 1.1 Before

`portal-select.php` was the public landing chooser. It offered **Register** buttons
for four portals and linked to four separate scripts:

| Portal  | Registration script        | Role created |
|---------|----------------------------|--------------|
| Patient | `auth/signup.php`          | Patient (3)  |
| Staff   | `auth/register_staff.php`  | Staff (2)    |
| Doctor  | `auth/register_doctor.php` | Doctor (4)   |
| Admin   | `auth/register_admin.php`  | Admin (1)    |

`includes/register_gate.php` + keys in `includes/config.php` guarded only the
Admin/Staff/Doctor forms with a shared "invitation" code.

### 1.2 Vulnerabilities found

1. **Public privilege creation.** `register_admin.php`, `register_staff.php`, and
   `register_doctor.php` were reachable by URL by anyone on the internet. The only
   barrier was a static invitation key, which is not an authentication control and
   leaks easily (shared in chat, committed to config, guessable).
2. **No server-side authorisation.** Even once the pages were removed from the UI,
   nothing prevented a direct POST to the scripts. Privilege escalation was a
   single crafted request.
3. **Client-trust on role.** Role selection was a request parameter, so the
   client influenced what was created.
4. **Invitation code = weak, unrotatable secret.** One key for all staff roles, no
   expiry, no per-user binding, no audit of who used it.
5. **Weak/unknown credentials for created staff.** `admin_staff_management.php`
   hashed `bin2hex(random_bytes(6))` and never showed it, so admin-created staff
   could never sign in (functional bug) — while `admin_doctor_management.php`
   issued a temp password with no forced change.
6. **No brute-force protection** on login or registration.
7. **No e-mail verification** for self-registered patients (spam/fake accounts).
8. **No CSRF protection** on account-management forms.
9. **No first-login password change** requirement for admin-issued credentials.
10. **No audit trail** tying an account creation to the acting administrator.

---

## 2. New flow (after)

### 2.1 Public website

* `portal-select.php` now shows four **Sign in** cards (patient / staff / doctor /
  admin) and **one** public action: **Create Account → `auth/signup.php`**.
* `auth/signup.php` is **Patient-only**. It:
  * accepts a CSRF token,
  * is per-IP rate limited (`RATE_LIMIT_REGISTER_PER_HOUR`, default 10/h),
  * **discards** any posted `role` / `role_id` / `RoleID` / `roleId` and hard-codes
    `RoleID = 3`,
  * creates the account **unverified** and e-mails a single-use verification link.
* Unverified patients cannot sign in (`REQUIRE_EMAIL_VERIFICATION`); a
  `resend_verification.php` page issues fresh links.
* The old `register_admin.php` / `register_staff.php` / `register_doctor.php`
  routes are **deleted** (return HTTP 404).

### 2.2 Admin workspace

* New page **Account Management** (`admin/admin_account_management.php`), linked in
  the admin sidebar, and a matching endpoint `api/admin/accounts.php`.
* The endpoint enforces, server-side:
  * **RBAC → HTTP 403** for any caller that is not an active Admin,
  * **CSRF** validation,
  * a **managed-role whitelist** `{1 Admin, 2 Staff, 4 Doctor}` — patients can never
    be created here.
* Supported operations: **create, update, enable/disable, reset password, delete**
  (self-delete, self-deactivate, last-active-admin deactivate/delete, and patient
  deletes are blocked — in the UI **and** server-side).
* New accounts get a generated temporary password, `MustChangePassword = 1`, and
  `EmailVerified = 1`; the Admin is shown the temp password **once**.
* Every action is written to the audit log and raises an admin notification.
* This is the **single authoritative creation route**: `admin_staff_management.php`
  and `admin_doctor_management.php` redirect any `action=add` POST here and their
  "Add" buttons navigate to Account Management. Their toggle/delete actions are
  routed through `account_service.php` so revocation, audit and the linked-record
  guard apply everywhere.
* Flash messages (status/message/generated password) travel in a one-time
  `$_SESSION['flash']`, never in the query string, so they cannot leak through
  history/logs/referrers or be forged by a crafted link.

### 2.4 Account-management hardening (review follow-up)

| Concern | Fix |
|---------|-----|
| Temp password in `?temp=` (forgeable `status`/`message`) | One-time `$_SESSION['flash']`, read then cleared by the console; `Cache-Control: no-store` on the page |
| Admin could deactivate/delete self or the last active admin | Buttons hidden for self / last active admin **and** blocked in `account_service.php` |
| Deactivation/reset left open sessions alive | `users.SessionVersion` counter + `includes/session_liveness.php`; sign-in copies the value, deactivate/reset increments it, every request re-checks it |
| Duplicate `status` select in the edit form | Single "Account status" control |
| Hidden role fields still posted | Hidden `data-role` controls are `disabled` in JS; the endpoint also ignores fields that don't belong to the role |
| Department matched by name | Console returns `DepartmentID` and matches by ID |
| Hard delete orphaning history | `accountDelete()` refuses when appointments/consultations/vitals/audit history reference the account (deactivate instead) |
| `h()` helper collisions | Guarded with `function_exists()` |
| Clickjacking / MIME sniffing | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy` from `includes/session.php`, `includes/password_gate.php`, `api/admin/accounts.php` |
| Role escalation via edit | `accountUpdate()` strips client `role_id`/`RoleID`; an account's role is fixed at creation |
| Plaintext password by e-mail | Removed; the password is shown once in the flash instead (a one-time set-password link is the natural next step) |

Account-list UX: search (name/e-mail), role filter, pagination, `overflow-x`
table wrapper, named confirmation dialogs, and `pattern="09[0-9]{9}"` phone
inputs.

### 2.3 Cross-cutting hardened controls

| Control | Where |
|---------|-------|
| RBAC (403) | `includes/auth_rbac.php`, `api/admin/accounts.php`, admin `requireRole('Admin')` |
| CSRF | `includes/csrf.php` (all POST forms) |
| Rate limiting / lockout | `includes/rate_limit.php` + `auth_rate_limits` table; per-IP login & registration windows; existing `FailedAttempts`/`LockUntil` lockout kept |
| Password hashing | `CURORA_PASSWORD_ALGO` = bcrypt, cost 12 (`includes/security_config.php`) |
| Password policy | existing `validatePasswordStrength()` (min 8, upper, lower, digit, special) |
| First-login password change | `users.MustChangePassword`, gate in `includes/session.php` + `includes/password_gate.php`, screen `auth/change_password.php` |
| Session revocation | `users.SessionVersion` + `includes/session_liveness.php` — deactivating an account or resetting its password signs out its live sessions; `auth/login.php` also rejects `Inactive` accounts |
| Browser hardening | `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Cache-Control: no-store` on admin pages |
| E-mail verification | `users.EmailVerified/EmailVerificationToken/EmailVerificationExpiry`, `auth/verify_email.php` |
| Audit trail | `includes/audit_helper.php` (`CREATE`, `UPDATE`, `ACTIVATE`, `DEACTIVATE`, `RESET_PASSWORD`, `DELETE`, `LOGIN`, `VERIFY_EMAIL`, `CHANGE_PASSWORD`), filterable in `admin/admin_system_settings.php` |

---

## 3. Files

### 3.1 Added — application

| File | Purpose |
|------|---------|
| `includes/security_config.php` | Central security flags/constants (verification, hashing, lockout, rate limits). |
| `includes/csrf.php` | `csrfToken()` / `csrfField()` / `csrfValidate()`. |
| `includes/rate_limit.php` | `rateLimitAllowed()` / `rateLimitRecord()` / `rateLimitClear()` backed by `auth_rate_limits`. |
| `includes/auth_rbac.php` | `rbacCurrentUser()`, `rbacIsAdmin()`, `rbacRequireAdmin()`. |
| `includes/account_service.php` | Single source of truth for account CRUD, role whitelist, temp passwords, audit. |
| `includes/password_gate.php` | `passwordGateEnforce()` for doctor/staff/patient dashboards (liveness + headers + forced change). |
| `includes/session_liveness.php` | `appBasePath()`, `sessionLivenessReason()`, `sessionRevokeAllFor()`, `sessionEnforceLiveness()` — per-account session revocation. |
| `api/admin/accounts.php` | Admin-only JSON/redirect endpoint — RBAC **(403)**, CSRF, create/update/toggle/reset/delete. |
| `admin/admin_account_management.php` | Admin-only UI for the above. |
| `auth/verify_email.php` | Consumes a one-time verification token. |
| `auth/resend_verification.php` | Issues a fresh verification link (enumeration-safe response). |
| `auth/change_password.php` | Voluntary + forced password change screen. |

### 3.2 Added — data & tests

| File | Purpose |
|------|---------|
| `database/migrations/2026_10_17_account_security.sql` | Schema migration (applied). |
| `database/migrations/2026_10_17_account_security_rollback.sql` | Reverses the migration. |
| `database/migrations/2026_10_17_account_session_revocation.sql` | Adds `users.SessionVersion` (applied). |
| `database/migrations/2026_10_17_account_session_revocation_rollback.sql` | Reverses it. |
| `tests/account_security_tests.php` | End-to-end HTTP + DB test suite (cases a–i). |

### 3.3 Removed (public staff registration)

* `auth/register_admin.php`
* `auth/register_staff.php`
* `auth/register_doctor.php`
* `includes/config.php` (invitation keys)
* `includes/register_gate.php`
* `assets/css/auth/register_admin.css`
* `assets/css/auth/register_staff.css`

### 3.4 Modified

* `portal-select.php` — Sign-in-only cards + single patient **Create Account**.
* `index.php` — landing CTAs point to `auth/signup.php`.
* `auth/signup.php` — CSRF, rate limit, role-forcing, verification e-mail.
* `auth/login.php` — per-IP rate limit, verification gate, forced password change,
  corrected "create account" link.
* `includes/session.php` — must-change-password gate, session-liveness check and
  anti-clickjacking/`no-store` headers.
* `admin/admin_staff_management.php`, `admin/admin_doctor_management.php`,
  `admin/admin_patient_management.php` — **deleted**; all admin sidebars, the
  dashboard quick-actions and the reports nav link to Account Management only.
  (Earlier interim state: creation removed, pages redirected here.)
* `auth/resetpassword.php` — clears `MustChangePassword` and bumps
  `SessionVersion` on reset.
* `includes/portal.php` — removed portal→registration mapping and
  `registrationInvitationValid()`.
* `includes/mailer.php` — bounded SMTP timeout (15 s) so a dead mail server can't
  stall a request.
* `admin/admin_dashboard.php` — sidebar **Account Management** link.
* `staff/staff_dashboard.php`, `doctor/doctor_dashboard.php`,
  `patient/patient_dashboard.php` — call `passwordGateEnforce()`.
* `admin/admin_system_settings.php` — audit-log action filter now lists the new
  security actions (`VERIFY_EMAIL`, `CHANGE_PASSWORD`, `RESET_PASSWORD`,
  `ACTIVATE`, `DEACTIVATE`).

---

## 4. Database migration

`database/migrations/2026_10_17_account_security.sql` (idempotent, guarded by
`information_schema` checks):

* `users.MustChangePassword TINYINT(1) NOT NULL DEFAULT 0`
* `users.EmailVerified TINYINT(1) NOT NULL DEFAULT 1`
  *(default 1 so all pre-existing/demo accounts keep working; new self-registered
  patients are explicitly set to 0)*
* `users.EmailVerificationToken VARCHAR(64) NULL`
* `users.EmailVerificationExpiry DATETIME NULL`
* table `auth_rate_limits (Scope, Identifier, Attempts, WindowStart, LockedUntil)`
  with `UNIQUE (Scope, Identifier)`.

`database/migrations/2026_10_17_account_session_revocation.sql` (idempotent):

* `users.SessionVersion INT NOT NULL DEFAULT 0` — bumped on deactivate / password
  reset to invalidate live sessions.

Apply both (DB name is `hoacrms`):

```bash
mysql -u root hoacrms < database/migrations/2026_10_17_account_security.sql
mysql -u root hoacrms < database/migrations/2026_10_17_account_session_revocation.sql
```

Roll back with the matching `*_rollback.sql` files (in reverse order).

---

## 5. Test cases

Run:

```powershell
C:\wamp64\bin\php\php8.3.14\php.exe tests/account_security_tests.php
```

The suite drives the **live app over HTTP** (real RBAC/CSRF) and inspects the
database. It truncates `auth_rate_limits` before and after and cleans up every
account it creates. Base URL override: `CURORA_BASE_URL`.

### 4.1 Required cases

| # | Case | Assertion | Result |
|---|------|-----------|--------|
| a | Patient cannot reach a staff-registration route | `GET /auth/register_{admin,staff,doctor}.php` → **404** | PASS |
| b | Non-admin gets **403** on account creation | unauthenticated **and** authenticated Staff POST `api/admin/accounts.php` → **403** | PASS |
| c | Admin can create each role | Admin login → Account Management (200) → create Admin/Doctor/Staff → `users.RoleID` and profile rows correct | PASS |
| d | Patient registration can't be coerced | POST `signup.php` with `role_id=1`/`RoleID=1` → stored `RoleID = 3` | PASS |
| e | Admin can't lock themselves out | self-deactivate and self-delete refused; account stays `Active` | PASS |
| f | History can't be orphaned | delete of an account with linked history refused, account remains | PASS |
| g | No role escalation via edit | `update` with `role_id=1` succeeds but stored role stays Staff (2) | PASS |
| h | Deactivation revokes sessions | second admin signed in → deactivated → its next request redirects to `login.php` | PASS |
| i | Secrets stay out of the URL | form create returns 302 with **no** `?temp=`/`?message=`; flash shown once, names the account, and renders with no PHP warnings; admin pages send `X-Frame-Options: DENY` + `nosniff` | PASS |

### 4.2 Additional assertions covered

* Deleted staff-registration routes: 3 × HTTP 404.
* Signup page issues a CSRF token and rejects role tampering.
* New admin-created accounts are flagged `MustChangePassword = 1`.
* A staff account's first login redirects to the forced password change screen.

### 4.3 Run output (excerpt)

```
(a) Public staff/admin/doctor registration routes are gone   3/3 PASS
(d) Public registration is patient-only                      3/3 PASS
(b) Non-admins get HTTP 403                                   1/1 PASS
(c) Administrator can create Admin, Doctor and Staff         16/16 PASS
(e) An admin cannot deactivate or delete their own account     3/3 PASS
(f) An account with linked history cannot be hard-deleted      3/3 PASS
(g) The edit form cannot escalate an account's role            3/3 PASS
(h) Deactivating an account revokes its open sessions          7/7 PASS
(i) Secrets stay out of the URL / anti-clickjacking headers    8/8 PASS
----------------------------------------
Passed: 44   Failed: 0
```

### 4.4 Manual test account

* Admin: `admin@curoraportal.com` / `Password123!`

---

## 6. Deployment notes

1. Run **both** migrations before deploying the code
   (`2026_10_17_account_security.sql`, then
   `2026_10_17_account_session_revocation.sql`).
2. `REQUIRE_EMAIL_VERIFICATION` lives in `includes/security_config.php`; set it to
   `false` for demos where no SMTP must be reachable (new patients then activate
   immediately). Keep it `true` in production.
3. Ensure SMTP credentials in `includes/mailer.php` are valid; the timeout is
   capped at 15 s so failures degrade gracefully (signup still succeeds and shows
   the verification link).
4. Rollback: run the `*_rollback.sql` files in reverse order, then restore the
   previous code revision.
