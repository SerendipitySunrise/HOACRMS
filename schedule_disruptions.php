<?php
session_start();
require_once __DIR__ . '/includes/db_pdo.php';

if (!in_array((string) ($_SESSION['RoleName'] ?? ''), ['Admin', 'Doctor'], true)) {
    header('Location: portal-select.php?action=login');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = db_pdo();
$role = (string) $_SESSION['RoleName'];
$doctors = [];
if ($role === 'Admin') {
    $doctors = $pdo->query(
        "SELECT s.StaffID, CONCAT(u.FirstName, ' ', u.LastName) AS Name
         FROM staff s INNER JOIN users u ON u.UserID = s.UserID
         WHERE s.StaffRole = 'Doctor' AND u.Status = 'Active'
         ORDER BY Name"
    )->fetchAll();
}
$departments = $pdo->query('SELECT DepartmentID, DepartmentName FROM departments ORDER BY DepartmentName')->fetchAll();

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Schedule Disruptions | Curora</title>
  <link rel="stylesheet" href="assets/css/schedule_disruptions.css">
</head>
<body>
<main class="page">
  <header class="page-head">
    <div>
      <h1>Schedule Disruptions</h1>
      <p>Review affected appointments before notifying patients.</p>
    </div>
    <a class="secondary" href="<?= $role === 'Admin' ? 'admin/admin_dashboard.php' : 'doctor/doctor_dashboard.php' ?>">Back to portal</a>
  </header>

  <section class="card">
    <h2>Mark unavailable</h2>
    <p class="muted">Create a disruption window, then review the exact scheduled appointments it affects.</p>
    <div id="message" class="notice hidden"></div>

    <form id="disruption-form">
      <div class="form-grid">
        <div class="field">
          <label for="scope">Scope</label>
          <select id="scope">
            <option value="doctor">Doctor</option>
            <option value="department">Department</option>
          </select>
        </div>
        <div class="field" id="doctor-field">
          <label for="doctor_id">Doctor</label>
          <select id="doctor_id">
            <option value="">Select doctor</option>
            <?php if ($role === 'Doctor'): ?>
              <option value="self" selected>My schedule</option>
            <?php else: foreach ($doctors as $doctor): ?>
              <option value="<?= (int) $doctor['StaffID'] ?>">Dr. <?= h($doctor['Name']) ?></option>
            <?php endforeach; endif; ?>
          </select>
        </div>
        <div class="field hidden" id="department-field">
          <label for="department_id">Department</label>
          <select id="department_id">
            <option value="">Select department</option>
            <?php foreach ($departments as $department): ?>
              <option value="<?= (int) $department['DepartmentID'] ?>"><?= h($department['DepartmentName']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="start_date">Start date</label><input type="date" id="start_date" required></div>
        <div class="field"><label for="end_date">End date</label><input type="date" id="end_date" required></div>
        <div class="field full"><label for="reason">Reason</label><input id="reason" maxlength="255" placeholder="e.g. Doctor emergency leave" required></div>
        <div class="field full"><label class="check"><input type="checkbox" id="is_emergency"> Same-day emergency / time-sensitive</label></div>
      </div>
      <div class="actions"><button class="primary" type="submit">Find affected appointments</button></div>
    </form>
  </section>

  <section class="card review" id="review">
    <h2>Review and notify</h2>
    <p class="muted">Select appointments to cancel. Patients will receive a link to choose a new appointment date.</p>
    <div id="review-message" class="notice hidden"></div>
    <div class="review-toolbar">
      <label class="check"><input type="checkbox" id="select-all"> Select all</label>
      <strong class="cancel-label"></strong>
    </div>
    <div class="appointment-list" id="appointment-list"></div>
    <div class="form-grid" style="margin-top:18px">
      <div class="field full">
        <label for="template">Notification template</label>
        <textarea id="template"></textarea>
        <span class="muted">[RESCHEDULE_LINK] is replaced with each patient's self-service reschedule link.</span>
      </div>
      <div class="field full">
        <label>Channels</label>
        <label class="check"><input type="checkbox" name="channel" value="in_app" checked> In-app alert</label>
        <label class="check"><input type="checkbox" name="channel" value="email" checked> Email when enabled by the patient</label>
      </div>
    </div>
    <div class="actions"><button class="danger" id="confirm-btn" type="button">Confirm cancellation and send notifications</button></div>
  </section>
</main>

<script>
const csrf = <?= json_encode($_SESSION['csrf_token']) ?>;
let unavailabilityId = 0;
let appointments = [];
const $ = (id) => document.getElementById(id);

function showMessage(id, text, error = false) {
  $(id).textContent = text;
  $(id).classList.toggle('error', error);
  $(id).classList.remove('hidden');
}

function scopePayload() {
  return {
    doctor_id: $('scope').value === 'doctor' && $('doctor_id').value !== 'self' ? $('doctor_id').value : '',
    department_id: $('scope').value === 'department' ? $('department_id').value : '',
    start_date: $('start_date').value,
    end_date: $('end_date').value
  };
}

function buildNotificationTemplate(appointment) {
  if ($('is_emergency').checked) {
    return `URGENT: Your appointment today, [DATE] at [TIME], has been cancelled due to a doctor emergency. We're sorry for the short notice. Please tap the link below to choose a new time as soon as possible: [RESCHEDULE_LINK]`;
  }

  return `Your appointment on [DATE] at [TIME] was cancelled because ${$('reason').value}. Please choose a new appointment date here: [RESCHEDULE_LINK]`;
}

$('scope').addEventListener('change', () => {
  $('doctor-field').classList.toggle('hidden', $('scope').value !== 'doctor');
  $('department-field').classList.toggle('hidden', $('scope').value !== 'department');
});

$('disruption-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const body = new FormData();
  body.append('csrf_token', csrf);
  body.append('action', 'create');
  Object.entries(scopePayload()).forEach(([key, value]) => body.append(key, value));
  body.append('reason', $('reason').value.trim());
  if ($('is_emergency').checked) body.append('is_emergency', '1');

  const response = await fetch('api/schedule_disruptions.php', { method: 'POST', body });
  const data = await response.json();
  if (!data.success) {
    showMessage('message', data.message, true);
    return;
  }

  unavailabilityId = data.unavailability_id;
  appointments = data.appointments;
  renderAppointments();
  $('template').value = appointments[0] ? buildNotificationTemplate(appointments[0]) : '';
  $('review').classList.add('visible');
  showMessage('message', data.message);
  $('review').scrollIntoView({ behavior: 'smooth' });
});

$('is_emergency').addEventListener('change', () => {
  if (appointments.length) {
    $('template').value = buildNotificationTemplate(appointments[0]);
  }
});

function renderAppointments() {
  if (!appointments.length) {
    $('appointment-list').innerHTML = '<div class="notice">No active appointments were found for this disruption window.</div>';
    return;
  }
  $('appointment-list').innerHTML = appointments.map((appointment) => `
    <label class="appointment">
      <input type="checkbox" class="appointment-check" value="${appointment.AppointmentID}" checked>
      <span><strong>${appointment.PatientName}</strong><span>${appointment.AppointmentDate} at ${appointment.AppointmentTime} · ${appointment.DepartmentName || 'Department not set'}</span></span>
      <span class="badge">${appointment.Status}</span>
    </label>`).join('');
}

$('select-all').addEventListener('change', (event) => {
  document.querySelectorAll('.appointment-check').forEach((checkbox) => { checkbox.checked = event.target.checked; });
});

$('confirm-btn').addEventListener('click', async () => {
  const ids = [...document.querySelectorAll('.appointment-check:checked')].map((checkbox) => checkbox.value);
  const channels = [...document.querySelectorAll('input[name="channel"]:checked')].map((checkbox) => checkbox.value);
  const body = new FormData();
  body.append('csrf_token', csrf);
  body.append('action', 'execute');
  body.append('unavailability_id', unavailabilityId);
  body.append('template', $('template').value);
  ids.forEach((id) => body.append('appointment_ids[]', id));
  channels.forEach((channel) => body.append('channels[]', channel));

  const response = await fetch('api/schedule_disruptions.php', { method: 'POST', body });
  const data = await response.json();
  if (!data.success) {
    showMessage('review-message', data.message, true);
    return;
  }
  showMessage('review-message', `${data.message} ${data.sent} notification attempts succeeded; ${data.failed} failed.`);
  $('confirm-btn').disabled = true;
});
</script>
</body>
</html>
