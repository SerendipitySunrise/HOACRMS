<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

/*
|--------------------------------------------------------------------------
| CHECK LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php?portal=patient');
    exit();
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    header('Location: ../portal-select.php?action=login');
    exit();
}

$userID = (int) $_SESSION['UserID'];

/*
|--------------------------------------------------------------------------
| GET PATIENT
|--------------------------------------------------------------------------
*/

$patientStmt = mysqli_prepare(
    $conn,
    'SELECT
        p.PatientID,
        u.FirstName,
        u.LastName,
        u.ProfilePhoto
     FROM patients p
     INNER JOIN users u ON p.UserID = u.UserID
     WHERE p.UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param($patientStmt, 'i', $userID);
mysqli_stmt_execute($patientStmt);

$patientResult = mysqli_stmt_get_result($patientStmt);
$patient = mysqli_fetch_assoc($patientResult);

if (!$patient) {
    die('Patient profile not found.');
}

$patientID = (int) $patient['PatientID'];

/*
|--------------------------------------------------------------------------
| LOAD APPOINTMENT TO RESCHEDULE
|--------------------------------------------------------------------------
*/

$appointmentID = (int) ($_GET['appointment_id'] ?? 0);

if ($appointmentID <= 0) {
    header('Location: patient_appointment.php?msg=No appointment was selected.');
    exit();
}

$apptStmt = mysqli_prepare(
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
     INNER JOIN departments d ON a.DepartmentID = d.DepartmentID
     LEFT JOIN staff s ON a.StaffID = s.StaffID
     LEFT JOIN users u ON s.UserID = u.UserID
     WHERE a.AppointmentID = ?
       AND a.PatientID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param($apptStmt, 'ii', $appointmentID, $patientID);
mysqli_stmt_execute($apptStmt);

$apptResult = mysqli_stmt_get_result($apptStmt);
$appointment = mysqli_fetch_assoc($apptResult);

if (!$appointment) {
    header('Location: patient_appointment.php?msg=Appointment not found.');
    exit();
}

$reschedulableStatuses = ['Pending', 'Scheduled', 'Confirmed'];

if (!in_array($appointment['Status'], $reschedulableStatuses, true)) {
    header(
        'Location: patient_appointment.php?msg=' .
        urlencode('This appointment cannot be rescheduled.')
    );
    exit();
}

$apptDate = $appointment['AppointmentDate'];

if (empty($apptDate) || !strtotime($apptDate)) {
    header(
        'Location: patient_appointment.php?msg=' .
        urlencode('This appointment has no valid date and cannot be rescheduled.')
    );
    exit();
}

$departmentID = (int) $appointment['DepartmentID'];
$departmentName = $appointment['DepartmentName'];
$currentDateLabel = date('F d, Y', strtotime($apptDate));
$currentTimeLabel = date('g:i A', strtotime($appointment['AppointmentTime']));
$currentPurpose = $appointment['Purpose'] ?? '';

// Reschedule usage count + 24-hour window
$reschedCountStmt = mysqli_prepare(
    $conn,
    'SELECT COUNT(*) AS total
     FROM appointment_reschedule_history
     WHERE AppointmentID = ?'
);
mysqli_stmt_bind_param($reschedCountStmt, 'i', $appointmentID);
mysqli_stmt_execute($reschedCountStmt);
$reschedCountResult = mysqli_stmt_get_result($reschedCountStmt);
$reschedCountRow = mysqli_fetch_assoc($reschedCountResult);
$reschedUsed = (int) ($reschedCountRow['total'] ?? 0);

$currentStartTs = strtotime($apptDate . ' ' . ($appointment['AppointmentTime'] ?? '00:00:00'));
$isCurrentWithin24h = ($currentStartTs !== false) && ($currentStartTs < (time() + 86400));

$reschedBlocked = ($reschedUsed >= 3) || $isCurrentWithin24h;
$reschedBlockReason = '';
if ($isCurrentWithin24h) {
    $reschedBlockReason = 'This appointment starts in less than 24 hours and can no longer be rescheduled.';
} elseif ($reschedUsed >= 3) {
    $reschedBlockReason = 'This appointment has already been rescheduled the maximum number of times (3).';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Reschedule Appointment — MediCare Patient Portal</title>

<link rel="preconnect"
      href="https://fonts.googleapis.com">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
      rel="stylesheet">

<link rel="stylesheet"
      href="../assets/css/patient/patient_dashboard.css">

</head>

<body>

<div class="app">

<!-- =========================================================
     SIDEBAR
========================================================= -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">

            <svg viewBox="0 0 24 24"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="2"
                 stroke-linecap="round"
                 stroke-linejoin="round">

                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>

            </svg>

        </div>

        <div class="brand-text">

            <div class="brand-title">
                MediCare
            </div>

            <div class="brand-sub">
                Patient Portal
            </div>

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
            <div class="user-avatar">

                <?php
                echo strtoupper(
                    substr($patient['FirstName'], 0, 1) .
                    substr($patient['LastName'], 0, 1)
                );
                ?>

            </div>
            <?php endif; ?>

            <div>

                <div class="user-name">

                    <?php
                    echo htmlspecialchars(
                        $patient['FirstName'] . ' ' . $patient['LastName']
                    );
                    ?>

                </div>

                <div class="user-role">
                    Patient
                </div>

            </div>

        </div>


        <a href="../auth/logout.php"
           class="sign-out"
           style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;"
           onclick="return confirm('Are you sure you want to sign out?');">

            Sign Out

        </a>

    </div>

</aside>


<!-- =========================================================
     MAIN
========================================================= -->

<main class="main">

    <div class="page-header">

        <h1>
            Reschedule Appointment
        </h1>

        <p>
            Choose a new date and time for your appointment
        </p>

    </div>


    <!-- TABS -->

    <div class="tab-switch">

        <a href="patient_appointment.php"
           class="tab-btn"
           style="text-decoration:none;display:inline-block;">

            My Appointments

        </a>

        <a href="book_appointment.php"
           class="tab-btn"
           style="text-decoration:none;display:inline-block;">

            Book New

        </a>

        <button class="tab-btn active"
                type="button">

            Reschedule

        </button>

    </div>


    <!-- =========================================================
         CURRENT APPOINTMENT SUMMARY
    ========================================================= -->

    <div class="re-current">

        <div class="re-current-title">
            Current Appointment
        </div>

        <div class="confirm-summary">

            <div class="confirm-row">
                <span class="label">Department</span>
                <span class="value"><?php echo htmlspecialchars($departmentName); ?></span>
            </div>

            <div class="confirm-row">
                <span class="label">Date</span>
                <span class="value"><?php echo htmlspecialchars($currentDateLabel); ?></span>
            </div>

            <div class="confirm-row">
                <span class="label">Time</span>
                <span class="value"><?php echo htmlspecialchars($currentTimeLabel); ?></span>
            </div>

            <div class="confirm-row">
                <span class="label">Status</span>
                <span class="value"><?php echo htmlspecialchars($appointment['Status']); ?></span>
            </div>

        </div>

    </div>


    <?php if ($reschedBlocked): ?>

    <!-- =========================================================
         BLOCKED (limit reached / within 24h)
    ========================================================= -->

    <div class="wizard-panel">

        <div class="wizard-panel-title">
            Reschedule Unavailable
        </div>

        <div class="re-blocked">
            <p><?php echo htmlspecialchars($reschedBlockReason); ?></p>
            <p class="re-blocked-sub">
                Reschedules used:
                <strong><?php echo $reschedUsed; ?></strong> of 3
            </p>
        </div>

        <div class="wizard-footer split">

            <a href="patient_appointment.php"
               class="btn-outline"
               style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">

                Back to Appointments

            </a>

        </div>

    </div>

    <?php else: ?>

    <!-- =========================================================
         STEPPER
    ========================================================= -->

    <div class="stepper" id="stepperBar">

        <div class="step active"
             id="step-ind-1">

            <div class="step-circle">
                <span>1</span>
            </div>

            <div class="step-label">
                New Date
            </div>

        </div>

        <div class="step-line"
             id="line-1">
        </div>

        <div class="step"
             id="step-ind-2">

            <div class="step-circle">
                <span>2</span>
            </div>

            <div class="step-label">
                New Time
            </div>

        </div>

        <div class="step-line"
             id="line-2">
        </div>

        <div class="step"
             id="step-ind-3">

            <div class="step-circle">
                <span>3</span>
            </div>

            <div class="step-label">
                Confirm
            </div>

        </div>

    </div>


    <div class="re-usage">
        Reschedules used:
        <strong><?php echo $reschedUsed; ?></strong>
        of 3
    </div>


    <!-- =========================================================
         STEP 1: CHOOSE NEW DATE
    ========================================================= -->

    <div class="wizard-panel"
         id="step-1">

        <div class="wizard-panel-title">
            Choose New Date
        </div>

        <div class="re-locked">
            <span class="re-locked-label">Department</span>
            <span class="re-locked-value">
                <?php echo htmlspecialchars($departmentName); ?>
            </span>
        </div>

        <div class="avail-days-label">
            Select Appointment Date
        </div>

        <div class="date-grid" id="dateGrid"></div>

        <div class="wizard-footer split">

            <a href="patient_appointment.php"
               class="btn-outline"
               style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">

                Back to Appointments

            </a>

            <button class="btn-primary-solid"
                    type="button"
                    onclick="nextFromDate()">

                Next: Choose Time

            </button>

        </div>

    </div>


    <!-- =========================================================
         STEP 2: CHOOSE NEW TIME
    ========================================================= -->

    <div class="wizard-panel"
         id="step-2"
         style="display:none;">

        <div class="wizard-panel-title">
            Choose New Time
        </div>

        <div class="timeslot-head">

            <div>

                <div class="timeslot-label">
                    Time Slot
                </div>

                <div class="timeslot-sub" id="timeslotSub">
                    Select an available appointment date.
                </div>

            </div>

        </div>

        <div class="slot-grid" id="slotGrid"></div>


        <!-- PURPOSE -->

        <div class="re-input-block">

            <label for="purpose">
                Purpose of Appointment
            </label>

            <input type="text"
                   id="purpose"
                   name="purpose"
                   value="<?php echo htmlspecialchars($currentPurpose); ?>"
                   maxlength="255"
                   style="
                       width:100%;
                       padding:12px;
                       border:1px solid #ddd;
                       border-radius:8px;
                   ">

        </div>


        <!-- REASON (OPTIONAL) -->

        <div class="re-input-block">

            <label for="reason">
                Reason for Rescheduling <span class="re-optional">(optional)</span>
            </label>

            <textarea id="reason"
                      name="reason"
                      rows="3"
                      placeholder="Tell us why you are rescheduling (optional)"
                      maxlength="255"
                      style="
                          width:100%;
                          padding:12px;
                          border:1px solid #ddd;
                          border-radius:8px;
                          resize:vertical;
                          font-family:inherit;
                      "></textarea>

        </div>

        <div class="wizard-footer split">

            <button class="btn-outline"
                    type="button"
                    onclick="goStep(1)">

                Back

            </button>

            <button class="btn-primary-solid"
                    type="button"
                    onclick="nextToConfirm()">

                Next: Review

            </button>

        </div>

    </div>


    <!-- =========================================================
         STEP 3: REVIEW & CONFIRM
    ========================================================= -->

    <div class="wizard-panel"
         id="step-3"
         style="display:none;">

        <div class="wizard-panel-title">
            Review &amp; Confirm
        </div>

        <div class="confirm-summary">

            <div class="confirm-row">
                <span class="label">Department</span>
                <span class="value" id="confirm-dept"></span>
            </div>

            <div class="confirm-row">
                <span class="label">Date</span>
                <span class="value" id="confirm-date"></span>
            </div>

            <div class="confirm-row">
                <span class="label">Time</span>
                <span class="value" id="confirm-time"></span>
            </div>

            <div class="confirm-row">
                <span class="label">Status</span>
                <span class="value"><?php echo htmlspecialchars($appointment['Status']); ?></span>
            </div>

        </div>

        <div class="wizard-footer split">

            <button class="btn-outline"
                    type="button"
                    onclick="goStep(2)">

                Back

            </button>

            <button class="btn-primary-solid"
                    type="button"
                    onclick="confirmReschedule()">

                Confirm Reschedule

            </button>

        </div>

    </div>

    <?php endif; ?>


    <!-- =========================================================
         SUCCESS
    ========================================================= -->

    <div class="success-wrap"
         id="step-4"
         style="display:none;">

        <div class="success-card">

            <div class="success-check">
                ✓
            </div>

            <h2>Appointment Rescheduled</h2>

            <p>Your appointment has been successfully updated.</p>

            <div class="success-summary">

                <div class="confirm-row">
                    <span class="label">Department</span>
                    <span class="value" id="success-dept"></span>
                </div>

                <div class="confirm-row">
                    <span class="label">Date</span>
                    <span class="value" id="success-date"></span>
                </div>

                <div class="confirm-row">
                    <span class="label">Time</span>
                    <span class="value" id="success-time"></span>
                </div>

            </div>

            <div class="success-actions">

                <a href="patient_appointment.php"
                   class="btn-primary-solid"
                   style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">

                    My Appointments

                </a>

                <a href="patient_dashboard.php"
                   class="btn-outline"
                   style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">

                    Go to Dashboard

                </a>

            </div>

        </div>

    </div>

</main>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>
const appointmentID = <?php echo (int) $appointmentID; ?>;
const departmentID = <?php echo (int) $departmentID; ?>;
const departmentName = '<?php echo htmlspecialchars($departmentName, ENT_QUOTES); ?>';

let selectedDate = '';
let selectedSlot = '';

let departmentSchedules = [];
let scheduleLoading = false;

function formatISODate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function loadDepartmentSchedule() {
    const dateGrid = document.getElementById('dateGrid');
    const slotGrid = document.getElementById('slotGrid');
    const timeslotSub = document.getElementById('timeslotSub');

    scheduleLoading = true;
    departmentSchedules = [];
    selectedDate = '';
    selectedSlot = '';

    dateGrid.innerHTML = '';
    slotGrid.innerHTML = '';
    timeslotSub.textContent = 'Loading department schedule...';

    const formData = new FormData();
    formData.append('department_id', departmentID);

    fetch('../api/appointments/get_department_schedule.php', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        const rawResponse = await response.text();

        let data;

        try {
            data = JSON.parse(rawResponse);
        } catch {
            throw new Error(
                'The schedule endpoint returned an error instead of JSON: ' +
                rawResponse.substring(0, 200)
            );
        }

        if (!response.ok) {
            throw new Error(data.message || `Server error ${response.status}`);
        }

        return data;
    })
    .then(data => {
        if (!data.success) {
            timeslotSub.textContent = data.message || 'Unable to load schedule.';
            alert(data.message || 'Unable to load this department schedule.');
            return;
        }

        if (Number(data.active_doctors) <= 0) {
            timeslotSub.textContent = 'No active doctors are assigned.';
            alert('No active doctors are currently assigned to this department.');
            return;
        }

        departmentSchedules = data.schedules;
        renderAvailableDates();
    })
    .catch(error => {
        console.error(error);

        timeslotSub.textContent = error.message;
        alert(error.message);
    })
    .finally(() => {
        scheduleLoading = false;
    });
}

function renderAvailableDates() {
    const dateGrid = document.getElementById('dateGrid');
    const slotGrid = document.getElementById('slotGrid');
    const timeslotSub = document.getElementById('timeslotSub');

    dateGrid.innerHTML = '';
    slotGrid.innerHTML = '';

    selectedDate = '';
    selectedSlot = '';

    timeslotSub.textContent = 'Select an available appointment date.';

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let datesAdded = 0;

    for (let offset = 1; offset <= 60 && datesAdded < 7; offset++) {
        const date = new Date(today);
        date.setDate(today.getDate() + offset);

        const hasSchedule = departmentSchedules.some(schedule => {
            return Number(schedule.DayOfWeek) === date.getDay();
        });

        if (!hasSchedule) {
            continue;
        }

        const cell = document.createElement('div');

        cell.className = 'date-cell';
        cell.dataset.date = formatISODate(date);

        cell.innerHTML = `
            <div class="dow">
                ${date.toLocaleDateString('en-US', { weekday: 'short' })}
            </div>
            <div class="num">${date.getDate()}</div>
            <div class="mon">
                ${date.toLocaleDateString('en-US', { month: 'short' })}
            </div>
        `;

        cell.addEventListener('click', () => {
            document
                .querySelectorAll('.date-cell')
                .forEach(item => item.classList.remove('selected'));

            cell.classList.add('selected');

            selectedDate = cell.dataset.date;
            selectedSlot = '';

            loadAvailableSlots();
        });

        dateGrid.appendChild(cell);
        datesAdded++;
    }

    if (datesAdded === 0) {
        dateGrid.innerHTML = '<p>No future appointment dates are available.</p>';
    }
}

function loadAvailableSlots() {
    const slotGrid = document.getElementById('slotGrid');
    const timeslotSub = document.getElementById('timeslotSub');

    selectedSlot = '';
    slotGrid.innerHTML = '';
    timeslotSub.textContent = 'Loading available time slots...';

    const formData = new FormData();
    formData.append('department_id', departmentID);
    formData.append('appointment_date', selectedDate);
    formData.append('exclude_appointment_id', appointmentID);

    fetch('../api/appointments/get_booked_slots.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            timeslotSub.textContent =
                data.message || 'No time slots are available.';
            return;
        }

        timeslotSub.textContent =
            `Each slot allows up to ${data.capacity} patient(s).`;

        data.slots.forEach(slot => {
            const cell = document.createElement('div');

            cell.className = 'slot-cell';
            cell.dataset.slot = slot.time;

            if (!slot.available) {
                cell.classList.add('disabled');

                cell.innerHTML = `
                    <span class="slot-time">${slot.label}</span>
                    <span class="slot-avail">
                        <span class="avail-dot red"></span>Fully Booked
                    </span>
                `;
            } else {
                const remaining = slot.capacity - slot.booked;
                const dotClass = remaining === 1 ? 'amber' : 'green';

                cell.innerHTML = `
                    <span class="slot-time">${slot.label}</span>
                    <span class="slot-avail">
                        <span class="avail-dot ${dotClass}"></span>${remaining} slot${remaining === 1 ? '' : 's'} left
                    </span>
                `;

                cell.addEventListener('click', () => {
                    document
                        .querySelectorAll('.slot-cell')
                        .forEach(item => item.classList.remove('selected'));

                    cell.classList.add('selected');
                    selectedSlot = cell.dataset.slot;
                });
            }

            slotGrid.appendChild(cell);
        });
    })
    .catch(() => {
        timeslotSub.textContent = 'Unable to load time slots.';
    });
}

