<?php

require_once __DIR__ . '/db.php';

/**
 * Returns a shared PDO connection for the hoacrms database.
 * Creates the connection on first use and reuses it afterwards.
 */
function db_pdo(): PDO
{
    static $pdo = null;

    if (!$pdo instanceof PDO) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    return $pdo;
}

/** Shared list of valid audience targets for new announcements. */
const ANNOUNCEMENT_AUDIENCES = ['All Staff', 'Patients Only', 'Doctors Only', 'Nurses Only', 'Administration', 'Technicians'];

/** Shared list of valid priority levels. */
const ANNOUNCEMENT_PRIORITIES = ['HIGH', 'MEDIUM', 'LOW'];

/**
 * Catalog of every capability an administrator can toggle per role.
 * The value doubles as the human-readable label shown in the matrix.
 */
const ROLE_PERMISSION_CATALOG = [
    'dashboard'             => 'View Dashboard',
    'manage_departments'    => 'Manage Departments',
    'manage_doctors'        => 'Manage Doctors',
    'manage_patients'       => 'Manage Patients',
    'manage_staff'          => 'Manage Staff',
    'manage_announcements'  => 'Manage Announcements',
    'view_reports'          => 'View Reports',
    'view_audit_logs'       => 'View Audit Logs',
    'manage_system_settings'=> 'Manage System Settings',
];

/**
 * Default permissions seeded for each role name when the matrix is first rendered.
 * Keys not listed here default to disabled (0).
 */
const ROLE_PERMISSION_DEFAULTS = [
    'Admin'   => ['dashboard', 'manage_departments', 'manage_doctors', 'manage_patients', 'manage_staff', 'manage_announcements', 'view_reports', 'view_audit_logs', 'manage_system_settings'],
    'Doctor'  => ['dashboard', 'manage_patients', 'view_reports'],
    'Staff'   => ['dashboard', 'manage_patients', 'manage_announcements', 'view_reports'],
    'Patient' => ['dashboard'],
];