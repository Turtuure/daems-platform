CREATE TABLE IF NOT EXISTS projects (
    id          CHAR(36)     NOT NULL,
    slug        VARCHAR(255) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    category    VARCHAR(100) NOT NULL,
    icon        VARCHAR(100) NOT NULL DEFAULT 'bi-folder',
    summary     TEXT         NOT NULL,
    description LONGTEXT     NOT NULL,
    status      VARCHAR(50)  NOT NULL DEFAULT 'active',
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY projects_slug_unique (slug),
    KEY projects_category_status (category, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
