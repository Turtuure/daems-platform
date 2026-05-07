-- 070_create_module_audit_table.sql
-- Append-only audit log of every module-state change (grant/revoke/enable/disable).
-- Used by the GSA Tenant Edit "Audit" tab and for compliance review.

CREATE TABLE IF NOT EXISTS module_audit (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    module_slug     VARCHAR(64)  NOT NULL,
    action          ENUM('made_available','revoked_availability','enabled','disabled') NOT NULL,
    actor_user_id   CHAR(36)     NOT NULL,
    actor_role      VARCHAR(32)  NOT NULL,
    reason          TEXT         NULL,
    created_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_tenant_slug_time (tenant_id, module_slug, created_at),
    CONSTRAINT fk_ma_tenant FOREIGN KEY (tenant_id)    REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ma_actor  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
