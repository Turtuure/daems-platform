-- 087_add_invited_to_full_at_to_users.sql
-- Timestamp set by InviteFullExecutor when the board confirms a BASIC → FULL promotion.

ALTER TABLE users
    ADD COLUMN invited_to_full_at DATETIME NULL AFTER membership_ended_at;
