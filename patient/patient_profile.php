<?php

session_start();

require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header("Location: ../auth/login.php");
    exit();
}

$userID = $_SESSION['UserID'];

/*
 * ASSUMPTIONS ABOUT includes/db.php — adjust the connection lines below if these don't match:
 *  - Uses mysqli and exposes the connection as $conn (the most common convention).
 *    If your db.php instead exposes $mysqli, $db, or a PDO object ($pdo), just rename
 *    $conn below (or swap the query block for PDO — see note further down).
 *
 * ASSUMPTIONS ABOUT DATA FORMAT (patients table has these as single TEXT columns,
 * so multiple entries need a separator convention):
 *  - Allergies: comma-separated, e.g. "Penicillin, Seafood"
 *  - FamilyMedicalHistory: one entry per line, e.g. "Father — Hypertension\nMother — Type 2 Diabetes"
 *  - PastMedicalCondition: one entry per line (used for the "Surgical History" panel,
 *    since the schema has no separate surgical-history column — rename the panel
 *    heading if PastMedicalCondition means something different in your app)
 *  - CurrentMedication: one entry per line
 * If your data actually uses different separators, tweak the explode() calls below.
 */

$sql = "SELECT
            u.UserID, u.FirstName, u.MiddleName, u.LastName, u.Email, u.Sex,
            u.DateOfBirth, u.ContactNumber, u.Address, u.ProfilePhoto,
            p.PatientID, p.CivilStatus, p.Religion, p.IsPWD, p.DisabilityType,
            p.BloodType, p.Allergies, p.PastMedicalCondition, p.CurrentMedication,
            p.FamilyMedicalHistory, p.EmergencyContactName, p.EmergencyContactNo,
            p.EmergencyRelation
        FROM patients p
        INNER JOIN users u ON p.UserID = u.UserID
        WHERE p.UserID = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();
$patient = $result->fetch_assoc();
$stmt->close();

if (!$patient) {
    // No patient record found for this logged-in user.
    die("Patient profile not found.");
}

// --- Derived / formatted values ---

$fullName = trim($patient['FirstName'] . ' '
    . ($patient['MiddleName'] ? $patient['MiddleName'] . ' ' : '')
    . $patient['LastName']);

$initials = strtoupper(substr($patient['FirstName'], 0, 1) . substr($patient['LastName'], 0, 1));

$patientIdFormatted = 'PT-' . str_pad($patient['PatientID'], 5, '0', STR_PAD_LEFT);

$age = '—';
if (!empty($patient['DateOfBirth'])) {
    $dob = new DateTime($patient['DateOfBirth']);
    $today = new DateTime('today');
    $age = $dob->diff($today)->y . ' years old';
}

$dobFormatted = !empty($patient['DateOfBirth'])
    ? (new DateTime($patient['DateOfBirth']))->format('Y-m-d')
    : 'Not specified';

function field($value, $fallback = 'Not specified') {
    $value = trim((string) $value);
    return $value !== '' ? htmlspecialchars($value) : $fallback;
}

// Allergies -> comma separated tags
$allergyTags = [];
if (!empty($patient['Allergies'])) {
    foreach (explode(',', $patient['Allergies']) as $a) {
        $a = trim($a);
        if ($a !== '') $allergyTags[] = $a;
    }
}

// Family medical history -> one item per line
$familyHistoryLines = [];
if (!empty($patient['FamilyMedicalHistory'])) {
    foreach (preg_split('/\r\n|\r|\n/', $patient['FamilyMedicalHistory']) as $line) {
        $line = trim($line);
        if ($line !== '') $familyHistoryLines[] = $line;
    }
}

// Past medical condition -> displayed as "Surgical History" list, one item per line
$pastConditionLines = [];
if (!empty($patient['PastMedicalCondition'])) {
    foreach (preg_split('/\r\n|\r|\n/', $patient['PastMedicalCondition']) as $line) {
        $line = trim($line);
        if ($line !== '') $pastConditionLines[] = $line;
    }
}

