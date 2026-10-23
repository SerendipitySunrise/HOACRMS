-- ============================================================================
--  2026_10_18_account_setup_tokens_rollback.sql
--
--  Reverses 2026_10_18_account_setup_tokens.sql.
--  Safe to re-run: every statement is guarded.
-- ============================================================================

SET @db := DATABASE();

DROP TABLE IF EXISTS account_setup_tokens;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE users DROP COLUMN TOTPEnabled',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'TOTPEnabled'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE users DROP COLUMN TOTPSecret',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'TOTPSecret'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE users DROP COLUMN DeletedAt',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'DeletedAt'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        "ALTER TABLE users MODIFY COLUMN Status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'",
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'Status'
      AND COLUMN_TYPE LIKE '%Pending%'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
