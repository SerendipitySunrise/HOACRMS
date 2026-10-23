-- ============================================================================
--  2026_10_18_account_setup_tokens.sql
--
--  Adds the tables/columns for the token-based account onboarding flow.
--
--  Changing the design of account creation from "temporary password" to a
--  one-time setup link requires:
--    * a place to store the single-use token (hashed, never raw);
--    * a "Pending" account state that cannot log in yet;
--    * a soft-delete marker (hard-delete is replaced by deactivate/soft-delete);
--    * TOTP fields for the mandatory Admin two-factor step.
--
--  Safe to re-run: every statement is guarded.
-- ============================================================================

SET @db := DATABASE();

/* ---- users.Status: allow the new 'Pending' state ------------------------- */
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE users MODIFY COLUMN Status ENUM('Active','Inactive','Pending') NOT NULL DEFAULT 'Active'",
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'Status'
      AND COLUMN_TYPE LIKE '%Pending%'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ---- users.DeletedAt (soft delete) --------------------------------------- */
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN DeletedAt DATETIME NULL AFTER LockUntil',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'DeletedAt'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ---- users.TOTPSecret / TOTPEnabled (Admin 2FA) -------------------------- */
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN TOTPSecret VARCHAR(64) NULL AFTER DeletedAt',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'TOTPSecret'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN TOTPEnabled TINYINT(1) NOT NULL DEFAULT 0 AFTER TOTPSecret',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'TOTPEnabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ---- account_setup_tokens ------------------------------------------------ */
/* One-time setup / password-reset tokens. Only hash('sha256', $token) is
   stored; the raw value exists only in the e-mail and (briefly) in a session
   flash. A token is valid while UsedAt IS NULL and ExpiresAt is in the future. */
CREATE TABLE IF NOT EXISTS account_setup_tokens (
    TokenID    INT AUTO_INCREMENT PRIMARY KEY,
    UserID     INT          NOT NULL,
    TokenHash  CHAR(64)     NOT NULL,
    ExpiresAt  DATETIME     NOT NULL,
    UsedAt     DATETIME     NULL,
    CreatedBy  INT          NOT NULL,
    CreatedAt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_setup_tokens_hash (TokenHash),
    KEY idx_account_setup_tokens_user (UserID),
    CONSTRAINT fk_account_setup_tokens_user
        FOREIGN KEY (UserID) REFERENCES users (UserID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
