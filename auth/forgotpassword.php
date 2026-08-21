<?php
date_default_timezone_set('Asia/Manila');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../PHPMailer/src/Exception.php';
require __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require __DIR__ . '/../PHPMailer/src/SMTP.php';

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

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST['email']);

    if (empty($email)) {

        $message = "Please enter your email.";
        $messageType = "error";

    } else {

        // Check if email exists
        $stmt = mysqli_prepare($conn, "SELECT UserID FROM users WHERE Email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) == 1) {

            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expiry = date("Y-m-d H:i:s", strtotime("+1 hour"));

            // Save token and expiry
            $update = mysqli_prepare($conn,
            "UPDATE users
            SET ResetToken = ?, TokenExpiry = ?
            WHERE Email = ?");

            mysqli_stmt_bind_param($update, "sss", $token, $expiry, $email);

            if (!mysqli_stmt_execute($update)) {
                die("Update Failed: " . mysqli_stmt_error($update));
            }

            // Reset Link (builds from current folder so renaming won't break it)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $resetLink = $baseUrl . '/resetpassword.php?token=' . $token;

            // Send Email
            $mail = new PHPMailer(true);

            try {

                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;

                $mail->Username = 'lagabanroz22@gmail.com';
                $mail->Password = 'zpcf ojvz zkqh dwuq';

                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;

                $mail->setFrom('lagabanroz22@gmail.com', 'MediCare');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Request';

                $mail->Body = "
                <h2>Password Reset</h2>

                <p>Hello,</p>

                <p>We received a request to reset your password.</p>

                <p>
                    <a href='$resetLink'
                    style='background:#2563eb;
                    color:#ffffff;
                    padding:12px 20px;
                    text-decoration:none;
                    border-radius:6px;'>
                    Reset Password
                    </a>
                </p>

                <p>This link will expire in <strong>1 hour</strong>.</p>

                <p>If you didn't request this, you can safely ignore this email.</p>
                ";

                $mail->send();

                $message = "Password reset link has been sent to your email.";
                $messageType = "success";

            } catch (Exception $e) {

                $message = "Unable to send email. Error: " . $mail->ErrorInfo;
                $messageType = "error";

            }

        } else {

            $message = "If the email is registered, a password reset link has been sent.";
            $messageType = "success";

        }

    }

}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - MediCare</title>
    <link rel="stylesheet" href="../assets/css/auth/forgotpassword.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="main-container">
    <div class="brand-header">
        <div class="logo">
            <img src="../assets/images/logo.png" alt="MediCare Logo" class="logo-img">
        </div>
        <h1>Reset Password</h1>
        <p class="subtitle">Enter your email and we'll send you a reset link</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="message-container">
            <div class="message <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        </div>
    <?php endif; ?>

    <form class="form" method="POST" action="">
        <div class="form-group">
            <label for="email" class="form-label">Email Address</label>
            <div class="input-wrapper">
                <span class="input-icon-left">
                    <i class="fas fa-envelope"></i>
                </span>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    placeholder="you@example.com" 
                    required
                    autocomplete="email"
                >
            </div>
        </div>

        <button type="submit" id="reset-btn">
            <i class="fas fa-paper-plane"></i> Send Reset Link
        </button>
    </form>

    <div class="form-footer">
        <i class="fas fa-chevron-left"></i>
        <a href="login.php" class="signin-link">Remember your password? <strong>Sign in</strong></a>
    </div>
</div>

<script>
    document.querySelector('.form').addEventListener('submit', function(e) {
        const btn = document.getElementById('reset-btn');
        const originalText = btn.innerHTML;

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        btn.disabled = true;

    });

    document.addEventListener('DOMContentLoaded', function() {
        const successMsg = document.querySelector('.message.success');
        if (successMsg) {
            setTimeout(() => {
                successMsg.style.transition = 'opacity 0.5s ease';
                successMsg.style.opacity = '0';
                setTimeout(() => {
                    successMsg.style.display = 'none';
                }, 500);
            }, 5000);
        }
    });
</script>

</body>
</html>