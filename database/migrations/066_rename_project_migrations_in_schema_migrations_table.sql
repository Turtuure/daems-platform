-- 066_rename_project_migrations_in_schema_migrations_table.sql
-- Rewrites schema_migrations rows so existing dev DBs don't try to re-run
-- project migrations after they're moved to modules/projects/backend/migrations/
-- with the project_NNN_* prefix. Idempotent.
--
-- Conditional: schema_migrations table is dev-only — test DBs reset by
-- MigrationTestCase don't have it. Skip UPDATEs when the table is missing.
SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_001_create_projects_table.sql' WHERE filename = '003_create_projects_table.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_002_create_project_extras.sql' WHERE filename = '009_create_project_extras.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_003_create_project_proposals.sql' WHERE filename = '010_create_project_proposals.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_004_add_owner_id_to_projects.sql' WHERE filename = '016_add_owner_id_to_projects.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_005_add_tenant_id_to_projects.sql' WHERE filename = '027_add_tenant_id_to_projects.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_006_add_tenant_id_to_project_extras.sql' WHERE filename = '031_add_tenant_id_to_project_extras.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_007_add_featured_to_projects.sql' WHERE filename = '044_add_featured_to_projects.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_008_add_decision_metadata_to_project_proposals.sql' WHERE filename = '046_add_decision_metadata_to_project_proposals.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_009_create_projects_i18n.sql' WHERE filename = '052_create_projects_i18n.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'project_010_add_source_locale_to_project_proposals.sql' WHERE filename = '055_add_source_locale_to_project_proposals.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
