-- ============================================================================
--  2026_10_17_account_session_revocation.sql
--
--  Adds users.SessionVersion so an administrator can revoke an account's
--  active sessions. The value is copied into the session at sign-in; when an
--  account is deactivated or its password is reset, the counter is incremented
--  and every existing session for that account is signed out on its next
--  request (see includes/session_liveness.php).
--
--  Safe to re-run: the statement is guarded.
-- ============================================================================

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE users ADD COLUMN SessionVersion INT NOT NULL DEFAULT 0 AFTER Status',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'SessionVersion'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
