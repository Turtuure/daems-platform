-- 051_create_events_i18n.sql
CREATE TABLE events_i18n (
    event_id    CHAR(36)     NOT NULL,
    locale      VARCHAR(10)  NOT NULL,
    title       VARCHAR(255) NOT NULL,
    location    VARCHAR(255) NULL,
    description TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, locale),
    CONSTRAINT fk_events_i18n_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
