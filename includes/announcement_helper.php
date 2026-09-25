<?php

/**
 * Returns the audience labels that apply to a given user.
 * Used to filter announcements per portal (admin/staff/doctor/patient).
 */
function announcementAudiencesForUser($conn, int $userID): array
{
    $res = mysqli_query($conn, 'SELECT RoleID FROM users WHERE UserID = ' . (int) $userID . ' LIMIT 1');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return [];
    }

    $roleID = (int) $row['RoleID'];

    if ($roleID === 1) {
        // Administration
        return ['Administration', 'All Staff'];
    }

    if ($roleID === 3) {
        // Patient
        return ['Patient', 'Patients Only'];
    }

    // RoleID 2 (Staff) or 4 (Doctor) -> check the staff record for the specific role
    $staffRes = mysqli_query($conn, 'SELECT StaffRole FROM staff WHERE UserID = ' . (int) $userID . ' LIMIT 1');
    $staffRow = $staffRes ? mysqli_fetch_assoc($staffRes) : null;
    $staffRole = $staffRow && isset($staffRow['StaffRole']) ? strtolower(trim($staffRow['StaffRole'])) : '';

    $audiences = ['All Staff'];
    if ($staffRole === 'doctor') {
        $audiences[] = 'Doctors Only';
    } elseif ($staffRole === 'nurse') {
        $audiences[] = 'Nurses Only';
    } elseif ($staffRole === 'technician') {
        $audiences[] = 'Technicians';
    }

    return $audiences;
}