<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Management — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_staff_management.css">
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
      <li class="nav-item active">
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
        <h1>Staff Management</h1>
        <p id="staff-count">1 staff members</p>
      </div>
      <button class="notif-bell" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span class="notif-badge">2</span>
      </button>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-head-title" style="font-size:1.15rem;">Staff</div>
          <div class="panel-head-meta" style="margin-top:4px;">Manage staff accounts and assignments</div>
        </div>
        <div class="staff-header-actions">
          <button class="btn-checkin-solid" id="add-staff-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Staff
          </button>
        </div>
      </div>

      <!-- Staff Table -->
      <div class="table-wrap">
        <table class="staff-table">
          <thead>
            <tr>
              <th>Staff Member</th>
              <th>Role</th>
              <th>Department</th>
              <th>Schedule</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="staff-rows">
            <!-- Rendered by JavaScript -->
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<!-- ================= ADD / EDIT STAFF MODAL ================= -->
<div class="modal-overlay hidden" id="staff-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">Add New Staff</div>
        <div class="modal-sub" id="modal-sub">Fill in the staff member's details</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <form id="staff-form" onsubmit="return false;">
      <input type="hidden" id="edit-index" value="-1" />

      <div class="form-group">
        <label for="staff-name">Full Name <span class="hint">(e.g. Nurse Jennifer Jones)</span></label>
        <input type="text" id="staff-name" placeholder="Nurse Jennifer Jones" required />
      </div>

      <div class="form-group">
        <label for="staff-email">Email</label>
        <input type="email" id="staff-email" placeholder="staff@hospital.com" />
      </div>

      <div class="form-group">
        <label for="staff-phone">Phone Number</label>
        <input type="text" id="staff-phone" placeholder="(555) 123-4567" />
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="staff-role">Staff Role</label>
          <select id="staff-role">
            <option value="Nurse">Nurse</option>
            <option value="Receptionist">Receptionist</option>
            <option value="Technician">Technician</option>
            <option value="Administrator">Administrator</option>
            <option value="Pharmacist">Pharmacist</option>
            <option value="Therapist">Therapist</option>
            <option value="Lab Assistant">Lab Assistant</option>
            <option value="Janitorial">Janitorial</option>
          </select>
        </div>
        <div class="form-group">
          <label for="staff-department">Assigned Department</label>
          <select id="staff-department">
            <option value="Internal Medicine">Internal Medicine</option>
            <option value="Cardiology">Cardiology</option>
            <option value="Neurology">Neurology</option>
            <option value="Orthopedics">Orthopedics</option>
            <option value="Pediatrics">Pediatrics</option>
            <option value="Obstetrics & Gynecology">Obstetrics & Gynecology</option>
            <option value="Emergency">Emergency</option>
            <option value="Radiology">Radiology</option>
            <option value="Pharmacy">Pharmacy</option>
            <option value="Administration">Administration</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label>Availability Status</label>
        <div class="status-options">
          <label class="status-option">
            <input type="radio" name="staff-status" value="Available" checked />
            Available
          </label>
          <label class="status-option">
            <input type="radio" name="staff-status" value="Off Duty" />
            Off Duty
          </label>
          <label class="status-option">
            <input type="radio" name="staff-status" value="On Leave" />
            On Leave
          </label>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="staff-start-time">Duty Start Time</label>
          <input type="time" id="staff-start-time" value="08:00" />
        </div>
        <div class="form-group">
          <label for="staff-end-time">Duty End Time</label>
          <input type="time" id="staff-end-time" value="17:00" />
        </div>
      </div>

      <div class="form-group">
        <label class="toggle-active">
          <input type="checkbox" id="staff-active" checked />
          <span>Staff member is active</span>
        </label>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Create Staff
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= STAFF DATA =================
  let staffMembers = [
    {
      name: "Nurse Jennifer Jones",
      email: "jennifer.jones@hospital.com",
      phone: "(555) 123-4567",
      role: "Nurse",
      department: "Internal Medicine",
      status: "Available",
      startTime: "08:00",
      endTime: "17:00",
      active: true
    }
  ];

  // ================= DOM REFS =================
  const tbody = document.getElementById('staff-rows');
  const staffCount = document.getElementById('staff-count');
  const modal = document.getElementById('staff-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const editIndex = document.getElementById('edit-index');
  const staffName = document.getElementById('staff-name');
  const staffEmail = document.getElementById('staff-email');
  const staffPhone = document.getElementById('staff-phone');
  const staffRole = document.getElementById('staff-role');
  const staffDepartment = document.getElementById('staff-department');
  const staffStatusRadios = document.querySelectorAll('input[name="staff-status"]');
  const staffStartTime = document.getElementById('staff-start-time');
  const staffEndTime = document.getElementById('staff-end-time');
  const staffActive = document.getElementById('staff-active');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-staff-btn');

  // ================= HELPERS =================
  function getSelectedStatus() {
    for (const radio of staffStatusRadios) {
      if (radio.checked) return radio.value;
    }
    return 'Available';
  }

  function setSelectedStatus(status) {
    for (const radio of staffStatusRadios) {
      radio.checked = radio.value === status;
    }
  }

  function resetForm() {
    staffName.value = '';
    staffEmail.value = '';
    staffPhone.value = '';
    staffRole.value = 'Nurse';
    staffDepartment.value = 'Internal Medicine';
    setSelectedStatus('Available');
    staffStartTime.value = '08:00';
    staffEndTime.value = '17:00';
    staffActive.checked = true;
    editIndex.value = '-1';
    modalTitle.textContent = 'Add New Staff';
    modalSub.textContent = 'Fill in the staff member\'s details';
    saveBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Create Staff
    `;
  }

  function openModal(staffData, index) {
    if (staffData) {
      staffName.value = staffData.name || '';
      staffEmail.value = staffData.email || '';
      staffPhone.value = staffData.phone || '';
      staffRole.value = staffData.role || 'Nurse';
      staffDepartment.value = staffData.department || 'Internal Medicine';
      setSelectedStatus(staffData.status || 'Available');
      staffStartTime.value = staffData.startTime || '08:00';
      staffEndTime.value = staffData.endTime || '17:00';
      staffActive.checked = staffData.active !== undefined ? staffData.active : true;
      editIndex.value = index;
      modalTitle.textContent = `Edit ${staffData.name}`;
      modalSub.textContent = 'Update staff information';
      saveBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Update Staff
      `;
    } else {
      resetForm();
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  // ================= RENDER TABLE =================
  function renderStaff() {
    const activeStaff = staffMembers.filter(s => s.active);
    staffCount.textContent = `${activeStaff.length} staff members`;

    if (!staffMembers.length) {
      tbody.innerHTML = `<tr><td colspan="6" class="empty-row">No staff members found. Click "Add Staff" to create one.</td></tr>`;
      return;
    }

    let html = '';
    staffMembers.forEach((s, i) => {
      const statusClass = s.status.toLowerCase().replace(' ', '-');
      const schedule = `${s.startTime} - ${s.endTime}`;
      const activeBadge = s.active ? 'Active' : 'Inactive';
      const activeClass = s.active ? 'badge-active' : 'badge-inactive';

      html += `
        <tr>
          <td>
            <div class="staff-name">${s.name}</div>
            <div class="staff-email">${s.email || ''}</div>
          </td>
          <td><span class="role-badge">${s.role}</span></td>
          <td>${s.department}</td>
          <td><span class="schedule-time">${schedule}</span></td>
          <td>
            <span class="status-badge ${statusClass}">${s.status}</span>
            <span class="active-badge ${activeClass}">${activeBadge}</span>
          </td>
          <td>
            <button class="action-btn edit-btn" data-index="${i}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
            </button>
            <button class="action-btn toggle-btn" data-index="${i}">
              ${s.active ? 'Deactivate' : 'Activate'}
            </button>
            <button class="action-btn delete-btn" data-index="${i}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </button>
          </td>
        </tr>
      `;
    });
    tbody.innerHTML = html;

    // Attach edit events
    document.querySelectorAll('.edit-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const staff = staffMembers[idx];
        if (staff) openModal(staff, idx);
      });
    });

    // Attach toggle active events
    document.querySelectorAll('.toggle-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        staffMembers[idx].active = !staffMembers[idx].active;
        renderStaff();
      });
    });

    // Attach delete events
    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        if (confirm(`Are you sure you want to remove ${staffMembers[idx].name}?`)) {
          staffMembers.splice(idx, 1);
          renderStaff();
        }
      });
    });
  }

  // ================= SAVE STAFF =================
  function saveStaff() {
    const name = staffName.value.trim();
    if (!name) { alert('Please enter the staff member\'s full name.'); return; }

    const newStaff = {
      name: name,
      email: staffEmail.value.trim(),
      phone: staffPhone.value.trim(),
      role: staffRole.value,
      department: staffDepartment.value,
      status: getSelectedStatus(),
      startTime: staffStartTime.value || '08:00',
      endTime: staffEndTime.value || '17:00',
      active: staffActive.checked
    };

    const idx = parseInt(editIndex.value, 10);
    if (idx >= 0 && idx < staffMembers.length) {
      staffMembers[idx] = newStaff;
    } else {
      staffMembers.push(newStaff);
    }
    renderStaff();
    closeModal();
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  saveBtn.addEventListener('click', saveStaff);
  document.getElementById('staff-form').addEventListener('submit', (e) => {
    e.preventDefault();
    saveStaff();
  });

  // ================= INIT =================
  renderStaff();

  // Set today's date
  const today = new Date();
  const dateStr = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  document.querySelector('.page-header p').textContent = dateStr;
</script>
</body>
</html>