-- 074_membership_type_v2.sql
-- MembershipCore v2 / 0.6a: add membership_subtier column. The four-value
-- canonical migration of membership_type happens in 075 (PHP, transactional).

ALTER TABLE users
    ADD COLUMN membership_subtier VARCHAR(30) NULL AFTER membership_type;
