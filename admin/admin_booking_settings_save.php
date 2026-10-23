<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_helper.php';

requireRole('Admin');
if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: admin_system_settings.php'); exit; }
csrfValidate($_POST['csrf_token'] ?? null) or exit('Invalid CSRF');

$keys = ['booking_window_min_hours','booking_window_max_days','cancel_cutoff_hours','reschedule_limit','no_show_limit','no_show_window_days','active_appointment_limit','per_department_limit','pediatrics_age_limit','max_guardians_per_child','unverified_guardians_can_book','accepted_proof_types','keep_id_copies','on_time_window_minutes','notification_method','walkins_may_exceed_session_max','who_may_mark_urgent','max_patients_per_session','checkin_opens_minutes_before'];
$pdo = db_pdo();
foreach ($keys as $k) {
    if (array_key_exists($k, $_POST)) {
        $v = trim((string)$_POST[$k]);
        $stmt = $pdo->prepare('INSERT INTO system_settings (SettingKey,SettingValue,UpdatedBy) VALUES (?,?,?) ON DUPLICATE KEY UPDATE SettingValue=VALUES(SettingValue), UpdatedBy=VALUES(UpdatedBy)');
        $stmt->execute([$k, $v, $_SESSION['UserID']]);
        logAudit($pdo, 'UPDATE', 'system_settings', null, null, json_encode([$k=>$v]));
    }
}
header('Location: admin_system_settings.php?tab=booking&ok=1'); exit;
