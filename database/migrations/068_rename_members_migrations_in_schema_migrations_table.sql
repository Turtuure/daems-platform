-- 068_rename_members_migrations_in_schema_migrations_table.sql
-- Rename schema_migrations rows for members migrations that moved to modules/members/.
-- Idempotent: each UPDATE is gated by a SELECT that checks the schema_migrations table exists.
-- Safe to re-run on dev DBs that already saw the rename.

SET @smt := (SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_001_create_member_applications_table.sql' WHERE filename = '004_create_member_applications_table.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_002_create_supporter_applications_table.sql' WHERE filename = '005_create_supporter_applications_table.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_003_add_tenant_id_to_member_applications.sql' WHERE filename = '028_add_tenant_id_to_member_applications.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_004_add_tenant_id_to_supporter_applications.sql' WHERE filename = '029_add_tenant_id_to_supporter_applications.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_005_add_tenant_id_to_member_register_audit.sql' WHERE filename = '033_add_tenant_id_to_member_register_audit.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_006_add_decision_metadata_to_applications.sql' WHERE filename = '034_add_decision_metadata_to_applications.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_007_create_member_status_audit.sql' WHERE filename = '035_create_member_status_audit.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_008_create_tenant_member_counters.sql' WHERE filename = '038_create_tenant_member_counters.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_009_create_admin_application_dismissals.sql' WHERE filename = '040_create_admin_application_dismissals.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@smt > 0, "UPDATE schema_migrations SET filename = 'members_010_create_tenant_supporter_counters.sql' WHERE filename = '041_create_tenant_supporter_counters.sql'", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
