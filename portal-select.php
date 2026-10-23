<?php

/**
 * portal-select.php
 *
 * Portal chooser. Sign-in cards route to the unified login page. The only
 * self-service account creation left on the public site is PATIENT sign-up.
 * Administrator, Doctor, and Staff accounts are created by an authenticated
 * administrator from Account Management.
 */

$portals = [
    [
        'key' => 'patient',
        'title' => 'Patient Portal',
        'description' => 'Book appointments, view results, and manage your healthcare.',
        'icon' => 'fa-user',
        'hint' => 'For patients',
    ],
    [
        'key' => 'staff',
        'title' => 'Staff Portal',
        'description' => 'Manage appointments, patients, and daily clinic operations.',
        'icon' => 'fa-user-nurse',
        'hint' => 'For nurses and clinic staff',
    ],
    [
        'key' => 'doctor',
        'title' => 'Doctor Portal',
        'description' => 'Review appointments, patient records, and clinical information.',
        'icon' => 'fa-user-doctor',
        'hint' => 'For doctors',
    ],
    [
        'key' => 'admin',
        'title' => 'Administrator Portal',
        'description' => 'Manage users, staff, reports, and system settings.',
        'icon' => 'fa-shield-halved',
        'hint' => 'Restricted access',
    ],
];

function escapeHtml(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function getPortalTarget(string $portal): string
{
    return 'auth/login.php?portal=' . urlencode($portal);
}

$pageTitle = 'Choose Your Portal';
$pageSubtitle = 'Sign in to the portal that matches your account.';
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
        <?php echo escapeHtml($pageTitle); ?> — Curora
    </title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">

    <link
        rel="stylesheet"
        href="assets/css/portal_select.css"
    >

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >
</head>

<body class="portal-select-page">

    <main class="portal-select-wrap">

        <header class="portal-select-header">

            <a href="index.php" class="home-link">
                <i class="fas fa-arrow-left"></i>
                Back to home
            </a>

            <div class="logo">
                <img src="assets/images/curora-logo-full.png" alt="Curora Logo">
            </div>

            <h1>
                <?php echo escapeHtml($pageTitle); ?>
            </h1>

            <p>
                <?php echo escapeHtml($pageSubtitle); ?>
            </p>

        </header>

        <section class="portal-cards">

            <?php foreach ($portals as $portal): ?>

                <article class="portal-card
                    <?php
                    echo in_array($portal['key'], ['staff', 'doctor'], true)
                        ? 'staff-admin'
                        : '';
                    ?>">

                    <div class="card-icon">
                        <i class="fas <?php echo escapeHtml($portal['icon']); ?>"></i>
                    </div>

                    <h2>
                        <?php echo escapeHtml($portal['title']); ?>
                    </h2>

                    <p>
                        <?php echo escapeHtml($portal['description']); ?>
                    </p>

                    <a
                        class="btn-portal"
                        href="<?php echo escapeHtml(getPortalTarget($portal['key'])); ?>"
                    >
                        Sign in
                    </a>

                    <div class="hint">
                        <?php echo escapeHtml($portal['hint']); ?>
                    </div>

                </article>

            <?php endforeach; ?>

        </section>

        <section style="margin-top:34px;text-align:center;">
            <p style="color:#475569;margin:0 0 14px;">
                New patient? Create your patient account. Doctor, staff, and
                administrator accounts are created by an administrator.
            </p>
            <a
                href="auth/signup.php"
                style="display:inline-block;background:#149385;color:#fff;text-decoration:none;font-weight:600;padding:13px 30px;border-radius:10px;"
            >
                Create Account
            </a>
        </section>

    </main>


<script src="assets/js/responsive_nav.js"></script>
</body>
</html>
