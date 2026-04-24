-- 058_add_public_avatar_visible_to_users.sql
-- Per-member opt-out for displaying their photo on the public /members/{n} page.
-- Default 1 = visible.

ALTER TABLE users
    ADD COLUMN public_avatar_visible TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Show member photo on the public profile page; 0 = monogram only';
