-- 073_create_user_dashboards_table.sql
-- Per-user dashboard layout customisation. Empty table = users see role default.

CREATE TABLE user_dashboards (
  id            char(36)      NOT NULL,
  user_id       char(36)      NOT NULL,
  tenant_id     char(36)      NOT NULL,
  layout        JSON          NOT NULL,
  updated_at    DATETIME      NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_tenant (user_id, tenant_id),
  KEY idx_tenant (tenant_id),
  CONSTRAINT fk_user_dashboards_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_dashboards_tenant
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
