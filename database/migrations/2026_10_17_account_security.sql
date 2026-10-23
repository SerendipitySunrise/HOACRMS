-- ============================================================================
--  2026_10_17_account_security.sql
--  Restrict public self-registration to PATIENTS ONLY and move all
--  staff-level account creation behind the authenticated Admin role.
--
--  Adds:
--    * users.MustChangePassword        -> force a password change on first login
--    * users.EmailVerified             -> patient e-mail verification gate
--    * users.EmailVerificationToken    -> one-time verification token
--    * users.EmailVerificationExpiry   -> token TTL
--    * auth_rate_limits                -> brute-force / mass-registration guard
--
--  Safe to re-run: every statement is guarded.
-- ============================================================================

-- --- users: first-login password change + e-mail verification ---------------
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN MustChangePassword TINYINT(1) NOT NULL DEFAULT 0 AFTER Status',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'MustChangePassword'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN EmailVerified TINYINT(1) NOT NULL DEFAULT 1 AFTER MustChangePassword',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'EmailVerified'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN EmailVerificationToken VARCHAR(64) NULL AFTER EmailVerified',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'EmailVerificationToken'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN EmailVerificationExpiry DATETIME NULL AFTER EmailVerificationToken',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'EmailVerificationExpiry'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing accounts predate verification: trust them so nobody is locked out.
UPDATE users SET EmailVerified = 1 WHERE EmailVerified IS NULL;

-- --- auth_rate_limits -------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_rate_limits (
  RateLimitID INT NOT NULL AUTO_INCREMENT,
  Scope       VARCHAR(100) NOT NULL,
  Identifier  VARCHAR(191) NOT NULL,
  Attempts    INT NOT NULL DEFAULT 0,
  WindowStart DATETIME NOT NULL,
  LockedUntil DATETIME NULL,
  PRIMARY KEY (RateLimitID),
  UNIQUE KEY uniq_auth_rate_scope_identifier (Scope, Identifier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
