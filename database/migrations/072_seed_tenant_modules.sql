-- 072_seed_tenant_modules.sql
-- Backfill: every existing tenant gets a tenant_modules row per shipping module
-- with available_at = NOW() and enabled_at = NOW(), preserving current behaviour.
-- Hardcoded list of 5 modules: events, forum, insights, members, projects.
-- Future modules onboard via GSA UI (CreateTenant + GrantModuleAvailability flows),
-- not via additional migrations.

-- Helper: now-timestamp captured once.
SET @now := UTC_TIMESTAMP();

-- One INSERT per (existing tenant × module). UUID() per row.
-- WHERE NOT EXISTS keeps the migration idempotent on re-run.
INSERT INTO tenant_modules
    (id, tenant_id, module_slug, available_at, available_by, enabled_at, enabled_by, disabled_at, created_at, updated_at)
SELECT UUID(), t.id, m.slug, @now, NULL, @now, NULL, NULL, @now, @now
FROM tenants t
CROSS JOIN (
    SELECT 'events'   AS slug UNION ALL
    SELECT 'forum'             UNION ALL
    SELECT 'insights'          UNION ALL
    SELECT 'members'           UNION ALL
    SELECT 'projects'
) m
WHERE NOT EXISTS (
    SELECT 1 FROM tenant_modules tm
    WHERE tm.tenant_id = t.id AND tm.module_slug = m.slug
);
