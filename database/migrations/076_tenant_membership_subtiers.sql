-- 076_tenant_membership_subtiers.sql
-- Honor sub-tier catalog per tenant. Award/revoke logic lives in 0.6b.
-- Sub-tier explicitly does NOT carry pricing (no annual_fee_cents column —
-- fee handling moves to 0.7 Billing).

CREATE TABLE tenant_membership_subtiers (
    id          CHAR(36)    NOT NULL,
    tenant_id   CHAR(36)    NOT NULL,
    slug        VARCHAR(30) NOT NULL,
    name        VARCHAR(80) NOT NULL,
    rank_order  INT         NOT NULL,
    applies_to  VARCHAR(20) NOT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_applies_slug (tenant_id, applies_to, slug),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_subtier_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
