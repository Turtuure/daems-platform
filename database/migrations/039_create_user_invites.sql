CREATE TABLE user_invites (
    id         CHAR(36) NOT NULL,
    user_id    CHAR(36) NOT NULL,
    tenant_id  CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    issued_at  DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_invites_token (token_hash),
    KEY idx_user_invites_user (user_id),
    CONSTRAINT fk_user_invites_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_invites_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
