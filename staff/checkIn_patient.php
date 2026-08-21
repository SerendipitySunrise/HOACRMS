<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

// ======================================================
// AUTHENTICATION
// ======================================================

// Make sure user is logged in
if (!isset($_SESSION['UserID'])) {
    header('Location: ../auth/login.php?portal=staff');
    exit();
}

// Make sure this is a Staff account
if (!isset($_SESSION['RoleName']) || $_SESSION['RoleName'] !== 'Staff') {
    header('Location: ../auth/login.php?portal=staff');
    exit();
}

$today = date('Y-m-d');

$message = '';
$messageType = '';

// ======================================================
// GET CURRENT LOGGED-IN STAFF MEMBER
// ======================================================

$currentUserID = (int) $_SESSION['UserID'];

$staffStmt = mysqli_prepare(
    $conn,
    'SELECT StaffID
     FROM staff
     WHERE UserID = ?
     LIMIT 1'
);

mysqli_stmt_bind_param(
    $staffStmt,
    'i',
    $currentUserID
);

mysqli_stmt_execute($staffStmt);

$staffResult = mysqli_stmt_get_result($staffStmt);

$currentStaff = mysqli_fetch_assoc($staffResult);

mysqli_stmt_close($staffStmt);

if (!$currentStaff) {
    die('Staff profile not found. Please contact the administrator.');
}

$currentStaffID = (int) $currentStaff['StaffID'];

// ======================================================
// CHECK-IN ACTION
// Existing scheduled patient
// ======================================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'checkin'
) {

    $appointmentID = (int) ($_POST['appointment_id'] ?? 0);

    if ($appointmentID <= 0) {

        $message = 'Invalid appointment.';
        $messageType = 'error';

    } else {

        // --------------------------------------------------
        // 1. Get today's appointment
        // --------------------------------------------------
        $appointmentStmt = mysqli_prepare(
            $conn,
            "SELECT
                AppointmentID,
                PatientID,
                DepartmentID,
                AppointmentDate,
                AppointmentTime,
                Purpose,
                Status
             FROM appointments
             WHERE AppointmentID = ?
             AND AppointmentDate = ?
             AND Status NOT IN ('Cancelled', 'Completed')
             LIMIT 1"
        );

        mysqli_stmt_bind_param(
            $appointmentStmt,
            'is',
            $appointmentID,
            $today
        );

        mysqli_stmt_execute($appointmentStmt);

        $appointmentResult = mysqli_stmt_get_result($appointmentStmt);
        $appointment = mysqli_fetch_assoc($appointmentResult);

        if (!$appointment) {

            $message = 'Appointment not found for today.';
            $messageType = 'error';

        } else {

            // --------------------------------------------------
            // 2. Check if patient is already in today's queue
            // --------------------------------------------------
            $existingQueueStmt = mysqli_prepare(
                $conn,
                "SELECT
                    QueueID,
                    QueueNumber,
                    Status
                 FROM queue
                 WHERE AppointmentID = ?
                 AND QueueDate = ?
                 LIMIT 1"
            );

            mysqli_stmt_bind_param(
                $existingQueueStmt,
                'is',
                $appointmentID,
                $today
            );

            mysqli_stmt_execute($existingQueueStmt);

            $existingQueueResult = mysqli_stmt_get_result(
                $existingQueueStmt
            );

            $existingQueue = mysqli_fetch_assoc(
                $existingQueueResult
            );

            if ($existingQueue) {

                // Already checked in
                $existingNumber = str_pad(
                    (string) $existingQueue['QueueNumber'],
                    3,
                    '0',
                    STR_PAD_LEFT
                );

                $message =
                    'This patient has already been checked in. ' .
                    'Queue number: Q' . $existingNumber;

                $messageType = 'error';

            } else {

                // --------------------------------------------------
                // 3. Start transaction
                // --------------------------------------------------
                mysqli_begin_transaction($conn);

                try {

                    // --------------------------------------------------
                    // 4. Get next queue number for TODAY
                    // --------------------------------------------------
                    $queueNumberStmt = mysqli_prepare(
                        $conn,
                        "SELECT
                            COALESCE(MAX(QueueNumber), 0) + 1
                            AS NextQueueNumber
                         FROM queue
                         WHERE QueueDate = ?
                         FOR UPDATE"
                    );

                    mysqli_stmt_bind_param(
                        $queueNumberStmt,
                        's',
                        $today
                    );

                    mysqli_stmt_execute($queueNumberStmt);

                    $queueNumberResult = mysqli_stmt_get_result(
                        $queueNumberStmt
                    );

                    $queueData = mysqli_fetch_assoc(
                        $queueNumberResult
                    );

                    $nextQueueNumber =
                        (int) $queueData['NextQueueNumber'];

                    // --------------------------------------------------
                    // 5. Create queue record
                    // --------------------------------------------------
                    $priorityLevel = 'Normal';
                    $queueTime = date('H:i:s');
                    $queueStatus = 'Waiting';

                    $queueStmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO queue
                        (
                            AppointmentID,
                            QueueNumber,
                            PriorityLevel,
                            QueueDate,
                            QueueTime,
                            Status
                        )
                        VALUES (?, ?, ?, ?, ?, ?)"
                    );

                    mysqli_stmt_bind_param(
                        $queueStmt,
                        'iissss',
                        $appointmentID,
                        $nextQueueNumber,
                        $priorityLevel,
                        $today,
                        $queueTime,
                        $queueStatus
                    );

                    if (!mysqli_stmt_execute($queueStmt)) {
                        throw new Exception(
                            'Failed to create queue record.'
                        );
                    }

                    // --------------------------------------------------
                    // 6. Update appointment status
                    // --------------------------------------------------
                    $appointmentStatus = 'Checked In';

                    $updateAppointmentStmt = mysqli_prepare(
                        $conn,
                        "UPDATE appointments
                         SET Status = ?
                         WHERE AppointmentID = ?"
                    );

                    mysqli_stmt_bind_param(
                        $updateAppointmentStmt,
                        'si',
                        $appointmentStatus,
                        $appointmentID
                    );

                    if (!mysqli_stmt_execute(
                        $updateAppointmentStmt
                    )) {
                        throw new Exception(
                            'Failed to update appointment status.'
                        );
                    }

                    // --------------------------------------------------
                    // 7. Everything successful
                    // --------------------------------------------------
                    mysqli_commit($conn);

                    $formattedQueueNumber = str_pad(
                        (string) $nextQueueNumber,
                        3,
                        '0',
                        STR_PAD_LEFT
                    );

                    $message =
                        'Patient checked in successfully. ' .
                        'Queue number: Q' .
                        $formattedQueueNumber;

                    $messageType = 'success';

                } catch (Exception $e) {

                    // --------------------------------------------------
                    // 8. Something failed → undo everything
                    // --------------------------------------------------
                    mysqli_rollback($conn);

                    $message =
                        'Unable to check in patient. ' .
                        'Please try again.';

                    $messageType = 'error';
                }
            }
        }
    }
}

