<?php

/**
 * Records an administrative action into the `audit_trail` table.
 * Automatically captures the acting user's ID (from the session),
 * their IP address, and the user-agent.
 *
 * @param PDO       $pdo       Active PDO connection.
 * @param string    $action    Action label, e.g. "CREATE", "UPDATE", "DELETE", "LOGIN".
 * @param string    $tableName Table that was the subject of the action.
 * @param int|null  $recordId  Primary key of the affected row, if any.
 * @param string|null $oldValue Optional JSON/text snapshot of the previous state.
 * @param string|null $newValue Optional JSON/text snapshot of the new state.
 */
function logAudit(
    PDO $pdo,
    string $action,
    string $tableName,
    ?int $recordId = null,
    ?string $oldValue = null,
    ?string $newValue = null
): bool {
    try {
        $userId = (int) ($_SESSION['UserID'] ?? 0);

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos((string) $ip, ',') !== false) {
            $ip = trim(explode(',', (string) $ip)[0]);
        }
        $ip = is_string($ip) ? substr($ip, 0, 45) : '';

        $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null)
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255)
            : null;

        $stmt = $pdo->prepare(
            'INSERT INTO audit_trail
                (UserID, Action, TableName, RecordID, OldValue, NewValue, IPAddress, UserAgent, ActionTimestamp)
             VALUES (:user_id, :action, :table_name, :record_id, :old_value, :new_value, :ip, :user_agent, NOW())'
        );

        $stmt->execute([
            'user_id'    => $userId,
            'action'     => substr($action, 0, 50),
            'table_name' => substr($tableName, 0, 100),
            'record_id'  => $recordId,
            'old_value'  => $oldValue,
            'new_value'  => $newValue,
            'ip'         => $ip,
            'user_agent' => $userAgent,
        ]);

        return true;
    } catch (Throwable $e) {
        return false;
    }
}