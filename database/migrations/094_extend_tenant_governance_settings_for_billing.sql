-- 094_extend_tenant_governance_settings_for_billing.sql
-- Adds billing-related toggles to the per-tenant governance settings row.
-- All 4 columns are nullable-with-default so existing rows from migration 088
-- pick up sensible defaults without an explicit UPDATE.

ALTER TABLE tenant_governance_settings
    ADD COLUMN requires_formal_decision_for_fees TINYINT(1) NOT NULL DEFAULT 0
        AFTER decision_expiration_days,
    ADD COLUMN default_due_days_from_anniversary INT NOT NULL DEFAULT 60
        AFTER requires_formal_decision_for_fees,
    ADD COLUMN overdue_grace_days INT NOT NULL DEFAULT 30
        AFTER default_due_days_from_anniversary,
    ADD COLUMN lapse_check_enabled TINYINT(1) NOT NULL DEFAULT 1
        AFTER overdue_grace_days;
