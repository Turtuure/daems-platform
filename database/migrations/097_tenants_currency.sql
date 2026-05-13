-- 097_tenants_currency.sql
-- Per-tenant currency for billing. § 5 fee schedules are per-tenant; sahegroup
-- runs in TZS while daems runs in EUR. Without this column, DraftAnnualFeeSchedule
-- hardcoded 'EUR' would silently mis-bill the sahegroup tenant.
--
-- ISO-4217 3-letter code (EUR, TZS, USD, ...). Default EUR covers existing
-- tenants (daems, edvin); sahegroup gets TZS explicitly.

ALTER TABLE tenants
    ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'EUR' AFTER default_locale;

UPDATE tenants SET currency = 'TZS' WHERE slug = 'sahegroup';
