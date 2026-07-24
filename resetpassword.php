<?php
$host = "localhost";
$username = "root";
$password = "";
$database = "hoacrms";

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Database connection failed.");
}

$message = "";
$messageType = "";
$validToken = false;

if (!isset($_GET['token'])) {
    $message = "Invalid reset link. Please request a new password reset.";
    $messageType = "error";
} else {
    $token = $_GET['token'];

    // Check if token is valid and not expired
    $stmt = mysqli_prepare($conn,
        "SELECT UserID
        FROM users
        WHERE ResetToken = ?
        AND TokenExpiry > NOW()");

    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        $validToken = true;

        if ($_SERVER["REQUEST_METHOD"] == "POST") {
            $password = $_POST['password'];
            $confirmPassword = $_POST['confirm-password'];

            if (empty($password) || empty($confirmPassword)) {
                $message = "Please complete all fields.";
                $messageType = "error";
            } elseif ($password != $confirmPassword) {
                $message = "Passwords do not match.";
                $messageType = "error";
            } elseif (
                strlen($password) < 8 ||
                !preg_match('/[A-Z]/', $password) ||
                !preg_match('/[a-z]/', $password) ||
                !preg_match('/[0-9]/', $password) ||
                !preg_match('/[\W]/', $password)
            ) {
                $message = "Password must be at least 8 characters and contain uppercase, lowercase, number, and special character.";
                $messageType = "error";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $update = mysqli_prepare($conn,
                    "UPDATE users
                    SET Password = ?,
                        ResetToken = NULL,
                        TokenExpiry = NULL
                    WHERE UserID = ?");

                mysqli_stmt_bind_param($update, "si", $hashedPassword, $user['UserID']);
                mysqli_stmt_execute($update);

                header("Location: login.php?reset=success");
                exit();
            }
        }
    } else {
        $message = "This reset link is invalid or has expired.";
        $messageType = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - MediCare</title>
    <link rel="stylesheet" href="assets/css/resetpassword_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="main-container">
    <div class="brand-header">
        <div class="logo">
            <img src="assets/images/logo.png" alt="MediCare Logo" class="logo-img">
        </div>
        <h1><?php echo $validToken ? 'Create New Password' : 'Invalid Link'; ?></h1>
        <p class="subtitle">
            <?php echo $validToken ? 'Enter your new password below' : 'The reset link is invalid or has expired.'; ?>
        </p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message-container">
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($validToken): ?>
        <form class="form" method="POST" action="">
            <div class="form-group">
                <label for="password" class="form-label">New Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="Enter new password" 
                        required
                        autocomplete="new-password"
                    >
                    <button type="button" class="input-icon-right" onclick="togglePassword('password')">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
                <div class="password-strength">
                    <span id="strengthText">Password strength</span>
                    <div class="strength-bar">
                        <div class="strength-bar-fill" id="strengthBar"></div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm-password" class="form-label">Confirm Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left">
                        <i class="fas fa-check-circle"></i>
                    </span>
                    <input 
                        type="password" 
                        id="confirm-password" 
                        name="confirm-password" 
                        placeholder="Re-enter your password" 
                        required
                        autocomplete="new-password"
                    >
                    <button type="button" class="input-icon-right" onclick="togglePassword('confirm-password')">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
                <div id="matchMessage" style="font-size: 12px; margin-top: 4px;"></div>
            </div>

            <div class="login-requirements">
                <strong><i class="fas fa-shield-alt"></i> Password Requirements:</strong>
                <ul>
                    <li><i class="fas fa-check-circle"></i> At least 8 characters long</li>
                    <li><i class="fas fa-check-circle"></i> Contains uppercase and lowercase letters</li>
                    <li><i class="fas fa-check-circle"></i> Contains at least one number</li>
                    <li><i class="fas fa-check-circle"></i> Contains at least one special character</li>
                </ul>
            </div>

            <button type="submit" id="reset-btn">
                <i class="fas fa-key"></i> Reset Password
            </button>
        </form>

        <div class="form-footer">
            <i class="fas fa-chevron-left"></i>
            <a href="login.php" class="signin-link">Remember your password? <strong>Sign in</strong></a>
        </div>
    <?php else: ?>
        <div style="text-align: center; margin-top: 20px;">
            <a href="forgotpassword.php" class="signin-link">
                <i class="fas fa-envelope"></i> Request a new reset link
            </a>
        </div>
    <?php endif; ?>
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

    document.addEventListener('DOMContentLoaded', function() {
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm-password');
        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');
        const matchMessage = document.getElementById('matchMessage');

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                const strength = checkPasswordStrength(password);
                
                strengthBar.style.width = strength.percentage + '%';
                strengthBar.style.background = strength.color;
                strengthText.textContent = strength.label;
                strengthText.style.color = strength.color;

                if (confirmInput.value.length > 0) {
                    checkMatch(password, confirmInput.value);
                }
            });
        }

        if (confirmInput) {
            confirmInput.addEventListener('input', function() {
                const password = document.getElementById('password').value;
                checkMatch(password, this.value);
            });
        }

        function checkPasswordStrength(password) {
            let score = 0;
            
            if (password.length >= 8) score++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
            if (/\d/.test(password)) score++;
            if (/[\W]/.test(password)) score++;
            
            const strengthMap = {
                0: { label: 'Very Weak', color: '#ef4444', percentage: 20 },
                1: { label: 'Weak', color: '#f59e0b', percentage: 40 },
                2: { label: 'Fair', color: '#fbbf24', percentage: 60 },
                3: { label: 'Good', color: '#22c55e', percentage: 80 },
                4: { label: 'Strong', color: '#16a34a', percentage: 100 }
            };
            
            return strengthMap[score];
        }

        function checkMatch(password, confirm) {
            if (confirm.length === 0) {
                matchMessage.textContent = '';
                matchMessage.style.color = '';
                return;
            }
            
            if (password === confirm) {
                matchMessage.innerHTML = '<i class="fas fa-check-circle" style="color: #16a34a;"></i> Passwords match';
                matchMessage.style.color = '#16a34a';
            } else {
                matchMessage.innerHTML = '<i class="fas fa-exclamation-circle" style="color: #ef4444;"></i> Passwords do not match';
                matchMessage.style.color = '#ef4444';
            }
        }

        const message = document.querySelector('.message');
        if (message) {
            setTimeout(() => {
                message.style.transition = 'opacity 0.5s ease';
                message.style.opacity = '0';
                setTimeout(() => {
                    message.style.display = 'none';
                }, 500);
            }, 5000);
        }
    });
</script>

</body>
</html>