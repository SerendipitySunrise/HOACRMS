<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Settings — MediCare Admin Portal</title>
<link rel="stylesheet" href="../assets/css/admin/admin_system_settings.css">
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
      <li class="nav-item">
        <a href="admin_announcements.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
          Announcement
        </a>
      </li>
      <li class="nav-item active">
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
      <h1>System Settings</h1>
      <p>Role management, audit logs, and system configuration</p>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button class="tab-btn active" data-tab="role-access">Role Access</button>
      <button class="tab-btn" data-tab="audit-logs">Audit Logs</button>
    </div>

    <!-- ================= TAB 1: ROLE ACCESS ================= -->
    <div class="tab-content active" id="tab-role-access">
      <div class="role-access-grid">
        <!-- Admin Role -->
        <div class="role-card">
          <div class="role-header">
            <div class="role-icon">👑</div>
            <div class="role-info">
              <h3 class="role-name">Admin</h3>
              <span class="role-badge">Full system access</span>
            </div>
          </div>
          <ul class="permission-list">
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>Full system access</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>Manage staff</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>View reports</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>System settings</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>Audit logs</span>
            </li>
          </ul>
        </div>

        <!-- Doctor Role -->
        <div class="role-card">
          <div class="role-header">
            <div class="role-icon">👨‍⚕️</div>
            <div class="role-info">
              <h3 class="role-name">Doctor</h3>
              <span class="role-badge">Clinical access</span>
            </div>
          </div>
          <ul class="permission-list">
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>View assigned patients</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>Record consultations</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>View medical records</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>Queue management</span>
            </li>
          </ul>
        </div>

        <!-- Nurse Role -->
        <div class="role-card">
          <div class="role-header">
            <div class="role-icon">👩‍⚕️</div>
            <div class="role-info">
              <h3 class="role-name">Nurse</h3>
              <span class="role-badge">Patient care access</span>
            </div>
          </div>
          <ul class="permission-list">
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>View assigned patients</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>Record vitals</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>Assist queue</span>
            </li>
            <li class="permission-item">
              <span class="permission-icon">✓</span>
              <span>View basic records</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- ================= TAB 2: AUDIT LOGS ================= -->
    <div class="tab-content" id="tab-audit-logs">
      <div class="audit-logs-container">
        <div class="audit-filters">
          <div class="filter-group">
            <label for="audit-search">Search</label>
            <input type="text" id="audit-search" placeholder="Search audit logs..." />
          </div>
          <div class="filter-group">
            <label for="audit-type">Event Type</label>
            <select id="audit-type">
              <option value="all">All Events</option>
              <option value="login">Login</option>
              <option value="update">Update</option>
              <option value="approve">Approve</option>
              <option value="add">Add</option>
              <option value="send">Send</option>
            </select>
          </div>
        </div>

        <div class="audit-list" id="audit-list">
          <!-- Rendered by JavaScript -->
        </div>
      </div>
    </div>

  </main>
</div>

<script>
  // ================= AUDIT LOG DATA =================
  const auditLogs = [
    {
      type: "LOGIN",
      user: "admin@hospital.com",
      action: "Admin login successful",
      timestamp: "4/25/2026, 4:00:00 PM",
      ip: "192.168.1.10"
    },
    {
      type: "UPDATE PATIENT",
      user: "admin@hospital.com",
      action: "Updated contact information",
      timestamp: "4/25/2026, 4:15:00 PM",
      ip: "192.168.1.10"
    },
    {
      type: "APPROVE EMERGENCY",
      user: "admin@hospital.com",
      action: "Emergency appointment approved for chest pain patient",
      timestamp: "4/25/2026, 4:22:00 PM",
      ip: "192.168.1.10"
    },
    {
      type: "UPDATE CUTOFF",
      user: "admin@hospital.com",
      action: "Morning session cutoff changed from 20 to 18",
      timestamp: "4/25/2026, 4:30:00 PM",
      ip: "192.168.1.10"
    },
    {
      type: "ADD STAFF",
      user: "admin@hospital.com",
      action: "New doctor added to Surgery department",
      timestamp: "4/25/2026, 5:00:00 PM",
      ip: "192.168.1.10"
    },
    {
      type: "SEND ANNOUNCEMENT",
      user: "admin@hospital.com",
      action: "System maintenance scheduled for tonight",
      timestamp: "4/25/2026, 5:15:00 PM",
      ip: "192.168.1.10"
    },
    {
      type: "LOGIN",
      user: "dr.sarah@hospital.com",
      action: "Doctor login successful",
      timestamp: "4/25/2026, 5:30:00 PM",
      ip: "192.168.1.11"
    }
  ];

  // ================= DOM REFS =================
  const auditList = document.getElementById('audit-list');
  const searchInput = document.getElementById('audit-search');
  const typeFilter = document.getElementById('audit-type');

  // ================= TAB FUNCTIONALITY =================
  document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      // Remove active class from all tabs
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      this.classList.add('active');

      // Hide all tab contents
      document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

      // Show the selected tab content
      const tabId = this.dataset.tab;
      document.getElementById(`tab-${tabId}`).classList.add('active');
    });
  });

  // ================= RENDER AUDIT LOGS =================
  function renderAuditLogs(filter = '', type = 'all') {
    let filtered = auditLogs;

    if (type !== 'all') {
      filtered = filtered.filter(log => log.type.toLowerCase() === type.toLowerCase());
    }

    if (filter.trim()) {
      const searchTerm = filter.toLowerCase().trim();
      filtered = filtered.filter(log => 
        log.type.toLowerCase().includes(searchTerm) ||
        log.user.toLowerCase().includes(searchTerm) ||
        log.action.toLowerCase().includes(searchTerm) ||
        log.ip.includes(searchTerm)
      );
    }

    if (!filtered.length) {
      auditList.innerHTML = `<div class="empty-state">No audit logs found matching your criteria.</div>`;
      return;
    }

    let html = '';
    filtered.forEach(log => {
      const icon = getEventIcon(log.type);
      html += `
        <div class="audit-item">
          <div class="audit-icon">${icon}</div>
          <div class="audit-details">
            <div class="audit-header">
              <span class="audit-type">${log.type}</span>
              <span class="audit-user">by ${log.user}</span>
            </div>
            <div class="audit-action">${log.action}</div>
            <div class="audit-meta">
              <span class="audit-timestamp">${log.timestamp}</span>
              <span class="audit-ip">• ${log.ip}</span>
            </div>
          </div>
        </div>
      `;
    });
    auditList.innerHTML = html;
  }

  function getEventIcon(type) {
    const map = {
      'LOGIN': '🔐',
      'UPDATE PATIENT': '📝',
      'APPROVE EMERGENCY': '🚨',
      'UPDATE CUTOFF': '⚙️',
      'ADD STAFF': '👤',
      'SEND ANNOUNCEMENT': '📢'
    };
    return map[type] || '📋';
  }

  // ================= EVENT BINDING =================
  searchInput.addEventListener('input', (e) => {
    renderAuditLogs(e.target.value, typeFilter.value);
  });

  typeFilter.addEventListener('change', (e) => {
    renderAuditLogs(searchInput.value, e.target.value);
  });

  // ================= INIT =================
  renderAuditLogs();
</script>
</body>
</html>