-- Phase 1 modular architecture: Insights migrations move into modules/insights/
-- and get renamed from NNN_<desc>.{sql,php} to insights_NNN_<desc>.{sql,php}.
-- This data-fix updates the schema_migrations table so the runner sees the
-- renamed files as already-applied and doesn't try to re-run them.
--
-- Idempotent: re-running this migration is a no-op (UPDATE WHERE filename = ...
-- matches at most one row, and the new filename will already be in the table on
-- subsequent runs so the WHERE condition fails).
--
-- NOTE — temporary duplicate-row state (Task 7 / Task 11 gap):
-- After this migration runs, schema_migrations contains the NEW insight_NNN_*
-- names. However, the actual files are NOT moved until Task 11. Between Task 7
-- and Task 11, the runner will encounter the old-named files (e.g.
-- 002_create_insights_table.sql) as "unapplied" and attempt to re-run them.
-- The idempotentMarkers guard in the runner will catch the "already exists"
-- errors and record the old names too, resulting in BOTH old and new names
-- being present in schema_migrations. This is harmless: Task 11 deletes the
-- old files, which makes the duplicate old-name rows stale but benign. A
-- follow-up cleanup of those stale rows is optional.

UPDATE schema_migrations SET filename = 'insights_001_create_insights_table.sql'
    WHERE filename = '002_create_insights_table.sql';

UPDATE schema_migrations SET filename = 'insights_002_add_tenant_id.sql'
    WHERE filename = '026_add_tenant_id_to_insights.sql';

UPDATE schema_migrations SET filename = 'insights_003_search_text.sql'
    WHERE filename = '060_search_text_insights.sql';

UPDATE schema_migrations SET filename = 'insights_004_search_text_backfill.php'
    WHERE filename = '060_search_text_insights_backfill.php';

UPDATE schema_migrations SET filename = 'insights_005_published_date_nullable.sql'
    WHERE filename = '062_make_insights_published_date_nullable.sql';

UPDATE schema_migrations SET filename = 'insights_006_published_date_datetime.sql'
    WHERE filename = '063_change_insights_published_date_to_datetime.sql';
