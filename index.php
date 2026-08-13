<?php
$logoutMessage = isset($_GET['logout']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediCare — Patient Portal</title>
    <link rel="stylesheet" href="assets/css/landing_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="landing-page">

    <?php if ($logoutMessage): ?>
        <div class="landing-alert">You have been logged out successfully.</div>
    <?php endif; ?>

    <header class="landing-nav">
        <a href="index.php" class="brand">
            <span>MediCare</span>
        </a>
        <ul class="nav-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
        <div class="nav-actions">
            <a href="portal-select.php?action=login" class="nav-signin">Sign In</a>
            <a href="portal-select.php?action=register" class="btn-primary">Get started</a>
        </div>
    </header>

    <section class="landing-hero">
        <div class="hero-content">

            <h1>Your Hospital Experience, <span class="accent">Made Easier</span></h1>
            <p class="hero-sub">
                Book appointments, view your results, and manage your healthcare journey—all in one secure portal.
                Patients, staff, and administrators each have their own sign-in.
            </p>
        </div>
    </section>


    <footer class="landing-footer">
        &copy; <?php echo date('Y'); ?> MediCare Outpatient Portal
    </footer>

</body>
</html>
