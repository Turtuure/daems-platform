-- 065_rename_forum_migrations_in_schema_migrations_table.sql
-- Rewrites schema_migrations rows so existing dev DBs don't try to re-run
-- forum migrations after they're moved to modules/forum/backend/migrations/
-- with the forum_NNN_* prefix. Idempotent.
--
-- Conditional: schema_migrations table is dev-only — test DBs reset by
-- MigrationTestCase don't have it. Skip UPDATEs when the table is missing.
SET @t := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_001_create_forum_tables.sql' WHERE filename = '007_create_forum_tables.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_002_add_user_id_to_forum.sql' WHERE filename = '013_add_user_id_to_forum.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_003_add_tenant_id_to_forum_tables.sql' WHERE filename = '030_add_tenant_id_to_forum_tables.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_004_add_locked_to_forum_topics.sql' WHERE filename = '047_add_locked_to_forum_topics.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_005_create_forum_reports_audit_warnings_and_edited_at.sql' WHERE filename = '048_create_forum_reports_audit_warnings_and_edited_at.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_006_extend_dismissals_enum_forum_report.sql' WHERE filename = '049_extend_dismissals_enum_forum_report.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_007_widen_app_id_for_compound_forum_report.sql' WHERE filename = '050_widen_app_id_for_compound_forum_report.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_008_search_text_forum_topics.sql' WHERE filename = '061_search_text_forum_topics.sql'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @sql := IF(@t > 0, "UPDATE schema_migrations SET filename = 'forum_009_search_text_forum_topics_backfill.php' WHERE filename = '061_search_text_forum_topics_backfill.php'", "DO 0");
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;
