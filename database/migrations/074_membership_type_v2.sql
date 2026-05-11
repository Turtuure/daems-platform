-- 074_membership_type_v2.sql
-- MembershipCore v2 / 0.6a: add membership_subtier + audit columns.
-- The four-value canonical migration of membership_type happens in 075
-- (PHP, transactional).

ALTER TABLE users
    ADD COLUMN membership_subtier        VARCHAR(30) NULL AFTER membership_type,
    ADD COLUMN membership_type_legacy    VARCHAR(30) NULL AFTER membership_subtier,
    ADD COLUMN membership_started_at     DATETIME    NULL AFTER membership_status,
    ADD COLUMN membership_ended_at       DATETIME    NULL AFTER membership_started_at,
    ADD COLUMN membership_status_reason  TEXT        NULL AFTER membership_ended_at;
