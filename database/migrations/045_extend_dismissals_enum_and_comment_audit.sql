ALTER TABLE admin_application_dismissals
    MODIFY COLUMN app_type ENUM('member','supporter','project_proposal') NOT NULL;

CREATE TABLE project_comment_moderation_audit (
    id              CHAR(36) NOT NULL,
    tenant_id       CHAR(36) NOT NULL,
    project_id      CHAR(36) NOT NULL,
    comment_id      CHAR(36) NOT NULL,
    action          ENUM('deleted') NOT NULL,
    reason          VARCHAR(500) NULL,
    performed_by    CHAR(36) NOT NULL,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_pcma_project (project_id),
    KEY idx_pcma_performer (performed_by),
    CONSTRAINT fk_pcma_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
