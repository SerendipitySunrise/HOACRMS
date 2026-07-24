<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validation.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone-number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm-password'] ?? '';

    if ($fname === '' || $lname === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $message = 'Please complete all required fields.';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
    } elseif (($pwdError = validatePasswordStrength($password)) !== null) {
        $message = $pwdError;
    } elseif (($phoneError = validatePhilippinePhone($phone)) !== null) {
        $message = $phoneError;
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT UserID FROM users WHERE Email = ?');
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) > 0) {
            $message = 'Email is already registered.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $roleID = 3;
            $sex = 'Not Specified';

            mysqli_begin_transaction($conn);

            $stmt = mysqli_prepare(
                $conn,
                'INSERT INTO users
                (RoleID, FirstName, LastName, Email, Password, Sex, ContactNumber)
                VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            mysqli_stmt_bind_param(
                $stmt,
                'issssss',
                $roleID,
                $fname,
                $lname,
                $email,
                $hashedPassword,
                $sex,
                $phone
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_rollback($conn);
                $message = 'Registration failed.';
            } else {
                $userId = (int) mysqli_insert_id($conn);
                $patientStmt = mysqli_prepare($conn, 'INSERT INTO patients (UserID) VALUES (?)');
                mysqli_stmt_bind_param($patientStmt, 'i', $userId);

                if (mysqli_stmt_execute($patientStmt)) {
                    mysqli_commit($conn);
                    header('Location: login.php?portal=patient');
                    exit();
                }

                mysqli_rollback($conn);
                $message = 'Registration failed.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Sign Up - MediCare</title>
    <link rel="stylesheet" href="assets/css/signup_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="main-container">
        <p style="margin-bottom: 16px; width:100%; text-align:center;"><a href="portal-select.php?action=register" style="color:#149385;text-decoration:none;font-size:14px;">&larr; Change registration type</a></p>
        <div class="brand-header">
            <div class="logo">
                <img src="assets/images/logo.png" alt="MediCare Logo" class="logo-img">
            </div>
            <h1>Patient Registration</h1>
            <p class="subtitle">Create your patient portal account</p>
        </div>

        <?php if ($message !== ''): ?>
            <div style="background:#fee2e2;color:#991b1b;padding:12px;border-radius:8px;margin-bottom:20px;text-align:center;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form class="form" action="signup.php" method="POST">
            <div class="form-group">
                <label class="form-label">Name</label>
                <div class="name-row">
                    <div class="input-wrapper name-field">
                        <span class="input-icon-left"><i class="fas fa-user"></i></span>
                        <input type="text" id="fname" name="fname" placeholder="First Name" required>
                    </div>
                    <div class="input-wrapper name-field">
                        <span class="input-icon-left"><i class="fas fa-user"></i></span>
                        <input type="text" id="lname" name="lname" placeholder="Last Name" required>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-envelope"></i></span>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                </div>
            </div>

            <div class="form-group">
                <label for="phone-number" class="form-label">Phone Number</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-phone"></i></span>
                    <input type="text" id="phone-number" name="phone-number" placeholder="09123456789" required maxlength="11">
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-lock"></i></span>
                    <input type="password" id="password" name="password" placeholder="Min. 8 characters" required>
                    <span class="input-icon-right"><i class="fas fa-eye-slash"></i></span>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm-password" class="form-label">Confirm Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-check-circle"></i></span>
                    <input type="password" id="confirm-password" name="confirm-password" placeholder="Re-enter your password" required>
                </div>
            </div>

            <div class="form-options">
                <label class="form-check-label">
                    <input type="checkbox" name="terms" required>
                    <span>I agree to the Terms and Conditions & Data Privacy Policy. I consent to the collection and processing of my personal and medical information for outpatient healthcare management purposes.</span>
                </label>
            </div>

            <button type="submit" name="signup" id="signup-btn">Create Patient Account</button>

            <div class="form-footer">
                Already have an account? <a href="login.php?portal=patient" class="login-link">Sign in</a>
            </div>
        </form>
    </div>

</body>
</html>