// ======================================================
// WALK-IN ACTION
// New / unregistered patient
// ======================================================

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'walkin'
) {

    // --------------------------------------------------
    // Patient information
    // --------------------------------------------------

    $fullName =
        trim($_POST['full_name'] ?? '');

    $dateOfBirth =
        trim($_POST['date_of_birth'] ?? '');

    $gender =
        trim($_POST['gender'] ?? 'Other');

    $phone =
        trim($_POST['phone'] ?? '');

    $bloodType =
        trim($_POST['blood_type'] ?? '') ?: null;

    $allergies =
        trim($_POST['allergies'] ?? '') ?: null;

    $currentMedication =
        trim($_POST['current_medication'] ?? '') ?: null;

    $pastConditions =
        trim($_POST['past_conditions'] ?? '') ?: null;

    $familyHistory =
        trim($_POST['family_history'] ?? '') ?: null;

    // --------------------------------------------------
    // Appointment information
    // --------------------------------------------------

    $departmentId =
        (int) ($_POST['department_id'] ?? 0);

    $session =
        $_POST['session'] ?? 'morning';

    $reason =
        trim($_POST['reason'] ?? '');

    // --------------------------------------------------
    // Validate session
    // --------------------------------------------------

    if (!in_array($session, ['morning', 'afternoon'], true)) {
        $session = 'morning';
    }

    // --------------------------------------------------
    // Validate required fields
    // --------------------------------------------------

    if (
        $fullName === '' ||
        $dateOfBirth === '' ||
        $phone === '' ||
        $departmentId <= 0 ||
        $reason === ''
    ) {

        $message =
            'Please complete all required walk-in fields.';

        $messageType = 'error';

    } else {

        // --------------------------------------------------
        // Validate date of birth
        // --------------------------------------------------

        $dobTimestamp =
            strtotime($dateOfBirth);

        if (
            $dobTimestamp === false ||
            $dateOfBirth > $today
        ) {

            $message =
                'Please enter a valid date of birth.';

            $messageType = 'error';

        } else {

            // --------------------------------------------------
            // Split full name
            // --------------------------------------------------

            $nameParts =
                preg_split(
                    '/\s+/',
                    $fullName
                );

            $lastName =
                array_pop($nameParts);

            $firstName =
                implode(' ', $nameParts);

            if ($firstName === '') {
                $firstName = $lastName;
            }

            // --------------------------------------------------
            // Appointment time
            //
            // This is the scheduled session time.
            // QueueTime below is the actual check-in time.
            // --------------------------------------------------

            if ($session === 'afternoon') {
                $appointmentTime = '13:00:00';
            } else {
                $appointmentTime = '09:00:00';
            }

            // --------------------------------------------------
            // Begin transaction
            // --------------------------------------------------

            mysqli_begin_transaction($conn);

            try {

                // ==================================================
                // GET PATIENT ROLE
                // ==================================================

                $roleResult = mysqli_query(
                    $conn,
                    "SELECT RoleID
                     FROM roles
                     WHERE RoleName = 'Patient'
                     LIMIT 1"
                );

                $roleRow =
                    $roleResult
                        ? mysqli_fetch_assoc($roleResult)
                        : null;

                if (!$roleRow) {
                    throw new Exception(
                        'Patient role not found in roles table.'
                    );
                }

                $patientRoleId =
                    (int) $roleRow['RoleID'];

                // ==================================================
                // CREATE PLACEHOLDER USER ACCOUNT
                // ==================================================

                $placeholderEmail =
                    'walkin_' .
                    bin2hex(random_bytes(8)) .
                    '@medicare.local';

                $randomPassword =
                    bin2hex(random_bytes(8));

                $placeholderPassword =
                    password_hash(
                        $randomPassword,
                        PASSWORD_DEFAULT
                    );

                $userStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO users
                    (
                        RoleID,
                        FirstName,
                        LastName,
                        Email,
                        Password,
                        Sex,
                        DateOfBirth,
                        ContactNumber,
                        Status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Active")'
                );

                mysqli_stmt_bind_param(
                    $userStmt,
                    'isssssss',
                    $patientRoleId,
                    $firstName,
                    $lastName,
                    $placeholderEmail,
                    $placeholderPassword,
                    $gender,
                    $dateOfBirth,
                    $phone
                );

                if (!mysqli_stmt_execute($userStmt)) {
                    throw new Exception(
                        'Failed to create patient account.'
                    );
                }

                $newUserId =
                    mysqli_insert_id($conn);

                mysqli_stmt_close($userStmt);

                // ==================================================
                // CREATE PATIENT RECORD
                // ==================================================

                $patientStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO patients
                    (
                        UserID,
                        BloodType,
                        Allergies,
                        PastMedicalCondition,
                        CurrentMedication,
                        FamilyMedicalHistory
                    )
                    VALUES (?, ?, ?, ?, ?, ?)'
                );

                mysqli_stmt_bind_param(
                    $patientStmt,
                    'isssss',
                    $newUserId,
                    $bloodType,
                    $allergies,
                    $pastConditions,
                    $currentMedication,
                    $familyHistory
                );

                if (!mysqli_stmt_execute($patientStmt)) {
                    throw new Exception(
                        'Failed to create patient record.'
                    );
                }

                $newPatientId =
                    mysqli_insert_id($conn);

                mysqli_stmt_close($patientStmt);

                // ==================================================
                // CREATE APPOINTMENT
                //
                // StaffID is NULL because this is not necessarily
                // assigned to a specific doctor yet.
                // ==================================================

                $apptStmt = mysqli_prepare(
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
                    VALUES (?, NULL, ?, ?, ?, ?, "Checked In")'
                );

                mysqli_stmt_bind_param(
                    $apptStmt,
                    'iisss',
                    $newPatientId,
                    $departmentId,
                    $today,
                    $appointmentTime,
                    $reason
                );

                if (!mysqli_stmt_execute($apptStmt)) {
                    throw new Exception(
                        'Failed to create appointment.'
                    );
                }

                $newAppointmentId =
                    mysqli_insert_id($conn);

                mysqli_stmt_close($apptStmt);

                // ==================================================
                // GET NEXT QUEUE NUMBER
                // ==================================================

                $queueNumStmt = mysqli_prepare(
                    $conn,
                    'SELECT
                        COALESCE(MAX(QueueNumber), 0) + 1
                        AS NextNum
                     FROM queue
                     WHERE QueueDate = ?'
                );

                mysqli_stmt_bind_param(
                    $queueNumStmt,
                    's',
                    $today
                );

                mysqli_stmt_execute($queueNumStmt);

                $queueNumResult =
                    mysqli_stmt_get_result($queueNumStmt);

                $queueNumRow =
                    mysqli_fetch_assoc($queueNumResult);

                mysqli_stmt_close($queueNumStmt);

                $newQueueNumber =
                    (int) $queueNumRow['NextNum'];

                // ==================================================
                // CREATE QUEUE RECORD
                // ==================================================

                $queueInsertStmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO queue
                    (
                        AppointmentID,
                        QueueNumber,
                        PriorityLevel,
                        QueueDate,
                        QueueTime,
                        Status
                    )
                    VALUES (?, ?, "Normal", ?, ?, "Waiting")'
                );

                // Actual walk-in/check-in time
                $queueTime =
                    date('H:i:s');

                mysqli_stmt_bind_param(
                    $queueInsertStmt,
                    'iiss',
                    $newAppointmentId,
                    $newQueueNumber,
                    $today,
                    $queueTime
                );

                if (!mysqli_stmt_execute($queueInsertStmt)) {
                    throw new Exception(
                        'Failed to add patient to queue.'
                    );
                }

                mysqli_stmt_close(
                    $queueInsertStmt
                );

                // ==================================================
                // COMMIT TRANSACTION
                // ==================================================

                mysqli_commit($conn);

                $message =
                    'Walk-in patient added successfully. Queue number: Q' .
                    str_pad(
                        (string) $newQueueNumber,
                        3,
                        '0',
                        STR_PAD_LEFT
                    );

                $messageType = 'success';

            } catch (Exception $e) {

                mysqli_rollback($conn);

                $message =
                    'Unable to add walk-in patient. Please try again.';

                $messageType = 'error';
            }
        }
    }
}

