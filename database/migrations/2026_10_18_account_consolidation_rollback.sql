-- ============================================================================
--  2026_10_18_account_consolidation_rollback.sql
--
--  Reverses 2026_10_18_account_consolidation.sql.
--
--  Only the purely additive pieces are removed. UserID is left NULL-able on
--  purpose: reverting it to NOT NULL would fail (and destroy data) once any
--  login-less patient exists. Restore it manually only after deciding what to
--  do with those rows.
-- ============================================================================

SET @db := DATABASE();

DROP TABLE IF EXISTS admin_recovery_codes;

DELETE FROM system_settings WHERE SettingKey = 'allowed_account_email_domains';

-- account_setup_tokens.Kind / .Attempts
SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE account_setup_tokens DROP COLUMN Attempts', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'account_setup_tokens' AND COLUMN_NAME = 'Attempts'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE account_setup_tokens DROP COLUMN Kind', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'account_setup_tokens' AND COLUMN_NAME = 'Kind'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- patients demographics
SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN Status', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN ContactNumber', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'ContactNumber'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN Sex', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'Sex'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN DateOfBirth', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'DateOfBirth'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN Email', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'Email'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN LastName', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'LastName'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN MiddleName', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'MiddleName'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE patients DROP COLUMN FirstName', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'FirstName'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
