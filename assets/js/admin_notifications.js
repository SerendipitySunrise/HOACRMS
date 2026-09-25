(function () {
  'use strict';

  function formatTimestamp(timestamp) {
    if (!timestamp) {
      return '';
    }

    const date = new Date(timestamp.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
      return timestamp;
    }

    return date.toLocaleString([], {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function setUnreadCount(wrapper, count) {
    const badge = wrapper.querySelector('[data-admin-notification-count]');
    if (!badge) {
      return;
    }

    const unreadCount = Number(count) || 0;
    badge.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
    badge.classList.toggle('is-hidden', unreadCount === 0);
  }

  function createNotificationItem(notification, onRead) {
    const item = document.createElement('button');
    const content = document.createElement('span');
    const title = document.createElement('strong');
    const message = document.createElement('span');
    const time = document.createElement('time');
    const isRead = Number(notification.IsReadInt) === 1;
    const timestamp = notification.SentAt || notification.CreatedAt || '';

    item.type = 'button';
    item.className = 'notification-item admin-notification-item';
    item.dataset.adminNotificationItem = '';
    item.dataset.notificationId = String(notification.NotificationID);
    item.dataset.isRead = isRead ? '1' : '0';
    if (!isRead) {
      item.classList.add('is-unread');
    }

    content.className = 'admin-notification-item-content';
    title.textContent = notification.Title || '';
    message.textContent = notification.Message || '';
    time.dateTime = timestamp;
    time.textContent = formatTimestamp(timestamp);

    content.append(title, message);
    item.append(content, time);
    item.addEventListener('click', function () {
      onRead(Number(notification.NotificationID), isRead);
    });

    return item;
  }

  function renderNotifications(wrapper, notifications, onRead) {
    const list = wrapper.querySelector('[data-admin-notification-list]');
    if (!list) {
      return;
    }

    const uniqueNotifications = new Map();
    notifications.forEach(function (notification) {
      const notificationId = Number(notification.NotificationID);
      if (notificationId > 0) {
        uniqueNotifications.set(notificationId, notification);
      }
    });

    list.replaceChildren();
    if (uniqueNotifications.size === 0) {
      const empty = document.createElement('div');
      const title = document.createElement('strong');
      const message = document.createElement('span');
      empty.className = 'notification-item admin-notification-empty';
      title.textContent = 'No notifications';
      message.textContent = 'You are all caught up.';
      empty.append(title, message);
      list.append(empty);
      return;
    }

    uniqueNotifications.forEach(function (notification) {
      list.append(createNotificationItem(notification, onRead));
    });
  }

  function initializeNotificationWidget(wrapper) {
    const endpoint = wrapper.dataset.adminNotificationEndpoint;
    const dropdown = wrapper.querySelector('[data-admin-notification-dropdown]');
    const toggle = wrapper.querySelector('[data-admin-notification-toggle]');
    const close = wrapper.querySelector('[data-admin-notification-close]');
    const markAll = wrapper.querySelector('[data-admin-notification-mark-all]');
    const clearAll = wrapper.querySelector('[data-admin-notification-clear-all]');

    if (!endpoint || !dropdown || !toggle || !close || !markAll || !clearAll) {
      return;
    }

    function setOpen(isOpen) {
      dropdown.hidden = !isOpen;
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    async function loadNotifications() {
      try {
        const response = await fetch(endpoint + '?action=load', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        });
        if (!response.ok) {
          throw new Error('Notification request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        if (!payload.success) {
          throw new Error(payload.message || 'Unable to load notifications.');
        }

        setUnreadCount(wrapper, payload.unreadCount);
        renderNotifications(wrapper, payload.notifications || [], markNotificationRead);
      } catch (error) {
        console.warn('Admin notifications could not be loaded.', error);
      }
    }

    async function markNotificationRead(notificationId, alreadyRead) {
      if (alreadyRead || !notificationId) {
        return;
      }

      try {
        const body = new URLSearchParams({
          action: 'mark_read',
          notification_id: String(notificationId)
        });
        const response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          },
          body: body.toString()
        });
        if (!response.ok) {
          throw new Error('Mark-read request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        if (!payload.success) {
          throw new Error(payload.message || 'Unable to mark notification as read.');
        }

        await loadNotifications();
      } catch (error) {
        console.warn('Admin notification could not be marked as read.', error);
      }
    }

    async function markAllNotificationsRead() {
      try {
        const body = new URLSearchParams({ action: 'mark_all_read' });
        const response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          },
          body: body.toString()
        });
        if (!response.ok) {
          throw new Error('Mark-all-read request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        if (!payload.success) {
          throw new Error(payload.message || 'Unable to mark notifications as read.');
        }

        await loadNotifications();
      } catch (error) {
        console.warn('Admin notifications could not be marked as read.', error);
      }
    }

    async function clearAllNotifications() {
      if (!window.confirm('Are you sure you want to clear all notifications?')) {
        return;
      }

      try {
        const body = new URLSearchParams({ action: 'clear_all' });
        const response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          },
          body: body.toString()
        });
        if (!response.ok) {
          throw new Error('Clear-all request failed with HTTP ' + response.status);
        }

        const payload = await response.json();
        if (!payload.success) {
          throw new Error(payload.message || 'Unable to clear notifications.');
        }

        setUnreadCount(wrapper, payload.unreadCount);
        renderNotifications(wrapper, payload.notifications || [], markNotificationRead);
      } catch (error) {
        console.warn('Admin notifications could not be cleared.', error);
      }
    }

    toggle.addEventListener('click', function () {
      setOpen(dropdown.hidden);
    });

    close.addEventListener('click', function () {
      setOpen(false);
    });

    markAll.addEventListener('click', markAllNotificationsRead);
    clearAll.addEventListener('click', clearAllNotifications);

    document.addEventListener('click', function (event) {
      if (!wrapper.contains(event.target)) {
        setOpen(false);
      }
    });

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        loadNotifications();
      }
    });

    loadNotifications();
    window.setInterval(function () {
      if (!document.hidden) {
        loadNotifications();
      }
    }, 30000);
  }

  document.querySelectorAll('[data-admin-notification-endpoint]').forEach(initializeNotificationWidget);
})();
