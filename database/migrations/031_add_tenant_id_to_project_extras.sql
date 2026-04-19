-- Migration 031: add tenant_id to project_comments, project_updates, project_participants, project_proposals

-- project_comments
ALTER TABLE project_comments ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE project_comments
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE project_comments
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_project_comments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX project_comments_tenant_idx (tenant_id, created_at);

-- project_updates
ALTER TABLE project_updates ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE project_updates
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE project_updates
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_project_updates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX project_updates_tenant_idx (tenant_id, created_at);

-- project_participants
ALTER TABLE project_participants ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE project_participants
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE project_participants
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_project_participants_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX project_participants_tenant_idx (tenant_id, joined_at);

-- project_proposals
ALTER TABLE project_proposals ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE project_proposals
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE project_proposals
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_project_proposals_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX project_proposals_tenant_idx (tenant_id, created_at);
