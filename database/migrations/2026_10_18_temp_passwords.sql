-- ---------------------------------------------------------------------------
-- Temporary-password onboarding / reset (replaces the setup-link flow).
--
-- Administrator-issued onboarding and password resets now e-mail a random
-- temporary password instead of a single-use setup link. The account carries
-- users.MustChangePassword = 1 and this expiry; the temporary password is
-- refused once the timestamp passes so a fresh one can be issued.
--
-- Idempotent: safe to re-run.
-- ---------------------------------------------------------------------------

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'TempPasswordExpiresAt'
);
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE users ADD COLUMN TempPasswordExpiresAt DATETIME NULL DEFAULT NULL AFTER MustChangePassword',
    'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
