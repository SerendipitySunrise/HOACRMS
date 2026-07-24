<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/portal.php';

$portal = normalizePortal($_GET['portal'] ?? $_POST['portal'] ?? null);
if ($portal === null) {
    header('Location: portal-select.php?action=login');
    exit();
}

$expectedRole = portalToRoleName($portal);
$portalLabel = portalDisplayName($portal);

$message = '';
if (isset($_GET['reset'])) {
    $message = 'Password changed successfully. Please log in.';
}
if (isset($_GET['expired'])) {
    $message = 'Session expired. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $portal = normalizePortal($_POST['portal'] ?? null) ?? $portal;
    $expectedRole = portalToRoleName($portal);
    $portalLabel = portalDisplayName($portal);

    if ($email === '' || $password === '') {
        $message = 'Please enter both email and password.';
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT u.*, r.RoleName
             FROM users u
             INNER JOIN roles r ON u.RoleID = r.RoleID
             WHERE u.Email = ?'
        );
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);

            if ($user['RoleName'] !== $expectedRole) {
                $message = 'This email is not registered as a ' . strtolower($portalLabel) . ' account. Choose the correct portal or contact support.';
            } elseif (strcasecmp($user['Status'], 'Active') !== 0) {
                $message = 'Your account is not active. Please contact the clinic administrator.';
            } elseif (
                $user['LockUntil'] !== null
                && strtotime($user['LockUntil']) > time()
            ) {
                $message = 'Your account has been locked due to multiple failed login attempts.';
            } elseif (password_verify($password, $user['Password'])) {
                mysqli_query(
                    $conn,
                    'UPDATE users SET FailedAttempts = 0, LockUntil = NULL WHERE UserID = ' . (int) $user['UserID']
                );

                session_regenerate_id(true);

                $_SESSION['UserID'] = (int) $user['UserID'];
                $_SESSION['FirstName'] = $user['FirstName'];
                $_SESSION['LastName'] = $user['LastName'];
                $_SESSION['RoleID'] = (int) $user['RoleID'];
                $_SESSION['RoleName'] = $user['RoleName'];
                $_SESSION['LAST_ACTIVITY'] = time();

                switch ($user['RoleName']) {
                    case 'Admin':
                        header('Location: admin_dashboard.php');
                        break;
                    case 'Doctor':
                        header('Location: staff_dashboard.php');
                        break;
                    case 'Patient':
                        header('Location: patient_dashboard.php');
                        break;
                    default:
                        header('Location: dashboard.php');
                        break;
                }
                exit();
            } else {
                $attempts = (int) $user['FailedAttempts'] + 1;

                if ($attempts >= 5) {
                    $lock = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $update = mysqli_prepare(
                        $conn,
                        'UPDATE users SET FailedAttempts = ?, LockUntil = ? WHERE UserID = ?'
                    );
                    mysqli_stmt_bind_param($update, 'isi', $attempts, $lock, $user['UserID']);
                    mysqli_stmt_execute($update);
                    $message = 'Your account has been locked due to multiple failed login attempts.';
                } else {
                    $update = mysqli_prepare(
                        $conn,
                        'UPDATE users SET FailedAttempts = ? WHERE UserID = ?'
                    );
                    mysqli_stmt_bind_param($update, 'ii', $attempts, $user['UserID']);
                    mysqli_stmt_execute($update);
                    $message = 'Invalid email or password.';
                }
            }
        } else {
            $message = 'Invalid email or password.';
        }
    }
}

$loginTitle = portalLoginTitle($portal);
$registerPath = portalRegisterPath($portal);
$registerLabel = $portal === 'patient' ? 'Create a patient account' : 'Register as ' . strtolower($portalLabel);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($loginTitle); ?> — MediCare</title>
    <link rel="stylesheet" href="assets/css/login_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="main-container">
        <div class="brand-header">
            <p style="margin-bottom: 16px;"><a href="portal-select.php?action=login" style="color:#149385;text-decoration:none;font-size:14px;">&larr; Change portal</a></p>
            <div class="logo">
                <img src="assets/images/logo.png" alt="MediCare Logo" class="logo-img">
            </div>
            <h1><?php echo htmlspecialchars($loginTitle); ?></h1>
            <p class="subtitle"><?php echo htmlspecialchars($portalLabel); ?> portal — use your <?php echo htmlspecialchars(strtolower($portalLabel)); ?> account only</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="error-message" style="background-color: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 20px; width: 100%; text-align: center; font-size: 14px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form class="form" action="login.php?portal=<?php echo urlencode($portal); ?>" method="POST">
            <input type="hidden" name="portal" value="<?php echo htmlspecialchars($portal); ?>">

            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-envelope"></i></span>
                    <input type="email" id="email" name="email" placeholder="you@example.com" required>
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-lock"></i></span>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <span class="input-icon-right"><i class="fas fa-eye-slash"></i></span>
                </div>
            </div>

            <div class="form-options">
                <a href="forgotpassword.php" class="forgot-password">Forgot password?</a>
            </div>

            <button type="submit" name="login" id="login-btn">Log In</button>

            <div class="form-footer">
                Need an account? <a href="<?php echo htmlspecialchars($registerPath); ?>" class="signup-link"><?php echo htmlspecialchars($registerLabel); ?></a>
            </div>
        </form>
    </div>

</body>
</html>
