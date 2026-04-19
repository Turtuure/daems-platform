-- Migration 032: add tenant_id to event_registrations

ALTER TABLE event_registrations ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE event_registrations
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE event_registrations
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_event_registrations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX event_registrations_tenant_idx (tenant_id, event_id);
