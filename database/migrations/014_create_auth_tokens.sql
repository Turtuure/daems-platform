CREATE TABLE IF NOT EXISTS auth_tokens (
    id            CHAR(36)     NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    user_id       CHAR(36)     NOT NULL,
    issued_at     DATETIME     NOT NULL,
    last_used_at  DATETIME     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    revoked_at    DATETIME         NULL,
    user_agent    VARCHAR(255)     NULL,
    ip            VARCHAR(45)      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
