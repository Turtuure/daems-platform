-- 056_create_event_proposals.sql
CREATE TABLE event_proposals (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    author_name     VARCHAR(255) NOT NULL,
    author_email    VARCHAR(255) NOT NULL,
    title           VARCHAR(255) NOT NULL,
    event_date      DATE         NOT NULL,
    event_time      VARCHAR(50)  NULL,
    location        VARCHAR(255) NULL,
    is_online       TINYINT(1)   NOT NULL DEFAULT 0,
    description     TEXT         NOT NULL,
    source_locale   VARCHAR(10)  NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at      DATETIME     NULL,
    decided_by      CHAR(36)     NULL,
    decision_note   TEXT         NULL,
    PRIMARY KEY (id),
    KEY event_proposals_tenant_status (tenant_id, status),
    KEY event_proposals_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
