<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reschedule Appointment — Curora Healthcare</title>
<link rel="icon" type="image/png" href="../assets/images/favicon.png">
<link rel="stylesheet" href="../assets/css/patient/patient_dashboard.css">
</head>
<body>
<div class="app">
  <main class="main">
    <div class="page-header">
      <h1>Reschedule Your Appointment</h1>
      <p>Please select a new date and time for your appointment</p>
    </div>

    <div class="panel">
      <form method="POST" action="process_reschedule.php">
        <input type="hidden" name="appointment_id" value="<?php echo (int) $_GET['appointment_id'] ?? 0; ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? ''); ?>">

        <div class="form-row">
          <div class="form-group">
            <label>Patient Name</label>
            <input type="text" readonly value="<?php echo htmlspecialchars($_GET['patient_name'] ?? 'Patient'); ?>">
          </div>
          <div class="form-group">
            <label>Original Appointment</label>
            <input type="text" readonly value="<?php echo date('F j, Y', strtotime($_GET['original_date'] ?? '')) . ' at ' . date('g:i A', strtotime($_GET['original_time'] ?? '')); ?>">
          </div>
        </div>

        <div class="form-group">
          <label>New Appointment Date</label>
          <input type="date" name="new_date" required />
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>New Appointment Time</label>
            <input type="time" name="new_time" required />
          </div>
          <div class="form-group">
            <label>Reason for Reschedule</label>
            <input type="text" name="reason" placeholder="e.g. Conflict, Personal reason" required />
          </div>
        </div>

        <div class="modal-actions">
          <button type="submit" class="btn-primary-solid">Confirm Reschedule</button>
          <a href="index.php" class="btn-outline">Cancel</a>
        </div>
      </form>
    </div>
  </main>
</div>
</body>
</html>