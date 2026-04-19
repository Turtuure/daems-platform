-- Migration 027: add tenant_id to projects

ALTER TABLE projects ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE projects
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE projects
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_projects_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX projects_tenant_idx (tenant_id, created_at);