function isWithin24Hours() {
    if (!selectedDate || !selectedSlot) {
        return false;
    }

    const newDateTime = new Date(selectedDate + 'T' + selectedSlot);
    const hour = Number(selectedSlot.split(':')[0]);

    return newDateTime.getTime() - Date.now() < 24 * 60 * 60 * 1000;
}

function updateStepper(step) {
    for (let i = 1; i <= 3; i++) {
        const indicator = document.getElementById('step-ind-' + i);

        if (!indicator) {
            continue;
        }

        indicator.classList.remove('active', 'done');

        if (i < step) {
            indicator.classList.add('done');
        } else if (i === step) {
            indicator.classList.add('active');
        }
    }

    for (let i = 1; i <= 2; i++) {
        const line = document.getElementById('line-' + i);

        if (line) {
            line.classList.toggle('done', i < step);
        }
    }
}

function goStep(step) {
    const stepperBar = document.getElementById('stepperBar');

    for (let i = 1; i <= 4; i++) {
        const panel = document.getElementById('step-' + i);

        if (panel) {
            panel.style.display = 'none';
        }
    }

    if (step === 4) {
        if (stepperBar) {
            stepperBar.style.display = 'none';
        }
    } else {
        if (stepperBar) {
            stepperBar.style.display = '';
        }

        updateStepper(step);
    }

    const selectedPanel = document.getElementById('step-' + step);

    if (selectedPanel) {
        selectedPanel.style.display = '';
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function nextFromDate() {
    if (!selectedDate) {
        alert('Please select a new appointment date first.');
        return;
    }

    if (scheduleLoading) {
        alert('Please wait while the schedule finishes loading.');
        return;
    }

    goStep(2);
}

function nextToConfirm() {
    const purpose = document.getElementById('purpose').value.trim();

    if (!selectedDate) {
        alert('Please select a new appointment date first.');
        return;
    }

    if (!selectedSlot) {
        alert('Please select a new appointment time.');
        return;
    }

    if (!purpose) {
        alert('Please enter the purpose of your appointment.');
        return;
    }

    document.getElementById('confirm-dept').textContent = departmentName;
    document.getElementById('confirm-date').textContent = selectedDate;
    document.getElementById('confirm-time').textContent = selectedSlot;

    goStep(3);
}

function confirmReschedule() {
    const purpose = document.getElementById('purpose').value.trim();

    if (!selectedDate) {
        alert('Please select a new appointment date.');
        return;
    }

    if (!selectedSlot) {
        alert('Please select a new appointment time.');
        return;
    }

    if (!purpose) {
        alert('Please enter the purpose of your appointment.');
        return;
    }

    if (isWithin24Hours()) {
        if (!confirm(
            'Your selected appointment is less than 24 hours away.\n\n' +
            'Please make sure you can come on time. Continue?'
        )) {
            return;
        }
    }

    const reason = document.getElementById('reason').value.trim();

    const formData = new FormData();

    formData.append('appointment_id', appointmentID);
    formData.append('appointment_date', selectedDate);
    formData.append('appointment_time', selectedSlot);
    formData.append('purpose', purpose);
    formData.append('reason', reason);

    const button = document.querySelector('.wizard-footer .btn-primary-solid');

    button.disabled = true;
    button.textContent = 'Rescheduling...';

    fetch('../api/appointments/reschedule_appointment.php', {
        method: 'POST',
        body: formData
    })
    .then(async response => {
        const text = await response.text();

        try {
            return JSON.parse(text);
        } catch (error) {
            console.error('Invalid JSON response:', text);
            throw new Error('Server returned an invalid response.');
        }
    })
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Unable to reschedule the appointment.');

            button.disabled = false;
            button.textContent = 'Confirm Reschedule';

            return;
        }

        if (data.within_24h) {
            alert('Note: Your rescheduled appointment is less than 24 hours away.');
        }

        document.getElementById('success-dept').textContent = '<?php echo htmlspecialchars($departmentName, ENT_QUOTES); ?>';
        document.getElementById('success-date').textContent = selectedDate;
        document.getElementById('success-time').textContent = selectedSlot;

        goStep(4);
    })
    .catch(error => {
        console.error('Reschedule error:', error);

        alert(
            'Something went wrong while rescheduling the appointment.\n\n' +
            'Please check the browser console for details.'
        );

        button.disabled = false;
        button.textContent = 'Confirm Reschedule';
    });
}

if (document.getElementById('step-1')) {
    loadDepartmentSchedule();
}
</script>

</body>
</html>