<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_helper.php';

requireRole('Staff');

$errors = [];
$success = null;
$linkId = isset($_POST['link_id']) ? (int) $_POST['link_id'] : (isset($_GET['link_id']) ? (int) $_GET['link_id'] : 0);
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($linkId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['verify', 'restrict', 'unrestrict', 'unlink'], true)) {
    if (!csrfValidate($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT g.GuardianLinkID, g.PatientID, g.GuardianUserID, g.Status, g.IsPrimary,
                    CONCAT(u.FirstName, " ", u.LastName) AS GuardianName,
                    CONCAT(pu.FirstName, " ", pu.LastName) AS PatientName
             FROM guardian_links g
             JOIN users u ON u.UserID = g.GuardianUserID
             JOIN patients p ON p.PatientID = g.PatientID
             LEFT JOIN users pu ON pu.UserID = p.UserID
             WHERE g.GuardianLinkID = ? LIMIT 1'
        );
        if ($stmt && mysqli_stmt_bind_param($stmt, 'i', $linkId) && mysqli_stmt_execute($stmt)) {
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$row) {
                $errors[] = 'Guardian link not found.';
            } else {
                if ($action === 'verify') {
                    $proofType = trim($_POST['proof_type'] ?? '');
                    $updSql = 'UPDATE guardian_links SET Status = "Verified", VerifiedAt = NOW(), VerifiedBy = ?';
                    $types = 'i';
                    $params = [$_SESSION['UserID']];
                    if ($proofType !== '') {
                        $updSql .= ', ProofType = ?';
                        $types .= 's';
                        $params[] = $proofType;
                    }
                    $updSql .= ' WHERE GuardianLinkID = ? AND Status = "Unverified"';
                    $types .= 'i';
                    $params[] = $linkId;
                    $upd = mysqli_prepare($conn, $updSql);
                    if ($upd) {
                        mysqli_stmt_bind_param($upd, $types, ...$params);
                        if (mysqli_stmt_execute($upd)) {
                            $affected = mysqli_stmt_affected_rows($upd);
                            mysqli_stmt_close($upd);
                            if ($affected > 0) {
                                $success = 'Guardian link verified.';
                                logAudit(db_pdo(), 'GUARDIAN_VERIFY', 'guardian_links', $linkId, json_encode(['Status' => 'Unverified']), json_encode(['Status' => 'Verified', 'ProofType' => $proofType, 'VerifiedBy' => (int)$_SESSION['UserID']]));
                            } else {
                                $success = 'Guardian link already verified or unchanged.';
                            }
                        } else {
                            mysqli_stmt_close($upd);
                            $errors[] = 'Failed to verify guardian link.';
                        }
                    } else {
                        $errors[] = 'Failed to prepare verification update.';
                    }
                } elseif ($action === 'restrict') {
                    $upd = mysqli_prepare($conn, 'UPDATE guardian_links SET Status = "Restricted" WHERE GuardianLinkID = ?');
                    if ($upd && mysqli_stmt_bind_param($upd, 'i', $linkId) && mysqli_stmt_execute($upd)) {
                        mysqli_stmt_close($upd);
                        $success = 'Guardian access restricted.';
                        logAudit(db_pdo(), 'GUARDIAN_RESTRICT', 'guardian_links', $linkId, null, json_encode(['Status' => 'Restricted']));
                    } else {
                        $errors[] = 'Failed to restrict guardian.';
                    }
                } elseif ($action === 'unrestrict') {
                    $upd = mysqli_prepare($conn, 'UPDATE guardian_links SET Status = "Verified" WHERE GuardianLinkID = ? AND Status = "Restricted"');
                    if ($upd && mysqli_stmt_bind_param($upd, 'i', $linkId) && mysqli_stmt_execute($upd)) {
                        $affected = mysqli_stmt_affected_rows($upd);
                        mysqli_stmt_close($upd);
                        if ($affected > 0) {
                            $success = 'Guardian access restored to verified.';
                            logAudit(db_pdo(), 'GUARDIAN_UNRESTRICT', 'guardian_links', $linkId, json_encode(['Status' => 'Restricted']), json_encode(['Status' => 'Verified']));
                        } else {
                            $success = 'Guardian already in that state.';
                        }
                    } else {
                        $errors[] = 'Failed to restore guardian access.';
                    }
                } elseif ($action === 'unlink') {
                    $reason = trim($_POST['reason'] ?? '');
                    $reason = mb_substr($reason, 0, 255);
                    mysqli_begin_transaction($conn);
                    try {
                        $del = mysqli_prepare($conn, 'DELETE FROM guardian_links WHERE GuardianLinkID = ?');
                        if (!$del || !mysqli_stmt_bind_param($del, 'i', $linkId) || !mysqli_stmt_execute($del)) {
                            throw new Exception('Failed to unlink guardian.');
                        }
                        mysqli_stmt_close($del);
                        mysqli_commit($conn);
                        logAudit(db_pdo(), 'GUARDIAN_UNLINK', 'guardian_links', $linkId, json_encode($row), json_encode(['reason' => $reason]));
                        $success = 'Guardian unlinked from patient.';
                    } catch (Exception $e) {
                        mysqli_rollback($conn);
                        $errors[] = $e->getMessage();
                    }
                }
            }
        } else {
            $errors[] = 'Failed to load guardian link.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Guardian | Curora</title>
    <link rel="stylesheet" href="../assets/styles.css">
</head>
<body>
    <div class="container">
        <h2>Guardian Management</h2>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><?= htmlspecialchars(implode(' ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="GET" class="mb-3">
            <label for="link_id">Guardian Link ID</label>
            <input type="number" name="link_id" id="link_id" value="<?= $linkId; ?>" min="1" required>
            <button type="submit">Load</button>
        </form>
        <?php if ($linkId > 0): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="link_id" value="<?= $linkId; ?>">
                <fieldset>
                    <legend>Actions</legend>
                    <div style="margin-bottom: .5rem;">
                        <label for="proof_type">Proof type (for verification)</label>
                        <input type="text" name="proof_type" id="proof_type" maxlength="50" placeholder="e.g. ID, Birth Certificate">
                    </div>
                    <button name="action" value="verify">Verify (Unverified → Verified)</button>
                    <button name="action" value="restrict">Restrict</button>
                    <button name="action" value="unrestrict">Unrestrict (Restricted → Verified)</button>
                </fieldset>
            </form>
            <form method="POST" style="margin-top: 1rem;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="link_id" value="<?= $linkId; ?>">
                <div>
                    <label for="reason">Unlink reason (optional)</label>
                    <input type="text" name="reason" id="reason" maxlength="255">
                </div>
                <button name="action" value="unlink" onclick="return confirm('Unlink this guardian?');">Unlink</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>