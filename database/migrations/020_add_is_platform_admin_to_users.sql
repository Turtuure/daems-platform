-- Migration 020: add is_platform_admin flag + audit table + trigger

ALTER TABLE users
    ADD COLUMN is_platform_admin BOOLEAN NOT NULL DEFAULT FALSE AFTER password_hash;

CREATE TABLE platform_admin_audit (
    id          CHAR(36)     NOT NULL,
    user_id     CHAR(36)     NOT NULL,
    action      ENUM('granted','revoked') NOT NULL,
    changed_by  CHAR(36)     NULL,
    reason      VARCHAR(500) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY pa_audit_user_idx (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_users_platform_admin_audit;

CREATE TRIGGER trg_users_platform_admin_audit
AFTER UPDATE ON users
FOR EACH ROW
INSERT INTO platform_admin_audit (id, user_id, action, changed_by, created_at)
SELECT
    UUID(),
    NEW.id,
    IF(NEW.is_platform_admin = TRUE, 'granted', 'revoked'),
    @app_actor_user_id,
    CURRENT_TIMESTAMP
WHERE NEW.is_platform_admin <> OLD.is_platform_admin
