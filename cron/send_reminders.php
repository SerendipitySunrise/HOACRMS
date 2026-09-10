<?php

/*
 * Appointment reminder cron.
 *
 * Reads active upcoming appointments and sends reminders:
 *  - 24-hour reminder for appointments roughly one day away
 *  - 3-hour reminder for appointments roughly three hours away
 *
 * Delivery follows each patient's preference set in the profile
 * (Email or In-App Notification). SMS is intentionally NOT supported.
 *
 * Reminders are only sent once per (AppointmentID, ReminderType) — a
 * successful send is recorded in `appointment_reminders`, so this script
 * is safe to run on any schedule. For full 3-hour coverage it should run
 * at least every hour (e.g. * * * * * on Linux, or a task every 60 mins
 * on Windows Task Scheduler). A single daily run still delivers the
 * 24-hour reminders and any 3-hour reminder that happens to fall in the
 * run window.
 *
 * Usage:
 *     php cron/send_reminders.php
 */

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function reminderSent(
    mysqli $conn,
    int $appointmentID,
    string $type
): bool {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT ReminderID
         FROM appointment_reminders
         WHERE AppointmentID = ?
           AND ReminderType = ?
           AND Status = "sent"
         LIMIT 1'
    );

    if (!$stmt) {
        return true;
    }

    mysqli_stmt_bind_param($stmt, 'is', $appointmentID, $type);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $found = mysqli_num_rows($result) > 0;

    mysqli_stmt_close($stmt);

    return $found;
}

function logReminder(
    mysqli $conn,
    int $appointmentID,
    string $type,
    string $via,
    string $status = 'sent'
): void {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO appointment_reminders
            (AppointmentID, ReminderType, SentAt, SentVia, Status)
         VALUES (?, ?, NOW(), ?, ?)'
    );

    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param($stmt, 'isss', $appointmentID, $type, $via, $status);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function sendNotification(
    mysqli $conn,
    int $userID,
    string $title,
    string $message,
    int $relatedID
): void {
    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO notifications
            (UserID, Title, Message, Type, RelatedID, RelatedTable, PriorityLevel)
         VALUES (?, ?, ?, "Appointment", ?, "appointments", "Medium")'
    );

    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param($stmt, 'issi', $userID, $title, $message, $relatedID);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$now = new DateTime();

// Active, still-upcoming appointments for today or tomorrow.
$candidateStmt = mysqli_prepare(
    $conn,
    'SELECT
        a.AppointmentID,
        a.AppointmentDate,
        a.AppointmentTime,
        a.DepartmentID,
        d.DepartmentName,
        p.PatientID,
        u.UserID,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.Email,
        u.ReceiveReminders,
        u.ReminderPreference
     FROM appointments a
     INNER JOIN patients p ON a.PatientID = p.PatientID
     INNER JOIN users u ON p.UserID = u.UserID
     INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
     WHERE a.Status IN ("Pending", "Scheduled", "Confirmed")
       AND a.AppointmentDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)'
);

if (!$candidateStmt) {
    out('ERROR: unable to prepare candidate query: ' . mysqli_error($conn));
    exit(1);
}

mysqli_stmt_execute($candidateStmt);

$candidates = mysqli_stmt_get_result($candidateStmt);

mysqli_stmt_close($candidateStmt);

$sent = 0;
$failed = 0;
$skipped = 0;

while ($appt = mysqli_fetch_assoc($candidates)) {
    $appointmentDateTime = new DateTime(
        $appt['AppointmentDate'] . ' ' . $appt['AppointmentTime']
    );

    $hoursUntil = ($appointmentDateTime->getTimestamp() - $now->getTimestamp()) / 3600;

    if ($hoursUntil >= 22 && $hoursUntil <= 26) {
        $type = '24h';
    } elseif ($hoursUntil >= 2 && $hoursUntil <= 4) {
        $type = '3h';
    } else {
        continue;
    }

    $appointmentID = (int) $appt['AppointmentID'];
    $userID = (int) $appt['UserID'];

    if (reminderSent($conn, $appointmentID, $type)) {
        $skipped++;
        continue;
    }

    // Respect the patient's opt-out toggle.
    if ((int) $appt['ReceiveReminders'] !== 1) {
        $skipped++;
        continue;
    }

    $timeLabel = date('g:i A', strtotime($appt['AppointmentTime']));
    $dateLabel = date('F j, Y', strtotime($appt['AppointmentDate']));
    $departmentName = $appt['DepartmentName'];
    $firstName = trim($appt['FirstName']);

    if ($type === '24h') {
        $notifMessage = 'Reminder: You have an appointment tomorrow at '
            . $timeLabel . ' at the ' . $departmentName . ' Department.';
        $emailSubject = 'Appointment Reminder: You have an appointment tomorrow';
    } else {
        $notifMessage = 'Reminder: Your appointment at the ' . $departmentName
            . ' Department is in about 3 hours (' . $timeLabel . ').';
        $emailSubject = 'Appointment Reminder: Your appointment is in 3 hours';
    }

    $via = $appt['ReminderPreference'] === 'email' ? 'email' : 'push';

    if ($via === 'email') {
        $fullName = trim(
            $appt['FirstName'] . ' '
            . ($appt['MiddleName'] ? $appt['MiddleName'] . ' ' : '')
            . $appt['LastName']
        );

        $emailBody = '
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:540px;">
            <h2 style="color:#1e293b;">' . htmlspecialchars($emailSubject) . '</h2>
            <p>Hello ' . htmlspecialchars($firstName) . ',</p>
            <p>This is a friendly reminder about your upcoming appointment:</p>
            <table style="border-collapse:collapse;margin:16px 0;">
                <tr>
                    <td style="padding:6px 12px 6px 0;color:#64748b;">Department</td>
                    <td style="padding:6px 0;font-weight:600;">'
                        . htmlspecialchars($departmentName) . '</td>
                </tr>
                <tr>
                    <td style="padding:6px 12px 6px 0;color:#64748b;">Date</td>
                    <td style="padding:6px 0;font-weight:600;">'
                        . htmlspecialchars($dateLabel) . '</td>
                </tr>
                <tr>
                    <td style="padding:6px 12px 6px 0;color:#64748b;">Time</td>
                    <td style="padding:6px 0;font-weight:600;">'
                        . htmlspecialchars($timeLabel) . '</td>
                </tr>
            </table>
            <p>Please arrive about 15 minutes early to allow time for check-in.</p>
            <p>If you need to reschedule, you can do so anytime from the
               Patient Portal.</p>
            <p>Thank you,<br>MediCare</p>
        </div>';

        $emailOk = sendMediCareEmail(
            $appt['Email'],
            $emailSubject,
            $emailBody
        );

        logReminder($conn, $appointmentID, $type, 'email', $emailOk ? 'sent' : 'failed');

        if ($emailOk) {
            $sent++;
        } else {
            $failed++;
        }

        out('[' . $type . '][email] appointment ' . $appointmentID
            . ' -> ' . $appt['Email'] . ($emailOk ? ' OK' : ' FAILED'));
    } else {
        sendNotification($conn, $userID, 'Appointment Reminder', $notifMessage, $appointmentID);
        logReminder($conn, $appointmentID, $type, 'push', 'sent');

        $sent++;

        out('[' . $type . '][push] appointment ' . $appointmentID
            . ' -> user ' . $userID . ' OK');
    }
}

out('Done. Sent: ' . $sent . ', failed: ' . $failed . ', skipped: ' . $skipped . '.');
exit(0);