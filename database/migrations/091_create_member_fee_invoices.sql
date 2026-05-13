-- 091_create_member_fee_invoices.sql
-- Yearly fee invoice per (tenant, user, year). Snapshot model: fee_type +
-- amount_cents are locked at issue time; later changes to annual_fee_schedules
-- never reach already-issued invoices.
--
-- UNIQUE (tenant_id, user_id, year) makes anniversary-cron idempotent.

CREATE TABLE IF NOT EXISTS member_fee_invoices (
    id                       CHAR(36)             NOT NULL,
    tenant_id                CHAR(36)             NOT NULL,
    user_id                  CHAR(36)             NOT NULL,
    year                     SMALLINT UNSIGNED    NOT NULL,
    fee_type                 VARCHAR(20)          NOT NULL,
    anniversary_date         DATE                 NOT NULL,
    amount_cents             INT UNSIGNED         NOT NULL,
    original_amount_cents    INT UNSIGNED         NULL,
    currency                 CHAR(3)              NOT NULL DEFAULT 'EUR',
    due_date                 DATE                 NOT NULL,
    status                   VARCHAR(20)          NOT NULL DEFAULT 'PENDING',
    paid_at                  DATETIME             NULL,
    paid_amount_cents        INT UNSIGNED         NULL,
    paid_method              VARCHAR(30)          NULL,
    paid_reference           VARCHAR(255)         NULL,
    paid_by                  CHAR(36)             NULL,
    waived_at                DATETIME             NULL,
    waived_by                CHAR(36)             NULL,
    waive_reason             TEXT                 NULL,
    override_id              CHAR(36)             NULL,
    created_at               DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_user_year (tenant_id, user_id, year),
    KEY idx_status_due (tenant_id, status, due_date),
    KEY idx_user_year (user_id, year),
    KEY idx_lapse_lookup (tenant_id, user_id, year, status),
    CONSTRAINT fk_mfi_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfi_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfi_paid_by FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_mfi_waived_by FOREIGN KEY (waived_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
