<?php
session_start();

require_once __DIR__ . '/includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header('Location: login.php?portal=patient');
    exit();
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    header('Location: portal-select.php?action=login');
    exit();
}

$userID = (int) $_SESSION['UserID'];

// Get patient info
$patientStmt = mysqli_prepare(
    $conn,
    'SELECT
        p.PatientID,
        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.ProfilePhoto
     FROM patients p
     INNER JOIN users u ON p.UserID = u.UserID
     WHERE p.UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param($patientStmt, 'i', $userID);
mysqli_stmt_execute($patientStmt);
$patientResult = mysqli_stmt_get_result($patientStmt);
$patient = mysqli_fetch_assoc($patientResult);

if (!$patient) {
    die('Patient profile not found.');
}

$initials = strtoupper(substr($patient['FirstName'], 0, 1) . substr($patient['LastName'], 0, 1));
$fullName = trim($patient['FirstName'] . ' '
    . ($patient['MiddleName'] ? $patient['MiddleName'] . ' ' : '')
    . $patient['LastName']);

// Get notifications for this user
$notifStmt = mysqli_prepare(
    $conn,
    'SELECT
        NotificationID,
        Title,
        Message,
        Type,
        IsRead,
        PriorityLevel,
        SentAt,
        CreatedAt
     FROM notifications
     WHERE UserID = ?
     ORDER BY IsRead ASC, CreatedAt DESC'
);

mysqli_stmt_bind_param($notifStmt, 'i', $userID);
mysqli_stmt_execute($notifStmt);
$notifResult = mysqli_stmt_get_result($notifStmt);

$notifications = [];
$unreadCount = 0;
while ($row = mysqli_fetch_assoc($notifResult)) {
    $row['IsRead'] = (int) $row['IsRead'];
    if (!$row['IsRead']) $unreadCount++;
    $notifications[] = $row;
}

// Format timestamp
function timeAgo($datetime) {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

// Get icon and color based on notification type
function getNotifStyle($type, $priority) {
    $type = strtolower($type ?? '');
    $priority = strtolower($priority ?? 'low');

    if (strpos($type, 'appointment') !== false || strpos($type, 'reminder') !== false) {
        return ['icon' => 'clock', 'bg' => '#99F6E4', 'color' => '#0D9488'];
    }
    if (strpos($type, 'queue') !== false || strpos($type, 'urgent') !== false || $priority === 'high') {
        return ['icon' => 'alert', 'bg' => '#FCA5A5', 'color' => '#DC2626'];
    }
    if (strpos($type, 'result') !== false || strpos($type, 'lab') !== false) {
        return ['icon' => 'file', 'bg' => '#BFDBFE', 'color' => '#2563EB'];
    }
    if (strpos($type, 'prescription') !== false || strpos($type, 'medication') !== false) {
        return ['icon' => 'pill', 'bg' => '#C4B5FD', 'color' => '#7C3AED'];
    }
    return ['icon' => 'info', 'bg' => '#BFDBFE', 'color' => '#2563EB'];
}

// Mark notification as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    $notifID = (int) $_POST['notif_id'];
    $updateStmt = mysqli_prepare(
        $conn,
        'UPDATE notifications SET IsRead = 1, ReadAt = NOW() WHERE NotificationID = ? AND UserID = ?'
    );
    mysqli_stmt_bind_param($updateStmt, 'ii', $notifID, $userID);
    mysqli_stmt_execute($updateStmt);
    header('Location: notifications.php');
    exit();
}

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    $updateStmt = mysqli_prepare(
        $conn,
        'UPDATE notifications SET IsRead = 1, ReadAt = NOW() WHERE UserID = ? AND IsRead = 0'
    );
    mysqli_stmt_bind_param($updateStmt, 'i', $userID);
    mysqli_stmt_execute($updateStmt);
    header('Location: notifications.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Notifications — MediCare Patient Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/patient_dashboard.css">
</head>
<body>
<div class="app">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </div>
      <div class="brand-text">
        <div class="brand-title">MediCare</div>
        <div class="brand-sub">Patient Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item">
        <a href="patient_dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
        Dashboard
      </li>
      <li class="nav-item">
        <a href="patient_appointment.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Appointments
      </li>
      <li class="nav-item">
        <a href="queue_status.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
        Queue Status
      </li>
      <li class="nav-item">
        <a href="view_results.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
        View Results
      </li>
      <li class="nav-item">
        <a href="consultation_history.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Consultation History
      </li>
      <li class="nav-item active">
        <a href="notifications.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
      </li>
      <li class="nav-item">
        <a href="patient_profile.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <?php if (!empty($patient['ProfilePhoto'])): ?>
        <div class="user-avatar"><img src="<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo $initials; ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
          <div class="user-role">Patient</div>
        </div>
      </div>
      <a class="sign-out" href="logout.php" onclick="return confirm('Are you sure you want to sign out?');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="page-header">
      <h1>Notifications</h1>
      <p>Stay updated on your appointments and health</p>
    </div>

    <!-- Filter + Mark All Read -->
    <div class="notif-toolbar">
      <div class="tab-switch">
        <button class="tab-btn active" onclick="filterNotifs('all')">All</button>
        <button class="tab-btn" onclick="filterNotifs('unread')">Unread<?php if ($unreadCount > 0): ?> (<?php echo $unreadCount; ?>)<?php endif; ?></button>
      </div>
      <?php if ($unreadCount > 0): ?>
        <form method="POST" style="display:inline;">
          <input type="hidden" name="mark_all_read" value="1">
          <button type="submit" class="btn-mark-all-read">Mark all as read</button>
        </form>
      <?php endif; ?>
    </div>

    <!-- Notification List -->
    <div class="notif-list" id="notifList">
      <?php if (empty($notifications)): ?>
        <div class="empty-state">
          <div class="empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          </div>
          <h3>No Notifications</h3>
          <p>You're all caught up! Notifications about appointments and health updates will appear here.</p>
        </div>
      <?php else: ?>
        <?php foreach ($notifications as $notif): ?>
          <?php $style = getNotifStyle($notif['Type'], $notif['PriorityLevel']); ?>
          <div class="notif-card <?php echo !$notif['IsRead'] ? 'unread' : 'read'; ?>" data-read="<?php echo $notif['IsRead']; ?>" onclick="toggleNotifCard(this)">
            <div class="notif-card-header">
              <div class="notif-card-left">
                <div class="notif-icon-badge" style="background: <?php echo $style['bg']; ?>; color: <?php echo $style['color']; ?>;">
                  <?php if ($style['icon'] === 'clock'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <?php elseif ($style['icon'] === 'alert'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <?php elseif ($style['icon'] === 'file'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
                  <?php elseif ($style['icon'] === 'pill'): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m10.5 1.5 3 3-8.5 8.5a2.12 2.12 0 0 0 3 3l8.5-8.5 3 3"/><path d="m7.5 7.5 3 3"/></svg>
                  <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                  <?php endif; ?>
                </div>
                <div class="notif-card-info">
                  <div class="notif-card-title-row">
                    <span class="notif-card-title"><?php echo htmlspecialchars($notif['Title']); ?></span>
                    <?php if (!$notif['IsRead']): ?>
                      <span class="notif-unread-dot"></span>
                    <?php endif; ?>
                  </div>
                  <div class="notif-card-message"><?php echo htmlspecialchars($notif['Message']); ?></div>
                  <div class="notif-card-time"><?php echo timeAgo($notif['CreatedAt']); ?></div>
                </div>
              </div>
              <div class="notif-card-chevron">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
              </div>
            </div>
            <div class="notif-card-body">
              <div class="notif-detail-grid">
                <div class="notif-detail">
                  <div class="notif-detail-label">Type</div>
                  <div class="notif-detail-value"><?php echo htmlspecialchars(ucfirst($notif['Type'] ?: 'General')); ?></div>
                </div>
                <div class="notif-detail">
                  <div class="notif-detail-label">Priority</div>
                  <div class="notif-detail-value"><?php echo htmlspecialchars($notif['PriorityLevel']); ?></div>
                </div>
                <div class="notif-detail">
                  <div class="notif-detail-label">Received</div>
                  <div class="notif-detail-value"><?php echo htmlspecialchars($notif['CreatedAt']); ?></div>
                </div>
                <?php if ($notif['IsRead'] && !empty($notif['SentAt'])): ?>
                <div class="notif-detail">
                  <div class="notif-detail-label">Read At</div>
                  <div class="notif-detail-value"><?php echo htmlspecialchars($notif['SentAt']); ?></div>
                </div>
                <?php endif; ?>
              </div>
              <?php if (!$notif['IsRead']): ?>
                <form method="POST" class="notif-mark-read-form">
                  <input type="hidden" name="mark_read" value="1">
                  <input type="hidden" name="notif_id" value="<?php echo $notif['NotificationID']; ?>">
                  <button type="submit" class="btn-mark-read" onclick="event.stopPropagation();">Mark as read</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </main>

</div>

<script>
function filterNotifs(filter) {
  document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

  const btns = document.querySelectorAll('.tab-btn');
  if (filter === 'all') {
    btns[0].classList.add('active');
  } else {
    btns[1].classList.add('active');
  }

  document.querySelectorAll('.notif-card').forEach(card => {
    if (filter === 'unread') {
      card.style.display = card.dataset.read === '0' ? '' : 'none';
    } else {
      card.style.display = '';
    }
  });
}

function toggleNotifCard(card) {
  const body = card.querySelector('.notif-card-body');
  const chevron = card.querySelector('.notif-card-chevron');
  const isOpen = card.classList.contains('open');

  if (isOpen) {
    card.classList.remove('open');
    body.style.maxHeight = '0';
    chevron.style.transform = 'rotate(0deg)';
  } else {
    card.classList.add('open');
    body.style.maxHeight = body.scrollHeight + 'px';
    chevron.style.transform = 'rotate(180deg)';
  }
}
</script>

</body>
</html>
