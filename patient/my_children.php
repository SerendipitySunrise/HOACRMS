<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/guardian_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_helper.php';
require_once __DIR__ . '/../includes/booking_rules.php';

requireRole('Patient');
$userID = (int) $_SESSION['UserID'];

/* Short age text for the list (e.g. "4 yrs", "7 mos"). */
function mcAge(?string $dob): string
{
    if (!$dob) {
        return '';
    }
    try {
        $birth = new DateTime($dob);
    } catch (Exception $e) {
        return '';
    }
    $diff = $birth->diff(new DateTime('today'));
    if ($diff->y >= 1) {
        return $diff->y . ($diff->y === 1 ? ' yr' : ' yrs');
    }
    return $diff->m . ($diff->m === 1 ? ' mo' : ' mos');
}

function mcDate(?string $dob): string
{
    if (!$dob) {
        return '';
    }
    try {
        return (new DateTime($dob))->format('M j, Y');
    } catch (Exception $e) {
        return '';
    }
}

/* Messages for the redirects sent by my_children_add.php */
$flash = null;
if (isset($_GET['ok'])) {
    $flash = ['ok', 'Child added. Staff will confirm your guardian link at the first visit.'];
} elseif (isset($_GET['err'])) {
    $errors = [
        '1'        => 'Fill in every field before saving.',
        '2'        => 'Enter a valid date of birth.',
        'notminor' => 'This form is for children under 18. Patients 18 and over need their own account.',
        'max'      => 'This child already has the maximum number of guardians.',
        '3'        => 'The record could not be saved. Try again.',
    ];
    $code  = (string) $_GET['err'];
    $flash = ['err', $errors[$code] ?? 'Something went wrong. Try again.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Children | Curora</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles.css">
    <link rel="stylesheet" href="../assets/css/patient/my_children.css">
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">

</head>
<body>
    <main class="mc">
        <h2>My Children</h2>
        <p>Linked Children/Dependents</p>

        <?php if ($flash): ?>
            <div class="mc-flash <?= $flash[0]; ?>" role="<?= $flash[0] === 'err' ? 'alert' : 'status'; ?>">
                <?= htmlspecialchars($flash[1], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <?php
        logAudit(db_pdo(), 'GUARDIAN_VIEW', 'guardian_links', 0, null, json_encode(['guardian_user_id' => $userID]));
        $stmt = mysqli_prepare(
            $conn,
            'SELECT p.PatientID, COALESCE(pu.FirstName, p.FirstName, "") AS FirstName,
                    COALESCE(pu.LastName, p.LastName, "") AS LastName,
                    COALESCE(pu.DateOfBirth, p.DateOfBirth) AS DateOfBirth,
                    g.Relationship, g.Status, g.IsPrimary, g.GuardianLinkID
             FROM guardian_links g
             JOIN patients p ON p.PatientID = g.PatientID
             LEFT JOIN users pu ON pu.UserID = p.UserID
             WHERE g.GuardianUserID = ?
             ORDER BY p.PatientID'
        );
        $children = [];
        if ($stmt && mysqli_stmt_bind_param($stmt, 'i', $userID) && mysqli_stmt_execute($stmt)) {
            $res = mysqli_stmt_get_result($stmt);
            while ($row = $res ? mysqli_fetch_assoc($res) : null) {
                $children[] = $row;
            }
            mysqli_stmt_close($stmt);
        }
        ?>

        <section class="mc-panel" aria-labelledby="linked-title">
            <h2 id="linked-title">Linked children</h2>

            <?php if (!$children): ?>
                <p class="mc-empty">No children linked yet. Use the form below to add your first child.</p>
            <?php else: ?>
                <div class="mc-table-wrap">
                    <table class="mc-table">
                        <thead>
                            <tr>
                                <th scope="col">Child</th>
                                <th scope="col">Date of birth</th>
                                <th scope="col">Your relationship</th>
                                <th scope="col">Guardian link</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($children as $c): ?>
                                <?php
                                $status = (string) ($c['Status'] ?? '');
                                $badge  = in_array($status, ['Verified', 'Unverified', 'Restricted'], true)
                                    ? strtolower($status)
                                    : 'other';
                                $fullName = trim(($c['FirstName'] ?? '') . ' ' . ($c['LastName'] ?? ''));
                                $age = mcAge($c['DateOfBirth'] ?? null);
                                ?>
                                <tr>
                                    <td class="mc-cell-name" data-label="Child">
                                        <span class="mc-name"><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ((int) $c['IsPrimary'] === 1): ?>
                                            <span class="mc-sub">Primary guardian</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Date of birth">
                                        <?= htmlspecialchars(mcDate($c['DateOfBirth'] ?? null), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php if ($age !== ''): ?>
                                            <span class="mc-sub"><?= htmlspecialchars($age, ENT_QUOTES, 'UTF-8'); ?> old</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Relationship"><?= htmlspecialchars($c['Relationship'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="Guardian link">
                                        <span class="mc-badge <?= $badge; ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="mc-note">Unverified means staff haven't checked your ID and proof of relationship yet. Bring both to your child's first visit.</p>
            <?php endif; ?>
        </section>

        <section class="mc-panel" aria-labelledby="add-title">
            <h2 id="add-title">Add a child</h2>

            <form method="POST" action="my_children_add.php">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_child">

                <fieldset>
                    <legend>About your child</legend>
                    <div class="mc-grid">
                        <div class="mc-field">
                            <label for="first_name">First name</label>
                            <input id="first_name" name="first_name" autocomplete="off" required>
                        </div>
                        <div class="mc-field">
                            <label for="last_name">Last name</label>
                            <input id="last_name" name="last_name" autocomplete="off" required>
                        </div>
                        <div class="mc-field full">
                            <label for="date_of_birth">Date of birth</label>
                            <input id="date_of_birth" type="date" name="date_of_birth" max="<?= date('Y-m-d'); ?>" required>
                            <span class="mc-hint">Children must be under 18.</span>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>About you</legend>
                    <div class="mc-grid">
                        <div class="mc-field">
                            <label for="relationship">Your relationship to the child</label>
                            <input id="relationship" name="relationship" placeholder="Mother, father, legal guardian" required>
                        </div>
                        <div class="mc-field">
                            <label for="phone">Your phone number</label>
                            <input id="phone" type="tel" name="phone" autocomplete="tel" required>
                        </div>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Emergency contact</legend>
                    <div class="mc-grid">
                        <div class="mc-field">
                            <label for="emergency_contact_name">Contact name</label>
                            <input id="emergency_contact_name" name="emergency_contact_name" autocomplete="off" required>
                        </div>
                        <div class="mc-field">
                            <label for="emergency_contact_no">Contact phone number</label>
                            <input id="emergency_contact_no" type="tel" name="emergency_contact_no" autocomplete="off" required>
                        </div>
                    </div>
                </fieldset>

                <button type="submit" class="mc-btn">Add child</button>
            </form>
        </section>
    </main>
</body>
</html>