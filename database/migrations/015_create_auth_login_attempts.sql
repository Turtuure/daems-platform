CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip           VARCHAR(45)     NOT NULL,
    email        VARCHAR(255)    NOT NULL,
    attempted_at DATETIME        NOT NULL,
    success      TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_auth_login_attempts_window (ip, email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
