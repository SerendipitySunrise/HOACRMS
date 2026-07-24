<?php
session_start();

$host = "localhost";
$username = "root";
$password = "";
$database = "hoacrms";

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Database connection failed.");
}

$message = "";
if (isset($_GET['reset'])) {
    $message = "Password changed successfully. Please log in.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // Empty Fields
    if (empty($email) || empty($password)) {
        $message = "Please enter both email and password.";
        if (isset($_GET['expired'])) {
            $message = "Session expired. Please log in again.";
}
    }
    else{
    
        // Search by Email
        $stmt = mysqli_prepare($conn,
        "SELECT * FROM users
        WHERE Email = ?");

        mysqli_stmt_bind_param($stmt,"s",$email);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if(mysqli_num_rows($result)==1){

            $user = mysqli_fetch_assoc($result);

            // Check if locked
            if($user['LockUntil'] != NULL &&
                strtotime($user['LockUntil']) > time()){

                $message = "Your account has been locked due to multiple failed login attempts.";

            }else{

                if(password_verify($password,$user['Password'])){

                    // Reset failed attempts
                    mysqli_query($conn,"
                    UPDATE users
                    SET FailedAttempts=0,
                        LockUntil=NULL
                    WHERE UserID=".$user['UserID']);

                    session_regenerate_id(true);

                    $_SESSION['UserID'] = $user['UserID'];
                    $_SESSION['FirstName'] = $user['FirstName'];
                $_SESSION['LastName'] = $user['LastName'];
                    $_SESSION['Role'] = $user['Role'];

                    $_SESSION['LAST_ACTIVITY'] = time();

                    header("Location: dashboard.php");
                    exit();

                }else{

                    $attempts = $user['FailedAttempts'] + 1;

                    if($attempts >=5){

                        $lock = date("Y-m-d H:i:s",strtotime("+15 minutes"));

                        $update = mysqli_prepare($conn,"
                        UPDATE users
                        SET FailedAttempts=?,
                            LockUntil=?
                        WHERE UserID=?");

                        mysqli_stmt_bind_param($update,"isi",
                        $attempts,$lock,$user['UserID']);

                        mysqli_stmt_execute($update);

                        $message = "Your account has been locked due to multiple failed login attempts.";

                    }else{

                        $update = mysqli_prepare($conn,"
                        UPDATE users
                        SET FailedAttempts=?
                        WHERE UserID=?");

                        mysqli_stmt_bind_param($update,"ii",
                        $attempts,$user['UserID']);

                        mysqli_stmt_execute($update);

                        $message = "Invalid email or password.";
                    }

                }

            }

        }else{

            $message = "Invalid email or password.";

        }

    }

}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MediCare</title>
    <link rel="stylesheet" href="assets/css/login_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <div class="main-container">
        <div class="brand-header">
            <div class="logo">
                <img src="assets/images/logo.png" alt="MediCare Logo" class="logo-img">
            </div>
            <h1>Welcome Back</h1>
            <p class="subtitle">Sign in to access your portal</p>
        </div>

        <?php if(!empty($message)): ?>
            <div class="error-message" style="background-color: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 20px; width: 100%; text-align: center; font-size: 14px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form class="form" action="login.php" method="POST">
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
                Don't have an account? <a href="signup.php" class="signup-link">Create one</a>
            </div>
        </form>
    </div>

</body>
</html>