// ======================================================
// DEPARTMENTS + SESSION SLOT ESTIMATES
// ======================================================

$sessionCapacity = 20;

$departments = [];

$deptSlots = [];

$deptResult = mysqli_query(
    $conn,
    "SELECT
        d.DepartmentID,
        d.DepartmentName,

        SUM(
            CASE
                WHEN
                    a.AppointmentDate = CURDATE()
                    AND a.Status != 'Cancelled'
                    AND a.AppointmentTime < '12:00:00'
                THEN 1
                ELSE 0
            END
        ) AS MorningCount,

        SUM(
            CASE
                WHEN
                    a.AppointmentDate = CURDATE()
                    AND a.Status != 'Cancelled'
                    AND a.AppointmentTime >= '12:00:00'
                THEN 1
                ELSE 0
            END
        ) AS AfternoonCount

     FROM departments d

     LEFT JOIN appointments a
        ON a.DepartmentID = d.DepartmentID

     GROUP BY
        d.DepartmentID,
        d.DepartmentName

     ORDER BY
        d.DepartmentName"
);

while ($row = mysqli_fetch_assoc($deptResult)) {

    $departments[] = $row;

    $deptSlots[
        (int) $row['DepartmentID']
    ] = [
        'morning' =>
            max(
                0,
                $sessionCapacity -
                (int) $row['MorningCount']
            ),

        'afternoon' =>
            max(
                0,
                $sessionCapacity -
                (int) $row['AfternoonCount']
            ),
    ];
}

// ======================================================
// QUEUE NUMBER PREVIEW
// IMPORTANT:
// This is only a preview.
// The actual number is calculated again
// when the form is submitted.
// ======================================================

$previewStmt = mysqli_prepare(
    $conn,
    'SELECT
        COALESCE(MAX(QueueNumber), 0) + 1
        AS NextNum
     FROM queue
     WHERE QueueDate = ?'
);

mysqli_stmt_bind_param(
    $previewStmt,
    's',
    $today
);

mysqli_stmt_execute($previewStmt);

$previewResult =
    mysqli_stmt_get_result($previewStmt);

