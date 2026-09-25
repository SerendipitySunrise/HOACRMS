<?php

require_once __DIR__ . '/audit_helper.php';
require_once __DIR__ . '/mailer.php';

function disruptionAffectedAppointments(PDO $pdo, ?int $doctorId, ?int $departmentId, string $startDate, string $endDate): array
{
    $stmt = $pdo->prepare(
        "SELECT a.AppointmentID, a.PatientID, a.StaffID AS DoctorID, a.DepartmentID,
                a.AppointmentDate, a.AppointmentTime, a.Purpose, a.Status,
                CONCAT(u.FirstName, ' ', u.LastName) AS PatientName,
                u.Email, u.ReceiveReminders, u.ReminderPreference,
                CONCAT(doctorUser.FirstName, ' ', doctorUser.LastName) AS DoctorName,
                d.DepartmentName
           FROM appointments a
           INNER JOIN patients p ON p.PatientID = a.PatientID
           INNER JOIN users u ON u.UserID = p.UserID
           LEFT JOIN staff doctor ON doctor.StaffID = a.StaffID
           LEFT JOIN users doctorUser ON doctorUser.UserID = doctor.UserID
           LEFT JOIN departments d ON d.DepartmentID = a.DepartmentID
          WHERE a.Status IN ('Pending', 'Scheduled')
            AND a.AppointmentDate BETWEEN :start_date AND :end_date
            AND (:doctor_id_filter IS NULL OR a.StaffID = :doctor_id_value)
            AND (:department_id_filter IS NULL OR a.DepartmentID = :department_id_value)
          ORDER BY a.AppointmentDate, a.AppointmentTime, PatientName"
    );
    $stmt->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
        'doctor_id_filter' => $doctorId,
        'doctor_id_value' => $doctorId,
        'department_id_filter' => $departmentId,
        'department_id_value' => $departmentId,
    ]);
    return $stmt->fetchAll();
}

function createDoctorUnavailability(PDO $pdo, ?int $doctorId, ?int $departmentId, string $startDate, string $endDate, string $reason, bool $isEmergency, int $createdBy): int
{
    if (!$doctorId && !$departmentId) {
        throw new InvalidArgumentException('Select a doctor or department.');
    }
    if ($startDate === '' || $endDate === '' || $endDate < $startDate) {
        throw new InvalidArgumentException('Enter a valid date range.');
    }
    if ($reason === '') {
        throw new InvalidArgumentException('Enter a reason for the disruption.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO DoctorUnavailability (DoctorID, DepartmentID, StartDate, EndDate, Reason, Priority, CreatedBy)
         VALUES (:doctor_id, :department_id, :start_date, :end_date, :reason, :priority, :created_by)'
    );
    $stmt->execute([
        'doctor_id' => $doctorId,
        'department_id' => $departmentId,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'reason' => $reason,
        'priority' => $isEmergency ? 'EMERGENCY' : 'NORMAL',
        'created_by' => $createdBy,
    ]);
    return (int) $pdo->lastInsertId();
}

function disruptionDefaultTemplate(array $appointment, string $reason, bool $isEmergency): string
{
    $urgency = $isEmergency ? 'Time-sensitive notice: ' : '';
    return $urgency . 'Your Curora appointment on ' . date('F j, Y', strtotime($appointment['AppointmentDate']))
        . ' at ' . date('g:i A', strtotime($appointment['AppointmentTime']))
        . ' needs attention because ' . $reason
        . '. Please review your appointment and use this link to choose another available schedule: [RESCHEDULE_LINK]';
}

function disruptionRescheduleLink(int $appointmentId, string $token): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
    return $scheme . '://' . $host . $base . '/patient/reschedule_appointment.php?appointment_id=' . $appointmentId . '&token=' . rawurlencode($token);
}

