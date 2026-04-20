ALTER TABLE events
    ADD COLUMN status ENUM('draft','published','archived')
        NOT NULL DEFAULT 'published'
        AFTER type;
