-- 085_create_tenant_governance_settings.sql
-- Per-tenant defaults for the governance workflow. Seed values added by 088.

CREATE TABLE IF NOT EXISTS tenant_governance_settings (
    tenant_id                  CHAR(36)  NOT NULL,
    expulsion_hearing_days     INT       NOT NULL DEFAULT 14,
    decision_expiration_days   INT       NOT NULL DEFAULT 60,
    updated_at                 DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tgs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
