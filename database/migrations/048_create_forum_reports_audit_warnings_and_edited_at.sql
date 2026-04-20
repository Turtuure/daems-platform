CREATE TABLE forum_reports (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    target_type       ENUM('post','topic') NOT NULL,
    target_id         CHAR(36)     NOT NULL,
    reporter_user_id  CHAR(36)     NOT NULL,
    reason_category   ENUM('spam','harassment','off_topic','hate_speech','misinformation','other') NOT NULL,
    reason_detail     VARCHAR(500) NULL,
    status            ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolved_at       DATETIME     NULL,
    resolved_by       CHAR(36)     NULL,
    resolution_note   VARCHAR(500) NULL,
    resolution_action ENUM('deleted','locked','warned','edited','dismissed') NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reporter_target (reporter_user_id, target_type, target_id),
    KEY idx_fr_tenant_status (tenant_id, status, created_at),
    KEY idx_fr_target (target_type, target_id),
    CONSTRAINT fk_fr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_moderation_audit (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    target_type       ENUM('post','topic','category') NOT NULL,
    target_id         CHAR(36)     NOT NULL,
    action            ENUM(
        'deleted','locked','unlocked','pinned','unpinned','edited',
        'category_created','category_updated','category_deleted','warned'
    ) NOT NULL,
    original_payload  JSON         NULL,
    new_payload       JSON         NULL,
    reason            VARCHAR(500) NULL,
    performed_by      CHAR(36)     NOT NULL,
    related_report_id CHAR(36)     NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fma_target (target_type, target_id),
    KEY idx_fma_performer (performed_by),
    KEY idx_fma_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_fma_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_user_warnings (
    id                CHAR(36)     NOT NULL,
    tenant_id         CHAR(36)     NOT NULL,
    user_id           CHAR(36)     NOT NULL,
    reason            VARCHAR(500) NOT NULL,
    related_report_id CHAR(36)     NULL,
    issued_by         CHAR(36)     NOT NULL,
    created_at        DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_fuw_user (user_id, created_at),
    KEY idx_fuw_tenant_created (tenant_id, created_at),
    CONSTRAINT fk_fuw_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE forum_posts
    ADD COLUMN edited_at DATETIME NULL AFTER created_at;
