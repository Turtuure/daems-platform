-- Per-tenant default + per-user override for the backstage TimePicker
-- (12-hour AM/PM vs 24-hour clock face).
-- Effective format: COALESCE(user.time_format_override, tenant.default_time_format, '24')

ALTER TABLE tenants
    ADD COLUMN default_time_format ENUM('12','24') NOT NULL DEFAULT '24'
    AFTER member_number_prefix;

ALTER TABLE users
    ADD COLUMN time_format_override ENUM('12','24') NULL
    AFTER public_avatar_visible;
