-- 093_create_member_fee_invoice_audit.sql
-- Per-invoice state-flip audit. Every change to a member_fee_invoice (creation,
-- waive, reduce, payment, payment_reversed, overdue_flagged, lapsed_via_invoice)
-- appends a row here. performed_by is NULL for cron-driven changes.

CREATE TABLE IF NOT EXISTS member_fee_invoice_audit (
    id              CHAR(36)             NOT NULL,
    tenant_id       CHAR(36)             NOT NULL,
    invoice_id      CHAR(36)             NOT NULL,
    action          VARCHAR(50)          NOT NULL,
    performed_by    CHAR(36)             NULL,
    performed_at    DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payload_json    TEXT                 NULL,
    PRIMARY KEY (id),
    KEY idx_invoice (invoice_id, performed_at),
    KEY idx_tenant (tenant_id, performed_at),
    CONSTRAINT fk_mfia_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfia_invoice FOREIGN KEY (invoice_id) REFERENCES member_fee_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfia_user FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
