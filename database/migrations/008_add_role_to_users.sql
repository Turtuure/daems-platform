ALTER TABLE users
    ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'registered'
    AFTER date_of_birth;
