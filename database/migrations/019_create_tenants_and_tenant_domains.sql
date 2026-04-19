-- Migration 019: create tenants + tenant_domains, seed daems + sahegroup

CREATE TABLE tenants (
    id         CHAR(36)     NOT NULL,
    slug       VARCHAR(64)  NOT NULL,
    name       VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY tenants_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_domains (
    domain     VARCHAR(255) NOT NULL,
    tenant_id  CHAR(36)     NOT NULL,
    is_primary BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (domain),
    KEY tenant_domains_tenant_idx (tenant_id),
    CONSTRAINT fk_tenant_domains_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tenants (id, slug, name) VALUES
    ('01958000-0000-7000-8000-000000000001', 'daems',     'Daems Society'),
    ('01958000-0000-7000-8000-000000000002', 'sahegroup', 'Sahe Group');

INSERT INTO tenant_domains (domain, tenant_id, is_primary) VALUES
    ('daems.fi',          '01958000-0000-7000-8000-000000000001', TRUE),
    ('www.daems.fi',      '01958000-0000-7000-8000-000000000001', FALSE),
    ('sahegroup.com',     '01958000-0000-7000-8000-000000000002', TRUE),
    ('www.sahegroup.com', '01958000-0000-7000-8000-000000000002', FALSE);
