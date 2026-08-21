<?php

$action = strtolower(trim((string) ($_GET['action'] ?? 'login')));

if (!in_array($action, ['login', 'register'], true)) {
    $action = 'login';
}

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

function getPortalTarget(string $portal, string $action): string
{
    if ($action === 'login') {
        return 'auth/login.php?portal=' . urlencode($portal);
    }

    return match ($portal) {
        'patient' => 'auth/signup.php',
        'admin' => 'auth/register_admin.php',
        'staff', 'doctor' => 'auth/register_staff.php',
        default => 'auth/signup.php',
    };
}

$pageTitle = $action === 'register'
    ? 'Create an Account'
    : 'Choose Your Portal';

$pageSubtitle = $action === 'register'
    ? 'Select the type of account you want to create.'
    : 'Select the portal that matches your account.';
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
        <?php echo escapeHtml($pageTitle); ?> — MediCare
    </title>

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
                <img
                    src="assets/images/logo.png"
                    alt="MediCare Logo"
                >
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

                <?php
                $target = getPortalTarget(
                    $portal['key'],
                    $action
                );
                ?>

                <article class="portal-card
                    <?php
                    echo in_array(
                        $portal['key'],
                        ['staff', 'admin'],
                        true
                    )
                        ? 'staff-admin'
                        : '';
                    ?>"
                >

                    <div class="card-icon">
                        <i class="fas <?php
                            echo escapeHtml($portal['icon']);
                        ?>"></i>
                    </div>

                    <h2>
                        <?php echo escapeHtml($portal['title']); ?>
                    </h2>

                    <p>
                        <?php
                        echo escapeHtml($portal['description']);
                        ?>
                    </p>

                    <a
                        href="<?php echo escapeHtml($target); ?>"
                        class="btn-portal"
                    >
                        <?php
                        echo $action === 'register'
                            ? 'Register'
                            : 'Sign In';
                        ?>
                    </a>

                    <div class="hint">
                        <?php echo escapeHtml($portal['hint']); ?>
                    </div>

                </article>

            <?php endforeach; ?>

        </section>

    </main>

</body>
</html>