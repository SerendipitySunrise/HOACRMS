<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Management — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_patient_management.css">
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
      <li class="nav-item active">
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
        <h1>Patient Management</h1>
        <p id="patient-count">10 registered patients</p>
      </div>
      <button class="notif-bell" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <span class="notif-badge">2</span>
      </button>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <div class="panel-head-title" style="font-size:1.15rem;">Patients</div>
          <div class="panel-head-meta" style="margin-top:4px;">Manage patient records and history</div>
        </div>
        <div class="staff-header-actions">
          <button class="btn-checkin-solid" id="add-patient-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add New Patient
          </button>
        </div>
      </div>

      <!-- Search Bar -->
      <div class="search-bar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" id="search-input" placeholder="Search by name or phone..." />
      </div>

      <!-- Patient Table -->
      <div class="table-wrap">
        <table class="patient-table">
          <thead>
            <tr>
              <th>NAME</th>
              <th>AGE</th>
              <th>GENDER</th>
              <th>PHONE</th>
              <th>BLOOD TYPE</th>
              <th>VISITS</th>
              <th>ACTIONS</th>
            </tr>
          </thead>
          <tbody id="patient-rows">
            <!-- Rendered by JavaScript -->
          </tbody>
        </table>
      </div>
    </div>

  </main>
</div>

<!-- ================= ADD / EDIT PATIENT MODAL ================= -->
<div class="modal-overlay hidden" id="patient-modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="modal-title" id="modal-title">Add New Patient</div>
        <div class="modal-sub" id="modal-sub">Fill in the patient's details</div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close">&times;</button>
    </div>

    <form id="patient-form" onsubmit="return false;">
      <input type="hidden" id="edit-index" value="-1" />

      <div class="form-group">
        <label for="patient-name">Full Name</label>
        <input type="text" id="patient-name" placeholder="e.g. Robert Chen" required />
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="patient-age">Age</label>
          <input type="number" id="patient-age" placeholder="45" min="0" />
        </div>
        <div class="form-group">
          <label for="patient-gender">Gender</label>
          <select id="patient-gender">
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label for="patient-phone">Phone Number</label>
        <input type="text" id="patient-phone" placeholder="+1-555-0101" />
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="patient-blood">Blood Type</label>
          <select id="patient-blood">
            <option value="A+">A+</option>
            <option value="A-">A-</option>
            <option value="B+">B+</option>
            <option value="B-">B-</option>
            <option value="AB+">AB+</option>
            <option value="AB-">AB-</option>
            <option value="O+">O+</option>
            <option value="O-">O-</option>
          </select>
        </div>
        <div class="form-group">
          <label for="patient-allergies">Allergies <span class="hint">(or 'None')</span></label>
          <input type="text" id="patient-allergies" placeholder="Penicillin, Sulfa drugs..." />
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" id="modal-cancel-btn">Cancel</button>
        <button type="submit" class="btn-primary-solid" id="modal-save-btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
          Add Patient
        </button>
      </div>
    </form>
  </div>
</div>

