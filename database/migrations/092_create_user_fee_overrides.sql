-- 092_create_user_fee_overrides.sql
-- Per-user time-bounded fee override (§ 5: "alentaa maksua määräajaksi
-- perustellusta syystä"). Anniversary-cron checks this table at invoice
-- creation; matching active override replaces the schedule price for that user.
--
-- Adds the deferred FK from member_fee_invoices.override_id once both
-- tables exist.

CREATE TABLE IF NOT EXISTS user_fee_overrides (
    id                       CHAR(36)             NOT NULL,
    tenant_id                CHAR(36)             NOT NULL,
    user_id                  CHAR(36)             NOT NULL,
    fee_type                 VARCHAR(20)          NOT NULL,
    override_amount_cents    INT UNSIGNED         NOT NULL,
    valid_from               DATE                 NOT NULL,
    valid_until              DATE                 NULL,
    reason                   TEXT                 NOT NULL,
    decision_id              CHAR(36)             NULL,
    created_by               CHAR(36)             NOT NULL,
    created_at               DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at               DATETIME             NULL,
    revoked_by               CHAR(36)             NULL,
    PRIMARY KEY (id),
    KEY idx_active_lookup (tenant_id, user_id, fee_type, valid_from, valid_until, revoked_at),
    KEY idx_tenant (tenant_id, created_at),
    CONSTRAINT fk_ufo_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_ufo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ufo_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE SET NULL,
    CONSTRAINT fk_ufo_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ufo_revoked_by FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add deferred FK from member_fee_invoices.override_id (table created in mig 091).
ALTER TABLE member_fee_invoices
    ADD CONSTRAINT fk_mfi_override
        FOREIGN KEY (override_id) REFERENCES user_fee_overrides(id) ON DELETE SET NULL;