$previewRow =
    mysqli_fetch_assoc($previewResult);

mysqli_stmt_close($previewStmt);

$nextQueueNumberPreview =
    (int) $previewRow['NextNum'];

// ======================================================
// GET TODAY'S REAL APPOINTMENTS
// ======================================================

$appointments = [];

$appointmentsStmt = mysqli_prepare(
    $conn,
    'SELECT

        a.AppointmentID,
        a.AppointmentDate,
        a.AppointmentTime,
        a.Purpose,
        a.Status AS AppointmentStatus,

        p.PatientID,

        u.FirstName,
        u.MiddleName,
        u.LastName,
        u.ContactNumber,

        d.DepartmentID,
        d.DepartmentName,

        q.QueueID,
        q.QueueNumber,
        q.Status AS QueueStatus,
        q.QueueTime

     FROM appointments a

     INNER JOIN patients p
        ON a.PatientID = p.PatientID

     INNER JOIN users u
        ON p.UserID = u.UserID

     INNER JOIN departments d
        ON a.DepartmentID = d.DepartmentID

     LEFT JOIN queue q
        ON a.AppointmentID = q.AppointmentID
        AND q.QueueDate = ?

     WHERE a.AppointmentDate = ?

     AND a.Status NOT IN (
        "Cancelled",
        "Completed"
     )

     ORDER BY

        CASE
            WHEN q.QueueNumber IS NOT NULL
            THEN 0
            ELSE 1
        END,

        a.AppointmentTime ASC'
);

mysqli_stmt_bind_param(
    $appointmentsStmt,
    'ss',
    $today,
    $today
);

mysqli_stmt_execute(
    $appointmentsStmt
);

$appointmentsResult =
    mysqli_stmt_get_result(
        $appointmentsStmt
    );

while (
    $row =
        mysqli_fetch_assoc($appointmentsResult)
) {
    $appointments[] = $row;
}

mysqli_stmt_close(
    $appointmentsStmt
);

// ======================================================
// CURRENT STAFF NAME + ROLE
// ======================================================

$staffInfoStmt = mysqli_prepare(
    $conn,
    "SELECT
        s.StaffRole,
        u.FirstName,
        u.LastName,
        u.ProfilePhoto

     FROM staff s

     INNER JOIN users u
        ON s.UserID = u.UserID

     WHERE s.UserID = ?

     LIMIT 1"
);

mysqli_stmt_bind_param(
    $staffInfoStmt,
    'i',
    $currentUserID
);

mysqli_stmt_execute(
    $staffInfoStmt
);

$staffInfoResult =
    mysqli_stmt_get_result(
        $staffInfoStmt
    );

$staffInfoRow =
    mysqli_fetch_assoc($staffInfoResult);

mysqli_stmt_close(
    $staffInfoStmt
);

$staffFirstName =
    $staffInfoRow['FirstName']
    ?? ($_SESSION['FirstName'] ?? '');

$staffLastName =
    $staffInfoRow['LastName']
    ?? ($_SESSION['LastName'] ?? '');

$staffRole =
    $staffInfoRow['StaffRole']
    ?? 'Staff';

$staffProfilePhoto =
    $staffInfoRow['ProfilePhoto']
    ?? null;

$staffInitials =
    strtoupper(
        substr($staffFirstName, 0, 1) .
        substr($staffLastName, 0, 1)
    );

$staffName =
    htmlspecialchars(
        $staffFirstName . ' ' . $staffLastName
    );
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Patient Check-in — MediCare Staff Portal
</title>

<link
    rel="preconnect"
    href="https://fonts.googleapis.com"
>

<link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

<link
    rel="stylesheet"
    href="../assets/css/staff/staff_dashboard.css"
>

</head>

<body>

<?php if ($message !== ''): ?>

<div
    style="
        margin:16px 36px 0;
        padding:12px 16px;
        border-radius:8px;
        background:
            <?php
            echo $messageType === 'success'
                ? '#dcfce7'
                : '#fee2e2';
            ?>;
        color:
            <?php
            echo $messageType === 'success'
                ? '#166534'
                : '#991b1b';
            ?>;
        font-size:14px;
        font-weight:500;
    "
>
    <?php echo htmlspecialchars($message); ?>
</div>

<?php endif; ?>

<div class="app">

<!-- =====================================================
     SIDEBAR
===================================================== -->

<aside class="sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>

        </div>

        <div class="brand-text">

            <div class="brand-title">
                MediCare
            </div>

            <div class="brand-sub">
                Staff Portal
            </div>

        </div>

    </div>

    <ul class="nav-list">

        <li class="nav-item">

            <a
                href="../staff/staff_dashboard.php"
                style="
                    text-decoration:none;
                    color:inherit;
                    display:flex;
                    align-items:center;
                    gap:12px;
                "
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <rect x="3" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="3" width="7" height="7" rx="1.5"/>
                    <rect x="3" y="14" width="7" height="7" rx="1.5"/>
                    <rect x="14" y="14" width="7" height="7" rx="1.5"/>
                </svg>

                Dashboard

            </a>

        </li>

        <li class="nav-item active">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <line x1="19" y1="8" x2="19" y2="14"/>
                <line x1="16" y1="11" x2="22" y2="11"/>
            </svg>

            Patient Check-in

        </li>

        <li class="nav-item">

            <a
                href="queue.php"
                style="
                    text-decoration:none;
                    color:inherit;
                    display:flex;
                    align-items:center;
                    gap:12px;
                "
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>
                </svg>

                Queue

            </a>

        </li>

        <li class="nav-item">

            <a
                href="staff_profile.php"
                style="
                    text-decoration:none;
                    color:inherit;
                    display:flex;
                    align-items:center;
                    gap:12px;
                "
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>

                Profile

            </a>

        </li>

    </ul>

    <div class="sidebar-footer">

        <div class="sidebar-user">

            <div class="user-avatar">
                <?php if (!empty($staffProfilePhoto)): ?>
                <img src="../<?php echo htmlspecialchars($staffProfilePhoto); ?>" alt="Photo">
                <?php else: ?>
                <?php echo $staffInitials; ?>
                <?php endif; ?>
            </div>

            <div>

                <div class="user-name">
                    <?php echo $staffName; ?>
                </div>

                <div class="user-role">
                    <?php echo htmlspecialchars($staffRole); ?>
                </div>

            </div>

        </div>

        <a
            class="sign-out"
            href="../auth/logout.php"
            onclick="return confirm('Are you sure you want to sign out?');"
        >

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>

            Sign Out

        </a>

    </div>

