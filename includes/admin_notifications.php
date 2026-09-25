<?php

function adminNotificationCurrentUserId(): int
{
    $userId = (int) ($_SESSION['UserID'] ?? 0);
    $roleId = (int) ($_SESSION['RoleID'] ?? 0);

    if ($userId <= 0 || $roleId !== 1) {
        return 0;
    }

    return $userId;
}

function adminNotificationUnreadCount(mysqli $conn, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS total
         FROM notifications
         WHERE UserID = ? AND IsRead = 0'
    );

    if (!$stmt) {
        error_log('Unable to prepare notification count query: ' . mysqli_error($conn));
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('Unable to execute notification count query: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return 0;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return (int) ($row['total'] ?? 0);
}

function adminNotificationRecent(mysqli $conn, int $userId, int $limit = 20): array
{
    if ($userId <= 0) {
        return [];
    }

    $limit = max(1, min($limit, 100));
    $sql = 'SELECT
                NotificationID,
                Title,
                Message,
                Type,
                RelatedID,
                RelatedTable,
                IsRead + 0 AS IsReadInt,
                PriorityLevel,
                SentAt,
                CreatedAt
            FROM notifications
            WHERE UserID = ?
            ORDER BY CreatedAt DESC, NotificationID DESC
            LIMIT ' . $limit;

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        error_log('Unable to prepare notification list query: ' . mysqli_error($conn));
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('Unable to execute notification list query: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $notifications = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $row['NotificationID'] = (int) $row['NotificationID'];
            $row['RelatedID'] = $row['RelatedID'] === null ? null : (int) $row['RelatedID'];
            $row['IsReadInt'] = (int) $row['IsReadInt'];
            $notifications[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
    return $notifications;
}

function adminNotificationCreateForUser(
    mysqli $conn,
    int $userId,
    string $title,
    string $message,
    ?string $type = null,
    ?int $relatedId = null,
    ?string $relatedTable = null,
    string $priority = 'Low'
): bool {
    if ($userId <= 0 || trim($title) === '' || trim($message) === '') {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        'INSERT INTO notifications
            (UserID, Title, Message, Type, RelatedID, RelatedTable, PriorityLevel, SentAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    if (!$stmt) {
        error_log('Unable to prepare notification insert: ' . mysqli_error($conn));
        return false;
    }

    mysqli_stmt_bind_param(
        $stmt,
        'isssiss',
        $userId,
        $title,
        $message,
        $type,
        $relatedId,
        $relatedTable,
        $priority
    );

    $success = mysqli_stmt_execute($stmt);
    if (!$success) {
        error_log('Unable to insert notification: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
    return $success;
}

function adminNotificationCreateForActiveAdmins(
    mysqli $conn,
    string $title,
    string $message,
    ?string $type = null,
    ?int $relatedId = null,
    ?string $relatedTable = null,
    string $priority = 'Low'
): int {
    $stmt = mysqli_prepare(
        $conn,
        'SELECT UserID
         FROM users
         WHERE RoleID = 1 AND Status = "Active"'
    );

    if (!$stmt) {
        error_log('Unable to prepare active Admin query: ' . mysqli_error($conn));
        return 0;
    }

    if (!mysqli_stmt_execute($stmt)) {
        error_log('Unable to execute active Admin query: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return 0;
    }

    $result = mysqli_stmt_get_result($stmt);
    $created = 0;
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            if (adminNotificationCreateForUser(
                $conn,
                (int) $row['UserID'],
                $title,
                $message,
                $type,
                $relatedId,
                $relatedTable,
                $priority
            )) {
                $created++;
            }
        }
    }

    mysqli_stmt_close($stmt);
    return $created;
}

function adminNotificationAppointmentRecipientUserIds(mysqli $conn, int $appointmentId): array
{
    if ($appointmentId <= 0) {
        return [];
    }

    $stmt = mysqli_prepare(
        $conn,
        'SELECT
            patientUser.UserID AS PatientUserID,
            staffUser.UserID AS StaffUserID
         FROM appointments a
         INNER JOIN patients p ON p.PatientID = a.PatientID
         INNER JOIN users patientUser ON patientUser.UserID = p.UserID
         LEFT JOIN staff s ON s.StaffID = a.StaffID
         LEFT JOIN users staffUser ON staffUser.UserID = s.UserID
         WHERE a.AppointmentID = ?
         LIMIT 1'
    );

    if (!$stmt) {
        error_log('Unable to prepare appointment recipient query: ' . mysqli_error($conn));
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'i', $appointmentId);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('Unable to execute appointment recipient query: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return [];
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        return [];
    }

    $userIds = [(int) $row['PatientUserID']];
    if (!empty($row['StaffUserID'])) {
        $userIds[] = (int) $row['StaffUserID'];
    }

    return array_values(array_unique(array_filter($userIds)));
}

function adminNotificationCreateForAppointmentUsers(
    mysqli $conn,
    int $appointmentId,
    string $title,
    string $message,
    string $type = 'Appointment',
    string $priority = 'Medium'
): int {
    $created = 0;
    foreach (adminNotificationAppointmentRecipientUserIds($conn, $appointmentId) as $userId) {
        if (adminNotificationCreateForUser(
            $conn,
            $userId,
            $title,
            $message,
            $type,
            $appointmentId,
            'appointments',
            $priority
        )) {
            $created++;
        }
    }

    return $created;
}

function adminNotificationNotifyAppointmentStatus(
    mysqli $conn,
    int $appointmentId,
    string $status
): int {
    $message = 'Appointment #' . $appointmentId . ' status changed to ' . $status . '.';
    $created = adminNotificationCreateForAppointmentUsers(
        $conn,
        $appointmentId,
        'Appointment Status Updated',
        $message,
        'Appointment Status',
        'Medium'
    );

    return $created + adminNotificationCreateForActiveAdmins(
        $conn,
        'Appointment Status Updated',
        $message,
        'Appointment Status',
        $appointmentId,
        'appointments',
        'Medium'
    );
}

function adminNotificationMarkRead(mysqli $conn, int $notificationId, int $userId): bool
{
    if ($notificationId <= 0 || $userId <= 0) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE notifications
         SET IsRead = 1, ReadAt = NOW()
         WHERE NotificationID = ? AND UserID = ?'
    );

    if (!$stmt) {
        error_log('Unable to prepare mark-read query: ' . mysqli_error($conn));
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'ii', $notificationId, $userId);
    $success = mysqli_stmt_execute($stmt);
    if (!$success) {
        error_log('Unable to mark notification as read: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
    return $success;
}

function adminNotificationMarkAllRead(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        'UPDATE notifications
         SET IsRead = 1, ReadAt = NOW()
         WHERE UserID = ? AND IsRead = 0'
    );

    if (!$stmt) {
        error_log('Unable to prepare mark-all-read query: ' . mysqli_error($conn));
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    $success = mysqli_stmt_execute($stmt);
    if (!$success) {
        error_log('Unable to mark all notifications as read: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
    return $success;
}

function adminNotificationClearAll(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        'DELETE FROM notifications WHERE UserID = ?'
    );

    if (!$stmt) {
        error_log('Unable to prepare clear-all notifications query: ' . mysqli_error($conn));
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    $success = mysqli_stmt_execute($stmt);
    if (!$success) {
        error_log('Unable to clear notifications: ' . mysqli_stmt_error($stmt));
    }

    mysqli_stmt_close($stmt);
    return $success;
}
