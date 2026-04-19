-- Migration 021: backfill is_platform_admin from legacy users.role

SET @app_actor_user_id = 'system-migration';

UPDATE users SET is_platform_admin = TRUE WHERE role = 'global_system_administrator';
