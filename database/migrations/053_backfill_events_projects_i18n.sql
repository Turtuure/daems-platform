-- 053_backfill_events_projects_i18n.sql
INSERT INTO events_i18n (event_id, locale, title, location, description, created_at, updated_at)
SELECT id, 'fi_FI', title, location, description, created_at, created_at
FROM events
WHERE NOT EXISTS (
    SELECT 1 FROM events_i18n ei WHERE ei.event_id = events.id AND ei.locale = 'fi_FI'
);

INSERT INTO projects_i18n (project_id, locale, title, summary, description, created_at, updated_at)
SELECT id, 'fi_FI', title, summary, description, created_at, created_at
FROM projects
WHERE NOT EXISTS (
    SELECT 1 FROM projects_i18n pi WHERE pi.project_id = projects.id AND pi.locale = 'fi_FI'
);
