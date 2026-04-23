-- 054_drop_translated_columns_from_events_projects.sql
-- A11 follow-up: make events_i18n / projects_i18n the sole source of truth
-- for translated content. Backfill happened in 053; this drop is irreversible.

ALTER TABLE events
    DROP COLUMN title,
    DROP COLUMN location,
    DROP COLUMN description;

ALTER TABLE projects
    DROP COLUMN title,
    DROP COLUMN summary,
    DROP COLUMN description;
