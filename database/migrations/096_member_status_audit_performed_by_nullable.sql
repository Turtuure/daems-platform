-- 096_member_status_audit_performed_by_nullable.sql
-- Allow NULL in performed_by so cron-driven status flips (LapseInactiveMember,
-- future expulsion cron, etc.) can record an audit row without inventing a
-- "system" UUID. The FK already had ON DELETE SET NULL but the column was
-- NOT NULL, contradicting that semantics.

ALTER TABLE member_status_audit
    MODIFY COLUMN performed_by CHAR(36) NULL;
