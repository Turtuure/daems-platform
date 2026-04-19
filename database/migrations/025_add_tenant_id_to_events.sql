-- Migration 025: add tenant_id to events

ALTER TABLE events ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE events
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE events
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_events_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX events_tenant_idx (tenant_id, event_date);
