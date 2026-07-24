<?php

require_once __DIR__ . '/includes/portal.php';

$action = strtolower(trim($_GET['action'] ?? 'login'));
if (!in_array($action, ['login', 'register'], true)) {
    $action = 'login';
}

$isLogin = $action === 'login';
$pageTitle = $isLogin ? 'Choose how to sign in' : 'Choose how to register';
$pageLead = $isLogin
    ? 'Select your role. You will only be able to sign in if your account matches that role.'
    : 'Patients can register freely. Staff and admin need an invitation code from your clinic.';

$portals = [
    [
        'key' => 'patient',
        'icon' => 'fa-user-injured',
        'title' => 'Patient',
        'desc' => 'Access appointments, results, and your health records.',
        'hint' => '',
    ],
    [
        'key' => 'staff',
        'icon' => 'fa-user-md',
        'title' => 'Staff',
        'desc' => 'For doctors, nurses, and clinic staff.',
        'hint' => $isLogin ? '' : 'Invitation code required',
    ],
    [
        'key' => 'admin',
        'icon' => 'fa-shield-halved',
        'title' => 'Administrator',
        'desc' => 'Manage the clinic system and users.',
        'hint' => $isLogin ? '' : 'Invitation code required',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> — MediCare</title>
    <link rel="stylesheet" href="assets/css/portal_select_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="portal-select-page">
    <div class="portal-select-wrap">
        <header class="portal-select-header">
            <a href="index.php" class="home-link"><i class="fas fa-arrow-left"></i> Back to home</a>
            <div class="logo">
                <img src="assets/images/logo.png" alt="MediCare">
            </div>
            <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
            <p><?php echo htmlspecialchars($pageLead); ?></p>
        </header>

        <div class="portal-cards">
            <?php foreach ($portals as $portal): ?>
                <?php
                if ($isLogin) {
                    $href = 'login.php?portal=' . urlencode($portal['key']);
                    $btnLabel = 'Sign in as ' . $portal['title'];
                } else {
                    $href = portalRegisterPath($portal['key']);
                    $btnLabel = 'Register as ' . $portal['title'];
                }
                $cardClass = in_array($portal['key'], ['staff', 'admin'], true) ? 'portal-card staff-admin' : 'portal-card';
                ?>
                <article class="<?php echo $cardClass; ?>">
                    <div class="card-icon"><i class="fas <?php echo $portal['icon']; ?>"></i></div>
                    <h2><?php echo htmlspecialchars($portal['title']); ?></h2>
                    <p><?php echo htmlspecialchars($portal['desc']); ?></p>
                    <a class="btn-portal" href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($btnLabel); ?></a>
                    <?php if ($portal['hint'] !== ''): ?>
                        <p class="hint"><?php echo htmlspecialchars($portal['hint']); ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
