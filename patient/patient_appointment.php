<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php?portal=patient');
    exit();
}

if ($_SESSION['RoleName'] !== 'Patient') {
    header('Location: ../portal-select.php?action=login');
    exit();
}

$userID = $_SESSION['UserID'];


// Get the PatientID belonging to the logged-in UserID
$stmt = mysqli_prepare(
    $conn,
    'SELECT p.PatientID, u.FirstName, u.LastName, u.ProfilePhoto
     FROM patients p
     INNER JOIN users u ON p.UserID = u.UserID
     WHERE p.UserID = ?'
);

mysqli_stmt_bind_param($stmt, 'i', $userID);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$patient = mysqli_fetch_assoc($result);

if (!$patient) {
    die('Patient profile not found.');
}

$patientID = $patient['PatientID'];

// Get all appointments for the logged-in patient
$appointmentsStmt = mysqli_prepare(
    $conn,
    'SELECT
        a.AppointmentID,
        a.StaffID,
        a.DepartmentID,
        a.AppointmentDate,
        a.AppointmentTime,
        a.Purpose,
        a.Status,
        d.DepartmentName,
        u.FirstName AS StaffFirstName,
        u.LastName AS StaffLastName
     FROM appointments a
     INNER JOIN departments d
        ON a.DepartmentID = d.DepartmentID
     LEFT JOIN staff s
        ON a.StaffID = s.StaffID
     LEFT JOIN users u
        ON s.UserID = u.UserID
     WHERE a.PatientID = ?
     ORDER BY a.AppointmentDate ASC, a.AppointmentTime ASC'
);

mysqli_stmt_bind_param(
    $appointmentsStmt,
    'i',
    $patientID
);

mysqli_stmt_execute($appointmentsStmt);

$appointmentsResult = mysqli_stmt_get_result($appointmentsStmt);

$appointments = [];

