-- 071_extend_tenants_for_management_ui.sql
-- Adds locale + display + suspension fields to tenants for TenantManagement UI.
-- Backfills existing daems and sahegroup rows.

ALTER TABLE tenants
    ADD COLUMN display_name_i18n       JSON         NULL          AFTER name,
    ADD COLUMN public_description_i18n JSON         NULL          AFTER display_name_i18n,
    ADD COLUMN supported_locales       VARCHAR(255) NOT NULL DEFAULT 'en_GB' AFTER public_description_i18n,
    ADD COLUMN default_locale          VARCHAR(8)   NOT NULL DEFAULT 'en_GB' AFTER supported_locales,
    ADD COLUMN suspended_at            DATETIME     NULL          AFTER status,
    ADD COLUMN suspended_reason        TEXT         NULL          AFTER suspended_at;

-- Backfill: Daem Society runs in Finnish today; preserve.
UPDATE tenants
SET display_name_i18n  = JSON_OBJECT('fi_FI', 'Daem Society ry', 'en_GB', 'Daem Society'),
    supported_locales  = 'fi_FI,en_GB,sw_TZ',
    default_locale     = 'fi_FI'
WHERE slug = 'daems';

-- Backfill: Sahegroup defaults to platform default en_GB.
UPDATE tenants
SET display_name_i18n  = JSON_OBJECT('en_GB', 'Sahe Group'),
    supported_locales  = 'fi_FI,en_GB,sw_TZ',
    default_locale     = 'en_GB'
WHERE slug = 'sahegroup';
