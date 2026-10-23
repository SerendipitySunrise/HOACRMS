<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_settings.php';
?>
<?php
function bookingSettingsHtml(mysqli $conn): string
{
    $keys = [
        'booking_window_min_hours','booking_window_max_days','cancel_cutoff_hours','reschedule_limit','no_show_limit','no_show_window_days','active_appointment_limit','per_department_limit','pediatrics_age_limit','max_guardians_per_child','unverified_guardians_can_book','accepted_proof_types','keep_id_copies','on_time_window_minutes','notification_method','walkins_may_exceed_session_max','who_may_mark_urgent','max_patients_per_session','checkin_opens_minutes_before'
    ];
    $html = '<form method="POST" action="admin_booking_settings_save.php"><fieldset><legend>Booking Settings</legend>';
    foreach ($keys as $k) {
        $v = bookingSetting($conn, $k, '');
        $html .= '<div><label>'.$k.' <input name="'.$k.'" value="'.htmlspecialchars($v,ENT_QUOTES,'UTF-8').'"></label></div>';
    }
    $html .= '<button type="submit">Save</button></fieldset></form>';
    return $html;
}