// Current medication -> one chip per line
$medicationLines = [];
if (!empty($patient['CurrentMedication'])) {
    foreach (preg_split('/\r\n|\r|\n/', $patient['CurrentMedication']) as $line) {
        $line = trim($line);
        if ($line !== '') $medicationLines[] = $line;
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — MediCare Patient Portal</title>
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
      <li class="nav-item">
        <a href="patient_dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
        Dashboard
      </li>
      <li class="nav-item">
        <a href="patient_appointment.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Appointments
      </li>
      <li class="nav-item">
        <a href="queue_status.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
        Queue Status
      </li>
      <li class="nav-item">
        <a href="view_results.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
        View Results
      </li>
      <li class="nav-item">
        <a href="consultation_history.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Consultation History
      </li>
      <li class="nav-item">
        <a href="notifications.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
      </li>
      <li class="nav-item active">
        <a href="patient_profile.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <?php if (!empty($patient['ProfilePhoto'])): ?>
        <div class="user-avatar"><img src="../<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Photo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;"></div>
        <?php else: ?>
        <div class="user-avatar"><?php echo $initials; ?></div>
        <?php endif; ?>
        <div>
          <div class="user-name"><?php echo field($fullName); ?></div>
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
      <h1>My Profile</h1>
      <p>Manage your personal and health information</p>
    </div>

    <!-- Profile banner -->
    <div class="profile-banner">
      <div class="profile-banner-info">
        <div class="avatar-upload-wrapper" id="avatarWrapper">
          <?php if (!empty($patient['ProfilePhoto'])): ?>
          <img class="profile-avatar profile-avatar-photo" id="avatarDisplay" src="../<?php echo htmlspecialchars($patient['ProfilePhoto']); ?>" alt="Profile Photo">
          <?php else: ?>
          <div class="profile-avatar" id="avatarDisplay"><?php echo $initials; ?></div>
          <?php endif; ?>
          <div class="avatar-hover-overlay">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
          </div>
          <input type="file" id="avatarInput" accept="image/*" style="display:none;">
        </div>
        <div>
          <div class="profile-banner-name"><?php echo field($fullName); ?></div>
          <div class="profile-banner-meta">
            <?php echo field($patient['Email']); ?>
            <span class="sep">|</span>
            <span class="id-pill"><?php echo $patientIdFormatted; ?></span>
          </div>
        </div>
      </div>
      <button class="btn-edit-profile" type="button" onclick="openEditModal()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>
        Edit Profile
      </button>
    </div>

    <!-- Profile grid -->
    <div class="profile-grid">

      <!-- Basic Information -->
      <div class="panel">
        <div class="profile-panel-title">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <h2>Basic Information</h2>
        </div>

        <div class="info-fields">
          <div class="info-field">
            <div class="label">Full Name</div>
            <div class="value"><?php echo field($fullName); ?></div>
          </div>
          <div class="info-field">
            <div class="label">Patient ID</div>
            <div class="value"><?php echo $patientIdFormatted; ?></div>
          </div>

          <div class="info-field">
            <div class="label">Date Of Birth</div>
            <div class="value"><?php echo field($dobFormatted); ?></div>
          </div>
          <div class="info-field">
            <div class="label">Age</div>
            <div class="value"><?php echo field($age); ?></div>
          </div>

          <div class="info-field">
            <div class="label">Sex</div>
            <div class="value"><?php echo field($patient['Sex']); ?></div>
          </div>
          <div class="info-field">
            <div class="label">Contact Number</div>
            <div class="value"><?php echo field($patient['ContactNumber']); ?></div>
          </div>

          <div class="info-field">
            <div class="label">Email Address</div>
            <div class="value"><?php echo field($patient['Email']); ?></div>
          </div>
          <div class="info-field">
            <div class="label">Religion</div>
            <div class="value"><?php echo field($patient['Religion']); ?></div>
          </div>

          <div class="info-field">
            <div class="label">Civil Status</div>
            <div class="value"><?php echo field($patient['CivilStatus']); ?></div>
          </div>

          <?php if ($patient['IsPWD']): ?>
          <div class="info-field">
            <div class="label">Disability Type</div>
            <div class="value"><?php echo field($patient['DisabilityType']); ?></div>
          </div>
          <?php endif; ?>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Address</div>
            <div class="value"><?php echo field($patient['Address']); ?></div>
          </div>
        </div>
      </div>

      <!-- Health Information -->
      <div class="panel">
        <div class="profile-panel-title">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          <h2>Health Information</h2>
        </div>

        <div class="info-fields">
          <div class="info-field">
            <div class="label">Blood Type</div>
            <div class="value"><?php echo field($patient['BloodType']); ?></div>
          </div>
          <div class="info-field">
            <div class="label">Allergies</div>
            <?php if (!empty($allergyTags)): ?>
            <div class="tag-group">
              <?php foreach ($allergyTags as $tag): ?>
              <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="value">None reported</div>
            <?php endif; ?>
          </div>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Family Medical History</div>
            <?php if (!empty($familyHistoryLines)): ?>
            <ul class="list-field">
              <?php foreach ($familyHistoryLines as $line): ?>
              <li><?php echo htmlspecialchars($line); ?></li>
              <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="value">None reported</div>
            <?php endif; ?>
          </div>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Surgical / Medical History</div>
            <?php if (!empty($pastConditionLines)): ?>
            <ul class="list-field">
              <?php foreach ($pastConditionLines as $line): ?>
              <li><?php echo htmlspecialchars($line); ?></li>
              <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <div class="value">None reported</div>
            <?php endif; ?>
          </div>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Current Medication</div>
            <?php if (!empty($medicationLines)): ?>
              <?php foreach ($medicationLines as $line): ?>
              <div class="med-chip">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                <?php echo htmlspecialchars($line); ?>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
            <div class="value">None reported</div>
            <?php endif; ?>
          </div>
        </div>

        <hr class="section-divider">

        <div class="label" style="margin-bottom: 14px;">Emergency Contact</div>
        <div class="emergency-contact-grid">
          <div>
            <div class="label">Name</div>
            <div class="value"><?php echo field($patient['EmergencyContactName']); ?></div>
          </div>
          <div>
            <div class="label">Phone</div>
            <div class="value"><?php echo field($patient['EmergencyContactNo']); ?></div>
          </div>
          <div>
            <div class="label">Relationship</div>
            <div class="value"><?php echo field($patient['EmergencyRelation']); ?></div>
          </div>
        </div>
      </div>

    </div>
  </main>

</div>

<!-- Edit Profile Modal -->
<div class="modal-overlay" id="editProfileModal">
  <div class="modal">
    <div class="modal-header">
      <h2>Edit Profile</h2>
      <button class="modal-close" onclick="closeEditModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-tabs">
        <button class="modal-tab active" onclick="switchTab('basic')">Basic Information</button>
        <button class="modal-tab" onclick="switchTab('health')">Health Information</button>
      </div>

      <form id="editProfileForm">
        <!-- Basic Information Tab -->
        <div class="tab-content active" id="tab-basic">
          <div class="form-grid">
            <div class="form-group">
              <label for="FirstName">First Name *</label>
              <input type="text" id="FirstName" name="FirstName" value="<?php echo htmlspecialchars($patient['FirstName']); ?>" required>
            </div>
            <div class="form-group">
              <label for="MiddleName">Middle Name</label>
              <input type="text" id="MiddleName" name="MiddleName" value="<?php echo htmlspecialchars($patient['MiddleName'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label for="LastName">Last Name *</label>
              <input type="text" id="LastName" name="LastName" value="<?php echo htmlspecialchars($patient['LastName']); ?>" required>
            </div>
            <div class="form-group">
              <label for="Email">Email *</label>
              <input type="email" id="Email" name="Email" value="<?php echo htmlspecialchars($patient['Email']); ?>" required>
            </div>
            <div class="form-group">
              <label for="Sex">Sex</label>
              <select id="Sex" name="Sex">
                <option value="">Select</option>
                <option value="Male" <?php echo ($patient['Sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                <option value="Female" <?php echo ($patient['Sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
              </select>
            </div>
            <div class="form-group">
              <label for="DateOfBirth">Date of Birth</label>
              <input type="date" id="DateOfBirth" name="DateOfBirth" value="<?php echo htmlspecialchars($dobFormatted); ?>">
            </div>
            <div class="form-group">
              <label for="ContactNumber">Contact Number</label>
              <input type="tel" id="ContactNumber" name="ContactNumber" value="<?php echo htmlspecialchars($patient['ContactNumber'] ?? ''); ?>" placeholder="09XXXXXXXXX">
            </div>
            <div class="form-group">
              <label for="Religion">Religion</label>
              <input type="text" id="Religion" name="Religion" value="<?php echo htmlspecialchars($patient['Religion'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label for="CivilStatus">Civil Status</label>
              <select id="CivilStatus" name="CivilStatus">
                <option value="">Select</option>
                <option value="Single" <?php echo ($patient['CivilStatus'] === 'Single') ? 'selected' : ''; ?>>Single</option>
                <option value="Married" <?php echo ($patient['CivilStatus'] === 'Married') ? 'selected' : ''; ?>>Married</option>
                <option value="Widowed" <?php echo ($patient['CivilStatus'] === 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                <option value="Separated" <?php echo ($patient['CivilStatus'] === 'Separated') ? 'selected' : ''; ?>>Separated</option>
                <option value="Divorced" <?php echo ($patient['CivilStatus'] === 'Divorced') ? 'selected' : ''; ?>>Divorced</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label for="Address">Address</label>
              <textarea id="Address" name="Address" rows="2"><?php echo htmlspecialchars($patient['Address'] ?? ''); ?></textarea>
            </div>
          </div>
        </div>

        <!-- Health Information Tab -->
        <div class="tab-content" id="tab-health">
          <div class="form-grid">
            <div class="form-group">
              <label for="BloodType">Blood Type</label>
              <select id="BloodType" name="BloodType">
                <option value="">Select</option>
                <option value="A+" <?php echo ($patient['BloodType'] === 'A+') ? 'selected' : ''; ?>>A+</option>
                <option value="A-" <?php echo ($patient['BloodType'] === 'A-') ? 'selected' : ''; ?>>A-</option>
                <option value="B+" <?php echo ($patient['BloodType'] === 'B+') ? 'selected' : ''; ?>>B+</option>
                <option value="B-" <?php echo ($patient['BloodType'] === 'B-') ? 'selected' : ''; ?>>B-</option>
                <option value="AB+" <?php echo ($patient['BloodType'] === 'AB+') ? 'selected' : ''; ?>>AB+</option>
                <option value="AB-" <?php echo ($patient['BloodType'] === 'AB-') ? 'selected' : ''; ?>>AB-</option>
                <option value="O+" <?php echo ($patient['BloodType'] === 'O+') ? 'selected' : ''; ?>>O+</option>
                <option value="O-" <?php echo ($patient['BloodType'] === 'O-') ? 'selected' : ''; ?>>O-</option>
              </select>
            </div>
            <div class="form-group full-width">
              <label for="Allergies">Allergies <small>(comma-separated)</small></label>
              <input type="text" id="Allergies" name="Allergies" value="<?php echo htmlspecialchars($patient['Allergies'] ?? ''); ?>" placeholder="e.g. Penicillin, Seafood">
            </div>
            <div class="form-group full-width">
              <label for="FamilyMedicalHistory">Family Medical History <small>(one per line)</small></label>
              <textarea id="FamilyMedicalHistory" name="FamilyMedicalHistory" rows="3"><?php echo htmlspecialchars($patient['FamilyMedicalHistory'] ?? ''); ?></textarea>
            </div>
            <div class="form-group full-width">
              <label for="PastMedicalCondition">Surgical / Medical History <small>(one per line)</small></label>
              <textarea id="PastMedicalCondition" name="PastMedicalCondition" rows="3"><?php echo htmlspecialchars($patient['PastMedicalCondition'] ?? ''); ?></textarea>
            </div>
            <div class="form-group full-width">
              <label for="CurrentMedication">Current Medication <small>(one per line)</small></label>
              <textarea id="CurrentMedication" name="CurrentMedication" rows="3"><?php echo htmlspecialchars($patient['CurrentMedication'] ?? ''); ?></textarea>
            </div>
            <div class="form-group full-width">
              <label for="EmergencyContactName">Emergency Contact Name</label>
              <input type="text" id="EmergencyContactName" name="EmergencyContactName" value="<?php echo htmlspecialchars($patient['EmergencyContactName'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label for="EmergencyContactNo">Emergency Contact Phone</label>
              <input type="tel" id="EmergencyContactNo" name="EmergencyContactNo" value="<?php echo htmlspecialchars($patient['EmergencyContactNo'] ?? ''); ?>" placeholder="09XXXXXXXXX">
            </div>
            <div class="form-group">
              <label for="EmergencyRelation">Relationship</label>
              <input type="text" id="EmergencyRelation" name="EmergencyRelation" value="<?php echo htmlspecialchars($patient['EmergencyRelation'] ?? ''); ?>" placeholder="e.g. Spouse, Parent">
            </div>
          </div>
        </div>

        <div class="form-message" id="formMessage"></div>

        <div class="modal-footer">
          <button type="button" class="btn-outline" onclick="closeEditModal()">Cancel</button>
          <button type="submit" class="btn-primary-solid" id="saveBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEditModal() {
  document.getElementById('editProfileModal').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeEditModal() {
  document.getElementById('editProfileModal').classList.remove('active');
  document.body.style.overflow = '';
  document.getElementById('formMessage').style.display = 'none';
}

function switchTab(tab) {
  document.querySelectorAll('.modal-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

  if (tab === 'basic') {
    document.querySelectorAll('.modal-tab')[0].classList.add('active');
    document.getElementById('tab-basic').classList.add('active');
  } else {
    document.querySelectorAll('.modal-tab')[1].classList.add('active');
    document.getElementById('tab-health').classList.add('active');
  }
}

document.getElementById('editProfileForm').addEventListener('submit', function(e) {
  e.preventDefault();

  const form = this;
  const saveBtn = document.getElementById('saveBtn');
  const messageEl = document.getElementById('formMessage');

  saveBtn.disabled = true;
  saveBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg> Saving...';

  const formData = new FormData(form);

  fetch('update_profile.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    messageEl.textContent = data.message;
    messageEl.className = 'form-message ' + (data.success ? 'success' : 'error');
    messageEl.style.display = 'block';

    if (data.success) {
      setTimeout(() => {
        location.reload();
      }, 1500);
    }
  })
  .catch(() => {
    messageEl.textContent = 'An error occurred. Please try again.';
    messageEl.className = 'form-message error';
    messageEl.style.display = 'block';
  })
  .finally(() => {
    saveBtn.disabled = false;
    saveBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Changes';
  });
});

document.getElementById('editProfileModal').addEventListener('click', function(e) {
  if (e.target === this) closeEditModal();
});

// Avatar upload
const avatarWrapper = document.getElementById('avatarWrapper');
const avatarInput = document.getElementById('avatarInput');
const avatarDisplay = document.getElementById('avatarDisplay');

avatarWrapper.addEventListener('click', function() {
  avatarInput.click();
});

avatarInput.addEventListener('change', function() {
  const file = this.files[0];
  if (!file) return;

  if (file.size > 2 * 1024 * 1024) {
    alert('Image must be 2MB or smaller.');
    return;
  }

  const reader = new FileReader();
  reader.onload = function(e) {
    if (avatarDisplay.tagName === 'IMG') {
      avatarDisplay.src = e.target.result;
    } else {
      const img = document.createElement('img');
      img.className = 'profile-avatar profile-avatar-photo';
      img.id = 'avatarDisplay';
      img.src = e.target.result;
      img.alt = 'Profile Photo';
      avatarDisplay.replaceWith(img);
    }
  };
  reader.readAsDataURL(file);

  const formData = new FormData();
  formData.append('avatar', file);

  fetch('../upload_avatar.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success && data.url) {
      const img = document.getElementById('avatarDisplay');
      if (img.tagName === 'IMG') {
        img.src = '../' + data.url + '?t=' + Date.now();
      }
    } else {
      alert(data.message || 'Failed to upload photo.');
    }
  })
  .catch(() => {
    alert('An error occurred while uploading.');
  });
});
</script>

</body>
</html>