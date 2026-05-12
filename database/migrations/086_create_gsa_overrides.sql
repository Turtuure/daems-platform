-- 086_create_gsa_overrides.sql
-- Dedicated audit table for GSA force-overrides that bypass the board flow.

CREATE TABLE IF NOT EXISTS gsa_overrides (
    id              CHAR(36)  NOT NULL,
    gsa_user_id     CHAR(36)  NOT NULL,
    tenant_id       CHAR(36)  NOT NULL,
    action          ENUM('force_approve_basic') NOT NULL,
    target_id       CHAR(36)  NOT NULL,
    reason          TEXT      NOT NULL,
    performed_at    DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant (tenant_id),
    KEY idx_gsa (gsa_user_id),
    CONSTRAINT fk_gsa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_gsa_user FOREIGN KEY (gsa_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
