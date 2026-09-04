<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_announcements.css">
</head>
<body>
<div class="app">

   <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/><path d="M3.22 8.5H9.5l1.5-2 2 4 1.5-2h6.28"/></svg>
      </div>
      <div class="brand-text">
        <div class="brand-title">MediCare</div>
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
        <a href="admin_system_settings.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
          System Settings
        </a>
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">PA</div>
        <div>
          <div class="user-name">Pedro Andres</div>
          <div class="user-role">Admin</div>
        </div>
      </div>
      <button class="sign-out">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </button>
    </div>
  </aside>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <div class="page-header">
      <h1>Announcements</h1>
      <p>Send and manage staff communications</p>
    </div>

    <!-- New Announcement Button -->
    <div class="top-actions">
      <button class="btn-primary" id="add-announcement-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Announcement
      </button>
    </div>

    <!-- Announcements Grid -->
    <div class="announcements-grid" id="announcements-grid">
      <!-- Rendered by JavaScript -->
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

    <form id="announcement-form" onsubmit="return false;">
      <input type="hidden" id="edit-index" value="-1" />

      <div class="form-group">
        <label for="announcement-title">Announcement Title</label>
        <input type="text" id="announcement-title" placeholder="e.g. System Maintenance Tonight" required />
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
            <option value="Doctors Only">Doctors Only</option>
            <option value="Nurses Only">Nurses Only</option>
            <option value="Administration">Administration</option>
            <option value="Technicians">Technicians</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label for="announcement-content">Content</label>
        <textarea id="announcement-content" rows="4" placeholder="Detailed announcement message..."></textarea>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="announcement-date">Date</label>
          <input type="date" id="announcement-date" />
        </div>
        <div class="form-group">
          <label for="announcement-read">Read Count</label>
          <input type="text" id="announcement-read" placeholder="e.g. 8/12 read" />
        </div>
      </div>

      <div class="form-group">
        <label for="announcement-author">Author</label>
        <input type="text" id="announcement-author" placeholder="admin@hospital.com" />
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
  // ================= ANNOUNCEMENT DATA =================
  let announcements = [
    {
      title: "System Maintenance Tonight",
      priority: "HIGH",
      audience: "All Staff",
      date: "Apr 25",
      content: "Scheduled maintenance will occur tonight at 11:00 PM. The system will be unavailable for approximately 30 minutes. Please complete all pending consultations before then.",
      author: "admin@hospital.com",
      read: "8/12 read"
    },
    {
      title: "New Pediatrics Schedule",
      priority: "MEDIUM",
      audience: "Doctors Only",
      date: "Apr 24",
      content: "Starting next week, Pediatrics will extend afternoon sessions on Thursdays and Fridays to accommodate increased demand. Please update your availability accordingly.",
      author: "admin@hospital.com",
      read: "5/6 read"
    },
    {
      title: "Emergency Protocol Update",
      priority: "HIGH",
      audience: "All Staff",
      date: "Apr 23",
      content: "All staff must review the updated emergency triage protocol. The new guidelines are available in the shared documents folder. Training session scheduled for next Monday.",
      author: "admin@hospital.com",
      read: "11/12 read"
    },
    {
      title: "Nursing Station Equipment Check",
      priority: "LOW",
      audience: "Nurses Only",
      date: "Apr 22",
      content: "Please ensure all vital signs monitoring equipment is calibrated and functioning. Report any issues to the maintenance team by end of day.",
      author: "admin@hospital.com",
      read: "4/5 read"
    },
    {
      title: "Weekend On-Call Roster",
      priority: "MEDIUM",
      audience: "Doctors Only",
      date: "Apr 22",
      content: "The weekend on-call roster for next week has been posted. Please check your assigned shifts and confirm availability.",
      author: "admin@hospital.com",
      read: "4/6 read"
    }
  ];

  // ================= DOM REFS =================
  const grid = document.getElementById('announcements-grid');
  const modal = document.getElementById('announcement-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const editIndex = document.getElementById('edit-index');
  const annTitle = document.getElementById('announcement-title');
  const annPriority = document.getElementById('announcement-priority');
  const annAudience = document.getElementById('announcement-audience');
  const annContent = document.getElementById('announcement-content');
  const annDate = document.getElementById('announcement-date');
  const annRead = document.getElementById('announcement-read');
  const annAuthor = document.getElementById('announcement-author');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-announcement-btn');

  // ================= HELPERS =================
  function getPriorityClass(priority) {
    const map = {
      'HIGH': 'priority-high',
      'MEDIUM': 'priority-medium',
      'LOW': 'priority-low'
    };
    return map[priority] || 'priority-medium';
  }

  function resetForm() {
    annTitle.value = '';
    annPriority.value = 'MEDIUM';
    annAudience.value = 'All Staff';
    annContent.value = '';
    annDate.value = '';
    annRead.value = '';
    annAuthor.value = '';
    editIndex.value = '-1';
    modalTitle.textContent = 'New Announcement';
    modalSub.textContent = 'Send a communication to staff members';
    saveBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Send Announcement
    `;
  }

  function openModal(announcementData, index) {
    if (announcementData) {
      annTitle.value = announcementData.title || '';
      annPriority.value = announcementData.priority || 'MEDIUM';
      annAudience.value = announcementData.audience || 'All Staff';
      annContent.value = announcementData.content || '';
      annDate.value = announcementData.date || '';
      annRead.value = announcementData.read || '';
      annAuthor.value = announcementData.author || '';
      editIndex.value = index;
      modalTitle.textContent = `Edit: ${announcementData.title}`;
      modalSub.textContent = 'Update announcement details';
      saveBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Update Announcement
      `;
    } else {
      resetForm();
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  // ================= RENDER ANNOUNCEMENTS =================
  function renderAnnouncements() {
    if (!announcements.length) {
      grid.innerHTML = `<div class="empty-state">No announcements found. Click "New Announcement" to create one.</div>`;
      return;
    }

    let html = '';
    announcements.forEach((a, i) => {
      const priorityClass = getPriorityClass(a.priority);

      html += `
        <div class="announcement-card">
          <div class="announcement-header">
            <div class="announcement-title-group">
              <h3 class="announcement-title">${a.title}</h3>
              <span class="priority-badge ${priorityClass}">${a.priority}</span>
            </div>
            <div class="announcement-actions">
              <button class="action-btn edit-btn" data-index="${i}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
              </button>
              <button class="action-btn delete-btn" data-index="${i}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
              </button>
            </div>
          </div>
          <div class="announcement-meta">
            <span class="audience-badge">${a.audience}</span>
            <span class="announcement-date">${a.date}</span>
          </div>
          <p class="announcement-content">${a.content}</p>
          <div class="announcement-footer">
            <span class="announcement-author">By ${a.author}</span>
            <span class="announcement-read">📅 ${a.read}</span>
          </div>
        </div>
      `;
    });
    grid.innerHTML = html;

    // Attach edit events
    document.querySelectorAll('.edit-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const announcement = announcements[idx];
        if (announcement) openModal(announcement, idx);
      });
    });

    // Attach delete events
    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        if (confirm(`Are you sure you want to delete "${announcements[idx].title}"?`)) {
          announcements.splice(idx, 1);
          renderAnnouncements();
        }
      });
    });
  }

  // ================= SAVE ANNOUNCEMENT =================
  function saveAnnouncement() {
    const title = annTitle.value.trim();
    if (!title) { alert('Please enter an announcement title.'); return; }
    const content = annContent.value.trim();
    if (!content) { alert('Please enter announcement content.'); return; }

    const newAnnouncement = {
      title: title,
      priority: annPriority.value,
      audience: annAudience.value,
      date: annDate.value || new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
      content: content,
      author: annAuthor.value.trim() || 'admin@hospital.com',
      read: annRead.value.trim() || '0/0 read'
    };

    const idx = parseInt(editIndex.value, 10);
    if (idx >= 0 && idx < announcements.length) {
      announcements[idx] = newAnnouncement;
    } else {
      announcements.push(newAnnouncement);
    }
    renderAnnouncements();
    closeModal();
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  saveBtn.addEventListener('click', saveAnnouncement);
  document.getElementById('announcement-form').addEventListener('submit', (e) => {
    e.preventDefault();
    saveAnnouncement();
  });

  // ================= INIT =================
  renderAnnouncements();
</script>
</body>
</html>