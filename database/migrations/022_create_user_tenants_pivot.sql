-- Migration 022: create user_tenants pivot

CREATE TABLE user_tenants (
    user_id    CHAR(36)     NOT NULL,
    tenant_id  CHAR(36)     NOT NULL,
    role       ENUM('admin','moderator','member','supporter','registered')
                            NOT NULL DEFAULT 'registered',
    joined_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at    DATETIME     NULL,
    PRIMARY KEY (user_id, tenant_id),
    KEY user_tenants_tenant_role_idx (tenant_id, role),
    CONSTRAINT fk_user_tenants_user
        FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_user_tenants_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