<script>
  // ================= PATIENT DATA =================
  let patients = [
    { name: "Robert Chen", age: 45, gender: "Male", phone: "+1-555-0101", blood: "A+", allergies: "Penicillin", visits: 4 },
    { name: "Maria Garcia", age: 32, gender: "Female", phone: "+1-555-0102", blood: "O-", allergies: "None", visits: 4 },
    { name: "David Kim", age: 67, gender: "Male", phone: "+1-555-0103", blood: "B+", allergies: "Sulfa drugs, Shellfish", visits: 0 },
    { name: "Jennifer Adams", age: 28, gender: "Female", phone: "+1-555-0104", blood: "AB+", allergies: "Latex", visits: 1 },
    { name: "Michael Brown", age: 52, gender: "Male", phone: "+1-555-0105", blood: "O+", allergies: "Aspirin", visits: 0 },
    { name: "Lisa Wang", age: 39, gender: "Female", phone: "+1-555-0106", blood: "A-", allergies: "None", visits: 0 },
    { name: "Thomas Anderson", age: 55, gender: "Male", phone: "+1-555-0107", blood: "B-", allergies: "Codeine", visits: 2 },
    { name: "Amanda Foster", age: 24, gender: "Female", phone: "+1-555-0108", blood: "O+", allergies: "None", visits: 2 },
    { name: "Carlos Rivera", age: 61, gender: "Male", phone: "+1-555-0109", blood: "A+", allergies: "Ibuprofen", visits: 1 },
    { name: "Priya Patel", age: 47, gender: "Female", phone: "+1-555-0110", blood: "B+", allergies: "None", visits: 1 }
  ];

  // ================= DOM REFS =================
  const tbody = document.getElementById('patient-rows');
  const patientCount = document.getElementById('patient-count');
  const searchInput = document.getElementById('search-input');
  const modal = document.getElementById('patient-modal');
  const modalTitle = document.getElementById('modal-title');
  const modalSub = document.getElementById('modal-sub');
  const editIndex = document.getElementById('edit-index');
  const patientName = document.getElementById('patient-name');
  const patientAge = document.getElementById('patient-age');
  const patientGender = document.getElementById('patient-gender');
  const patientPhone = document.getElementById('patient-phone');
  const patientBlood = document.getElementById('patient-blood');
  const patientAllergies = document.getElementById('patient-allergies');
  const closeBtn = document.getElementById('modal-close-btn');
  const cancelBtn = document.getElementById('modal-cancel-btn');
  const saveBtn = document.getElementById('modal-save-btn');
  const addBtn = document.getElementById('add-patient-btn');

  // ================= HELPERS =================
  function resetForm() {
    patientName.value = '';
    patientAge.value = '';
    patientGender.value = 'Male';
    patientPhone.value = '';
    patientBlood.value = 'A+';
    patientAllergies.value = '';
    editIndex.value = '-1';
    modalTitle.textContent = 'Add New Patient';
    modalSub.textContent = 'Fill in the patient\'s details';
    saveBtn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
      Add Patient
    `;
  }

  function openModal(patientData, index) {
    if (patientData) {
      patientName.value = patientData.name || '';
      patientAge.value = patientData.age || '';
      patientGender.value = patientData.gender || 'Male';
      patientPhone.value = patientData.phone || '';
      patientBlood.value = patientData.blood || 'A+';
      patientAllergies.value = patientData.allergies || '';
      editIndex.value = index;
      modalTitle.textContent = `Edit ${patientData.name}`;
      modalSub.textContent = 'Update patient information';
      saveBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Update Patient
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
  function renderPatients(filter = '') {
    const searchTerm = filter.toLowerCase().trim();
    let filtered = patients;
    
    if (searchTerm) {
      filtered = patients.filter(p => 
        p.name.toLowerCase().includes(searchTerm) || 
        p.phone.includes(searchTerm)
      );
    }

    patientCount.textContent = `${filtered.length} registered patients`;

    if (!filtered.length) {
      tbody.innerHTML = `<tr><td colspan="7" class="empty-row">No patients found.</td></tr>`;
      return;
    }

    let html = '';
    filtered.forEach((p, i) => {
      const originalIndex = patients.indexOf(p);
      const allergyDisplay = p.allergies && p.allergies !== 'None' 
        ? `<div class="patient-allergy">Allergies: ${p.allergies}</div>` 
        : '';

      html += `
        <tr>
          <td>
            <div class="patient-name">${p.name}</div>
            ${allergyDisplay}
          </td>
          <td>${p.age}</td>
          <td>${p.gender}</td>
          <td>${p.phone}</td>
          <td><span class="blood-type">${p.blood}</span></td>
          <td>${p.visits}</td>
          <td>
            <button class="action-btn edit-btn" data-index="${originalIndex}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
            </button>
            <button class="action-btn delete-btn" data-index="${originalIndex}">
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
        const patient = patients[idx];
        if (patient) openModal(patient, idx);
      });
    });

    // Attach delete events
    document.querySelectorAll('.delete-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = +btn.dataset.index;
        if (confirm(`Are you sure you want to remove ${patients[idx].name}?`)) {
          patients.splice(idx, 1);
          renderPatients(searchInput.value);
        }
      });
    });
  }

  // ================= SAVE PATIENT =================
  function savePatient() {
    const name = patientName.value.trim();
    if (!name) { alert('Please enter the patient\'s full name.'); return; }

    const newPatient = {
      name: name,
      age: parseInt(patientAge.value, 10) || 0,
      gender: patientGender.value,
      phone: patientPhone.value.trim() || 'N/A',
      blood: patientBlood.value,
      allergies: patientAllergies.value.trim() || 'None',
      visits: 0
    };

    const idx = parseInt(editIndex.value, 10);
    if (idx >= 0 && idx < patients.length) {
      newPatient.visits = patients[idx].visits;
      patients[idx] = newPatient;
    } else {
      patients.push(newPatient);
    }
    renderPatients(searchInput.value);
    closeModal();
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  saveBtn.addEventListener('click', savePatient);
  document.getElementById('patient-form').addEventListener('submit', (e) => {
    e.preventDefault();
    savePatient();
  });

  searchInput.addEventListener('input', (e) => {
    renderPatients(e.target.value);
  });

  // ================= INIT =================
  renderPatients();
</script>
</body>
</html>