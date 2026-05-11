-- 078_create_boards.sql
-- One boards row per tenant. Seeded via GSA bootstrap UI, not by migration.

CREATE TABLE IF NOT EXISTS boards (
    id                       CHAR(36)  NOT NULL,
    tenant_id                CHAR(36)  NOT NULL,
    bootstrapped_by_user_id  CHAR(36)  NOT NULL,
    bootstrapped_at          DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at               DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_board_tenant (tenant_id),
    CONSTRAINT fk_board_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_board_bootstrapper FOREIGN KEY (bootstrapped_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
