<?php

session_start();

require_once __DIR__ . '/../includes/db.php';

$message = '';
$messageType = 'error';

if (isset($_GET['reset'])) {
    $message = 'Password changed successfully. Please log in.';
    $messageType = 'success';
}

if (isset($_GET['expired'])) {
    $message = 'Session expired. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {

        $message = 'Please enter both email and password.';

    } else {

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                u.UserID,
                u.FirstName,
                u.LastName,
                u.Email,
                u.Password,
                u.RoleID,
                u.Status,
                u.FailedAttempts,
                u.LockUntil,
                r.RoleName
             FROM users u
             INNER JOIN roles r ON u.RoleID = r.RoleID
             WHERE u.Email = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $message = 'Unable to process login. Please try again later.';
        } else {

            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);

            if ($result && mysqli_num_rows($result) === 1) {

                $user = mysqli_fetch_assoc($result);

                $userRole = strtolower(
                    trim((string) $user['RoleName'])
                );

                $userRole = preg_replace(
                    '/\s+/',
                    '',
                    $userRole
                );

                if (strcasecmp($user['Status'], 'Active') !== 0) {

                    $message =
                        'Your account is not active. Please contact the clinic administrator.';

                } elseif (
                    $user['LockUntil'] !== null &&
                    strtotime($user['LockUntil']) > time()
                ) {

                    $message =
                        'Your account has been locked due to multiple failed login attempts.';

                } elseif (password_verify($password, $user['Password'])) {

                    $update = mysqli_prepare(
                        $conn,
                        "UPDATE users
                         SET FailedAttempts = 0,
                             LockUntil = NULL
                         WHERE UserID = ?"
                    );

                    if ($update) {
                        mysqli_stmt_bind_param(
                            $update,
                            'i',
                            $user['UserID']
                        );

                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                    }

                    session_regenerate_id(true);

                    // Update last login time
                    $lastLoginStmt = mysqli_prepare($conn,
                        'UPDATE users SET LastLogin = NOW() WHERE UserID = ?');
                    mysqli_stmt_bind_param($lastLoginStmt, 'i', $user['UserID']);
                    mysqli_stmt_execute($lastLoginStmt);
                    mysqli_stmt_close($lastLoginStmt);

                    $_SESSION['UserID'] = (int) $user['UserID'];
                    $_SESSION['FirstName'] = $user['FirstName'];
                    $_SESSION['LastName'] = $user['LastName'];
                    $_SESSION['RoleID'] = (int) $user['RoleID'];
                    $_SESSION['RoleName'] = $user['RoleName'];
                    $_SESSION['LAST_ACTIVITY'] = time();

                    switch ($userRole) {

                        case 'admin':
                            header('Location: ../admin/admin_dashboard.php');
                            exit();

                        case 'doctor':
                            header('Location: ../doctor/doctor_dashboard.php');
                            exit();

                        case 'staff':
                        case 'nurse':
                            header('Location: ../staff/staff_dashboard.php');
                            exit();

                        case 'patient':
                            header('Location: ../patient/patient_dashboard.php');
                            exit();

                        default:
                            session_destroy();

                            $message =
                                'Your account has an invalid role. Please contact the administrator.';
                            break;
                    }

                } else {

                    $attempts =
                        (int) $user['FailedAttempts'] + 1;

                    if ($attempts >= 5) {

                        $lock = date(
                            'Y-m-d H:i:s',
                            strtotime('+15 minutes')
                        );

                        $update = mysqli_prepare(
                            $conn,
                            "UPDATE users
                             SET FailedAttempts = ?,
                                 LockUntil = ?
                             WHERE UserID = ?"
                        );

                        if ($update) {
                            mysqli_stmt_bind_param(
                                $update,
                                'isi',
                                $attempts,
                                $lock,
                                $user['UserID']
                            );

                            mysqli_stmt_execute($update);
                            mysqli_stmt_close($update);
                        }

                        $message =
                            'Your account has been locked due to multiple failed login attempts.';

                    } else {

                        $update = mysqli_prepare(
                            $conn,
                            "UPDATE users
                             SET FailedAttempts = ?
                             WHERE UserID = ?"
                        );

                        if ($update) {
                            mysqli_stmt_bind_param(
                                $update,
                                'ii',
                                $attempts,
                                $user['UserID']
                            );

                            mysqli_stmt_execute($update);
                            mysqli_stmt_close($update);
                        }

                        $message = 'Invalid email or password.';
                    }
                }

            } else {
                $message = 'Invalid email or password.';
            }

            mysqli_stmt_close($stmt);
        }
    }
}

$loginTitle = 'Sign in to MediCare';
$registerPath = '../portal-select.php?action=register';
$registerLabel = 'Create an account';

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
        <?php echo htmlspecialchars($loginTitle); ?> — MediCare
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/auth/login.css"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >
</head>

<body>

    <div class="main-container">

        <div class="brand-header">

            <p style="margin-bottom: 16px;">
                <a
                    href="../index.php"
                    style="color:#149385;text-decoration:none;font-size:14px;"
                >
                    &larr; Back to home
                </a>
            </p>

            <div class="logo">
                <img
                    src="../assets/images/logo.png"
                    alt="MediCare Logo"
                    class="logo-img"
                >
            </div>

            <h1>
                <?php echo htmlspecialchars($loginTitle); ?>
            </h1>

            <p class="subtitle">
                Sign in using your MediCare email and password.
            </p>

        </div>

        <?php if (!empty($message)): ?>

            <div
                class="error-message"
                style="
                    background-color:
                    <?php echo $messageType === 'success'
                        ? '#dcfce7'
                        : '#fee2e2'; ?>;

                    color:
                    <?php echo $messageType === 'success'
                        ? '#166534'
                        : '#991b1b'; ?>;

                    padding: 12px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    width: 100%;
                    text-align: center;
                    font-size: 14px;
                "
            >
                <?php echo htmlspecialchars($message); ?>
            </div>

        <?php endif; ?>

        <form
            class="form"
            action="login.php"
            method="POST"
        >

            <div class="form-group">
                <label
                    for="email"
                    class="form-label"
                >
                    Email Address
                </label>

                <div class="input-wrapper">
                    <span class="input-icon-left">
                        <i class="fas fa-envelope"></i>
                    </span>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        autocomplete="email"
                        required
                    >
                </div>
            </div>

            <div class="form-group">
                <label
                    for="password"
                    class="form-label"
                >
                    Password
                </label>

                <div class="input-wrapper">
                    <span class="input-icon-left">
                        <i class="fas fa-lock"></i>
                    </span>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="input-icon-right"
                        onclick="togglePassword('password')"
                    >
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
            </div>

            <div class="form-options">
                <a
                    href="forgotpassword.php"
                    class="forgot-password"
                >
                    Forgot password?
                </a>
            </div>

            <button
                type="submit"
                name="login"
                id="login-btn"
            >
                Log In
            </button>

            <div class="form-footer">
                Need an account?

                <a
                    href="<?php echo htmlspecialchars($registerPath); ?>"
                    class="signup-link"
                >
                    <?php echo htmlspecialchars($registerLabel); ?>
                </a>
            </div>

        </form>

    </div>

    <script>
        function togglePassword(fieldId) {
            const input = document.getElementById(fieldId);
            const icon = input.parentElement
                .querySelector('.input-icon-right i');

            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye-slash';
            }
        }
    </script>

</body>
</html>