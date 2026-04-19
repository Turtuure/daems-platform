ALTER TABLE projects
    ADD COLUMN owner_id CHAR(36) NULL AFTER id,
    ADD KEY idx_projects_owner (owner_id),
    ADD CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;