function executeDisruption(PDO $pdo, int $unavailabilityId, array $appointmentIds, string $decision, ?string $rescheduleDate, ?string $rescheduleTime, array $channels, string $template, int $confirmedBy): array
{
    $decision = 'cancel';
    if (!$appointmentIds) {
        throw new InvalidArgumentException('Select at least one affected appointment.');
    }
    if (!$channels) {
        throw new InvalidArgumentException('Select at least one notification channel.');
    }

    $pdo->beginTransaction();
    try {
        $unavailabilityStmt = $pdo->prepare('SELECT * FROM DoctorUnavailability WHERE UnavailabilityID = :id FOR UPDATE');
        $unavailabilityStmt->execute(['id' => $unavailabilityId]);
        $unavailability = $unavailabilityStmt->fetch();
        if (!$unavailability) {
            throw new RuntimeException('Unavailability record not found.');
        }

        $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
        $affectedStmt = $pdo->prepare(
            "SELECT a.AppointmentID, a.PatientID, a.AppointmentDate, a.AppointmentTime,
                    CONCAT(u.FirstName, ' ', u.LastName) AS PatientName, u.Email,
                    u.ReceiveReminders, u.ReminderPreference
               FROM appointments a
               INNER JOIN patients p ON p.PatientID = a.PatientID
               INNER JOIN users u ON u.UserID = p.UserID
              WHERE a.AppointmentID IN ($placeholders) AND a.Status IN ('Pending', 'Scheduled')
                AND a.AppointmentDate BETWEEN ? AND ?"
        );
        $affectedStmt->execute(array_merge($appointmentIds, [$unavailability['StartDate'], $unavailability['EndDate']]));
        $appointments = $affectedStmt->fetchAll();
        if (!$appointments) {
            throw new RuntimeException('The selected appointments are no longer scheduled or are outside the disruption range.');
        }

        $announcementStmt = $pdo->prepare(
            'INSERT INTO announcements (title, priority, audience, content, author, created_at)
             VALUES (:title, :priority, :audience, :content, :author, NOW())'
        );
        $priority = in_array($unavailability['Priority'], ['HIGH', 'EMERGENCY'], true) ? 'HIGH' : 'MEDIUM';
        $announcementStmt->execute([
            'title' => $decision === 'cancel' ? 'Appointment cancellation notice' : 'Appointment reschedule notice',
            'priority' => $priority,
            'audience' => 'Patients Only',
            'content' => $template,
            'author' => 'Curora Schedule Disruption Engine',
        ]);
        $announcementId = (int) $pdo->lastInsertId();

        $tokens = [];
        $update = $pdo->prepare(
            "UPDATE appointments
                SET Status = 'Cancelled', disruption_reason = :reason,
                    is_emergency_disruption = :is_emergency, reschedule_token = :token,
                    UpdatedAt = NOW()
              WHERE AppointmentID = :appointment_id
                AND Status IN ('Pending', 'Scheduled')"
        );
        foreach ($appointments as $appointment) {
            $token = bin2hex(random_bytes(32));
            $tokens[(int) $appointment['AppointmentID']] = $token;
            $update->execute([
                'reason' => $unavailability['Reason'],
                'is_emergency' => in_array($unavailability['Priority'], ['HIGH', 'EMERGENCY'], true) ? 1 : 0,
                'token' => $token,
                'appointment_id' => $appointment['AppointmentID'],
            ]);
        }

        $notificationInsert = $pdo->prepare(
            'INSERT INTO notifications (UserID, Title, Message, Type, RelatedID, RelatedTable, PriorityLevel, SentAt)
             SELECT p.UserID, :title, :message, :type, :related_id, :related_table, :priority, NOW()
               FROM patients p WHERE p.PatientID = :patient_id'
        );
        $recipientInsert = $pdo->prepare(
            'INSERT INTO AnnouncementRecipients (AnnouncementID, PatientID, Channel, Status, SentAt, ErrorMessage)
             VALUES (:announcement_id, :patient_id, :channel, :status, :sent_at, :error_message)'
        );
        $sent = 0;
        $failed = 0;
        $recipientKeys = [];
        foreach ($appointments as $appointment) {
            $message = str_replace(
                ['[DATE]', '[TIME]', '[RESCHEDULE_LINK]'],
                [
                    date('F j, Y', strtotime($appointment['AppointmentDate'])),
                    date('g:i A', strtotime($appointment['AppointmentTime'])),
                    disruptionRescheduleLink((int) $appointment['AppointmentID'], $tokens[(int) $appointment['AppointmentID']])
                ],
                $template
            );
            $isUrgent = $appointment['AppointmentDate'] === date('Y-m-d')
                || in_array($unavailability['Priority'], ['HIGH', 'EMERGENCY'], true);
            $priorityValue = $isUrgent ? 'High' : 'Medium';
            $title = $isUrgent ? 'Urgent appointment cancellation' : 'Appointment cancelled';
            foreach (['in_app', 'email'] as $channel) {
                if (!in_array($channel, $channels, true)) {
                    continue;
                }
                $recipientKey = (int) $appointment['PatientID'] . ':' . $channel;
                if (isset($recipientKeys[$recipientKey])) {
                    continue;
                }
                $recipientKeys[$recipientKey] = true;
                if ((int) $appointment['ReceiveReminders'] !== 1) {
                    $recipientInsert->execute(['announcement_id' => $announcementId, 'patient_id' => $appointment['PatientID'], 'channel' => strtoupper($channel), 'status' => 'FAILED', 'sent_at' => null, 'error_message' => 'Skipped: patient notifications are disabled.']);
                    continue;
                }
                if ($channel === 'in_app') {
                    $notificationInsert->execute(['title' => $title, 'message' => $message, 'type' => 'Schedule Disruption', 'related_id' => $appointment['AppointmentID'], 'related_table' => 'appointments', 'priority' => $priorityValue, 'patient_id' => $appointment['PatientID']]);
                    $recipientInsert->execute(['announcement_id' => $announcementId, 'patient_id' => $appointment['PatientID'], 'channel' => strtoupper($channel), 'status' => 'SENT', 'sent_at' => date('Y-m-d H:i:s'), 'error_message' => null]);
                    $sent++;
                    continue;
                }
                $emailEnabled = strtolower((string) $appointment['ReminderPreference']) === 'email';
                if (!$emailEnabled || trim((string) $appointment['Email']) === '') {
                    $recipientInsert->execute(['announcement_id' => $announcementId, 'patient_id' => $appointment['PatientID'], 'channel' => strtoupper($channel), 'status' => 'FAILED', 'sent_at' => null, 'error_message' => 'Skipped: email is not the patient preferred channel or no email exists.']);
                    continue;
                }
                $emailSent = sendCuroraEmail((string) $appointment['Email'], $title, nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')));
                $recipientInsert->execute(['announcement_id' => $announcementId, 'patient_id' => $appointment['PatientID'], 'channel' => strtoupper($channel), 'status' => $emailSent ? 'SENT' : 'FAILED', 'sent_at' => $emailSent ? date('Y-m-d H:i:s') : null, 'error_message' => $emailSent ? null : 'Mailer failed to send the message.']);
                $emailSent ? $sent++ : $failed++;
            }
        }

        $confirmed = $pdo->prepare('UPDATE DoctorUnavailability SET ConfirmedBy = :user_id, ConfirmedAt = NOW() WHERE UnavailabilityID = :id');
        $confirmed->execute(['user_id' => $confirmedBy, 'id' => $unavailabilityId]);
        logAudit($pdo, 'CONFIRM', 'DoctorUnavailability', $unavailabilityId, null, json_encode(['decision' => $decision, 'appointment_ids' => $appointmentIds, 'channels' => $channels, 'sent' => $sent, 'failed' => $failed]));
        $pdo->commit();
        return ['announcement_id' => $announcementId, 'appointments' => count($appointments), 'sent' => $sent, 'failed' => $failed];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
