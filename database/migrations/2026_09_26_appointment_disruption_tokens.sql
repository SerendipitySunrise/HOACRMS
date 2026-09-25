SET @add_disruption_reason = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE appointments ADD COLUMN disruption_reason VARCHAR(100) NULL AFTER RescheduleReason',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'appointments' AND LOWER(COLUMN_NAME) = 'disruption_reason'
);
PREPARE add_disruption_reason FROM @add_disruption_reason;
EXECUTE add_disruption_reason;
DEALLOCATE PREPARE add_disruption_reason;

SET @add_emergency_disruption = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE appointments ADD COLUMN is_emergency_disruption TINYINT(1) NOT NULL DEFAULT 0 AFTER disruption_reason',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'appointments' AND LOWER(COLUMN_NAME) = 'is_emergency_disruption'
);
PREPARE add_emergency_disruption FROM @add_emergency_disruption;
EXECUTE add_emergency_disruption;
DEALLOCATE PREPARE add_emergency_disruption;

SET @add_reschedule_token = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE appointments ADD COLUMN reschedule_token VARCHAR(64) NULL AFTER is_emergency_disruption',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'appointments' AND LOWER(COLUMN_NAME) = 'reschedule_token'
);
PREPARE add_reschedule_token FROM @add_reschedule_token;
EXECUTE add_reschedule_token;
DEALLOCATE PREPARE add_reschedule_token;

SET @add_reschedule_token_index = (
    SELECT IF(COUNT(*) = 0,
        'CREATE INDEX idx_appointments_reschedule_token ON appointments (reschedule_token)',
        'SELECT 1')
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'appointments' AND LOWER(INDEX_NAME) = 'idx_appointments_reschedule_token'
);
PREPARE add_reschedule_token_index FROM @add_reschedule_token_index;
EXECUTE add_reschedule_token_index;
DEALLOCATE PREPARE add_reschedule_token_index;