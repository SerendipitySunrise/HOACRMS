<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db_pdo.php';
requireRole('Admin');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$pdo = db_pdo();

$stmt = $pdo->query(
    'SELECT a.id, a.title, a.priority, a.audience, a.content, a.author, a.created_at,
            COUNT(ar.announcement_id) AS read_count
     FROM announcements a
     LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id
     GROUP BY a.id
     ORDER BY a.created_at DESC'
);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Escape output safely. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$priorityMap = [
    'HIGH'   => 'priority-high',
    'MEDIUM' => 'priority-medium',
    'LOW'    => 'priority-low',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements — Curora Admin Portal</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_announcements.css">
</head>
<body>
<div class="app">

   <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <img src="../assets/images/curora-icon.png" alt="Curora">
      </div>
      <div class="brand-text">
        <div class="brand-title">Curora</div>
        <div class="brand-sub">Admin Portal</div>
      </div>
    </div>

    <ul class="nav-list">
      <li class="nav-item">
        <a href="admin_dashboard.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>
          Dashboard
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_department.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 21v-6h6v6"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01"/></svg>
          Departments
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_doctor_management.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-1a6 6 0 0 1 6-6h1a6 6 0 0 1 6 6v1"/><circle cx="9.5" cy="7" r="4"/><path d="M19 8v4M21 10h-4"/></svg>
          Doctors
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_patient_management.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-1a7 7 0 0 0-7-7h-2a7 7 0 0 0-7 7v1"/><circle cx="12" cy="7" r="4"/></svg>
          Patients
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_staff_management.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          Staff
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_reports.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-4"/></svg>
          Reports
        </a>
      </li>
      <li class="nav-item active">
        <a href="admin_announcements.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          Announcement
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_profile.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_system_settings.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
          System Settings
        </a>
      </li>
    </ul>

    <?php include __DIR__ . '/../includes/admin_sidebar_footer.php'; ?>
  </aside>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <div class="page-header">
      <h1>Announcements</h1>
      <p>Send and manage staff communications</p>
    </div>

    <!-- New Announcement Button -->
    <div class="top-actions">
      <a class="btn-disruption" href="../schedule_disruptions.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="4" width="18" height="17" rx="2"></rect>
          <path d="M16 2v4M8 2v4M3 10h18"></path>
          <path d="m12 14 .01 0M12 17v.01"></path>
        </svg>
        Schedule disruption
      </a>
      <button class="btn-primary" id="add-announcement-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Announcement
      </button>
    </div>

    <!-- Announcements Grid -->
    <div class="announcements-grid" id="announcements-grid">
      <?php if (empty($announcements)): ?>
        <div class="empty-state">No announcements found. Click "New Announcement" to create one.</div>
      <?php else: ?>
        <?php foreach ($announcements as $a): ?>
          <?php
            $priorityClass = $priorityMap[$a['priority']] ?? 'priority-medium';
            $created = strtotime((string) $a['created_at']);
            $dateLabel = $created ? date('M j, Y', $created) : '—';
          ?>
          <div class="announcement-card" data-id="<?= (int) $a['id'] ?>">
            <div class="announcement-header">
              <div class="announcement-title-group">
                <h3 class="announcement-title"><?= e($a['title']) ?></h3>
                <span class="priority-badge <?= e($priorityClass) ?>"><?= e($a['priority']) ?></span>
              </div>
              <div class="announcement-actions">
                <button class="action-btn edit-btn" data-id="<?= (int) $a['id'] ?>"
                        data-title="<?= e($a['title']) ?>" data-priority="<?= e($a['priority']) ?>"
                        data-audience="<?= e($a['audience']) ?>" data-content="<?= e($a['content']) ?>"
                        data-date="<?= e(substr((string) $a['created_at'], 0, 10)) ?>"
                        title="Edit">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                </button>
                <button class="action-btn delete-btn" data-id="<?= (int) $a['id'] ?>"
                        data-title="<?= e($a['title']) ?>" title="Delete">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </div>
            </div>
            <div class="announcement-meta">
              <span class="audience-badge"><?= e($a['audience']) ?></span>
              <span class="announcement-date"><?= e($dateLabel) ?></span>
            </div>
            <p class="announcement-content"><?= e($a['content']) ?></p>
            <div class="announcement-footer">
              <span class="announcement-author">By <?= e($a['author']) ?></span>
              <span class="announcement-read">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                <?= (int) $a['read_count'] ?> read
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- ================= ADD / EDIT ANNOUNCEMENT MODAL ================= -->
<div class="modal-overlay hidden" id="announcement-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">New Announcement</div>
        <div class="modal-sub" id="modal-sub">Send a communication to staff members</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <div class="modal-message hidden" id="modal-message"></div>

    <form id="announcement-form" onsubmit="return false;">
      <input type="hidden" id="announcement-id" value="" />
      <input type="hidden" id="csrf-token" value="<?= e($csrfToken) ?>" />

      <div class="form-group">
        <label for="announcement-title">Announcement Title</label>
        <input type="text" id="announcement-title" maxlength="255"
               placeholder="e.g. System Maintenance Tonight" required />
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="announcement-priority">Priority</label>
          <select id="announcement-priority">
            <option value="HIGH">HIGH</option>
            <option value="MEDIUM">MEDIUM</option>
            <option value="LOW">LOW</option>
          </select>
        </div>
        <div class="form-group">
          <label for="announcement-audience">Audience</label>
          <select id="announcement-audience">
            <option value="All Staff">All Staff</option>
            <option value="Patients Only">Patients Only</option>
            <option value="Doctors Only">Doctors Only</option>
            <option value="Nurses Only">Nurses Only</option>
            <option value="Administration">Administration</option>
            <option value="Technicians">Technicians</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label for="announcement-content">Content</label>
        <textarea id="announcement-content" rows="4" placeholder="Detailed announcement message..." required></textarea>
      </div>

      <div class="form-group">
        <label for="announcement-date">Date</label>
        <input type="date" id="announcement-date" />
      </div>

      <div class="form-group">
        <label for="announcement-author">Author</label>
        <input type="text" id="announcement-author" value="<?= e(trim((string) ($_SESSION['FirstName'] ?? '') . ' ' . (string) ($_SESSION['LastName'] ?? ''))) ?>" disabled />
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Send Announcement
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= DOM REFS =================
  const grid = document.getElementById('announcements-grid');
  const modal = document.getElementById('announcement-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const modalMessage = document.getElementById('modal-message');
  const hiddenId = document.getElementById('announcement-id');
  const csrfToken = document.getElementById('csrf-token').value;
  const annTitle = document.getElementById('announcement-title');
  const annPriority = document.getElementById('announcement-priority');
  const annAudience = document.getElementById('announcement-audience');
  const annContent = document.getElementById('announcement-content');
  const annDate = document.getElementById('announcement-date');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-announcement-btn');

  // ================= HELPERS =================
  function showMessage(text, isError) {
    modalMessage.textContent = text;
    modalMessage.classList.toggle('is-error', !!isError);
    modalMessage.classList.remove('hidden');
  }

  function hideMessage() {
    modalMessage.classList.add('hidden');
  }

  function todayValue() {
    return new Date().toISOString().slice(0, 10);
  }

  function resetForm() {
    hiddenId.value = '';
    annTitle.value = '';
    annPriority.value = 'MEDIUM';
    annAudience.value = 'All Staff';
    annContent.value = '';
    annDate.value = todayValue();
    modalTitle.textContent = 'New Announcement';
    modalSub.textContent = 'Send a communication to staff members';
    saveBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Send Announcement
    `;
    hideMessage();
  }

  function openModal(announcementData) {
    resetForm();
    if (announcementData) {
      hiddenId.value = announcementData.id;
      annTitle.value = announcementData.title;
      annPriority.value = announcementData.priority;
      annAudience.value = announcementData.audience;
      annContent.value = announcementData.content;
      annDate.value = announcementData.date || todayValue();
      modalTitle.textContent = 'Edit: ' + announcementData.title;
      modalSub.textContent = 'Update announcement details';
      saveBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Update Announcement
      `;
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  function disableSave(disabled) {
    saveBtn.disabled = disabled;
    saveBtn.style.opacity = disabled ? '0.6' : '';
  }

  // ================= API =================
  async function submitForm() {
    const id = hiddenId.value;
    const title = annTitle.value.trim();
    const content = annContent.value.trim();
    if (!title) { showMessage('Please enter an announcement title.', true); return; }
    if (!content) { showMessage('Please enter announcement content.', true); return; }

    const body = new FormData();
    body.append('csrf_token', csrfToken);
    body.append('action', id ? 'update' : 'create');
    if (id) body.append('id', id);
    body.append('title', title);
    body.append('priority', annPriority.value);
    body.append('audience', annAudience.value);
    body.append('content', content);
    if (annDate.value) body.append('created_at', annDate.value + ' 00:00:00');

    disableSave(true);
    hideMessage();

    try {
      const res = await fetch('../api/announcements.php', {
        method: 'POST',
        body: body
      });
      const data = await res.json();
      if (data && data.success) {
        window.location.reload();
      } else {
        showMessage((data && data.message) || 'Something went wrong.', true);
        disableSave(false);
      }
    } catch (err) {
      showMessage('Network error. Please try again.', true);
      disableSave(false);
    }
  }

  async function deleteAnnouncement(id, title) {
    if (!confirm(`Are you sure you want to delete "${title}"?`)) return;

    const body = new FormData();
    body.append('csrf_token', csrfToken);
    body.append('action', 'delete');
    body.append('id', id);

    try {
      const res = await fetch('../api/announcements.php', {
        method: 'POST',
        body: body
      });
      const data = await res.json();
      if (data && data.success) {
        window.location.reload();
      } else {
        alert((data && data.message) || 'Something went wrong.');
      }
    } catch (err) {
      alert('Network error. Please try again.');
    }
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null));

  grid.addEventListener('click', (e) => {
    const editBtn = e.target.closest('.edit-btn');
    const deleteBtn = e.target.closest('.delete-btn');
    if (editBtn) {
      openModal({
        id: editBtn.dataset.id,
        title: editBtn.dataset.title,
        priority: editBtn.dataset.priority,
        audience: editBtn.dataset.audience,
        content: editBtn.dataset.content,
        date: (editBtn.dataset.date || todayValue()).slice(0, 10)
      });
    } else if (deleteBtn) {
      deleteAnnouncement(deleteBtn.dataset.id, deleteBtn.dataset.title);
    }
  });

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  document.getElementById('announcement-form').addEventListener('submit', (e) => {
    e.preventDefault();
    submitForm();
  });
</script>
</body>
</html>