while ($row = mysqli_fetch_assoc($appointmentsResult)) {
    $appointments[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Appointments — MediCare Patient Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/patient/patient_dashboard.css">
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
  <li>
    <a href="patient_dashboard.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
      Dashboard
    </a>
  </li>
  <li>
    <a href="patient_appointment.php" class="nav-item active">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      Appointments
    </a>
  </li>
  <li>
    <a href="queue_status.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
      Queue Status
    </a>
  </li>
  <li>
    <a href="view_results.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
      View Results
    </a>
  </li>
  <li>
    <a href="consultation_history.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Consultation History
    </a>
  </li>
  <li>
    <a href="notifications.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      Notifications
    </a>
  </li>
  <li>
    <a href="patient_profile.php" class="nav-item">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Profile
    </a>
  </li>
</ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <?php if (!empty($patient['ProfilePhoto'])): ?>
        <div class="user-avatar"><img src="../<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo strtoupper(substr($patient['FirstName'], 0, 1) . substr($patient['LastName'], 0, 1)); ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo htmlspecialchars($patient['FirstName'] . ' ' . $patient['LastName']); ?></div>
          <div class="user-role">Patient</div>
        </div>
      </div>
      <a class="sign-out" href="../auth/logout.php" onclick="return confirm('Are you sure you want to sign out?');">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="page-header">
      <h1>Appointments</h1>
      <p>Book and manage your appointments</p>
    </div>

    <!-- Tabs -->
    <div class="tab-switch">
      <button class="tab-btn active" type="button">My Appointments</button>
      <a href="book_appointment.php" class="tab-btn" style="text-decoration:none; display:inline-block;">Book New</a>
    </div>

    <?php
    $today = date('Y-m-d');

    $filteredAppointments = [];

    foreach ($appointments as $appointment) {
        $apptDate = $appointment['AppointmentDate'];

        if (empty($apptDate) || !strtotime($apptDate)) {
            continue;
        }

        $filteredAppointments[] = $appointment;
    }
    ?>

    <!-- Filter Bar -->
    <div class="filter-bar">

      <div class="filter-tabs" id="statusTabs">
        <button type="button" class="filter-tab active" data-tab="all">All</button>
        <button type="button" class="filter-tab" data-tab="upcoming">Upcoming</button>
        <button type="button" class="filter-tab" data-tab="completed">Completed</button>
        <button type="button" class="filter-tab" data-tab="cancelled">Cancelled</button>
      </div>

      <div class="filter-row">
        <div class="filter-field">
          <label for="presetSelect">Quick Preset</label>
          <select id="presetSelect" class="filter-select">
            <option value="all_time" selected>All Time</option>
            <option value="this_month">This Month</option>
            <option value="last_30">Last 30 Days</option>
            <option value="this_year">This Year</option>
            <option value="custom">Custom Range</option>
          </select>
        </div>

        <div class="filter-field" id="dateRangeFields" style="display:none;">
          <label for="startDate">From</label>
          <input type="date" id="startDate" class="filter-date">
          <span class="filter-date-sep">to</span>
          <input type="date" id="endDate" class="filter-date">
        </div>

        <button type="button" class="btn-clear" id="clearFilters">Clear Filters</button>
      </div>

    </div>

    <!-- Filtered Appointments -->
    <div class="section-panel">
      <div class="section-panel-title" id="listTitle">All Appointments</div>

      <div class="appt-row-list" id="apptList">

        <?php if (count($filteredAppointments) === 0): ?>
          <div class="appt-row">
            <div class="appt-row-info">
              <div class="title">No appointments</div>
              <div class="date">No appointments are available yet.</div>
            </div>
          </div>
        <?php else: ?>

        <?php foreach ($filteredAppointments as $appointment): ?>
          <?php
          $apptDate = $appointment['AppointmentDate'];
          $apptStatus = $appointment['Status'];

          $formattedDate = date('F d, Y', strtotime($apptDate));
          $formattedTime = date('g:i A', strtotime($appointment['AppointmentTime']));

          $statusClass = match($apptStatus) {
              'Pending'          => 'pending',
              'Cancelled'        => 'cancelled',
              'Completed'        => 'completed',
              'Checked In'       => 'checked-in',
              'Called'           => 'called',
              'In Consultation'  => 'in-consultation',
              default            => 'pending'
          };
          ?>
          <div
            class="appt-row"
            data-date="<?php echo htmlspecialchars($apptDate); ?>"
            data-status="<?php echo htmlspecialchars($apptStatus); ?>">

            <div class="appt-row-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="18" rx="2"/>
                <path d="M16 2v4M8 2v4M3 10h18"/>
              </svg>
            </div>

            <div class="appt-row-info">
              <div class="title">
                <?php echo htmlspecialchars($appointment['Purpose'] ?: 'Medical Appointment'); ?>
              </div>
              <div class="date">
                <?php echo htmlspecialchars($formattedDate); ?>
                •
                <?php echo htmlspecialchars($formattedTime); ?>
              </div>
              <div class="date">
                <?php echo htmlspecialchars($appointment['DepartmentName']); ?>
                <?php if (!empty($appointment['StaffFirstName'])): ?>
                  • Dr.
                  <?php echo htmlspecialchars($appointment['StaffFirstName']); ?>
                  <?php echo htmlspecialchars($appointment['StaffLastName']); ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="appt-row-actions">

              <span class="pill-status <?php echo $statusClass; ?>">
                <?php echo htmlspecialchars($apptStatus); ?>
              </span>

              <?php if (
                  $apptStatus !== 'Cancelled' &&
                  $apptStatus !== 'Completed' &&
                  $apptStatus !== 'Checked In' &&
                  $apptStatus !== 'In Consultation'
              ): ?>
                <button
                  class="btn-cancel"
                  type="button"
                  data-id="<?php echo (int)$appointment['AppointmentID']; ?>">
                  Cancel
                </button>
              <?php endif; ?>

            </div>

          </div>
        <?php endforeach; ?>

        <?php endif; ?>

      </div>
    </div>

  </main>

</div>

<script>
document.querySelectorAll('.btn-cancel').forEach(button => {
    button.addEventListener('click', () => {
        const appointmentID = button.dataset.id;

        if (!confirm('Cancel this appointment?')) {
            return;
        }

        button.disabled = true;
        button.textContent = 'Cancelling...';

        const formData = new FormData();
        formData.append('appointment_id', appointmentID);

        fetch('../api/appointments/cancel_appointment.php', {
    method: 'POST',
    body: formData
})
.then(async response => {

    const text = await response.text();

    console.log('Cancel response:', text);

    try {
        return JSON.parse(text);
    } catch (error) {
        console.error('Invalid JSON response:', text);
        throw new Error('Server returned an invalid response.');
    }
})
.then(data => {

    if (!data.success) {
        alert(data.message || 'Unable to cancel the appointment.');

        button.disabled = false;
        button.textContent = 'Cancel';

        return;
    }

    alert(data.message);

    window.location.reload();
})
.catch(error => {

    console.error('Cancellation error:', error);

    alert(
        'Something went wrong while cancelling the appointment.\n\n' +
        'Please check the browser console for details.'
    );

    button.disabled = false;
    button.textContent = 'Cancel';
});
    });
});

// ---------- Filtering ----------
(function () {
    const tabs = document.querySelectorAll('.filter-tab');
    const presetSelect = document.getElementById('presetSelect');
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');
    const dateRangeFields = document.getElementById('dateRangeFields');
    const clearBtn = document.getElementById('clearFilters');
    const apptList = document.getElementById('apptList');
    const listTitle = document.getElementById('listTitle');

    const tabTitles = {
        all: 'All Appointments',
        upcoming: 'Upcoming Appointments',
        completed: 'Completed Appointments',
        cancelled: 'Cancelled Appointments'
    };

    let activeTab = 'all';

    function todayISO() {
        const d = new Date();
        return d.toISOString().slice(0, 10);
    }

    function addDays(iso, days) {
        const d = new Date(iso + 'T00:00:00');
        d.setDate(d.getDate() + days);
        return d.toISOString().slice(0, 10);
    }

    function getDateRange() {
        const preset = presetSelect.value;
        const today = todayISO();

        switch (preset) {
            case 'this_month': {
                const d = new Date(today + 'T00:00:00');
                const start = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01';
                return { start, end: today };
            }
            case 'last_30':
                return { start: addDays(today, -29), end: today };
            case 'this_year':
                return { start: today.slice(0, 4) + '-01-01', end: today };
            case 'custom':
                if (startDate.value && endDate.value) {
                    return { start: startDate.value, end: endDate.value };
                }
                return null;
            case 'all_time':
            default:
                return null;
        }
    }

    function matchesStatus(status) {
        const today = todayISO();

        switch (activeTab) {
            case 'upcoming':
                return (status === 'Pending' || status === 'Confirmed' ||
                        status === 'Checked In' || status === 'Called' ||
                        status === 'In Consultation');
            case 'completed':
                return status === 'Completed';
            case 'cancelled':
                return status === 'Cancelled';
            case 'all':
            default:
                return true;
        }
    }

    function applyFilters() {
        const range = getDateRange();
        let visible = 0;

        tabs.forEach(t => {
            t.classList.toggle('active', t.dataset.tab === activeTab);
        });

        listTitle.textContent = tabTitles[activeTab];

        apptList.querySelectorAll('.appt-row').forEach(row => {
            const date = row.dataset.date;
            const status = row.dataset.status;

            let show = matchesStatus(status);

            if (show && range) {
                show = date >= range.start && date <= range.end;
            }

            row.style.display = show ? '' : 'none';

            if (show) {
                visible++;
            }
        });

        let emptyRow = apptList.querySelector('.empty-filter-row');

        if (visible === 0) {
            if (!emptyRow) {
                emptyRow = document.createElement('div');
                emptyRow.className = 'appt-row empty-filter-row';
                emptyRow.innerHTML =
                    '<div class="appt-row-info">' +
                    '<div class="title">No appointments found</div>' +
                    '<div class="date">Try adjusting the filters to see more results.</div>' +
                    '</div>';
                apptList.appendChild(emptyRow);
            }
        } else if (emptyRow) {
            emptyRow.remove();
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            activeTab = tab.dataset.tab;
            applyFilters();
        });
    });

    presetSelect.addEventListener('change', () => {
        const showCustom = presetSelect.value === 'custom';
        dateRangeFields.style.display = showCustom ? 'flex' : 'none';
        applyFilters();
    });

    startDate.addEventListener('change', applyFilters);
    endDate.addEventListener('change', applyFilters);

    clearBtn.addEventListener('click', () => {
        activeTab = 'all';
        presetSelect.value = 'all_time';
        startDate.value = '';
        endDate.value = '';
        dateRangeFields.style.display = 'none';
        applyFilters();
    });

    applyFilters();
})();
</script>
</body>
</html>