</aside>

<!-- =====================================================
     MAIN
===================================================== -->

<main class="main">

    <div class="staff-topbar">

        <div class="page-header">

            <h1>
                Patient Check-in
            </h1>

            <p>
                <?php echo date('l, F j, Y'); ?>
            </p>

        </div>

        <div class="staff-header-actions">

            <button
                class="btn-checkin-solid"
                type="button"
                onclick="openCheckinModal()"
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <polyline points="20 6 9 17 4 12"/>
                </svg>

                Check In Patient

            </button>

            <button
                class="btn-walkin"
                type="button"
                onclick="openWalkinModal()"
            >

                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <line x1="19" y1="8" x2="19" y2="14"/>
                    <line x1="16" y1="11" x2="22" y2="11"/>
                </svg>

                Walk-in

            </button>

        </div>

    </div>

    <!-- SEARCH -->

    <div class="staff-search-bar">

        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>

        <input
            type="text"
            id="mainSearchInput"
            placeholder="Search by patient name or queue number..."
            oninput="filterMainTable()"
        >

    </div>

    <!-- =================================================
         APPOINTMENTS TABLE
    ================================================== -->

    <div class="staff-table-wrap">

        <table class="staff-table">

            <thead>

                <tr>

                    <th>Queue #</th>

                    <th>Patient</th>

                    <th>Department</th>

                    <th>Time</th>

                    <th>Notes</th>

                    <th>Action</th>

                </tr>

            </thead>

            <tbody id="appointmentsTableBody">

            <?php if (empty($appointments)): ?>

                <tr class="empty-row">

                    <td colspan="6">
                        No scheduled appointments for today
                    </td>

                </tr>

            <?php else: ?>

                <?php foreach ($appointments as $appointment): ?>

                    <?php

                    $fullName = trim(
                        $appointment['FirstName'] . ' ' .
                        (
                            !empty($appointment['MiddleName'])
                            ? $appointment['MiddleName'] . ' '
                            : ''
                        ) .
                        $appointment['LastName']
                    );

                    $formattedTime = date(
                        'h:i A',
                        strtotime(
                            $appointment['AppointmentTime']
                        )
                    );

                    ?>

                    <tr
                        data-search="<?php
                            echo htmlspecialchars(
                                strtolower(
                                    $fullName . ' ' .
                                    $appointment['DepartmentName'] . ' ' .
                                    (
                                        $appointment['QueueNumber']
                                        ? 'q' .
                                        $appointment['QueueNumber']
                                        : ''
                                    )
                                )
                            );
                        ?>"
                    >

                        <!-- QUEUE NUMBER -->

                        <td>

                            <?php if (!empty($appointment['QueueID'])): ?>

                                <span class="modal-tag-checked">

                                    Q<?php
                                    echo str_pad(
                                        $appointment['QueueNumber'],
                                        3,
                                        '0',
                                        STR_PAD_LEFT
                                    );
                                    ?>

                                </span>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>

                        <!-- PATIENT -->

                        <td>

                            <?php
                            echo htmlspecialchars($fullName);
                            ?>

                        </td>

                        <!-- DEPARTMENT -->

                        <td>

                            <?php
                            echo htmlspecialchars(
                                $appointment['DepartmentName']
                            );
                            ?>

                        </td>

                        <!-- TIME -->

                        <td>

                            <?php echo $formattedTime; ?>

                        </td>

                        <!-- NOTES -->

                        <td>

                            <?php
                            echo htmlspecialchars(
                                $appointment['Purpose']
                                ?: '—'
                            );
                            ?>

                        </td>

                        <!-- ACTION -->

                        <td>

                            <?php if (!empty($appointment['QueueNumber'])): ?>

                                <span class="modal-tag-checked">
                                    Checked In
                                </span>

                            <?php else: ?>

                                <button
                                    type="button"
                                    class="btn-primary-solid"
                                    onclick="selectAppointment(
                                        <?php
                                        echo (int)
                                            $appointment['AppointmentID'];
                                        ?>
                                    )"
                                >
                                    Check In
                                </button>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</main>

</div>

<!-- =====================================================
     CHECK-IN MODAL
===================================================== -->

<div
    class="modal-overlay hidden"
    id="checkinModal"
