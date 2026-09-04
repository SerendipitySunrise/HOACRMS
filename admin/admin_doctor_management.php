<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Doctor Management — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_doctor_management.css">
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
      <li class="nav-item active">
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
        <h1>Doctor Management</h1>
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
          <div class="panel-head-title" style="font-size:1.15rem;">Doctors</div>
          <div class="panel-head-meta" style="margin-top:4px;">Manage doctor accounts and assignments</div>
        </div>
        <div class="staff-header-actions">
          <button class="btn-checkin-solid" id="add-doctor-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add New Doctor
          </button>
        </div>
      </div>

      <!-- Doctor Cards Grid -->
      <div class="doctor-grid" id="doctor-grid">
        <!-- Rendered by JavaScript -->
      </div>
    </div>

  </main>
</div>

<!-- ================= ADD / EDIT DOCTOR MODAL ================= -->
<div class="modal-overlay hidden" id="doctor-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">Add New Doctor</div>
        <div class="modal-sub" id="modal-sub">Fill in the doctor's details</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <form id="doctor-form" onsubmit="return false;">
      <input type="hidden" id="edit-index" value="-1" />

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-name">Full Name <span class="hint">(e.g. Dr. Sarah Mitchell)</span></label>
          <input type="text" id="doctor-name" placeholder="Dr. Sarah Mitchell" required />
        </div>
        <div class="form-group">
          <label for="doctor-phone">Phone Number</label>
          <input type="text" id="doctor-phone" placeholder="(555) 123-4567" />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-license">License Number</label>
          <input type="text" id="doctor-license" placeholder="MD-XXXX-001" />
        </div>
        <div class="form-group">
          <label for="doctor-specialization">Specialization</label>
          <input type="text" id="doctor-specialization" placeholder="e.g. Cardiologist" />
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-experience">Years of Experience</label>
          <input type="number" id="doctor-experience" value="0" min="0" />
        </div>
        <div class="form-group">
          <label for="doctor-status">Availability Status</label>
          <select id="doctor-status">
            <option value="Available">Available</option>
            <option value="Off Duty">Off Duty</option>
            <option value="On Leave">On Leave</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="doctor-start-time">Duty Start Time</label>
          <input type="time" id="doctor-start-time" value="08:00" />
        </div>
        <div class="form-group">
          <label for="doctor-end-time">Duty End Time</label>
          <input type="time" id="doctor-end-time" value="17:00" />
        </div>
      </div>

      <div class="form-group">
        <label for="doctor-bio">Bio / Description</label>
        <textarea id="doctor-bio" placeholder="Brief professional bio..."></textarea>
      </div>

      <div class="form-group">
        <label for="doctor-image">Profile Image URL</label>
        <input type="text" id="doctor-image" placeholder="https://..." />
      </div>

      <div class="form-group">
        <label class="day-check" style="display:flex; align-items:center; gap:10px; cursor:pointer;">
          <input type="checkbox" id="doctor-active" checked />
          <span style="font-weight:600;">Doctor is active</span>
        </label>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Create Doctor
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= DOCTOR DATA =================
  let doctors = [
    {
      id: 1,
      name: "Dr. Sarah Mitchell",
      specialization: "Cardiology",
      license: "MD-CARD-001",
      status: "Available",
      experience: 18,
      rating: 4.9,
      patients: 2400,
      startTime: "08:00",
      endTime: "17:00",
      bio: "Board-certified cardiologist with expertise in interventional cardiology and heart failure management.",
      image: "",
      active: true
    },
    {
      id: 2,
      name: "Dr. James Wilson",
      specialization: "Neurology",
      license: "MD-NEURH-001",
      status: "Available",
      experience: 22,
      rating: 4.8,
      patients: 3100,
      startTime: "08:00",
      endTime: "17:00",
      bio: "Leading neurologist specializing in stroke care, epilepsy, and neurodegenerative disorders.",
      image: "",
      active: true
    },
    {
      id: 3,
      name: "Dr. Emily Chen",
      specialization: "Orthopedic Surgery",
      license: "MD-ORTH-001",
      status: "Available",
      experience: 15,
      rating: 4.9,
      patients: 1800,
      startTime: "08:00",
      endTime: "17:00",
      bio: "Expert in minimally invasive joint replacement and sports medicine procedures.",
      image: "",
      active: true
    },
    {
      id: 4,
      name: "Dr. Robert Kim",
      specialization: "Cardiology",
      license: "MD-CARD-002",
      status: "Available",
      experience: 12,
      rating: 4.7,
      patients: 1500,
      startTime: "08:00",
      endTime: "17:00",
      bio: "Specializing in preventive cardiology and cardiac rehabilitation.",
      image: "",
      active: true
    },
    {
      id: 5,
      name: "Dr. Lisa Martinez",
      specialization: "Obstetrics & Gynecology",
      license: "MD-080Y-001",
      status: "Available",
      experience: 17,
      rating: 4.9,
      patients: 2200,
      startTime: "08:00",
      endTime: "17:00",
      bio: "Comprehensive women's health specialist with focus on high-risk pregnancies.",
      image: "",
      active: true
    },
    {
      id: 6,
      name: "Dr. Michael Chen",
      specialization: "Internal Medicine",
      license: "MD-INTM-001",
      status: "Available",
      experience: 20,
      rating: 4.8,
      patients: 4500,
      startTime: "08:00",
      endTime: "17:00",
      bio: "Experienced internist with focus on chronic disease management and preventive care.",
      image: "",
      active: true
    }
  ];

  let nextId = 7;

  // ================= DOM REFS =================
  const grid = document.getElementById('doctor-grid');
  const modal = document.getElementById('doctor-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const editIndex = document.getElementById('edit-index');
  const doctorName = document.getElementById('doctor-name');
  const doctorPhone = document.getElementById('doctor-phone');
  const doctorLicense = document.getElementById('doctor-license');
  const doctorSpecialization = document.getElementById('doctor-specialization');
  const doctorExperience = document.getElementById('doctor-experience');
  const doctorStatus = document.getElementById('doctor-status');
  const doctorStartTime = document.getElementById('doctor-start-time');
  const doctorEndTime = document.getElementById('doctor-end-time');
  const doctorBio = document.getElementById('doctor-bio');
  const doctorImage = document.getElementById('doctor-image');
  const doctorActive = document.getElementById('doctor-active');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-doctor-btn');

  // ================= HELPERS =================
  function getInitials(name) {
    return name.split(' ').map(word => word[0]).join('').substring(0, 2).toUpperCase();
  }

  function getStatusClass(status) {
    const map = {
      'Available': 'status-available',
      'Off Duty': 'status-offduty',
      'On Leave': 'status-onleave'
    };
    return map[status] || 'status-available';
  }

  function resetForm() {
    doctorName.value = '';
    doctorPhone.value = '';
    doctorLicense.value = '';
    doctorSpecialization.value = '';
    doctorExperience.value = 0;
    doctorStatus.value = 'Available';
    doctorStartTime.value = '08:00';
    doctorEndTime.value = '17:00';
    doctorBio.value = '';
    doctorImage.value = '';
    doctorActive.checked = true;
    editIndex.value = '-1';
    modalTitle.textContent = 'Add New Doctor';
    modalSub.textContent = 'Fill in the doctor\'s details';
    saveBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Create Doctor
    `;
  }

  function openModal(doctorData, index) {
    if (doctorData) {
      doctorName.value = doctorData.name || '';
      doctorPhone.value = doctorData.phone || '';
      doctorLicense.value = doctorData.license || '';
      doctorSpecialization.value = doctorData.specialization || '';
      doctorExperience.value = doctorData.experience || 0;
      doctorStatus.value = doctorData.status || 'Available';
      doctorStartTime.value = doctorData.startTime || '08:00';
      doctorEndTime.value = doctorData.endTime || '17:00';
      doctorBio.value = doctorData.bio || '';
      doctorImage.value = doctorData.image || '';
      doctorActive.checked = doctorData.active !== undefined ? doctorData.active : true;
      editIndex.value = index;
      modalTitle.textContent = `Edit ${doctorData.name}`;
      modalSub.textContent = 'Update doctor information';
      saveBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Update Doctor
      `;
    } else {
      resetForm();
    }
    modal.classList.remove('hidden');
  }

  function closeModal() {
    modal.classList.add('hidden');
  }

  // ================= RENDER DOCTOR CARDS =================
  function renderDoctors() {
    if (!doctors.length) {
      grid.innerHTML = `<div class="empty-state">No doctors found. Click "Add New Doctor" to create one.</div>`;
      return;
    }

    let html = '';
    doctors.forEach((d, i) => {
      const statusClass = getStatusClass(d.status);
      const initials = getInitials(d.name);
      const ratingStars = '★'.repeat(Math.floor(d.rating)) + '☆'.repeat(5 - Math.floor(d.rating));
      const activeBadge = d.active ? 'Active' : 'Inactive';
      const activeClass = d.active ? 'badge-active' : 'badge-inactive';

      html += `
        <div class="doctor-card">
          <div class="doctor-card-header">
            <div class="doctor-avatar">${initials}</div>
            <div class="doctor-card-title">
              <div class="doctor-name">${d.name}</div>
              <div class="doctor-specialty">${d.specialization}</div>
            </div>
            <div class="doctor-license-badge">${d.license}</div>
          </div>
          <div class="doctor-status-row">
            <span class="doctor-status ${statusClass}">${d.status}</span>
            <span class="doctor-active-badge ${activeClass}">${activeBadge}</span>
          </div>
          <div class="doctor-stats">
            <div class="stat-item">
              <div class="stat-number">${d.experience}yr</div>
              <div class="stat-label">Experience</div>
            </div>
            <div class="stat-item">
              <div class="stat-number">${d.rating}</div>
              <div class="stat-label">${ratingStars}</div>
            </div>
            <div class="stat-item">
              <div class="stat-number">${d.patients.toLocaleString()}+</div>
              <div class="stat-label">Patients</div>
            </div>
          </div>
          <div class="doctor-duty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            ${d.startTime} - ${d.endTime}
          </div>
          <div class="doctor-actions">
            <button class="btn-edit" data-index="${i}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
              Edit
            </button>
            <button class="btn-toggle" data-index="${i}">
              ${d.active ? 'Deactivate' : 'Activate'}
            </button>
          </div>
        </div>
      `;
    });
    grid.innerHTML = html;

    // Attach edit events
    document.querySelectorAll('.btn-edit').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        const doctor = doctors[idx];
        if (doctor) openModal(doctor, idx);
      });
    });

    // Attach toggle active events
    document.querySelectorAll('.btn-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        doctors[idx].active = !doctors[idx].active;
        renderDoctors();
      });
    });
  }

  // ================= SAVE DOCTOR =================
  function saveDoctor() {
    const name = doctorName.value.trim();
    if (!name) { alert('Please enter the doctor\'s full name.'); return; }

    const newDoctor = {
      name: name,
      phone: doctorPhone.value.trim(),
      license: doctorLicense.value.trim() || `MD-${Math.random().toString(36).substring(2, 6).toUpperCase()}-${String(Math.floor(Math.random() * 1000)).padStart(3, '0')}`,
      specialization: doctorSpecialization.value.trim() || 'General Practice',
      experience: parseInt(doctorExperience.value, 10) || 0,
      status: doctorStatus.value,
      startTime: doctorStartTime.value || '08:00',
      endTime: doctorEndTime.value || '17:00',
      bio: doctorBio.value.trim(),
      image: doctorImage.value.trim(),
      active: doctorActive.checked,
      rating: (4.0 + Math.random() * 0.9).toFixed(1),
      patients: Math.floor(500 + Math.random() * 4000)
    };

    const idx = parseInt(editIndex.value, 10);
    if (idx >= 0 && idx < doctors.length) {
      // Preserve id and rating/patients if editing
      newDoctor.id = doctors[idx].id;
      newDoctor.rating = doctors[idx].rating;
      newDoctor.patients = doctors[idx].patients;
      doctors[idx] = newDoctor;
    } else {
      newDoctor.id = nextId++;
      newDoctor.rating = (4.0 + Math.random() * 0.9).toFixed(1);
      newDoctor.patients = Math.floor(500 + Math.random() * 4000);
      doctors.push(newDoctor);
    }
    renderDoctors();
    closeModal();
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  saveBtn.addEventListener('click', saveDoctor);
  document.getElementById('doctor-form').addEventListener('submit', (e) => {
    e.preventDefault();
    saveDoctor();
  });

  // ================= INIT =================
  renderDoctors();

  // Set today's date
  const today = new Date();
  const dateStr = today.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  document.getElementById('today-date').textContent = dateStr;
</script>
</body>
</html>