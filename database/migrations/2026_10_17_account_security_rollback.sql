-- ============================================================================
--  2026_10_17_account_security_rollback.sql
--  Reverts 2026_10_17_account_security.sql.
-- ============================================================================

DROP TABLE IF EXISTS auth_rate_limits;

ALTER TABLE users DROP COLUMN EmailVerificationExpiry;
ALTER TABLE users DROP COLUMN EmailVerificationToken;
ALTER TABLE users DROP COLUMN EmailVerified;
ALTER TABLE users DROP COLUMN MustChangePassword;
