<?php
/**
 * announcements.php
 * CarePath - Staff Announcements
 */

session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/announcement_helper.php';

if (!isset($conn) || !$conn) {
    die('Database connection is not available.');
}

$userID = $_SESSION['UserID']
    ?? $_SESSION['user_id']
    ?? $_SESSION['userid']
    ?? null;

if (!$userID) {
    header('Location: ../auth/login.php?portal=staff');
    exit;
}

$userID = (int) $userID;

$roleName = $_SESSION['RoleName']
    ?? $_SESSION['role']
    ?? $_SESSION['user_role']
    ?? null;

if ($roleName !== null && strcasecmp(trim((string) $roleName), 'Staff') !== 0) {
    header('Location: ../portal-select.php?action=login');
    exit;
}

/*
|--------------------------------------------------------------------------
| MARK READ / MARK ALL (POST)
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'mark_read') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare(
                'INSERT INTO announcement_reads (announcement_id, UserID)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP'
            );
            $stmt->bind_param('ii', $id, $userID);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($action === 'mark_all') {
        $audiences = announcementAudiencesForUser($conn, $userID);
        if ($audiences !== []) {
            $placeholders = implode(',', array_fill(0, count($audiences), '?'));
            $types = str_repeat('s', count($audiences));
            $stmt = $conn->prepare(
                'SELECT id FROM announcements WHERE audience IN (' . $placeholders . ')'
            );
            $stmt->bind_param($types, ...array_values($audiences));
            $stmt->execute();
            $ids = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int) $row['id'];
            }
            $stmt->close();

            $ins = $conn->prepare(
                'INSERT IGNORE INTO announcement_reads (announcement_id, UserID) VALUES (?, ?)'
            );
            foreach ($ids as $id) {
                $ins->bind_param('ii', $id, $userID);
                $ins->execute();
            }
            $ins->close();
        }
    }

    header('Location: announcements.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| FETCH ANNOUNCEMENTS
|--------------------------------------------------------------------------
*/

$audiences = announcementAudiencesForUser($conn, $userID);

$readSet = [];
$readStmt = $conn->prepare(
    'SELECT announcement_id FROM announcement_reads WHERE UserID = ' . (int) $userID
);
$readStmt->execute();
$readRes = $readStmt->get_result();
while ($row = $readRes->fetch_assoc()) {
    $readSet[(int) $row['announcement_id']] = true;
}
$readStmt->close();

$announcements = [];
if ($audiences !== []) {
    $placeholders = implode(',', array_fill(0, count($audiences), '?'));
    $types = str_repeat('s', count($audiences));
    $sql = '
        SELECT a.id, a.title, a.priority, a.audience, a.content, a.author, a.created_at,
               (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id) AS read_count
        FROM announcements a
        WHERE a.audience IN (' . $placeholders . ')
        ORDER BY a.created_at DESC, a.id DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...array_values($audiences));
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $announcements[] = $row;
    }
    $stmt->close();
}

$unreadCount = 0;
foreach ($announcements as $a) {
    if (!isset($readSet[(int) $a['id']])) {
        $unreadCount++;
    }
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements — CarePath Staff Portal</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/staff/staff_dashboard.css">
<style>
  .mark-all-read-btn {
    background: #fff;
    border: 1px solid var(--color-border);
    color: var(--color-blue);
    font-family: var(--font-family);
    font-size: 0.7rem;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 999px;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
    white-space: nowrap;
  }
  .mark-all-read-btn:hover {
    background: #eff6ff;
    border-color: #bfdbfe;
  }
</style>
</head>
<body>
<div class="app">

  <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <img src="../assets/images/carepath-icon.png" alt="CarePath">
      </div>
      <div class="brand-text">
        <div class="brand-title">Care<strong>Path</strong></div>
        <div class="brand-sub">Staff Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item">
        <a href="checkIn_patient.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11H3M11 5h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-7"/><circle cx="11" cy="11" r="6"/><polyline points="14 11 17 11"/></svg>
          Check-in Patient
        </a>
      </li>
      <li class="nav-item">
        <a href="queue.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>
          Queue
        </a>
      </li>
      <li class="nav-item active">
        <a href="announcements.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          Announcements
        </a>
      </li>
      <li class="nav-item">
        <a href="staff_profile.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </li>
    </ul>

    <!-- SIDEBAR FOOTER -->
    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">
          <?= esc(strtoupper(substr((string) ($_SESSION['FirstName'] ?? ''), 0, 1) . substr((string) ($_SESSION['LastName'] ?? ''), 0, 1))) ?>
        </div>
        <div>
          <div class="user-name"><?= esc(trim((string) ($_SESSION['FirstName'] ?? '') . ' ' . (string) ($_SESSION['LastName'] ?? ''))) ?></div>
          <div class="user-role">Staff</div>
        </div>
      </div>
      <a href="../auth/logout.php" class="sign-out"
         onclick="return confirm('Are you sure you want to sign out?');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <div class="page-header">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap;">
        <div>
          <h1>Announcements</h1>
          <p>Stay up to date with hospital announcements</p>
        </div>
        <?php if ($unreadCount > 0): ?>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="mark_all">
            <button type="submit" class="mark-all-read-btn">Mark all as read</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($announcements)): ?>
      <div class="empty-state-box">
        <div class="empty-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        </div>
        <h3>No announcements</h3>
        <p>There are no announcements for you right now.</p>
      </div>
    <?php else: ?>
      <div class="announcement-list">
        <?php foreach ($announcements as $a): ?>
          <?php
            $id = (int) $a['id'];
            $read = isset($readSet[$id]);
            $priorityClass = 'priority-medium';
            if (($a['priority'] ?? '') === 'HIGH') { $priorityClass = 'priority-high'; }
            elseif (($a['priority'] ?? '') === 'LOW') { $priorityClass = 'priority-low'; }
            $ts = strtotime((string) $a['created_at']);
            $dateLabel = $ts ? date('M j, Y', $ts) : '—';
          ?>
          <div class="announcement-card">
            <div class="announcement-card-head">
              <div class="announcement-card-title-row">
                <span class="announcement-card-title"><?= esc((string) $a['title']) ?></span>
                <span class="priority-badge <?= esc($priorityClass) ?>"><?= esc((string) $a['priority']) ?></span>
              </div>
              <span class="announcement-card-date"><?= esc($dateLabel) ?></span>
            </div>
            <div class="announcement-card-body"><?= esc((string) $a['content']) ?></div>
            <div class="announcement-card-foot">
              <span class="announcement-card-author">By <?= esc((string) $a['author']) ?></span>
              <?php if ($read): ?>
                <span class="announcement-card-read">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  Read
                </span>
              <?php else: ?>
                <form method="POST">
                  <input type="hidden" name="action" value="mark_read">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <button type="submit" class="mark-all-read-btn">Mark as read</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </main>
</div>
</body>
</html>