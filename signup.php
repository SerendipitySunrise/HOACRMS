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

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $fname = trim($_POST['fname']);
    $lname = trim($_POST['lname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone-number']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm-password'];

    if (
        empty($fname) ||
        empty($lname) ||
        empty($email) ||
        empty($password) ||
        empty($confirmPassword)
    ) {

        $message = "Please complete all required fields.";

    }
    elseif ($password != $confirmPassword) {

        $message = "Passwords do not match.";

    }
    elseif (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[\W]/', $password)
    ) {

        $message = "Password must be at least 8 characters and contain uppercase, lowercase, number, and special character.";

    }
    elseif (!preg_match('/^09\d{9}$/', $phone)) {

        $message = "Please enter a valid Philippine phone number.";

    }
    else {
        $stmt = mysqli_prepare($conn,
        "SELECT UserID FROM users WHERE Email = ?");

        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) > 0) {

            $message = "Email is already registered.";

        } else {

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $roleID = 3;         
        $sex = "Not Specified"; 

        $stmt = mysqli_prepare($conn,
        "INSERT INTO users
        (RoleID, FirstName, LastName, Email, Password, Sex, ContactNumber)
        VALUES (?, ?, ?, ?, ?, ?, ?)");

        mysqli_stmt_bind_param(
            $stmt,
            "issssss",
            $roleID,
            $fname,
            $lname,
            $email,
            $hashedPassword,
            $sex,
            $phone
        );

        if (mysqli_stmt_execute($stmt)) {

            header("Location: login.php");
            exit();

        } else {

            $message = "Registration failed.";

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
    <title>Sign Up - MediCare</title>
    <link rel="stylesheet" href="assets/css/signup_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="main-container">
        <div class="brand-header">
            <div class="logo">
                <img src="assets/images/logo.png" alt="MediCare Logo" class="logo-img">
            </div>
            <h1>Create an Account</h1>
            <p class="subtitle">Set up your portal in minutes</p>
        </div>

        <?php if (!empty($message)): ?>
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
                    <input type="number" id="phone-number" name="phone-number" placeholder="0912-3456-789" required>
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

            <div class="form-options">
                <label class="form-check-label">
                    <input type="checkbox" name="terms" required>
                    <span>I agree to the Terms and Conditions & Data Privacy Policy. I consent to the collection and processing of my personal and medical information for outpatient healthcare management purposes.</span>
                </label>
            </div>

            <button type="submit" name="signup" id="signup-btn">Create Account</button>

            <div class="form-footer">
                Already have an account? <a href="login.php" class="login-link">Sign in</a>
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

</body>
</html>