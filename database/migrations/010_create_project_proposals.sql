CREATE TABLE IF NOT EXISTS project_proposals (
    id          CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
    user_id     CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    author_email VARCHAR(200) NOT NULL,
    title       VARCHAR(200) NOT NULL,
    category    VARCHAR(100) NOT NULL,
    summary     VARCHAR(300) NOT NULL,
    description TEXT         NOT NULL,
    status      VARCHAR(30)  NOT NULL DEFAULT 'pending',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_prop_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
