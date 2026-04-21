-- 055_add_source_locale_to_project_proposals.sql
ALTER TABLE project_proposals
    ADD COLUMN source_locale VARCHAR(10) NOT NULL DEFAULT 'fi_FI' AFTER description;
