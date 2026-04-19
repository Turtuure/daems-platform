-- Migration 026: add tenant_id to insights

ALTER TABLE insights ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE insights
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE insights
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_insights_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX insights_tenant_idx (tenant_id, published_date);
