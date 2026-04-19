-- Migration 023: backfill user_tenants from legacy users.role
-- All existing users → member of 'daems' tenant with their legacy role.
-- GSA users → 'registered' (GSA is a global flag now, not a tenant role).

INSERT INTO user_tenants (user_id, tenant_id, role, joined_at)
SELECT
    u.id,
    (SELECT id FROM tenants WHERE slug = 'daems'),
    CASE
        WHEN u.role = 'global_system_administrator' THEN 'registered'
        WHEN u.role IN ('admin','moderator','member','supporter','registered') THEN u.role
        ELSE 'registered'
    END,
    u.created_at
FROM users u
LEFT JOIN user_tenants ut ON ut.user_id = u.id
WHERE ut.user_id IS NULL;
