<?php

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/db.php';
requireRole('Admin');

$flashMessage = '';
$flashType = 'success';

function emailExists(mysqli $conn, string $email, int $excludeUserId = 0): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT UserID FROM users WHERE Email = ? AND UserID <> ?');
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'si', $email, $excludeUserId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    return mysqli_num_rows($result) > 0;
}

function generatePatientEmail(mysqli $conn, string $firstName, string $lastName, string $phone): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '', $firstName . '.' . $lastName));
    if ($base === '') {
        $base = 'patient';
    }

    $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
    $suffix = $phoneDigits !== '' ? substr($phoneDigits, -6) : '000000';
    $emailBase = $base . $suffix;
    $email = $emailBase . '@mediacare.local';
    $counter = 1;

    while (emailExists($conn, $email)) {
        $email = $emailBase . $counter . '@mediacare.local';
        $counter++;
    }

    return $email;
}

function dateOfBirthFromAge(int $age): ?string
{
    if ($age <= 0) {
        return null;
    }

    $today = new DateTime('today');
    $today->modify('-' . $age . ' years');
    return $today->format('Y-m-d');
}

function fetchPatients(mysqli $conn): array
{
    $patients = [];
    $sql = "SELECT
                u.UserID,
                p.PatientID,
                u.FirstName,
                u.LastName,
                u.Email,
                u.Sex,
                u.ContactNumber,
                u.DateOfBirth,
                u.Status,
                p.BloodType,
                p.Allergies,
                COUNT(a.AppointmentID) AS Visits
            FROM patients p
            INNER JOIN users u ON u.UserID = p.UserID
            LEFT JOIN appointments a ON a.PatientID = p.PatientID
            WHERE u.RoleID = 3
              AND u.Status = 'Active'
            GROUP BY p.PatientID, u.UserID, u.FirstName, u.LastName, u.Email, u.Sex, u.ContactNumber, u.DateOfBirth, u.Status, p.BloodType, p.Allergies
            ORDER BY u.LastName, u.FirstName";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return $patients;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $fullName = trim(($row['FirstName'] ?? '') . ' ' . ($row['LastName'] ?? ''));
        $dob = $row['DateOfBirth'] ?? null;
        $age = 0;

        if (!empty($dob) && $dob !== '0000-00-00') {
            $dobDate = new DateTime($dob);
            $today = new DateTime('today');
            $age = (int) $dobDate->diff($today)->y;
        }

        $patients[] = [
            'userId' => (int) ($row['UserID'] ?? 0),
            'patientId' => (int) ($row['PatientID'] ?? 0),
            'name' => $fullName,
            'age' => $age,
            'gender' => (string) ($row['Sex'] ?? 'Male'),
            'phone' => (string) ($row['ContactNumber'] ?? 'N/A'),
            'blood' => (string) ($row['BloodType'] ?? 'A+'),
            'allergies' => (string) ($row['Allergies'] ?? 'None'),
            'visits' => (int) ($row['Visits'] ?? 0),
            'status' => (string) ($row['Status'] ?? 'Active'),
            'email' => (string) ($row['Email'] ?? '')
        ];
    }

    return $patients;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['patient-name'] ?? '');
    $age = (int) ($_POST['patient-age'] ?? 0);
    $gender = trim($_POST['patient-gender'] ?? 'Male');
    $phone = trim($_POST['patient-phone'] ?? '');
    $blood = trim($_POST['patient-blood'] ?? 'A+');
    $allergies = trim($_POST['patient-allergies'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'add' || $action === 'update') {
        if ($name === '') {
            $flashMessage = 'Please enter the patient\'s full name.';
            $flashType = 'error';
        } else {
            $parts = preg_split('/\s+/', $name, 2);
            $firstName = $parts[0] ?? '';
            $lastName = $parts[1] ?? '';

            if ($firstName === '' || $lastName === '') {
                $firstName = $name;
                $lastName = '';
            }

            $email = generatePatientEmail($conn, $firstName, $lastName, $phone);
            $tempPassword = bin2hex(random_bytes(6));
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
            $roleId = 3;
            $status = 'Active';
            $sex = $gender !== '' ? $gender : 'Male';
            $dateOfBirth = dateOfBirthFromAge($age);
            $phoneValue = $phone !== '' ? $phone : null;
            $bloodValue = $blood !== '' ? $blood : 'A+';
            $allergyValue = $allergies !== '' ? $allergies : 'None';

            mysqli_begin_transaction($conn);

            if ($action === 'add') {
                $userStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO users (RoleID, FirstName, LastName, Email, Password, Sex, DateOfBirth, ContactNumber, Status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                mysqli_stmt_bind_param(
                    $userStmt,
                    'issssssss',
                    $roleId,
                    $firstName,
                    $lastName,
                    $email,
                    $hashedPassword,
                    $sex,
                    $dateOfBirth,
                    $phoneValue,
                    $status
                );

                if (!mysqli_stmt_execute($userStmt)) {
                    mysqli_rollback($conn);
                    $flashMessage = 'Unable to create patient account.';
                    $flashType = 'error';
                } else {
                    $newUserId = (int) mysqli_insert_id($conn);
                    $patientStmt = mysqli_prepare(
                        $conn,
                        'INSERT INTO patients (UserID, BloodType, Allergies) VALUES (?, ?, ?)'
                    );
                    mysqli_stmt_bind_param($patientStmt, 'iss', $newUserId, $bloodValue, $allergyValue);

                    if (!mysqli_stmt_execute($patientStmt)) {
                        mysqli_rollback($conn);
                        $flashMessage = 'Unable to create patient record.';
                        $flashType = 'error';
                    } else {
                        mysqli_commit($conn);
                        $flashMessage = 'Patient added successfully.';
                    }
                }
            } else {
                if ($userId <= 0) {
                    $flashMessage = 'Patient record not found for update.';
                    $flashType = 'error';
                } else {
                    if ($dateOfBirth !== null) {
                        $userStmt = mysqli_prepare(
                            $conn,
                            'UPDATE users SET FirstName = ?, LastName = ?, Sex = ?, DateOfBirth = ?, ContactNumber = ?, Status = ? WHERE UserID = ?'
                        );
                        mysqli_stmt_bind_param(
                            $userStmt,
                            'ssssssi',
                            $firstName,
                            $lastName,
                            $sex,
                            $dateOfBirth,
                            $phoneValue,
                            $status,
                            $userId
                        );
                    } else {
                        $userStmt = mysqli_prepare(
                            $conn,
                            'UPDATE users SET FirstName = ?, LastName = ?, Sex = ?, ContactNumber = ?, Status = ? WHERE UserID = ?'
                        );
                        mysqli_stmt_bind_param(
                            $userStmt,
                            'sssssi',
                            $firstName,
                            $lastName,
                            $sex,
                            $phoneValue,
                            $status,
                            $userId
                        );
                    }

                    if (!mysqli_stmt_execute($userStmt)) {
                        mysqli_rollback($conn);
                        $flashMessage = 'Unable to update patient profile.';
                        $flashType = 'error';
                    } else {
                        $patientStmt = mysqli_prepare(
                            $conn,
                            'UPDATE patients SET BloodType = ?, Allergies = ? WHERE UserID = ?'
                        );
                        mysqli_stmt_bind_param($patientStmt, 'ssi', $bloodValue, $allergyValue, $userId);

                        if (!mysqli_stmt_execute($patientStmt)) {
                            mysqli_rollback($conn);
                            $flashMessage = 'Unable to update patient details.';
                            $flashType = 'error';
                        } else {
                            mysqli_commit($conn);
                            $flashMessage = 'Patient updated successfully.';
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete') {
        if ($userId <= 0) {
            $flashMessage = 'Invalid patient selection.';
            $flashType = 'error';
        } else {
            $newStatus = 'Inactive';
            $stmt = mysqli_prepare(
                $conn,
                'UPDATE users SET Status = ? WHERE UserID = ? AND RoleID = 3'
            );

            if (!$stmt) {
                $flashMessage = 'Unable to prepare patient removal.';
                $flashType = 'error';
            } else {
                mysqli_stmt_bind_param($stmt, 'si', $newStatus, $userId);

                if (mysqli_stmt_execute($stmt)) {
                    $flashMessage = 'Patient deactivated successfully.';
                    $flashType = 'success';
                } else {
                    $flashMessage = 'Unable to deactivate patient: ' . mysqli_error($conn);
                    $flashType = 'error';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$patients = fetchPatients($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Management — Curora Admin Portal</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/admin/admin_patient_management.css">
<link rel="stylesheet" href="../assets/css/admin/admin_notifications.css">
<script src="../assets/js/admin_notifications.js?v=20260924-clear-all" defer></script>
</head>
<body>
<div class="app">

     <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">
        <img src="../assets/images/curora-icon.png" alt="Curora">
      </div>
      <div class="brand-text">
        <div class="brand-title">Curora</div>
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
        <a href="admin_profile.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </li>
      <li class="nav-item">
        <a href="admin_system_settings.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; width:100%;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg>
          System Settings
        </a>
      </li>
    </ul>

    <?php include __DIR__ . '/../includes/admin_sidebar_footer.php'; ?>
  </aside>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <div class="staff-topbar">
      <div class="page-header">
        <h1>Patient Management</h1>
        <p id="patient-count">10 registered patients</p>
      </div>
      <?php include __DIR__ . '/../includes/admin_notification_widget.php'; ?>
    </div>

    <div class="panel">
      <?php if ($flashMessage !== ''): ?>
        <div class="flash-message" style="padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;font-weight:500;border:1px solid;
          <?php echo $flashType === 'error' ? 'background:#fee2e2;color:#991b1b;border-color:#fecaca;' : 'background:#dcfce7;color:#065f46;border-color:#bbf7d0;'; ?>">
          <?php echo htmlspecialchars($flashMessage); ?>
        </div>
      <?php endif; ?>

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

    <form id="patient-form" method="POST">
      <input type="hidden" id="edit-index" value="-1" />
      <input type="hidden" id="patient-action" name="action" value="add" />
      <input type="hidden" id="patient-user-id" name="user_id" value="" />

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
  let patients = <?php echo json_encode($patients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

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
    document.getElementById('patient-action').value = 'add';
    document.getElementById('patient-user-id').value = '';
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
      document.getElementById('patient-action').value = 'update';
      document.getElementById('patient-user-id').value = patientData.userId || '';
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
    filtered.forEach((p) => {
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
        const patient = patients[idx];
        if (!patient) return;

        if (!patient.userId || patient.userId <= 0) {
          alert('Cannot delete: invalid patient ID.');
          return;
        }

        if (confirm(`Are you sure you want to deactivate ${patient.name}?`)) {
          const params = new URLSearchParams();
          params.set('action', 'delete');
          params.set('user_id', String(patient.userId));

          fetch(window.location.pathname, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
          })
          .then(response => response.text())
          .then(data => {
            console.log('Server response:', data);
            window.location.reload();
          })
          .catch(error => {
            console.error('Delete error:', error);
            alert('Delete failed: ' + error.message);
          });
        }
      });
    });
  }

  // ================= SAVE PATIENT =================
  function savePatient() {
    const name = patientName.value.trim();
    if (!name) { alert('Please enter the patient\'s full name.'); return; }

    const params = new URLSearchParams();
    params.set('action', document.getElementById('patient-action').value || 'add');
    params.set('user_id', document.getElementById('patient-user-id').value || '');
    params.set('patient-name', patientName.value.trim());
    params.set('patient-age', patientAge.value || '0');
    params.set('patient-gender', patientGender.value);
    params.set('patient-phone', patientPhone.value.trim());
    params.set('patient-blood', patientBlood.value);
    params.set('patient-allergies', patientAllergies.value.trim());

    fetch(window.location.pathname, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: params.toString()
    })
    .then(() => {
      window.location.reload();
    })
    .catch(() => {
      window.location.reload();
    });
  }

  // ================= EVENT BINDING =================
  addBtn.addEventListener('click', () => openModal(null, -1));
  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

  // Only bind form submit — the button is type="submit" and will trigger this.
  // Do NOT also bind saveBtn's click, or savePatient() runs twice.
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