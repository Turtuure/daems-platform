-- 090_create_annual_fee_schedules.sql
-- Per (tenant, year, fee_type) yearly price set by hallituksen päätös or direct admin-action.
-- status lifecycle: draft → proposed (if formal decision) → active OR superseded.
-- Multiple history rows per (tenant, year, fee_type) exist; "current" is WHERE status='active'.
-- Uniqueness is enforced at application level inside ActivateAnnualFeeSchedule (transaction).

CREATE TABLE IF NOT EXISTS annual_fee_schedules (
    id              CHAR(36)             NOT NULL,
    tenant_id       CHAR(36)             NOT NULL,
    year            SMALLINT UNSIGNED    NOT NULL,
    fee_type        VARCHAR(20)          NOT NULL,
    amount_cents    INT UNSIGNED         NOT NULL DEFAULT 0,
    currency        CHAR(3)              NOT NULL DEFAULT 'EUR',
    status          VARCHAR(20)          NOT NULL DEFAULT 'draft',
    decision_id     CHAR(36)             NULL,
    activated_at    DATETIME             NULL,
    activated_by    CHAR(36)             NULL,
    superseded_at   DATETIME             NULL,
    created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by      CHAR(36)             NULL,
    PRIMARY KEY (id),
    KEY idx_lookup (tenant_id, year, fee_type, status),
    KEY idx_decision (decision_id),
    CONSTRAINT fk_afs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_afs_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE SET NULL,
    CONSTRAINT fk_afs_activated_by FOREIGN KEY (activated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_afs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
