-- 057_add_member_number_prefix_to_tenants.sql
-- Tenants choose how their members' card numbers are displayed.
-- NULL = raw number ("123"). "DAEMS" → "DAEMS-123". Frontend formatter
-- strips leading zeros from the raw value and joins with the prefix.

ALTER TABLE tenants
    ADD COLUMN member_number_prefix VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'Display prefix for member numbers; NULL = no prefix';
