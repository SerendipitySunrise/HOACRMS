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
      <li class="nav-item">
        <a href="patient_dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>
        Dashboard
      </li>
      <li class="nav-item active">
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
      <li class="nav-item">
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

    <!-- Upcoming -->
    <div class="section-panel">
    <div class="section-panel-title">Upcoming</div>

    <div class="appt-row-list">

        <?php
        $hasUpcoming = false;
        $today = date('Y-m-d');
        ?>

        <?php foreach ($appointments as $appointment): ?>

            <?php if ($appointment['AppointmentDate'] < $today) {
                continue;
            } ?>

            <?php
            $hasUpcoming = true;

            $formattedDate = date(
                'F d, Y',
                strtotime($appointment['AppointmentDate'])
            );

            $formattedTime = date(
                'g:i A',
                strtotime($appointment['AppointmentTime'])
            );
            ?>

            <div class="appt-row">

                <div class="appt-row-icon">
                    <svg viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="2"
                         stroke-linecap="round"
                         stroke-linejoin="round">

                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <path d="M16 2v4M8 2v4M3 10h18"/>

                    </svg>
                </div>

                <div class="appt-row-info">

                    <div class="title">
                        <?php
                        echo htmlspecialchars(
                            $appointment['Purpose'] ?: 'Medical Appointment'
                        );
                        ?>
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

                    <?php
                    $statusClass = match($appointment['Status']) {
                        'Pending'       => 'pending',
                        'Cancelled'     => 'cancelled',
                        'Completed'     => 'completed',
                        'Checked In'    => 'checked-in',
                        'Called'        => 'called',
                        'In Consultation' => 'in-consultation',
                        default         => 'pending'
                    };
                    ?>

                    <span class="pill-status <?php echo $statusClass; ?>">
                        <?php echo htmlspecialchars($appointment['Status']); ?>
                    </span>

                    <?php if (
                        $appointment['Status'] !== 'Cancelled' &&
                        $appointment['Status'] !== 'Completed' &&
                        $appointment['Status'] !== 'Checked In' &&
                        $appointment['Status'] !== 'In Consultation'
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


        <?php if (!$hasUpcoming): ?>

            <div class="appt-row">
                <div class="appt-row-info">
                    <div class="title">No upcoming appointments</div>
                    <div class="date">
                        You currently have no upcoming appointments.
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>
</div>

    <!-- Past Appointments -->
    <div class="section-panel">

    <div class="section-panel-title">
        Past Appointments
    </div>

    <div class="appt-row-list">

        <?php
        $hasPast = false;
        $today = date('Y-m-d');
        ?>

        <?php foreach ($appointments as $appointment): ?>

            <?php if ($appointment['AppointmentDate'] >= $today) {
                continue;
            } ?>

            <?php
            $hasPast = true;

            $formattedDate = date(
                'F d, Y',
                strtotime($appointment['AppointmentDate'])
            );
            ?>

            <div class="appt-row">

                <div class="appt-row-icon">

                    <svg viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="2"
                         stroke-linecap="round"
                         stroke-linejoin="round">

                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <path d="M16 2v4M8 2v4M3 10h18"/>

                    </svg>

                </div>

                <div class="appt-row-info">

                    <div class="title">
                        <?php
                        echo htmlspecialchars(
                            $appointment['Purpose'] ?: 'Medical Appointment'
                        );
                        ?>
                    </div>

                    <div class="date">
                        <?php echo htmlspecialchars($formattedDate); ?>
                        •
                        <?php echo htmlspecialchars($appointment['DepartmentName']); ?>
                    </div>

                </div>

                <div class="appt-row-actions">

                    <?php
                    $statusClass = match($appointment['Status']) {
                        'Pending'       => 'pending',
                        'Cancelled'     => 'cancelled',
                        'Completed'     => 'completed',
                        'Checked In'    => 'checked-in',
                        'Called'        => 'called',
                        'In Consultation' => 'in-consultation',
                        default         => 'pending'
                    };
                    ?>

                    <span class="pill-status <?php echo $statusClass; ?>">
                        <?php echo htmlspecialchars($appointment['Status']); ?>
                    </span>

                </div>

            </div>

        <?php endforeach; ?>


        <?php if (!$hasPast): ?>

            <div class="appt-row">

                <div class="appt-row-info">

                    <div class="title">
                        No past appointments
                    </div>

                    <div class="date">
                        Your appointment history will appear here.
                    </div>

                </div>

            </div>

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
</script>
</body>
</html>