>

    <div class="modal-card">

        <div class="modal-head">

            <div>

                <div class="modal-title">
                    Patient Check-In
                </div>

                <div class="modal-sub">
                    Search and check in an existing patient
                </div>

            </div>

            <button
                class="modal-close"
                type="button"
                onclick="closeCheckinModal()"
            >
                &times;
            </button>

        </div>

        <!-- SEARCH -->

        <div class="modal-search">

            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>

            <input
                type="text"
                id="modalSearchInput"
                placeholder="Search by name or phone number..."
                oninput="filterModalList()"
            >

        </div>

        <!-- PATIENT LIST -->

        <div
            class="modal-list"
            id="modalList"
        >

        <?php if (empty($appointments)): ?>

            <div
                style="
                    padding:20px;
                    text-align:center;
                    color:#777;
                "
            >
                No appointments for today.
            </div>

        <?php else: ?>

            <?php foreach ($appointments as $appointment): ?>

                <?php

                $fullName = trim(
                    $appointment['FirstName'] . ' ' .
                    (
                        !empty($appointment['MiddleName'])
                        ? $appointment['MiddleName'] . ' '
                        : ''
                    ) .
                    $appointment['LastName']
                );

                $searchData = strtolower(
                    $fullName . ' ' .
                    ($appointment['ContactNumber'] ?? '')
                );

                $formattedTime = date(
                    'h:i A',
                    strtotime(
                        $appointment['AppointmentTime']
                    )
                );

                ?>

                <div
                    class="modal-list-item"
                    data-name="<?php
                        echo htmlspecialchars($searchData);
                    ?>"
                    data-appointment-id="<?php
                        echo (int)
                            $appointment['AppointmentID'];
                    ?>"
                    onclick="selectPatient(this)"
                >

                    <div class="modal-avatar">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>

                    </div>

                    <div class="modal-item-info">

                        <div class="modal-item-name">

                            <?php
                            echo htmlspecialchars($fullName);
                            ?>

                        </div>

                        <div class="modal-item-meta">

                            <?php
                            echo htmlspecialchars(
                                $appointment['ContactNumber']
                                ?: 'No contact number'
                            );
                            ?>

                            ·

                            <?php
                            echo htmlspecialchars(
                                $appointment['DepartmentName']
                            );
                            ?>

                            ·

                            <?php echo $formattedTime; ?>

                        </div>

                    </div>

                    <?php if (!empty($appointment['QueueNumber'])): ?>

                        <span class="modal-tag-checked">

                            Q<?php
                            echo str_pad(
                                $appointment['QueueNumber'],
                                3,
                                '0',
                                STR_PAD_LEFT
                            );
                            ?>

                        </span>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

        </div>

        <!-- CHECK-IN FORM -->

        <form
            method="POST"
            id="checkinForm"
        >

            <input
                type="hidden"
                name="action"
                value="checkin"
            >

            <input
                type="hidden"
                name="appointment_id"
                id="selectedAppointmentID"
                value=""
            >

            <div class="modal-actions">

                <button
                    class="btn-outline"
                    type="button"
                    onclick="closeCheckinModal()"
                >
                    Cancel
                </button>

                <button
                    class="btn-primary-solid"
                    id="assignQueueBtn"
                    type="submit"
                    disabled
                >

                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>

                    Check In & Assign Queue

                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     WALK-IN MODAL
===================================================== -->

<div
    class="modal-overlay hidden"
    id="walkinModal"
