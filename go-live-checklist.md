# Go-live checklist (report-only, do not apply)

## Configuration
- [ ] Set `CURORA_ALLOWED_EMAIL_DOMAINS` / use `allowed_account_email_domains` (empty now) to restrict which domains can receive account invites if required.
- [ ] Ensure `CURORA_FORCE_ADMIN_TOTP=1` (default) in production; do **not** set to `0` unless policy allows.
- [ ] `CURORA_EMAIL_MX_CHECK` on in production (do not leave `CURORA_EMAIL_MX_CHECK=0`).
- [ ] Confirm SMTP/mail delivery via `includes/mailer.php` (dry-run **cannot** be enabled in production). Verify `CURORA_MAIL_DRY_RUN=0`.
- [ ] Rotate any Gmail app password used for SMTP if it ever existed in source or configs.

## Database
- [ ] Back up the `hoacrms` database before applying any migration in production.
- [ ] Test migration `2026_10_18_temp_passwords.sql` (and rollback) on a copy of the database.
- [ ] Verify `users.TempPasswordExpiresAt` exists and is `NULLABLE` (e.g. `SHOW COLUMNS FROM users`).
- [ ] Verify `patients.UserID` nullable; run a sample query for orphaned logins.
- [ ] Confirm no left-over `account_setup_tokens` rows with `Kind='link'` are being written (feature removed). **Decision:** keep the `account_setup_tokens` table — it is repurposed for self-service reset links (`Kind='reset'`, SHA-256 hashes only). Do **not** drop it in a migration. Optionally add a cleanup migration later to `DELETE FROM account_setup_tokens WHERE Kind <> 'reset'` (or `Kind='link'`) once production data is confirmed clean; the login path ignores non-`reset` rows anyway.
- [ ] Audit Administrators: check for duplicates and that all active Admins have `TOTPEnabled=1` (or will be forced to enrol). Primary `admin@curoraportal.com` currently has `TOTPSecret NULL` and will be redirected to setup_totp.php on next login.

## Auth flow
- [ ] Test login with an expired temp password is rejected with a generic message.
- [ ] Test `MustChangePassword` blocks access to every page except `auth/change_password.php`.
- [ ] Test post-change revokes other sessions.
- [ ] Test password policy (>=12 chars, mixed case, digit, symbol, not common, different from temp).
- [ ] Test Admin must enrol TOTP after first password change when `FORCE_ADMIN_TOTP=1`.
- [x] Test forgot-password issues a single-use reset **link** (1h expiry, SHA-256-hashed token); it does NOT change or invalidate the current password. Admin-triggered resets still e-mail a temporary password. (Verified by `tests/account_security_tests.php` sections (i)+(j).)

## Patient records
- [ ] Verify deactivating a login-less patient record hides it from booking and check-in without breaking appointment history.
- [ ] Patient lookups filter `patients.Status = 'Active'` in booking/check-in/search paths.

## Security & privacy
- [ ] `TempPasswordExpiresAt` is never logged, returned in responses, or persisted in plaintext.
- [ ] `/api/admin/accounts.php` still enforces RBAC/CSRF/reauth (create-Admin, reset, delete).
- [ ] No `delivery=inperson` or QR code paths exposed.
- [ ] Legacy pages `setup-password.php` and `includes/setup_tokens.php` are removed.
- [ ] Recovery code service (`includes/recovery_codes.php`) is present (UI deferred).

## Post-deploy smoke
- [ ] Create Admin/Doctor/Staff via the new form; email arrives, sign-in works, forced change → TOTP (if admin).
- [ ] Resend temp password; old temp password is invalidated.
- [ ] Reset another admin's password requires re-auth; self-reset allowed? reset_password is blocked for self in UI (we block self), resend is blocked for self (must be managed and has temp password? we allow resend only when `hasTempPassword` true and managed; self cannot resend). Confirm.
- [ ] Last active Admin cannot be deactivated/deleted.
- [ ] Non-admin cannot reach admin API endpoints (403/redirects).

## Notes
- Tests: `tests/account_security_tests.php` has been rewritten for the temp-password + TOTP-on model and currently passes 113/113 against the local WAMP stack. Run it with `php tests/account_security_tests.php`; it uses the dry-run mail outbox (`CURORA_MAIL_OUTBOX_DIR`, set for Apache via `.htaccess` and shared with CLI at `C:/wamp64/tmp/curora_mail_outbox`). Note it TRUNCATES `auth_rate_limits` and creates/removes fixture accounts.
- Ordering: change-password gate runs before TOTP gate.
- `users.Status` for newly created managed accounts is `Active` (never `Pending`). `Pending` remains only for edge cases; login rejects `Pending` with a generic message.

## Remaining risks / follow-ups
- **Dry-run mail in production:** if `CURORA_MAIL_HOST` is unset in production, every "sent" temp password/reset link silently lands in the outbox directory instead of being delivered (and is written to disk in cleartext). Production config must set SMTP and must not set `CURORA_MAIL_OUTBOX_DIR`; audit the mailer config during deploy.
- **Outbox cleartext:** dry-run outbox files contain temporary passwords and reset links in cleartext. Restrict/rotate the outbox directory; it is a test-only mechanism.
- **Reset-link delivery e-mail tampering:** the reset link goes to the address on file; ensure the mail transport is authenticated SMTP so third parties cannot intercept it. The link is single-use + 1h TTL + hashed-at-rest, but e-mail remains the trust root.
- **Password history:** after a change/reset, the previous password hash is simply overwritten; there is no password-history table, so a user could cycle back to an old password (beyond "new != current" for the temp-password case).
- **Recovery codes UI:** the recovery-code service exists (`recoveryCode*` in `includes/account_service.php`, table `admin_recovery_codes`, codes shown once, SHA-256 hashed, single use) but the enrolment UI wiring is minimal — confirm the intended UX before go-live or defer.
- **Login-less patient default:** walk-in patients are login-less by default; temp-password delivery happens only when a login is requested with a validated (format + MX + not-in-use) e-mail. Confirm front-desk workflow accepts this.
- **Rate-limit persistence:** rate limiting is DB-backed (`auth_rate_limits`); if the table is not purged it grows. Add a scheduled purge (rows older than the lock window).
- **`account_setup_tokens` table:** kept and repurposed for reset links (see Database section). No schema drop needed; optional cleanup of legacy `Kind='link'` rows.
- **Last-admin protection scope:** the last-active-Admin guard covers deactivate/delete; verify no other path (bulk SQL, future feature) can remove the last admin.
- **Session revocation propagation:** `SessionVersion` checks depend on `includes/session_liveness.php` running on every request; new pages must include it or they will tolerate stale sessions.
- **Legacy pages removed:** `admin_doctor_management.php`, `admin_staff_management.php` and `admin_patient_management.php` have been **deleted**; every admin sidebar/quick-action/report nav now points to Account Management only (old URLs 404 — add redirects if any bookmarks exist). `staff/checkIn_patient.php` (walk-in refactor) is still pending.