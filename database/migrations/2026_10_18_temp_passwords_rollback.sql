-- ---------------------------------------------------------------------------
-- Rollback for 2026_10_18_temp_passwords.sql.
-- ---------------------------------------------------------------------------

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'TempPasswordExpiresAt'
);
SET @ddl := IF(@has_col = 1, 'ALTER TABLE users DROP COLUMN TempPasswordExpiresAt', 'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
