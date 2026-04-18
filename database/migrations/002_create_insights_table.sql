CREATE TABLE IF NOT EXISTS insights (
    id             CHAR(36)     NOT NULL,
    slug           VARCHAR(255) NOT NULL,
    title          VARCHAR(255) NOT NULL,
    category       VARCHAR(100) NOT NULL,
    category_label VARCHAR(100) NOT NULL,
    featured       TINYINT(1)   NOT NULL DEFAULT 0,
    published_date DATE         NOT NULL,
    author         VARCHAR(255) NOT NULL,
    reading_time   INT          NOT NULL DEFAULT 0,
    excerpt        TEXT         NOT NULL,
    hero_image     VARCHAR(500) NULL,
    tags_json      TEXT         NULL,
    content        LONGTEXT     NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY insights_slug_unique (slug),
    KEY insights_category_date (category, published_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
