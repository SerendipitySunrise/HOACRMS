<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Management — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_department.css">
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
      <li class="nav-item active">
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
      <li class="nav-item">
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

    <div class="staff-topbar">
      <div class="page-header">
        <h1>Department Management</h1>
        <p id="today-date">Sunday, May 10, 2026</p>
      </div>
      <button class="notif-bell" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span class="notif-badge">2</span>
      </button>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-head-title" style="font-size:1.15rem;">Departments</div>
          <div class="panel-head-meta" style="margin-top:4px;">Manage department schedules and appointment rules</div>
        </div>
        <div class="staff-header-actions">
          <button class="btn-checkin-solid" id="add-department-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Department
          </button>
        </div>
      </div>

      <div class="staff-table-wrap" style="margin-top:0;">
        <table class="staff-table">
          <thead>
            <tr>
              <th>Department</th>
              <th>Operating Days</th>
              <th>Morning</th>
              <th>Afternoon</th>
              <th>Slots</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="department-rows">
            <!-- rows rendered by JS -->
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<!-- ================= ADD / EDIT DEPARTMENT MODAL ================= -->
<div class="modal-overlay hidden" id="dept-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">Add Department</div>
        <div class="modal-sub" id="modal-sub">Fill in the department details</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <form id="dept-form" onsubmit="return false;">
      <input type="hidden" id="edit-index" value="-1" />

      <div class="form-group">
        <label for="dept-name">Department Name <span class="hint">(e.g. Cardiology)</span></label>
        <input type="text" id="dept-name" placeholder="Cardiology" required />
      </div>

      <div class="form-group">
        <label for="dept-desc">Description</label>
        <textarea id="dept-desc" placeholder="Brief description of the department..."></textarea>
      </div>

      <div class="form-group">
        <label>Operating Days</label>
        <div class="days-grid" id="days-grid">
          <label class="day-check"><input type="checkbox" value="Mon" /> Monday</label>
          <label class="day-check"><input type="checkbox" value="Tue" /> Tuesday</label>
          <label class="day-check"><input type="checkbox" value="Wed" /> Wednesday</label>
          <label class="day-check"><input type="checkbox" value="Thu" /> Thursday</label>
          <label class="day-check"><input type="checkbox" value="Fri" /> Friday</label>
          <label class="day-check"><input type="checkbox" value="Sat" /> Saturday</label>
          <label class="day-check"><input type="checkbox" value="Sun" /> Sunday</label>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="morning-start">Morning Start</label>
          <input type="time" id="morning-start" value="06:00" />
        </div>
        <div class="form-group">
          <label for="morning-end">Morning End</label>
          <input type="time" id="morning-end" value="11:59" />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="afternoon-start">Afternoon Start</label>
          <input type="time" id="afternoon-start" value="12:00" />
        </div>
        <div class="form-group">
          <label for="afternoon-end">Afternoon End</label>
          <input type="time" id="afternoon-end" value="17:00" />
        </div>
      </div>

      <div class="form-group">
        <label for="dept-slots">Total Slots</label>
        <input type="number" id="dept-slots" value="20" min="1" step="1" />
      </div>

      <div class="form-group">
        <label for="dept-status">Status</label>
        <select id="dept-status">
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Save Department
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= DEPARTMENT DATA =================
  const departments = [
    {
      name: "Orthopedics",
      desc: "Advanced joint replacement, sports medicine, and minimal invasive surgery...",
      days: ["Tue", "Fri"],
      morning: "06:00",
      morningEnd: "11:59",
      afternoon: "12:00",
      afternoonEnd: "17:00",
      slots: 20,
      active: true
    },
    {
      name: "Internal Medicine",
      desc: "Preventive care, chronic disease management, and complete checkups...",
      days: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
      morning: "06:00",
      morningEnd: "11:59",
      afternoon: "12:00",
      afternoonEnd: "17:00",
      slots: 20,
      active: true
    },
    {
      name: "Obstetrics & Gynecology",
      desc: "Complete women's health services from prenatal care, to postpartum and beyond...",
      days: ["Wed", "Fri"],
      morning: "06:00",
      morningEnd: "11:59",
      afternoon: "12:00",
      afternoonEnd: "17:00",
      slots: 20,
      active: true
    },
    {
      name: "Cardiology",
      desc: "Comprehensive heart care including advanced diagnostics, in-patient, and outpatient services...",
      days: ["Mon", "Tue"],
      morning: "06:00",
      morningEnd: "11:59",
      afternoon: "12:00",
      afternoonEnd: "17:00",
      slots: 20,
      active: true
    },
    {
      name: "Neurology",
      desc: "Expert diagnosis and treatment of complex neurological disorders, strokes, and diseases...",
      days: ["Mon", "Thu"],
      morning: "06:00",
      morningEnd: "11:59",
      afternoon: "12:00",
      afternoonEnd: "17:00",
      slots: 20,
      active: true
    },
    {
      name: "Pediatrics",
      desc: "General pediatric care, immunizations, developmental screenings...",
      days: ["Tue", "Thu", "Sat"],
      morning: "08:00",
      morningEnd: "13:00",
      afternoon: "14:00",
      afternoonEnd: "18:00",
      slots: 30,
      active: true
    }
  ];

  // ================= DOM REFS =================
  const tbody = document.getElementById('department-rows');
  const modal = document.getElementById('dept-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const editIndex = document.getElementById('edit-index');
  const deptName = document.getElementById('dept-name');
  const deptDesc = document.getElementById('dept-desc');
  const morningStart = document.getElementById('morning-start');
  const morningEnd = document.getElementById('morning-end');
  const afternoonStart = document.getElementById('afternoon-start');
  const afternoonEnd = document.getElementById('afternoon-end');
  const deptSlots = document.getElementById('dept-slots');
  const deptStatus = document.getElementById('dept-status');
  const dayCheckboxes = document.querySelectorAll('#days-grid input[type="checkbox"]');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-department-btn');

  // ================= HELPERS =================
  function getSelectedDays() {
    return Array.from(dayCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
  }

  function setSelectedDays(daysArray) {
    dayCheckboxes.forEach(cb => cb.checked = daysArray.includes(cb.value));
  }

  function resetForm() {
    deptName.value = '';
    deptDesc.value = '';
    morningStart.value = '06:00';
    morningEnd.value = '11:59';
    afternoonStart.value = '12:00';
    afternoonEnd.value = '17:00';
    deptSlots.value = 20;
    deptStatus.value = 'active';
    setSelectedDays([]);
    editIndex.value = '-1';
    modalTitle.textContent = 'Add Department';
    modalSub.textContent = 'Fill in the department details';
  }

  function openModal(deptData, index) {
    if (deptData) {
      deptName.value = deptData.name || '';
      deptDesc.value = deptData.desc || '';
      morningStart.value = deptData.morning || '06:00';
      morningEnd.value = deptData.morningEnd || '11:59';
      afternoonStart.value = deptData.afternoon || '12:00';
      afternoonEnd.value = deptData.afternoonEnd || '17:00';
      deptSlots.value = deptData.slots || 20;
      deptStatus.value = deptData.active ? 'active' : 'inactive';
      setSelectedDays(deptData.days || []);
      editIndex.value = index;
      modalTitle.textContent = `Edit ${deptData.name}`;
      modalSub.textContent = 'Update schedule and appointment rules';
    } else {
      resetForm();
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  // ================= RENDER TABLE =================
  function renderRows() {
    if (!departments.length) {
      tbody.innerHTML = `<tr class="empty-row"><td colspan="7">No departments found. Click "Add Department" to create one.</td></tr>`;
      return;
    }
    let html = '';
    departments.forEach((d, i) => {
      const statusClass = d.active ? 'active' : 'inactive';
      const statusText = d.active ? 'Active' : 'Inactive';
      const dayPills = d.days.map(day => `<span class="day-pill">${day}</span>`).join('');
      const morningStr = `${d.morning} – ${d.morningEnd}`;
      const afternoonStr = `${d.afternoon} – ${d.afternoonEnd}`;
      html += `
        <tr>
          <td>
            <div class="dept-name">${d.name}</div>
            <div class="dept-desc">${d.desc || ''}</div>
          </td>
          <td><div class="day-pills">${dayPills}</div></td>
          <td><span class="time-range">${morningStr}</span></td>
          <td><span class="time-range">${afternoonStr}</span></td>
          <td><span class="slots-value">${d.slots}</span> <span class="slots-unit">total</span></td>
          <td><span class="status-pill ${statusClass}">${statusText}</span></td>
          <td class="actions-cell">
            <button class="action-icon-btn" data-index="${i}" aria-label="Edit ${d.name}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
            </button>
          </td>
        </tr>
      `;
    });
    tbody.innerHTML = html;

    document.querySelectorAll('.action-icon-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const dept = departments[idx];
        if (dept) openModal(dept, idx);
      });
    });
  }

  // ================= SAVE DEPARTMENT =================
  function saveDepartment() {
    const name = deptName.value.trim();
    if (!name) { alert('Please enter a department name.'); return; }
    const days = getSelectedDays();
    if (!days.length) { alert('Please select at least one operating day.'); return; }

    const newDept = {
      name: name,
      desc: deptDesc.value.trim(),
      days: days,
      morning: morningStart.value,
      morningEnd: morningEnd.value,
      afternoon: afternoonStart.value,
      afternoonEnd: afternoonEnd.value,
      slots: parseInt(deptSlots.value, 10) || 20,
      active: deptStatus.value === 'active'
    };

    const idx = parseInt(editIndex.value, 10);
    if (idx >= 0 && idx < departments.length) {
      departments[idx] = newDept;
    } else {
      departments.push(newDept);
    }
    renderRows();
    closeModal();
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  saveBtn.addEventListener('click', saveDepartment);
  document.getElementById('dept-form').addEventListener('submit', (e) => {
    e.preventDefault();
    saveDepartment();
  });

  // ================= INIT =================
  renderRows();

  // Set today's date
  const today = new Date();
  const dateStr = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  document.getElementById('today-date').textContent = dateStr;
</script>
</body>
</html>