-- 065_rename_forum_migrations_in_schema_migrations_table.sql
-- Rewrites schema_migrations rows so existing dev DBs don't try to re-run
-- forum migrations after they're moved to modules/forum/backend/migrations/
-- with the forum_NNN_* prefix. Idempotent.

UPDATE schema_migrations SET migration = 'forum_001_create_forum_tables.sql'
  WHERE migration = '007_create_forum_tables.sql';
UPDATE schema_migrations SET migration = 'forum_002_add_user_id_to_forum.sql'
  WHERE migration = '013_add_user_id_to_forum.sql';
UPDATE schema_migrations SET migration = 'forum_003_add_tenant_id_to_forum_tables.sql'
  WHERE migration = '030_add_tenant_id_to_forum_tables.sql';
UPDATE schema_migrations SET migration = 'forum_004_add_locked_to_forum_topics.sql'
  WHERE migration = '047_add_locked_to_forum_topics.sql';
UPDATE schema_migrations SET migration = 'forum_005_create_forum_reports_audit_warnings_and_edited_at.sql'
  WHERE migration = '048_create_forum_reports_audit_warnings_and_edited_at.sql';
UPDATE schema_migrations SET migration = 'forum_006_extend_dismissals_enum_forum_report.sql'
  WHERE migration = '049_extend_dismissals_enum_forum_report.sql';
UPDATE schema_migrations SET migration = 'forum_007_widen_app_id_for_compound_forum_report.sql'
  WHERE migration = '050_widen_app_id_for_compound_forum_report.sql';
UPDATE schema_migrations SET migration = 'forum_008_search_text_forum_topics.sql'
  WHERE migration = '061_search_text_forum_topics.sql';
UPDATE schema_migrations SET migration = 'forum_009_search_text_forum_topics_backfill.php'
  WHERE migration = '061_search_text_forum_topics_backfill.php';
