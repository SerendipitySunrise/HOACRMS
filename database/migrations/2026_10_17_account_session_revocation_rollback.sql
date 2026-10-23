-- ============================================================================
--  2026_10_17_account_session_revocation_rollback.sql
--  Reverts 2026_10_17_account_session_revocation.sql.
-- ============================================================================

ALTER TABLE users DROP COLUMN SessionVersion;
