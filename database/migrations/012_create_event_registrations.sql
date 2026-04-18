CREATE TABLE IF NOT EXISTS event_registrations (
    id         CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    event_id   CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    user_id    CHAR(36)     COLLATE utf8mb4_unicode_ci NOT NULL,
    registered_at DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_user (event_id, user_id),
    CONSTRAINT fk_er_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_er_user  FOREIGN KEY (user_id)  REFERENCES users  (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
