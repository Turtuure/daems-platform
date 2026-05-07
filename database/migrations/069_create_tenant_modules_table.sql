-- 069_create_tenant_modules_table.sql
-- Per-tenant module gating state. GSA grants availability (available_at);
-- tenant admin enables (enabled_at). Both NULL = not in gating system.

CREATE TABLE IF NOT EXISTS tenant_modules (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    module_slug     VARCHAR(64)  NOT NULL,
    available_at    DATETIME     NULL,
    available_by    CHAR(36)     NULL,
    enabled_at      DATETIME     NULL,
    enabled_by      CHAR(36)     NULL,
    disabled_at     DATETIME     NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_module (tenant_id, module_slug),
    KEY idx_tenant_enabled (tenant_id, enabled_at),
    CONSTRAINT fk_tm_tenant   FOREIGN KEY (tenant_id)    REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tm_avail_by FOREIGN KEY (available_by) REFERENCES users(id),
    CONSTRAINT fk_tm_enab_by  FOREIGN KEY (enabled_by)   REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
