<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_notifications.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/terms_policy.php';
require_once __DIR__ . '/../includes/security_config.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/app_url.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$message = '';
$messageType = 'error';
$verificationLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    // Defensive: a public visitor may NEVER choose the account role. Any
    // posted role field is discarded; the insert below is hard-coded to the
    // Patient role (RoleID = 3).
    unset($_POST['role'], $_POST['role_id'], $_POST['RoleID'], $_POST['roleId']);

    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $message = 'Your form session expired. Please refresh the page and try again.';
    } elseif (!rateLimitAllowed($conn, 'register', $clientIp, RATE_LIMIT_REGISTER_PER_HOUR, 3600, 3600)) {
        $message = 'Too many registration attempts from this device. Please try again later.';
    } else {
        rateLimitRecord($conn, 'register', $clientIp, RATE_LIMIT_REGISTER_PER_HOUR, 3600, 3600);

    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone-number'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm-password'] ?? '';

    if ($fname === '' || $lname === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $message = 'Please complete all required fields.';
    } elseif (empty($_POST['agree_terms'])) {
        $message = 'You must agree to the Terms and Conditions & Data Privacy Policy before continuing.';
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
                    $verifyToken = bin2hex(random_bytes(32));

                    if (REQUIRE_EMAIL_VERIFICATION) {
                        $expiry = date('Y-m-d H:i:s', time() + (EMAIL_VERIFICATION_TTL_HOURS * 3600));
                        $verifyStmt = mysqli_prepare(
                            $conn,
                            'UPDATE users
                                SET EmailVerified = 0, EmailVerificationToken = ?, EmailVerificationExpiry = ?
                              WHERE UserID = ?'
                        );
                        mysqli_stmt_bind_param($verifyStmt, 'ssi', $verifyToken, $expiry, $userId);
                        mysqli_stmt_execute($verifyStmt);
                        mysqli_stmt_close($verifyStmt);
                    }

                    mysqli_commit($conn);
                    adminNotificationCreateForActiveAdmins(
                        $conn,
                        'New Patient Registration',
                        $fname . ' ' . $lname . ' has registered as a new patient.',
                        'Registration',
                        $userId,
                        'users',
                        'Medium'
                    );

                    if (REQUIRE_EMAIL_VERIFICATION) {
                        // Build the link from the configured base URL, never from
                        // the client-supplied Host header (host-header injection).
                        $verifyUrl = curoraAbsoluteUrl('auth/verify_email.php')
                            . '?token=' . urlencode($verifyToken);

                        $verifyHtml = '<p>Hi ' . htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') . ',</p>'
                            . '<p>Welcome to Curora. Please confirm your e-mail address to activate your patient account.</p>'
                            . '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '">Verify my e-mail address</a></p>'
                            . '<p>This link expires in ' . (int) EMAIL_VERIFICATION_TTL_HOURS . ' hours.</p>';

                        $mailSent = false;
                        if (function_exists('sendCuroraEmail')) {
                            $mailSent = sendCuroraEmail($email, 'Verify your Curora patient account', $verifyHtml);
                        }

                        // In dry-run (no SMTP configured) the message is only
                        // written to the outbox, so surface the link on screen.
                        $showLink = !$mailSent || curoraMailIsDryRun();

                        $message = $mailSent && !curoraMailIsDryRun()
                            ? 'Account created. We sent a verification link to ' . $email . '. Please confirm your e-mail, then sign in.'
                            : 'Account created. Use the link below to confirm your e-mail address, then sign in.';
                        $messageType = 'success';
                        $verificationLink = $showLink ? $verifyUrl : '';
                    } else {
                        header('Location: login.php?portal=patient');
                        exit();
                    }
                }

                mysqli_rollback($conn);
                $message = 'Registration failed.';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Sign Up - Curora</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/auth/signup.css">
    <link rel="stylesheet" href="../assets/css/auth/terms_policy.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="main-container">
        <div class="brand-header">
            <div class="logo">
                <img src="../assets/images/curora-logo-full.png" alt="Curora Logo" class="logo-img">
            </div>
            <h1>Patient Registration</h1>
            <p class="subtitle">Create your patient portal account</p>
        </div>

        <?php if ($message !== ''): ?>
            <div style="background:<?php echo $messageType === 'success' ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $messageType === 'success' ? '#166534' : '#991b1b'; ?>;padding:12px;border-radius:8px;margin-bottom:20px;text-align:center;">
                <?php echo htmlspecialchars($message); ?>
                <?php if ($verificationLink !== ''): ?>
                    <br><br>
                    <a href="<?php echo htmlspecialchars($verificationLink, ENT_QUOTES, 'UTF-8'); ?>" style="color:#166534;font-weight:600;word-break:break-all;">Verify my e-mail now</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form class="form" action="signup.php" method="POST">
            <?php echo csrfField(); ?>
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
                    <button type="button" class="input-icon-right" onclick="togglePassword('password')">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm-password" class="form-label">Confirm Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-check-circle"></i></span>
                    <input type="password" id="confirm-password" name="confirm-password" placeholder="Re-enter your password" required>
                    <button type="button" class="input-icon-right" onclick="togglePassword('confirm-password')">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
            </div>

            <?php renderTermsCheckbox(); ?>

            <button type="submit" name="signup" id="signup-btn">Create Patient Account</button>

            <div class="form-footer">
                Already have an account? <a href="login.php?portal=patient" class="login-link">Sign in</a>
            </div>
        </form>
    </div>

<script>
    function togglePassword(fieldId) {
        const input = document.getElementById(fieldId);
        const icon = input.parentElement.querySelector('.input-icon-right i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.className = 'fas fa-eye';
        } else {
            input.type = 'password';
            icon.className = 'fas fa-eye-slash';
        }
    }
</script>

<?php renderTermsModal(); ?>


<script src="../assets/js/responsive_nav.js"></script>
</body>
</html>
