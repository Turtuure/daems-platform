<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration043Test extends MigrationTestCase
{
    public function test_status_column_exists_with_enum_and_default_published(): void
    {
        $this->runMigrationsUpTo(42);
        $this->runMigration('043_add_status_to_events.sql');

        $stmt = $this->pdo->query(
            "SELECT COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'events'
               AND COLUMN_NAME = 'status'"
        );
        $row = $stmt?->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringContainsString("enum('draft','published','archived')", strtolower((string) $row['COLUMN_TYPE']));
        self::assertSame('published', $row['COLUMN_DEFAULT']);
        self::assertSame('NO', $row['IS_NULLABLE']);
    }

    public function test_existing_rows_backfill_to_published(): void
    {
        $this->runMigrationsUpTo(42);
        // Seed an event before running 043 (no status column yet).
        $this->pdo->exec(
            "INSERT INTO events (id, tenant_id, slug, title, type, event_date, is_online)
             VALUES ('01959900-0000-7000-8000-000000000001',
                     (SELECT id FROM tenants LIMIT 1),
                     'legacy-evt','Legacy','upcoming','2026-06-01',0)"
        );

        $this->runMigration('043_add_status_to_events.sql');

        $status = $this->pdo->query(
            "SELECT status FROM events WHERE slug = 'legacy-evt'"
        )?->fetch(PDO::FETCH_ASSOC)['status'] ?? null;
        self::assertSame('published', $status);
    }
}