>

    <div
        class="modal-card"
        style="width:500px;"
    >

        <div class="modal-head">

            <div>

                <div class="modal-title">
                    Add Walk-In Patient
                </div>

                <div
                    class="modal-sub"
                    id="walkinStepLabel"
                >
                    Step 1 of 2 — Patient Profile
                </div>

            </div>

            <button
                class="modal-close"
                type="button"
                onclick="closeWalkinModal()"
            >
                &times;
            </button>

        </div>

        <!-- PROGRESS -->

        <div class="modal-progress">

            <div
                class="modal-progress-bar"
                id="walkinProgressBar"
                style="width:50%;"
            ></div>

        </div>

        <form
            method="POST"
            id="walkinForm"
        >

            <input
                type="hidden"
                name="action"
                value="walkin"
            >

            <!-- =========================================
                 STEP 1
            ========================================== -->

            <div id="walkinStep1">

                <!-- FULL NAME -->

                <div class="form-group">

                    <label class="form-label">
                        Full Name *
                    </label>

                    <input
                        type="text"
                        name="full_name"
                        id="wi_full_name"
                        class="form-input"
                        placeholder="e.g. Juan dela Cruz"
                        required
                    >

                </div>

                <!-- DOB + GENDER -->

                <div class="form-row">

                    <div class="form-group">

                        <label class="form-label">
                            Date of Birth *
                        </label>

                        <input
                            type="date"
                            name="date_of_birth"
                            id="wi_date_of_birth"
                            class="form-input"
                            max="<?php echo $today; ?>"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            Gender
                        </label>

                        <select
                            name="gender"
                            id="wi_gender"
                            class="form-input"
                        >

                            <option value="Male">
                                Male
                            </option>

                            <option value="Female">
                                Female
                            </option>

                            <option value="Other">
                                Other
                            </option>

                        </select>

                    </div>

                </div>

                <!-- PHONE + BLOOD TYPE -->

                <div class="form-row">

                    <div class="form-group">

                        <label class="form-label">
                            Phone *
                        </label>

                        <input
                            type="text"
                            name="phone"
                            id="wi_phone"
                            class="form-input"
                            placeholder="+63 912 345 6789"
                            required
                        >

                    </div>

                    <div class="form-group">

                        <label class="form-label">
                            Blood Type
                        </label>

                        <select
                            name="blood_type"
                            id="wi_blood_type"
                            class="form-input"
                        >

                            <option value="">
                                Unknown
                            </option>

                            <?php
                            foreach (
                                [
                                    'O+',
                                    'O-',
                                    'A+',
                                    'A-',
                                    'B+',
                                    'B-',
                                    'AB+',
                                    'AB-'
                                ]
                                as $bt
                            ):
                            ?>

                                <option value="<?php echo $bt; ?>">

                                    <?php echo $bt; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <!-- ALLERGIES -->

                <div class="form-group">

                    <label class="form-label">
                        Allergies
                    </label>

                    <input
                        type="text"
                        name="allergies"
                        class="form-input"
                        placeholder="None"
                    >

                </div>

                <!-- MEDICATION -->

                <div class="form-group">

                    <label class="form-label">
                        Current Medication
                    </label>

                    <input
                        type="text"
                        name="current_medication"
                        class="form-input"
                        placeholder="None"
                    >

                </div>

                <!-- PAST CONDITIONS -->

                <div class="form-group">

                    <label class="form-label">
                        Past Medical Conditions
                    </label>

                    <input
                        type="text"
                        name="past_conditions"
                        class="form-input"
                        placeholder="None"
                    >

                </div>

                <!-- FAMILY HISTORY -->

                <div
                    class="form-group"
                    style="margin-bottom:4px;"
                >

                    <label class="form-label">
                        Family Medical History
                    </label>

                    <input
                        type="text"
                        name="family_history"
                        class="form-input"
                        placeholder="None"
                    >

                </div>

                <!-- STEP ACTIONS -->

                <div
                    class="modal-actions"
                    style="margin-top:18px;"
                >

                    <button
                        class="btn-outline"
                        type="button"
                        onclick="closeWalkinModal()"
                    >
                        Cancel
                    </button>

                    <button
                        class="btn-primary-solid"
                        type="button"
                        onclick="goToWalkinStep2()"
                    >
                        Next: Appointment Details &rarr;
                    </button>

                </div>

            </div>

            <!-- =========================================
                 STEP 2
            ========================================== -->

            <div
                id="walkinStep2"
                style="display:none;"
            >

                <!-- PATIENT SUMMARY -->

                <div class="patient-summary">

                    <div class="patient-summary-avatar">

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>

                    </div>

                    <div>

                        <div
                            class="patient-summary-name"
                            id="summaryName"
                        ></div>

                        <div
                            class="patient-summary-meta"
                            id="summaryMeta"
                        ></div>

                    </div>

                </div>

                <!-- DEPARTMENT -->

                <div class="form-group">

                    <label class="form-label">
                        Department *
                    </label>

                    <select
                        name="department_id"
                        id="wi_department"
                        class="form-input"
                        required
                        onchange="updateSlotsLeft()"
                    >

                        <option value="">
                            Select department
                        </option>

                        <?php foreach ($departments as $dept): ?>

                            <option
                                value="<?php
                                    echo (int)
                                        $dept['DepartmentID'];
                                ?>"
                            >

                                <?php
                                echo htmlspecialchars(
                                    $dept['DepartmentName']
                                );
                                ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- SESSION -->

                <div class="form-group">

                    <label class="form-label">
                        Session
                    </label>

                    <div class="session-options">

                        <div
                            class="session-option selected"
                            data-session="morning"
                            onclick="selectSession(this)"
                        >

                            <div class="session-option-title">
                                Morning
                            </div>

                            <div
                                class="session-option-slots"
                                id="morningSlots"
                            >
                                — slots left
                            </div>

                        </div>

                        <div
                            class="session-option"
                            data-session="afternoon"
                            onclick="selectSession(this)"
                        >

                            <div class="session-option-title">
                                Afternoon
                            </div>

                            <div
                                class="session-option-slots"
                                id="afternoonSlots"
                            >
                                — slots left
                            </div>

                        </div>

                    </div>

                    <input
                        type="hidden"
                        name="session"
                        id="wi_session"
                        value="morning"
                    >

                </div>

                <!-- REASON -->

                <div class="form-group">

                    <label class="form-label">
                        Reason for Visit *
                    </label>

                    <textarea
                        name="reason"
                        id="wi_reason"
                        class="form-input form-textarea"
                        required
                        placeholder="Briefly describe the patient's concern..."
                    ></textarea>

                </div>

                <!-- QUEUE PREVIEW -->

                <div class="queue-assigned-card">

                    <div>

                        <div class="queue-assigned-title">
                            Queue Number Assigned
                        </div>

                        <div
                            class="queue-assigned-sub"
                            id="queuePreviewSub"
                        >
                            Morning session · select a department
                        </div>

                    </div>

                    <div class="queue-assigned-number">

                        <?php
                        echo str_pad(
                            (string)
                                $nextQueueNumberPreview,
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        ?>

                    </div>

                </div>

                <!-- ACTIONS -->

                <div
                    class="modal-actions"
                    style="margin-top:18px;"
                >

                    <button
                        class="btn-outline"
                        type="button"
                        onclick="goToWalkinStep1()"
                    >
                        &larr; Back
                    </button>

                    <button
                        class="btn-primary-solid"
                        type="submit"
                    >

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <line x1="19" y1="8" x2="19" y2="14"/>
                            <line x1="16" y1="11" x2="22" y2="11"/>
                        </svg>

                        Add Walk-In Patient

                    </button>

                </div>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

// ======================================================
// EXISTING CHECK-IN MODAL
// ======================================================

function openCheckinModal() {

    document
        .getElementById('checkinModal')
        .classList
        .remove('hidden');

}

function closeCheckinModal() {

    document
        .getElementById('checkinModal')
        .classList
        .add('hidden');

    document
        .querySelectorAll('.modal-list-item')
        .forEach(function(item) {

            item.classList.remove('selected');

        });

    document.getElementById(
        'assignQueueBtn'
    ).disabled = true;

    document.getElementById(
        'selectedAppointmentID'
    ).value = '';

    document.getElementById(
        'modalSearchInput'
    ).value = '';

    filterModalList();
}

function selectPatient(el) {

    document
        .querySelectorAll('.modal-list-item')
        .forEach(function(item) {

            item.classList.remove('selected');

        });

    el.classList.add('selected');

    const appointmentID =
        el.getAttribute(
            'data-appointment-id'
        );

    document.getElementById(
        'selectedAppointmentID'
    ).value = appointmentID;

    document.getElementById(
        'assignQueueBtn'
    ).disabled = false;
}

function filterModalList() {

    const query =
        document
            .getElementById(
                'modalSearchInput'
            )
            .value
            .trim()
            .toLowerCase();

    document
        .querySelectorAll('.modal-list-item')
        .forEach(function(item) {

            const searchableText =
                item.getAttribute(
                    'data-name'
                ) || '';

            item.style.display =
                searchableText.includes(query)
                    ? 'flex'
                    : 'none';

        });
}

function selectAppointment(appointmentID) {

    openCheckinModal();

    const item =
        document.querySelector(
            '.modal-list-item[data-appointment-id="' +
            appointmentID +
            '"]'
        );

    if (item) {
        selectPatient(item);
    }
}

// Close modal by clicking outside
document
    .getElementById('checkinModal')
    .addEventListener(
        'click',
        function(e) {

            if (e.target === this) {
                closeCheckinModal();
            }

        }
    );

// ======================================================
// MAIN TABLE SEARCH
// ======================================================

function filterMainTable() {

    const query =
        document
            .getElementById(
                'mainSearchInput'
            )
            .value
            .trim()
            .toLowerCase();

    document
        .querySelectorAll(
            '#appointmentsTableBody tr[data-search]'
        )
        .forEach(function(row) {

            const searchableText =
                row.getAttribute(
                    'data-search'
                ) || '';

            row.style.display =
                searchableText.includes(query)
                    ? ''
                    : 'none';

        });
}

// ======================================================
// WALK-IN MODAL
// ======================================================

const deptSlots =
    <?php
    echo json_encode(
        $deptSlots,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    );
    ?>;

function openWalkinModal() {

    document
        .getElementById('walkinModal')
        .classList
        .remove('hidden');

}

function closeWalkinModal() {

    document
        .getElementById('walkinModal')
        .classList
        .add('hidden');

    document
        .getElementById('walkinForm')
        .reset();

    goToWalkinStep1();

    // Reset session selection
    document
        .querySelectorAll('.session-option')
        .forEach(function(option) {

            option.classList.remove('selected');

        });

    const morningOption =
        document.querySelector(
            '.session-option[data-session="morning"]'
        );

    if (morningOption) {
        morningOption.classList.add('selected');
    }

    document.getElementById(
        'wi_session'
    ).value = 'morning';

    document.getElementById(
        'morningSlots'
    ).textContent = '— slots left';

    document.getElementById(
        'afternoonSlots'
    ).textContent = '— slots left';

    document.getElementById(
        'queuePreviewSub'
    ).textContent =
        'Morning session · select a department';
}

function goToWalkinStep2() {

    const fullName =
        document
            .getElementById(
                'wi_full_name'
            )
            .value
            .trim();

    const dateOfBirth =
        document
            .getElementById(
                'wi_date_of_birth'
            )
            .value;

    const phone =
        document
            .getElementById(
                'wi_phone'
            )
            .value
            .trim();

    if (
        !fullName ||
        !dateOfBirth ||
        !phone
    ) {

        alert(
            'Please fill in Full Name, Date of Birth, and Phone before continuing.'
        );

        return;
    }

    const gender =
        document
            .getElementById(
                'wi_gender'
            )
            .value;

    const bloodType =
        document
            .getElementById(
                'wi_blood_type'
            )
            .value || '—';

    document.getElementById(
        'summaryName'
    ).textContent = fullName;

    document.getElementById(
        'summaryMeta'
    ).textContent =
        'DOB ' +
        dateOfBirth +
        ' · ' +
        gender +
        ' · ' +
        bloodType +
        ' · ' +
        phone;

    document.getElementById(
        'walkinStep1'
    ).style.display = 'none';

    document.getElementById(
        'walkinStep2'
    ).style.display = 'block';

    document.getElementById(
        'walkinStepLabel'
    ).textContent =
        'Step 2 of 2 — Appointment Details';

    document.getElementById(
        'walkinProgressBar'
    ).style.width = '100%';

}

function goToWalkinStep1() {

    document.getElementById(
        'walkinStep2'
    ).style.display = 'none';

    document.getElementById(
        'walkinStep1'
    ).style.display = 'block';

    document.getElementById(
        'walkinStepLabel'
    ).textContent =
        'Step 1 of 2 — Patient Profile';

    document.getElementById(
        'walkinProgressBar'
    ).style.width = '50%';

}

function selectSession(el) {

    document
        .querySelectorAll('.session-option')
        .forEach(function(option) {

            option.classList.remove(
                'selected'
            );

        });

    el.classList.add('selected');

    document.getElementById(
        'wi_session'
    ).value =
        el.getAttribute(
            'data-session'
        );

    updateQueuePreviewSub();

}

function updateSlotsLeft() {

    const deptId =
        document.getElementById(
            'wi_department'
        ).value;

    const slots =
        deptSlots[deptId] || {
            morning: 20,
            afternoon: 20
        };

    document.getElementById(
        'morningSlots'
    ).textContent =
        slots.morning +
        ' slots left';

    document.getElementById(
        'afternoonSlots'
    ).textContent =
        slots.afternoon +
        ' slots left';

    updateQueuePreviewSub();

}

function updateQueuePreviewSub() {

    const deptSelect =
        document.getElementById(
            'wi_department'
        );

    let deptName = '';

    if (
        deptSelect &&
        deptSelect.selectedIndex >= 0
    ) {

        const selectedOption =
            deptSelect.options[
                deptSelect.selectedIndex
            ];

        if (selectedOption) {
            deptName =
                selectedOption.text;
        }
    }

    const session =
        document.getElementById(
            'wi_session'
        ).value;

    const sessionLabel =
        session === 'morning'
            ? 'Morning session'
            : 'Afternoon session';

    document.getElementById(
        'queuePreviewSub'
    ).textContent =
        deptName
            ? sessionLabel +
              ' · ' +
              deptName
            : sessionLabel +
              ' · select a department';

}

// Close walk-in modal by clicking outside
document
    .getElementById('walkinModal')
    .addEventListener(
        'click',
        function(e) {

            if (e.target === this) {
                closeWalkinModal();
            }

        }
    );

</script>

</body>

</html>