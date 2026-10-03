# Phase 3 — Migration Runbook

Order matters. Each step lists what it changes and what rolls it back.

| # | File | What it does | Rollback |
|---|------|--------------|----------|
| 0 | `2026_10_04_p3_00_preflight_verification.sql` | Read-only. Run first. Every check must return its "expect" value. | none |
| 1 | `2026_10_04_p3_01_quarantine_zero_date_appointments.sql` | Creates `appointments_quarantine`, copies 3 zero-date rows into it. **Deletes nothing.** | `2026_10_04_p3_01_rollback.sql` |
| 1b | `2026_10_04_p3_01b_delete_quarantined.sql` | **Review first.** Deletes the 3 zero-date rows from `appointments`. Quarantine copy stays. | Re-insert from `appointments_quarantine` (commented in the file) |
| 2 | `2026_10_04_p3_02_vitals_source_and_bp_split.sql` | `vitals.Source` enum + `Systolic`/`Diastolic` columns + bidirectional BP triggers. Backfills 118 rows. | `2026_10_04_p3_02_rollback.sql` |
| 3 | `2026_10_04_p3_03_consultations_vital_link.sql` | `consultations.VitalID` FK, 2 new consultation-sourced vitals rows, backfills 68. | `2026_10_04_p3_03_rollback.sql` |
| 4 | `2026_10_04_p3_04_status_enums_and_dead_columns.sql` | Status columns → ENUMs; drops `reports.FormatStatus`, `staff.AssignedDays`. | `2026_10_04_p3_04_rollback.sql` |
| 5 | `2026_10_04_p3_05_appointments_slot_unique.sql` | `appointments.SlotKey` stored generated + UNIQUE. | `2026_10_04_p3_05_rollback.sql` |
| 6 | `2026_10_04_p3_06_queue_department_unique.sql` | `queue.DepartmentID` + UNIQUE per-dept-per-day queue number. | `2026_10_04_p3_06_rollback.sql` |
| 7 | `2026_10_04_p3_07_department_schedules_innodb_fk.sql` | `department_schedules` MyISAM → InnoDB + FK. | `2026_10_04_p3_07_rollback.sql` |
| 8 | `2026_10_04_p3_08_remaining_foreign_keys.sql` | 11 missing FKs on admin/patients/staff/users/vitals/no_shows. | `2026_10_04_p3_08_rollback.sql` |

## Run commands

```powershell
# Backup first — do not skip this
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe" -u root --single-transaction --routines --triggers --events --default-character-set=utf8mb4 hoacrms > "C:\Users\lenovo\AppData\Local\Temp\opencode\hoacrms_backup_2026-10-03.sql"

# Then run in order
mysql -u root hoacrms < database\migrations\2026_10_04_p3_00_preflight_verification.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_01_quarantine_zero_date_appointments.sql
# review output, then only when ready:
mysql -u root hoacrms < database\migrations\2026_10_04_p3_01b_delete_quarantined.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_02_vitals_source_and_bp_split.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_03_consultations_vital_link.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_04_status_enums_and_dead_columns.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_05_appointments_slot_unique.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_06_queue_department_unique.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_07_department_schedules_innodb_fk.sql
mysql -u root hoacrms < database\migrations\2026_10_04_p3_08_remaining_foreign_keys.sql
```

## Important caveats

- **MySQL DDL is not transactional.** `ALTER TABLE`, `CREATE TRIGGER`, and
  `ENGINE` changes cause an implicit COMMIT. There is no `ROLLBACK` for them.
  The `*_rollback.sql` files are forward-compensating scripts — they undo the
  structural change, not a transaction. Take the mysqldump. That is the real
  safety net.
- **Trigger tests in 02 are wrapped in a transaction and rolled back.**
- **`SlotKey` is STORED, not VIRTUAL.** MySQL does not allow a UNIQUE index to
  enforce itself on a virtual generated column in this configuration.
- **`appointments_quarantine` has no FKs** (copied via `CREATE TABLE ... LIKE`,
  which does not carry constraints). It is a working area, not a live table.
- **The `confirmations` → vitals backfill is 68 rows, not 107.** 39
  consultations have no matching staff-recorded vitals row and keep
  `VitalID = NULL`. That is expected, not a failure.
- **Phase 4 still required.** The PHP code has not been repointed yet. Until
  Phase 4 runs:
  - `queue_status.php` / `patient_dashboard.php` still filter on the dead
    `'Serving'` literal (0 rows, harmless but misleading).
  - The 8 sites reading `Scheduled`/`Confirmed` still expect them; they were
    NOT merged.
  - The `AvailabilityStatus` AND-derived-schedule refinement belongs to Phase 4.