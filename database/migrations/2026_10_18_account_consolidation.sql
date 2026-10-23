-- ============================================================================
--  2026_10_18_account_consolidation.sql
--
--  Stage (a) of the Account Management consolidation.
--
--  The admin console becomes the single source of truth for Administrator,
--  Doctor, Staff and walk-in Patient records. That change needs:
--
--    * patients.UserID to be NULL-able, so a walk-in patient can exist as a
--      clinical record with NO login (no users row, no password);
--    * demographic columns on patients, so a login-less record still holds a
--      name / DOB / sex / phone / e-mail (the users row supplies them only once
--      the patient is invited to the portal);
--    * a Kind + Attempts column on account_setup_tokens, so the same table can
--      later back short activation codes (link vs. code) without a new table;
--    * an allowed-email-domain setting for Admin/Doctor account creation;
--    * a table for one-time administrator two-factor recovery codes.
--
--  Safe to re-run: every statement is guarded.
-- ============================================================================

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
--  patients.UserID -> NULL (a patient record without a login)
-- ---------------------------------------------------------------------------
SET @sql := (
    SELECT IF(
        COUNT(*) = 1 AND MAX(IS_NULLABLE) = 'NO',
        'ALTER TABLE patients MODIFY COLUMN UserID INT NULL',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'UserID'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
--  patients.* : demographics for a login-less walk-in record
-- ---------------------------------------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE patients ADD COLUMN FirstName VARCHAR(100) NULL AFTER UserID', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'FirstName'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE patients ADD COLUMN MiddleName VARCHAR(100) NULL AFTER FirstName', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'MiddleName'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE patients ADD COLUMN LastName VARCHAR(100) NULL AFTER MiddleName', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'LastName'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE patients ADD COLUMN Email VARCHAR(191) NULL AFTER LastName', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'Email'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE patients ADD COLUMN DateOfBirth DATE NULL AFTER Email', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'DateOfBirth'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0, "ALTER TABLE patients ADD COLUMN Sex VARCHAR(13) NULL AFTER DateOfBirth", 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'Sex'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 0, 'ALTER TABLE patients ADD COLUMN ContactNumber VARCHAR(20) NULL AFTER Sex', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'ContactNumber'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE patients ADD COLUMN Status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER ContactNumber",
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'Status'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
--  account_setup_tokens.Kind / .Attempts (link vs. short activation code)
-- ---------------------------------------------------------------------------
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE account_setup_tokens ADD COLUMN Kind VARCHAR(20) NOT NULL DEFAULT 'link' AFTER TokenHash",
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'account_setup_tokens' AND COLUMN_NAME = 'Kind'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE account_setup_tokens ADD COLUMN Attempts INT NOT NULL DEFAULT 0 AFTER Kind',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'account_setup_tokens' AND COLUMN_NAME = 'Attempts'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
--  Allowed e-mail domains for the privileged roles (empty = no restriction)
-- ---------------------------------------------------------------------------
INSERT INTO system_settings (SettingKey, SettingValue, Description)
SELECT 'allowed_account_email_domains', '',
       'Comma-separated e-mail domains allowed for Admin and Doctor accounts (blank = allow any)'
WHERE NOT EXISTS (
    SELECT 1 FROM system_settings WHERE SettingKey = 'allowed_account_email_domains'
);

-- ---------------------------------------------------------------------------
--  Administrator two-factor recovery codes (hashed, single-use)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_recovery_codes (
    RecoveryID INT AUTO_INCREMENT PRIMARY KEY,
    UserID     INT          NOT NULL,
    CodeHash   CHAR(64)     NOT NULL,
    UsedAt     DATETIME     NULL,
    CreatedAt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_recovery_codes_hash (CodeHash),
    KEY idx_admin_recovery_codes_user (UserID),
    CONSTRAINT fk_admin_recovery_codes_user
        FOREIGN KEY (UserID) REFERENCES users (UserID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
