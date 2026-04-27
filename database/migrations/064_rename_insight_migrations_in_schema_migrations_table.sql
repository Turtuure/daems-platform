-- Phase 1 modular architecture: Insights migrations move into modules/insights/
-- and get renamed from NNN_<desc>.{sql,php} to insights_NNN_<desc>.{sql,php}.
-- This data-fix updates the schema_migrations table so the runner sees the
-- renamed files as already-applied and doesn't try to re-run them.
--
-- Idempotent: re-running this migration is a no-op.
-- Conditional: schema_migrations table is dev-only — test DBs reset by
-- MigrationTestCase don't have it. Skip UPDATEs when the table is missing.
SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'insights_001_create_insights_table.sql' WHERE filename = '002_create_insights_table.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'insights_002_add_tenant_id.sql' WHERE filename = '026_add_tenant_id_to_insights.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'insights_003_search_text.sql' WHERE filename = '060_search_text_insights.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'insights_004_search_text_backfill.php' WHERE filename = '060_search_text_insights_backfill.php'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'insights_005_published_date_nullable.sql' WHERE filename = '062_make_insights_published_date_nullable.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'insights_006_published_date_datetime.sql' WHERE filename = '063_change_insights_published_date_to_datetime.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
