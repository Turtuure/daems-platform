-- Migration 059: add FULLTEXT indexes to i18n content tables for global search.
-- No data changes; indexes are built from existing rows.

ALTER TABLE events_i18n   ADD FULLTEXT INDEX ft_title_body (title, location, description);
ALTER TABLE projects_i18n ADD FULLTEXT INDEX ft_title_body (title, summary, description);
