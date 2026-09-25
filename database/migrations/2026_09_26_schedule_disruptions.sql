CREATE TABLE IF NOT EXISTS DoctorUnavailability (
    UnavailabilityID INT NOT NULL AUTO_INCREMENT,
    DoctorID INT NULL,
    DepartmentID INT NULL,
    StartDate DATE NOT NULL,
    EndDate DATE NOT NULL,
    Reason VARCHAR(255) NOT NULL,
    Priority ENUM('NORMAL', 'HIGH', 'EMERGENCY') NOT NULL DEFAULT 'NORMAL',
    CreatedBy INT NOT NULL,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ConfirmedBy INT NULL,
    ConfirmedAt DATETIME NULL,
    PRIMARY KEY (UnavailabilityID),
    KEY idx_unavailability_doctor_dates (DoctorID, StartDate, EndDate),
    KEY idx_unavailability_department_dates (DepartmentID, StartDate, EndDate),
    CONSTRAINT fk_unavailability_doctor FOREIGN KEY (DoctorID) REFERENCES staff (StaffID) ON DELETE SET NULL,
    CONSTRAINT fk_unavailability_department FOREIGN KEY (DepartmentID) REFERENCES departments (DepartmentID) ON DELETE SET NULL,
    CONSTRAINT fk_unavailability_created_by FOREIGN KEY (CreatedBy) REFERENCES users (UserID) ON DELETE RESTRICT,
    CONSTRAINT fk_unavailability_confirmed_by FOREIGN KEY (ConfirmedBy) REFERENCES users (UserID) ON DELETE SET NULL,
    CONSTRAINT chk_unavailability_scope CHECK (DoctorID IS NOT NULL OR DepartmentID IS NOT NULL),
    CONSTRAINT chk_unavailability_dates CHECK (EndDate >= StartDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS AnnouncementRecipients (
    RecipientID INT NOT NULL AUTO_INCREMENT,
    AnnouncementID INT NOT NULL,
    PatientID INT NOT NULL,
    Channel ENUM('IN_APP', 'EMAIL') NOT NULL,
    Status ENUM('PENDING', 'SENT', 'DELIVERED', 'FAILED', 'BOUNCED', 'READ') NOT NULL DEFAULT 'PENDING',
    SentAt DATETIME NULL,
    DeliveredAt DATETIME NULL,
    ReadAt DATETIME NULL,
    ErrorMessage TEXT NULL,
    RetryCount INT NOT NULL DEFAULT 0,
    CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (RecipientID),
    UNIQUE KEY uq_announcement_patient_channel (AnnouncementID, PatientID, Channel),
    KEY idx_announcement_recipients_patient (PatientID),
    KEY idx_announcement_recipients_status (Status),
    CONSTRAINT fk_announcement_recipient_announcement FOREIGN KEY (AnnouncementID) REFERENCES announcements (id) ON DELETE CASCADE,
    CONSTRAINT fk_announcement_recipient_patient FOREIGN KEY (PatientID) REFERENCES patients (PatientID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE DoctorUnavailability MODIFY DoctorID INT NULL;
SET @add_confirmed_by = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE DoctorUnavailability ADD COLUMN ConfirmedBy INT NULL AFTER CreatedAt',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'doctorunavailability' AND LOWER(COLUMN_NAME) = 'confirmedby'
);
PREPARE add_confirmed_by FROM @add_confirmed_by;
EXECUTE add_confirmed_by;
DEALLOCATE PREPARE add_confirmed_by;

SET @add_confirmed_at = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE DoctorUnavailability ADD COLUMN ConfirmedAt DATETIME NULL AFTER ConfirmedBy',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'doctorunavailability' AND LOWER(COLUMN_NAME) = 'confirmedat'
);
PREPARE add_confirmed_at FROM @add_confirmed_at;
EXECUTE add_confirmed_at;
DEALLOCATE PREPARE add_confirmed_at;
