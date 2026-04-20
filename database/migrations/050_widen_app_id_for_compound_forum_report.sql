-- Widen admin_application_dismissals.app_id to fit compound forum_report keys
-- like 'post:<uuid-36>' (41 chars) or 'topic:<uuid-36>' (42 chars).
-- Member / supporter / project_proposal dismissals still fit (36-char UUID).

ALTER TABLE admin_application_dismissals
    MODIFY COLUMN app_id VARCHAR(64) NOT NULL;
