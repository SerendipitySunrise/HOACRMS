<?php
session_start();

require_once __DIR__ . '/includes/db.php';

/*
|--------------------------------------------------------------------------
| CHECK LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['UserID'])) {
    header('Location: login.php?portal=patient');
    exit();
}

if (($_SESSION['RoleName'] ?? '') !== 'Patient') {
    header('Location: portal-select.php?action=login');
    exit();
}

$userID = (int) $_SESSION['UserID'];


/*
|--------------------------------------------------------------------------
| GET PATIENT ID
|--------------------------------------------------------------------------
*/

$patientStmt = mysqli_prepare(
    $conn,
    'SELECT 
        p.PatientID,
        p.UserID,
        u.FirstName,
        u.LastName
     FROM patients p
     INNER JOIN users u ON p.UserID = u.UserID
     WHERE p.UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param(
    $patientStmt,
    'i',
    $_SESSION['UserID']
);

mysqli_stmt_execute($patientStmt);

$patientResult = mysqli_stmt_get_result($patientStmt);

if (mysqli_num_rows($patientResult) === 0) {
    die('Patient record not found.');
}

$patient = mysqli_fetch_assoc($patientResult);

$patientID = (int) $patient['PatientID'];


/*
|--------------------------------------------------------------------------
| GET DEPARTMENTS
|--------------------------------------------------------------------------
*/

$departmentStmt = mysqli_prepare(
    $conn,
    'SELECT DepartmentID, DepartmentName
     FROM departments
     ORDER BY DepartmentName ASC'
);

mysqli_stmt_execute($departmentStmt);

$departmentResult = mysqli_stmt_get_result($departmentStmt);


/*
|--------------------------------------------------------------------------
| BOOK APPOINTMENT
|--------------------------------------------------------------------------
*/

$bookingMessage = '';
$bookingSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {

    $departmentID = (int) ($_POST['department_id'] ?? 0);
    $appointmentDate = trim($_POST['appointment_date'] ?? '');
    $appointmentTime = trim($_POST['appointment_time'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | VALIDATE INPUT
    |--------------------------------------------------------------------------
    */

    if ($departmentID <= 0) {

        $bookingMessage = 'Please select a department.';

    } elseif ($appointmentDate === '') {

        $bookingMessage = 'Please select an appointment date.';

    } elseif ($appointmentTime === '') {

        $bookingMessage = 'Please select an appointment time.';

    } elseif ($purpose === '') {

        $bookingMessage = 'Please enter the purpose of your appointment.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | FIND AVAILABLE STAFF/DOCTOR
        |--------------------------------------------------------------------------
        */

        $staffStmt = mysqli_prepare(
            $conn,
            'SELECT StaffID
             FROM staff
             WHERE DepartmentID = ?
             AND AvailabilityStatus = "Available"
             AND StaffRole = "Doctor"
             LIMIT 1'
        );

        mysqli_stmt_bind_param(
            $staffStmt,
            'i',
            $departmentID
        );

        mysqli_stmt_execute($staffStmt);

        $staffResult = mysqli_stmt_get_result($staffStmt);

        if (mysqli_num_rows($staffResult) === 0) {

            $bookingMessage =
                'No available doctor is currently assigned to this department.';

        } else {

            $staff = mysqli_fetch_assoc($staffResult);

            $staffID = (int) $staff['StaffID'];


            /*
            |--------------------------------------------------------------------------
            | CHECK IF TIME SLOT IS ALREADY BOOKED
            |--------------------------------------------------------------------------
            */

            $checkStmt = mysqli_prepare(
                $conn,
                'SELECT AppointmentID
                 FROM appointments
                 WHERE StaffID = ?
                 AND AppointmentDate = ?
                 AND AppointmentTime = ?
                 AND Status NOT IN ("Cancelled", "Completed")
                 LIMIT 1'
            );

            mysqli_stmt_bind_param(
                $checkStmt,
                'iss',
                $staffID,
                $appointmentDate,
                $appointmentTime
            );

            mysqli_stmt_execute($checkStmt);

            $checkResult = mysqli_stmt_get_result($checkStmt);


            if (mysqli_num_rows($checkResult) > 0) {

                $bookingMessage =
                    'This time slot is already booked. Please select another time.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | INSERT APPOINTMENT
                |--------------------------------------------------------------------------
                */

                $insertStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO appointments
                    (
                        PatientID,
                        StaffID,
                        DepartmentID,
                        AppointmentDate,
                        AppointmentTime,
                        Purpose,
                        Status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, "Pending")'
                );

                $purpose = trim($_POST['purpose'] ?? '');

                mysqli_stmt_bind_param(
                    $insertStmt,
                    'iiisss',
                    $patientID,
                    $staffID,
                    $departmentID,
                    $appointmentDate,
                    $appointmentTime,
                    $purpose
                );

                if (mysqli_stmt_execute($insertStmt)) {

                    $bookingSuccess = true;

                } else {

                    $bookingMessage =
                        'Unable to book the appointment. Please try again.';
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Book Appointment — MediCare Patient Portal</title>

<link rel="preconnect"
      href="https://fonts.googleapis.com">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
      rel="stylesheet">

<link rel="stylesheet"
      href="assets/css/patient_dashboard.css">

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

        <li class="nav-item">

            <a href="patient_dashboard.php"
               style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">

                Dashboard

            </a>

        </li>


        <li class="nav-item active">

            <a href="patient_appointment.php"
               style="text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px;">

                Appointments

            </a>

        </li>


        <li class="nav-item">
            Queue Status
        </li>

        <li class="nav-item">
            View Results
        </li>

        <li class="nav-item">
            Consultation History
        </li>

        <li class="nav-item">
            Notifications
        </li>

        <li class="nav-item">
            Profile
        </li>

    </ul>


    <div class="sidebar-footer">

        <div class="sidebar-user">

            <div class="user-avatar">

                <?php
                echo strtoupper(
                    substr($patient['FirstName'], 0, 1) .
                    substr($patient['LastName'], 0, 1)
                );
                ?>

            </div>

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


        <a href="logout.php"
           class="sign-out"
           style="text-decoration:none;">

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
            Appointments
        </h1>

        <p>
            Book and manage your appointments
        </p>

    </div>


    <!-- TABS -->

    <div class="tab-switch">

        <a href="patient_appointment.php"
           class="tab-btn"
           style="text-decoration:none;display:inline-block;">

            My Appointments

        </a>

        <button class="tab-btn active"
                type="button">

            Book New

        </button>

    </div>


<?php if ($bookingSuccess): ?>

<!-- =========================================================
     SUCCESS
========================================================= -->

<div class="success-wrap"
     style="display:flex;">

    <div class="success-card">

        <div class="success-check">

            ✓

        </div>

        <h2>
            Appointment Booked
        </h2>

        <p>
            Your appointment has been successfully submitted.
        </p>

        <div class="success-actions">

            <a href="patient_dashboard.php"
               class="btn-primary-solid"
               style="text-decoration:none;">

                Go to Dashboard

            </a>

            <a href="patient_appointment.php"
               class="btn-outline"
               style="text-decoration:none;">

                My Appointments

            </a>

        </div>

    </div>

</div>


<?php else: ?>


<?php if ($bookingMessage !== ''): ?>

<div style="
    background:#fee2e2;
    color:#991b1b;
    padding:14px;
    border-radius:8px;
    margin-bottom:20px;
">

    <?php
    echo htmlspecialchars($bookingMessage);
    ?>

</div>

<?php endif; ?>


<!-- =========================================================
     STEPPER
========================================================= -->

<div class="stepper">

    <div class="step active"
         id="step-ind-1">

        <div class="step-circle">
            <span>1</span>
        </div>

        <div class="step-label">
            Department
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
            Date & Time
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


<!-- =========================================================
     BOOKING FORM
========================================================= -->

<form method="POST"
      id="bookingForm">


<!-- =========================================================
     STEP 1
========================================================= -->

<div class="wizard-panel"
     id="step-1">

    <div class="wizard-panel-title">
        Select Department
    </div>


    <div class="dept-grid">

<?php if (mysqli_num_rows($departmentResult) > 0): ?>

<?php while ($department = mysqli_fetch_assoc($departmentResult)): ?>

<div class="dept-card"
     data-dept-id="<?php echo (int)$department['DepartmentID']; ?>"
     data-dept="<?php echo htmlspecialchars($department['DepartmentName']); ?>">

    <div class="dept-check">

        <svg viewBox="0 0 24 24"
             fill="none"
             stroke="currentColor"
             stroke-width="3">

            <polyline points="20 6 9 17 4 12"/>

        </svg>

    </div>


    <div class="dept-icon">

        <svg viewBox="0 0 24 24"
             fill="none"
             stroke="currentColor"
             stroke-width="2">

            <path d="M12 2v20"/>
            <path d="M2 12h20"/>

        </svg>

    </div>


    <div>

        <div class="dept-name">

            <?php
            echo htmlspecialchars(
                $department['DepartmentName']
            );
            ?>

        </div>

        <div class="dept-desc">
            Medical consultation
        </div>

    </div>

</div>

<?php endwhile; ?>

<?php else: ?>

<p>
    No departments are currently available.
</p>

<?php endif; ?>

    </div>


    <div class="wizard-footer">

        <button class="btn-primary-solid"
                type="button"
                onclick="nextFromDepartment()">

            Next: Choose Date & Time

        </button>

    </div>

</div>


<!-- =========================================================
     STEP 2
========================================================= -->

<div class="wizard-panel"
     id="step-2"
     style="display:none;">

    <div class="wizard-panel-title">
        Select Date & Time
    </div>


    <div class="datetime-grid">


        <!-- DATE -->

        <div>

            <div class="avail-days-label">

                Select Appointment Date

            </div>


            <div class="date-grid">

            </div>

        </div>


        <!-- TIME -->

        <div>

            <div class="timeslot-head">

                <div>

                    <div class="timeslot-label">
                        Time Slot
                    </div>

                    <div class="timeslot-sub">
                        Morning Slots
                    </div>

                </div>

            </div>


            <div class="slot-grid">

                <div class="slot-cell selected" data-slot="06:00:00">
                    06:00 AM
                </div>

                <div class="slot-cell" data-slot="06:30:00">
                    06:30 AM
                </div>

                <div class="slot-cell" data-slot="07:00:00">
                    07:00 AM
                </div>
                <div class="slot-cell" data-slot="08:00:00">
                    08:00 AM
                </div>

                <div class="slot-cell" data-slot="08:30:00">
                    08:30 AM
                </div>

                <div class="slot-cell" data-slot="09:00:00">
                    09:00 AM
                </div>

                <div class="slot-cell" data-slot="09:30:00">
                    09:30 AM
                </div>

                <div class="slot-cell" data-slot="10:00:00">
                    10:00 AM
                </div>

                <div class="slot-cell" data-slot="10:30:00">
                    10:30 AM
                </div>

                <div class="slot-cell" data-slot="11:00:00">
                    11:00 AM
                </div>

                <div class="slot-cell" data-slot="11:30:00">
                    11:30 AM
                </div>

            </div>

        </div>

    </div>


    <!-- PURPOSE -->

    <div style="margin-top:25px;">

        <label for="purpose"
               style="font-weight:600;display:block;margin-bottom:8px;">

            Purpose of Appointment

        </label>

        <input type="text"
               id="purpose"
               name="purpose"
               placeholder="Enter the purpose of your appointment"
               maxlength="255"
               required
               style="
                   width:100%;
                   padding:12px;
                   border:1px solid #ddd;
                   border-radius:8px;
               ">

    </div>


    <div class="wizard-footer split">

        <button class="btn-outline"
                type="button"
                onclick="goStep(1)">

            Back

        </button>


        <button
            class="btn-primary-solid"
            type="button"
            onclick="nextToConfirm()"
        >
            Next: Review

            <svg viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"/>
                <polyline points="12 5 19 12 12 19"/>
            </svg>
        </button>

    </div>

</div>


<!-- =========================================================
     STEP 3
========================================================= -->

<div class="wizard-panel"
     id="step-3"
     style="display:none;">

    <div class="wizard-panel-title">
        Confirm Your Appointment
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
            <span class="label">Purpose</span>
            <span class="value" id="confirm-purpose"></span>
        </div>

    </div>


    <!-- HIDDEN VALUES SENT TO PHP -->

    <input type="hidden"
           name="department_id"
           id="department_id">


    <input type="hidden"
           name="appointment_date"
           id="appointment_date">


    <input type="hidden"
           name="appointment_time"
           id="appointment_time">


    <div class="wizard-footer split">

        <button class="btn-outline"
                type="button"
                onclick="goStep(2)">

            Back

        </button>


        <button class="btn-primary-solid" type="button" onclick="confirmBooking()">
            Confirm Booking
        </button>

    </div>

</div>

</form>


<?php endif; ?>

</main>

</div>


<!-- =========================================================
     JAVASCRIPT
========================================================= -->

<script>



let selectedDeptID = null;
let selectedDept = "";
let selectedDate = "";
let selectedDisplayDate = "";
let selectedSlot = "";
loadBookedSlots();



const firstDate = document.querySelector('.date-cell.selected');

if (firstDate) {
    selectedDate = firstDate.getAttribute('data-date');
}


const firstSlot = document.querySelector('.slot-cell.selected');

if (firstSlot) {
    selectedSlot = firstSlot.getAttribute('data-slot');
}

/*
|--------------------------------------------------------------------------
| DEPARTMENT SELECTION
|--------------------------------------------------------------------------
*/

document.querySelectorAll('.dept-card').forEach(card => {

    card.addEventListener('click', function () {

        document
            .querySelectorAll('.dept-card')
            .forEach(c => c.classList.remove('selected'));

        this.classList.add('selected');

        selectedDeptID =
            this.getAttribute('data-dept-id');

        selectedDept =
            this.getAttribute('data-dept');

    });

});


/*
|--------------------------------------------------------------------------
| DATE SELECTION
|--------------------------------------------------------------------------
*/

document.querySelectorAll('.date-cell').forEach(cell => {

    cell.addEventListener('click', () => {

        document
            .querySelectorAll('.date-cell')
            .forEach(c => c.classList.remove('selected'));

        cell.classList.add('selected');

        selectedDate = cell.getAttribute('data-date');

    });

});


/*
|--------------------------------------------------------------------------
| TIME SELECTION
|--------------------------------------------------------------------------
*/


document.querySelectorAll('.slot-cell').forEach(cell => {

    if (cell.classList.contains('disabled')) {
    return;
}

    cell.addEventListener('click', () => {

        document
            .querySelectorAll('.slot-cell')
            .forEach(c => c.classList.remove('selected'));

        cell.classList.add('selected');

        selectedSlot = cell.getAttribute('data-slot');

    });

});

function loadBookedSlots() {
    if (selectedDeptID === null || selectedDate === '') {
        return;
    }

    const formData = new FormData();
    formData.append('department_id', selectedDeptID);
    formData.append('appointment_date', selectedDate);

    fetch('get_booked_slots.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Unable to load available time slots.');
            return;
        }

        document.querySelectorAll('.slot-cell').forEach(cell => {
            const isBooked = data.slots.includes(cell.dataset.slot);

            cell.classList.toggle('disabled', isBooked);

            if (isBooked && cell.classList.contains('selected')) {
                cell.classList.remove('selected');
                selectedSlot = '';
            }
        });
    });
}
/*
|--------------------------------------------------------------------------
| NEXT FROM DEPARTMENT
|--------------------------------------------------------------------------
*/

function nextFromDepartment() {

    if (selectedDeptID === null) {

        alert('Please select a department first.');

        return;
    }

    goStep(2);
}


/*
|--------------------------------------------------------------------------
| NEXT TO CONFIRM
|--------------------------------------------------------------------------
*/

function nextToConfirm() {

    // Check department
    if (selectedDeptID === null) {
        alert('Please select a department first.');
        return;
    }

    // Check date
    if (selectedDate === "") {
        alert('Please select an appointment date.');
        return;
        
    }

    // Check time
    if (selectedSlot === "") {
        alert('Please select an appointment time.');
        return;
    }

    // Get purpose
    const purpose = document
        .getElementById('purpose')
        .value
        .trim();

    // Check purpose
    if (purpose === "") {
        alert('Please enter the purpose of your appointment.');
        return;
    }

    // Display information on confirmation page
    document.getElementById('confirm-dept').textContent =
        selectedDept;

    document.getElementById('confirm-date').textContent =
        selectedDate;

    document.getElementById('confirm-time').textContent =
        selectedSlot;

    document.getElementById('confirm-purpose').textContent =
        purpose;

    // Go to Step 3
    goStep(3);
}

function confirmBooking() {
    const purpose = document.getElementById('purpose').value.trim();

    if (selectedDeptID === null || !selectedDate || !selectedSlot || !purpose) {
        alert('Please complete all appointment details.');
        return;
    }

    const formData = new FormData();

    formData.append('department_id', selectedDeptID);
    formData.append('appointment_date', selectedDate);
    formData.append('appointment_time', selectedSlot);
    formData.append('purpose', purpose);

    fetch('save_appointment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Unable to book the appointment.');
            return;
        }

        alert(data.message || 'Appointment booked successfully.');
        window.location.href = 'patient_appointment.php';
    })
    .catch(() => {
        alert('Something went wrong while booking the appointment.');
    });
}



/*
|--------------------------------------------------------------------------
| STEPPER
|--------------------------------------------------------------------------
*/

function updateStepper(step) {

    for (let i = 1; i <= 3; i++) {

        const ind =
            document.getElementById('step-ind-' + i);

        ind.classList.remove(
            'active',
            'done'
        );

        if (i < step) {

            ind.classList.add('done');

        } else if (i === step) {

            ind.classList.add('active');

        }

    }


    for (let i = 1; i <= 2; i++) {

        const line =
            document.getElementById('line-' + i);

        line.classList.toggle(
            'done',
            i < step
        );

    }

}


/*
|--------------------------------------------------------------------------
| CHANGE STEP
|--------------------------------------------------------------------------
*/

function goStep(step) {

    for (let i = 1; i <= 3; i++) {

        const panel =
            document.getElementById('step-' + i);

        if (panel) {

            panel.style.display = 'none';

        }

    }


    updateStepper(step);


    const selectedPanel =
        document.getElementById('step-' + step);

    if (selectedPanel) {

        selectedPanel.style.display = 'block';

    }


    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });

}

</script>

</body>
</html>