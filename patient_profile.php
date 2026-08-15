<?php

session_start();

require_once 'includes/db.php';

if (!isset($_SESSION['UserID'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['UserID'];


?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — MediCare Patient Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/patient_dashboard.css">
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
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
        Dashboard
      </li>
      <li class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Appointments
      </li>
      <li class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
        Queue Status
      </li>
      <li class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
        View Results
      </li>
      <li class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Consultation History
      </li>
      <li class="nav-item">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
      </li>
      <li class="nav-item active">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </li>
    </ul>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">JD</div>
        <div>
          <div class="user-name">Juan Dela Cruz</div>
          <div class="user-role">Patient</div>
        </div>
      </div>
      <a class="sign-out">
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
        <div class="profile-avatar">JD</div>
        <div>
          <div class="profile-banner-name">Juan Dela Cruz</div>
          <div class="profile-banner-meta">
            juan.delacruz@example.com
            <span class="sep">|</span>
            <span class="id-pill">PT-00123</span>
          </div>
        </div>
      </div>
      <button class="btn-edit-profile" type="button">
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
            <div class="value">Juan Dela Cruz</div>
          </div>
          <div class="info-field">
            <div class="label">Patient ID</div>
            <div class="value">PT-00123</div>
          </div>

          <div class="info-field">
            <div class="label">Date Of Birth</div>
            <div class="value">1985-06-15</div>
          </div>
          <div class="info-field">
            <div class="label">Age</div>
            <div class="value">40 years old</div>
          </div>

          <div class="info-field">
            <div class="label">Sex</div>
            <div class="value">Male</div>
          </div>
          <div class="info-field">
            <div class="label">Contact Number</div>
            <div class="value">0912-3456-789</div>
          </div>

          <div class="info-field">
            <div class="label">Email Address</div>
            <div class="value">juan.delacruz@example.com</div>
          </div>
          <div class="info-field">
            <div class="label">Religion</div>
            <div class="value">Christian</div>
          </div>

          <div class="info-field">
            <div class="label">Civil Status</div>
            <div class="value">Married</div>
          </div>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Address</div>
            <div class="value">9 Central Ave, U.P. Campus, Quezon City</div>
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
            <div class="value">O+</div>
          </div>
          <div class="info-field">
            <div class="label">Allergies</div>
            <div class="tag-group">
              <span class="tag">Penicillin</span>
              <span class="tag">Seafood</span>
            </div>
          </div>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Family Medical History</div>
            <ul class="list-field">
              <li>Father — Hypertension</li>
              <li>Mother — Type 2 Diabetes</li>
            </ul>
          </div>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Surgical History</div>
            <ul class="list-field">
              <li>Appendectomy (2010)</li>
              <li>Fractured left arm (2015)</li>
            </ul>
          </div>

          <div class="info-field" style="grid-column: 1 / -1;">
            <div class="label">Current Medication</div>
            <div class="med-chip">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
              Lisinopril 10mg — once daily
            </div>
          </div>
        </div>

        <hr class="section-divider">

        <div class="label" style="margin-bottom: 14px;">Emergency Contact</div>
        <div class="emergency-contact-grid">
          <div>
            <div class="label">Name</div>
            <div class="value">Juana Dela Cruz</div>
          </div>
          <div>
            <div class="label">Phone</div>
            <div class="value">0915-2354-248</div>
          </div>
          <div>
            <div class="label">Relationship</div>
            <div class="value">Wife</div>
          </div>
        </div>
      </div>

    </div>
  </main>

</div>
</body>
</html>