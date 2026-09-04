<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports & Analytics — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_reports.css">
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
      <li class="nav-item active">
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

    <div class="page-header">
      <h1>Reports & Analytics</h1>
      <p>Hospital performance metrics and insights</p>
    </div>

    <!-- ================= KPI CARDS ================= -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-number">10</div>
        <div class="kpi-label">Total Patients</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-number">0</div>
        <div class="kpi-label">Completed Today</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-number">0m</div>
        <div class="kpi-label">Avg Consult Time</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-number">6</div>
        <div class="kpi-label">Active Staff</div>
      </div>
    </div>

    <!-- ================= TWO COLUMN LAYOUT ================= -->
    <div class="two-column">
      
      <!-- LEFT COLUMN -->
      <div class="left-column">
        
        <!-- Doctor Workload -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-1a6 6 0 0 1 6-6h1a6 6 0 0 1 6 6v1"/><circle cx="9.5" cy="7" r="4"/><path d="M19 8v4M21 10h-4"/></svg>
              Doctor Workload Today
            </div>
            <div class="panel-sub">Consultations per doctor vs daily limit</div>
          </div>
          <div class="doctor-list" id="doctor-list">
            <!-- Rendered by JavaScript -->
          </div>
        </div>

      </div>

      <!-- RIGHT COLUMN -->
      <div class="right-column">
        
        <!-- Department Performance -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 21v-6h6v6"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01"/></svg>
              Department Performance
            </div>
            <div class="panel-sub">Patient volume and average wait times</div>
          </div>
          <div class="department-list" id="department-list">
            <!-- Rendered by JavaScript -->
          </div>
        </div>

      </div>
    </div>

  </main>
</div>

<script>
  // ================= DATA =================
  const doctors = [
    { name: "Dr. Sarah Mitchell", done: 0, active: 1, limit: 20 },
    { name: "Dr. James Wilson", done: 0, active: 0, limit: 20 },
    { name: "Dr. Michael Lee", done: 0, active: 0, limit: 20 }
  ];

  const departments = [
    { code: "GEN", name: "General Medicine", morning: 10, afternoon: 8, avgWait: 14, total: 18, capacity: 20, percentage: 90 },
    { code: "IM", name: "Internal Medicine", morning: 8, afternoon: 6, avgWait: 18, total: 14, capacity: 20, percentage: 70 },
    { code: "SURG", name: "Surgery", morning: 5, afternoon: 0, avgWait: 22, total: 5, capacity: 20, percentage: 25 },
    { code: "PEDIA", name: "Pediatrics", morning: 12, afternoon: 10, avgWait: 12, total: 22, capacity: 20, percentage: 110 }
  ];

  // ================= RENDER DOCTORS =================
  function renderDoctors() {
    const container = document.getElementById('doctor-list');
    let html = '';
    doctors.forEach(d => {
      const progress = Math.min((d.done / d.limit) * 100, 100);
      const color = progress > 80 ? '#dc2626' : progress > 50 ? '#d97706' : '#059669';
      html += `
        <div class="doctor-item">
          <div class="doctor-info">
            <span class="doctor-name">${d.name}</span>
            <span class="doctor-stats">${d.done} done, ${d.active} active</span>
          </div>
          <div class="progress-bar">
            <div class="progress-fill" style="width: ${progress}%; background: ${color}"></div>
          </div>
          <div class="progress-label">${d.done}/${d.limit}</div>
        </div>
      `;
    });
    container.innerHTML = html;
  }

  // ================= RENDER DEPARTMENTS =================
  function renderDepartments() {
    const container = document.getElementById('department-list');
    let html = '';
    departments.forEach(d => {
      const color = d.percentage >= 90 ? '#059669' : d.percentage >= 70 ? '#d97706' : '#dc2626';
      html += `
        <div class="department-item">
          <div class="dept-header">
            <div class="dept-code">${d.code}</div>
            <div class="dept-name">${d.name}</div>
          </div>
          <div class="dept-stats">
            <span>${d.morning} morning</span>
            <span>${d.afternoon} afternoon</span>
            <span>Avg wait: ${d.avgWait}m</span>
          </div>
          <div class="dept-progress">
            <div class="progress-bar">
              <div class="progress-fill" style="width: ${Math.min(d.percentage, 100)}%; background: ${color}"></div>
            </div>
            <div class="dept-total">${d.total}/${d.capacity}</div>
            <div class="dept-percentage" style="color: ${color}">${d.percentage}%</div>
          </div>
        </div>
      `;
    });
    container.innerHTML = html;
  }

  // ================= INIT =================
  renderDoctors();
  renderDepartments();
</script>
</body>
</html>