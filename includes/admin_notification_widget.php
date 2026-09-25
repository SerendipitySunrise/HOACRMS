<?php

require_once __DIR__ . '/admin_notifications.php';

$adminNotificationUserId = adminNotificationCurrentUserId();
$adminNotificationCount = adminNotificationUnreadCount($conn, $adminNotificationUserId);
$adminNotificationItems = adminNotificationRecent($conn, $adminNotificationUserId);

function adminNotificationDisplayTime(?string $timestamp): string
{
    if (!$timestamp) {
        return '';
    }

    $time = strtotime($timestamp);
    return $time === false ? '' : date('M j, Y g:i A', $time);
}
?>

<div class="notification-wrapper admin-notification-wrapper" data-admin-notification-endpoint="../api/admin/notifications.php">
  <button
    class="notif-bell admin-notification-bell"
    type="button"
    aria-label="Notifications"
    aria-expanded="false"
    aria-controls="adminNotificationDropdown"
    data-admin-notification-toggle
  >
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/>
      <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>
    </svg>
    <span
      class="notif-badge admin-notification-badge<?php echo $adminNotificationCount > 0 ? '' : ' is-hidden'; ?>"
      data-admin-notification-count
    ><?php echo $adminNotificationCount > 99 ? '99+' : $adminNotificationCount; ?></span>
  </button>

  <div class="notification-dropdown admin-notification-dropdown" id="adminNotificationDropdown" data-admin-notification-dropdown hidden>
    <button
      class="notification-close admin-notification-close"
      type="button"
      aria-label="Close notifications"
      data-admin-notification-close
    >
      ×
    </button>

    <div class="notification-header admin-notification-header">
      <strong>Notifications</strong>
      <span class="admin-notification-header-actions">
        <button class="admin-notification-mark-all" type="button" data-admin-notification-mark-all>
          Mark All as Read
        </button>
        <button class="admin-notification-clear-all" type="button" data-admin-notification-clear-all>
          Clear All
        </button>
      </span>
    </div>

    <div class="admin-notification-list" data-admin-notification-list>
      <?php if (empty($adminNotificationItems)): ?>
        <div class="notification-item admin-notification-empty" data-admin-notification-empty>
          <strong>No notifications</strong>
          <span>You are all caught up.</span>
        </div>
      <?php else: ?>
        <?php foreach ($adminNotificationItems as $notification): ?>
          <?php
          $isRead = (int) ($notification['IsReadInt'] ?? 0) === 1;
          $timestamp = $notification['SentAt'] ?: $notification['CreatedAt'];
          ?>
          <button
            class="notification-item admin-notification-item<?php echo $isRead ? '' : ' is-unread'; ?>"
            type="button"
            data-admin-notification-item
            data-notification-id="<?php echo (int) $notification['NotificationID']; ?>"
            data-is-read="<?php echo $isRead ? '1' : '0'; ?>"
          >
            <span class="admin-notification-item-content">
              <strong><?php echo htmlspecialchars((string) $notification['Title'], ENT_QUOTES, 'UTF-8'); ?></strong>
              <span><?php echo htmlspecialchars((string) $notification['Message'], ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
            <time datetime="<?php echo htmlspecialchars((string) $timestamp, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars(adminNotificationDisplayTime($timestamp), ENT_QUOTES, 'UTF-8'); ?>
            </time>
          </button>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
