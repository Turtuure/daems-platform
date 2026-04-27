-- Idempotent rename of event-related migration filenames in schema_migrations
-- so dev/prod DBs don't re-run them after the Events module move (Task 12).
-- Pairs with Task 25 of the modular-architecture-phase1-events plan.

SET @t := (SELECT COUNT(*) FROM information_schema.tables
           WHERE table_schema = DATABASE() AND table_name = 'schema_migrations');

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_001_create_events_table.sql'' WHERE migration = ''001_create_events_table.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_002_create_event_registrations.sql'' WHERE migration = ''012_create_event_registrations.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_003_add_tenant_id_to_events.sql'' WHERE migration = ''025_add_tenant_id_to_events.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_004_add_tenant_id_to_event_registrations.sql'' WHERE migration = ''032_add_tenant_id_to_event_registrations.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_005_add_status_to_events.sql'' WHERE migration = ''043_add_status_to_events.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_006_create_events_i18n.sql'' WHERE migration = ''051_create_events_i18n.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@t > 0, 'UPDATE schema_migrations SET migration = ''event_007_create_event_proposals.sql'' WHERE migration = ''056_create_event_proposals.sql''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
