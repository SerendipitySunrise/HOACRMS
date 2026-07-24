<?php
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/portal.php';
require_once __DIR__ . '/includes/validation.php';

$message = '';
$messageType = '';
$formData = [];
$prefillInvite = trim($_GET['access'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $formData = [
        'fname' => trim($_POST['fname'] ?? ''),
        'lname' => trim($_POST['lname'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone-number'] ?? ''),
        'invitation_code' => trim($_POST['invitation_code'] ?? '')
    ];
    
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm-password'] ?? '';

    // Validation
    if (!registrationInvitationValid($formData['invitation_code'], ADMIN_REGISTRATION_ACCESS_KEY)) {
        $message = 'Invalid admin invitation code. Only authorized personnel may create admin accounts.';
        $messageType = 'error';
    } elseif ($formData['fname'] === '' || $formData['lname'] === '' || $formData['email'] === '' || $password === '' || $confirmPassword === '') {
        $message = 'Please complete all required fields.';
        $messageType = 'error';
    } elseif ($password !== $confirmPassword) {
        $message = 'Passwords do not match.';
        $messageType = 'error';
    } elseif (($pwdError = validatePasswordStrength($password)) !== null) {
        $message = $pwdError;
        $messageType = 'error';
    } elseif ($formData['phone'] !== '' && ($phoneError = validatePhilippinePhone($formData['phone'])) !== null) {
        $message = $phoneError;
        $messageType = 'error';
    } else {
        // Check if email exists
        $stmt = mysqli_prepare($conn, 'SELECT UserID FROM users WHERE Email = ?');
        mysqli_stmt_bind_param($stmt, 's', $formData['email']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) > 0) {
            $message = 'Email is already registered.';
            $messageType = 'error';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $roleID = 1; // Admin role
            $sex = 'Not Specified';
            $contact = $formData['phone'] !== '' ? $formData['phone'] : null;

            mysqli_begin_transaction($conn);

            // Insert user
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
                $formData['fname'],
                $formData['lname'],
                $formData['email'],
                $hashedPassword,
                $sex,
                $contact
            );

            if (!mysqli_stmt_execute($stmt)) {
                mysqli_rollback($conn);
                $message = 'Registration failed. Please try again.';
                $messageType = 'error';
            } else {
                $userId = (int) mysqli_insert_id($conn);
                
                // Insert admin record
                $adminStmt = mysqli_prepare($conn, 'INSERT INTO admin (UserID) VALUES (?)');
                mysqli_stmt_bind_param($adminStmt, 'i', $userId);

                if (mysqli_stmt_execute($adminStmt)) {
                    mysqli_commit($conn);
                    header('Location: login.php?portal=admin&registered=success');
                    exit();
                }

                mysqli_rollback($conn);
                $message = 'Registration failed. Please try again.';
                $messageType = 'error';
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
    <title>Admin Registration - MediCare</title>
    <link rel="stylesheet" href="assets/css/register_admin_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="main-container">
        <div class="back-link">
            <a href="portal-select.php?action=register">
                <i class="fas fa-arrow-left"></i> Change registration type
            </a>
        </div>

        <div class="brand-header">
            <div class="logo">
                <img src="assets/images/logo.png" alt="MediCare Logo" class="logo-img" onerror="this.style.display='none'">
            </div>
            <h1>Administrator Registration</h1>
            <p class="subtitle">Invitation-only admin account setup</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form class="form" action="register_admin.php" method="POST">
            <!-- Invitation Code -->
            <div class="form-group">
                <label for="invitation_code" class="form-label">
                    Admin Invitation Code
                </label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-lock"></i></span>
                    <input 
                        type="password" 
                        id="invitation_code" 
                        name="invitation_code" 
                        placeholder="Enter invitation code from organization" 
                        required 
                        autocomplete="off"
                        value="<?php echo htmlspecialchars($_POST['invitation_code'] ?? $prefillInvite); ?>"
                    >
                    <button type="button" class="input-icon-right" onclick="togglePassword('invitation_code')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <!-- Name -->
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <div class="name-row">
                    <div class="input-wrapper name-field">
                        <span class="input-icon-left"><i class="fas fa-user"></i></span>
                        <input 
                            type="text" 
                            id="fname" 
                            name="fname" 
                            placeholder="First Name" 
                            required
                            value="<?php echo htmlspecialchars($formData['fname'] ?? ''); ?>"
                        >
                    </div>
                    <div class="input-wrapper name-field">
                        <span class="input-icon-left"><i class="fas fa-user"></i></span>
                        <input 
                            type="text" 
                            id="lname" 
                            name="lname" 
                            placeholder="Last Name" 
                            required
                            value="<?php echo htmlspecialchars($formData['lname'] ?? ''); ?>"
                        >
                    </div>
                </div>
            </div>

            <!-- Email -->
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-envelope"></i></span>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        placeholder="admin@clinic.example" 
                        required
                        value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>"
                    >
                </div>
            </div>

            <!-- Phone -->
            <div class="form-group">
                <label for="phone-number" class="form-label">
                    Phone Number <span class="optional">(optional)</span>
                </label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-phone"></i></span>
                    <input 
                        type="tel" 
                        id="phone-number" 
                        name="phone-number" 
                        placeholder="09123456789" 
                        maxlength="11"
                        value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>"
                    >
                </div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-lock"></i></span>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="Min. 8 characters" 
                        required
                        autocomplete="new-password"
                    >
                    <button type="button" class="input-icon-right" onclick="togglePassword('password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="password-strength">
                    <span class="strength-text" id="strengthText">Password strength</span>
                    <div class="strength-bar">
                        <div class="strength-bar-fill" id="strengthBar"></div>
                    </div>
                </div>
                <div class="password-requirements">
                    <ul>
                        <li id="req-length" class="invalid"><i class="fas fa-times-circle"></i> At least 8 characters</li>
                        <li id="req-uppercase" class="invalid"><i class="fas fa-times-circle"></i> Uppercase letter</li>
                        <li id="req-lowercase" class="invalid"><i class="fas fa-times-circle"></i> Lowercase letter</li>
                        <li id="req-number" class="invalid"><i class="fas fa-times-circle"></i> Number</li>
                        <li id="req-special" class="invalid"><i class="fas fa-times-circle"></i> Special character</li>
                    </ul>
                </div>
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
                <label for="confirm-password" class="form-label">Confirm Password</label>
                <div class="input-wrapper">
                    <span class="input-icon-left"><i class="fas fa-check-circle"></i></span>
                    <input 
                        type="password" 
                        id="confirm-password" 
                        name="confirm-password" 
                        placeholder="Re-enter your password" 
                        required
                        autocomplete="new-password"
                    >
                    <button type="button" class="input-icon-right" onclick="togglePassword('confirm-password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div id="matchMessage" class="match-message"></div>
            </div>

            <button type="submit" id="submit-btn">
                <i class="fas fa-user-shield"></i> Create Admin Account
            </button>

            <div class="form-footer">
                <a href="login.php?portal=admin">
                    <i class="fas fa-sign-in-alt"></i> Already have an account? Sign in
                </a>
            </div>
        </form>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(fieldId) {
            const input = document.getElementById(fieldId);
            const icon = input.parentElement.querySelector('.input-icon-right i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Real-time password validation
        document.addEventListener('DOMContentLoaded', function() {
            const passwordInput = document.getElementById('password');
            const confirmInput = document.getElementById('confirm-password');
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            const matchMessage = document.getElementById('matchMessage');

            function checkPasswordStrength(password) {
                let score = 0;
                const checks = {
                    length: password.length >= 8,
                    uppercase: /[A-Z]/.test(password),
                    lowercase: /[a-z]/.test(password),
                    number: /[0-9]/.test(password),
                    special: /[!@#$%^&*(),.?":{}|<>]/.test(password)
                };

                // Update requirement list
                const reqLength = document.getElementById('req-length');
                const reqUppercase = document.getElementById('req-uppercase');
                const reqLowercase = document.getElementById('req-lowercase');
                const reqNumber = document.getElementById('req-number');
                const reqSpecial = document.getElementById('req-special');

                reqLength.className = checks.length ? 'valid' : 'invalid';
                reqLength.innerHTML = checks.length ? '<i class="fas fa-check-circle"></i> At least 8 characters' : '<i class="fas fa-times-circle"></i> At least 8 characters';
                
                reqUppercase.className = checks.uppercase ? 'valid' : 'invalid';
                reqUppercase.innerHTML = checks.uppercase ? '<i class="fas fa-check-circle"></i> Uppercase letter' : '<i class="fas fa-times-circle"></i> Uppercase letter';
                
                reqLowercase.className = checks.lowercase ? 'valid' : 'invalid';
                reqLowercase.innerHTML = checks.lowercase ? '<i class="fas fa-check-circle"></i> Lowercase letter' : '<i class="fas fa-times-circle"></i> Lowercase letter';
                
                reqNumber.className = checks.number ? 'valid' : 'invalid';
                reqNumber.innerHTML = checks.number ? '<i class="fas fa-check-circle"></i> Number' : '<i class="fas fa-times-circle"></i> Number';
                
                reqSpecial.className = checks.special ? 'valid' : 'invalid';
                reqSpecial.innerHTML = checks.special ? '<i class="fas fa-check-circle"></i> Special character' : '<i class="fas fa-times-circle"></i> Special character';

                // Calculate score
                Object.values(checks).forEach(check => {
                    if (check) score++;
                });

                const strengthMap = {
                    0: { label: 'Very Weak', color: '#ef4444', percentage: 20 },
                    1: { label: 'Weak', color: '#f59e0b', percentage: 40 },
                    2: { label: 'Fair', color: '#fbbf24', percentage: 60 },
                    3: { label: 'Good', color: '#22c55e', percentage: 80 },
                    4: { label: 'Strong', color: '#16a34a', percentage: 95 },
                    5: { label: 'Very Strong', color: '#059669', percentage: 100 }
                };

                const strength = strengthMap[score];
                strengthBar.style.width = strength.percentage + '%';
                strengthBar.style.background = strength.color;
                strengthText.textContent = strength.label;
                strengthText.style.color = strength.color;

                return { checks, score };
            }

            if (passwordInput) {
                passwordInput.addEventListener('input', function() {
                    const password = this.value;
                    checkPasswordStrength(password);
                    
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

            function checkMatch(password, confirm) {
                if (confirm.length === 0) {
                    matchMessage.textContent = '';
                    matchMessage.className = 'match-message';
                    return;
                }
                
                if (password === confirm) {
                    matchMessage.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                    matchMessage.className = 'match-message success';
                } else {
                    matchMessage.innerHTML = '<i class="fas fa-exclamation-circle"></i> Passwords do not match';
                    matchMessage.className = 'match-message error';
                }
            }

            // Form submission handling
            document.querySelector('form').addEventListener('submit', function(e) {
                const btn = document.getElementById('submit-btn');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating account...';
                btn.disabled = true;
            });

            // Auto-hide messages
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