-- Migration 030: add tenant_id to forum_categories, forum_topics, forum_posts

-- forum_categories
ALTER TABLE forum_categories ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE forum_categories
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE forum_categories
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_forum_categories_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX forum_categories_tenant_idx (tenant_id, sort_order);

-- forum_topics
ALTER TABLE forum_topics ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE forum_topics
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE forum_topics
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_forum_topics_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX forum_topics_tenant_idx (tenant_id, created_at);

-- forum_posts
ALTER TABLE forum_posts ADD COLUMN tenant_id CHAR(36) NULL AFTER id;

UPDATE forum_posts
   SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')
 WHERE tenant_id IS NULL;

ALTER TABLE forum_posts
    MODIFY tenant_id CHAR(36) NOT NULL,
    ADD CONSTRAINT fk_forum_posts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    ADD INDEX forum_posts_tenant_idx (tenant_id, created_at);
