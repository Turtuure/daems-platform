CREATE TABLE IF NOT EXISTS events (
    id           CHAR(36)     NOT NULL,
    slug         VARCHAR(255) NOT NULL,
    title        VARCHAR(255) NOT NULL,
    type         ENUM('upcoming','past','online') NOT NULL,
    event_date   DATE         NOT NULL,
    event_time   VARCHAR(50)  NULL,
    location     VARCHAR(255) NULL,
    is_online    TINYINT(1)   NOT NULL DEFAULT 0,
    description  TEXT         NULL,
    hero_image   VARCHAR(500) NULL,
    gallery_json TEXT         NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY events_slug_unique (slug),
    KEY events_type_date (type, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
