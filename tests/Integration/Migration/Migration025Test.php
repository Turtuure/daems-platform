<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration025Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedWithFk(): void
    {
        $this->runMigrationsUpTo(24);
        $this->pdo()->exec(
            "INSERT INTO events (id, slug, title, type, event_date) VALUES ('01958000-0000-7000-8000-00000000c001', 'test-event', 'Test Event', 'upcoming', '2026-04-19')"
        );

        $this->runMigration('025_add_tenant_id_to_events.sql');

        $this->assertContains('tenant_id', $this->columnsOf('events'));
        $this->assertContains('fk_events_tenant', $this->foreignKeysOf('events'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM events WHERE id = '01958000-0000-7000-8000-00000000c001'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $stmt2 = $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'");
        $this->assertNotFalse($stmt2);
        $daemsId = (string) $stmt2->fetchColumn();
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdIsNotNullAfterMigration(): void
    {
        $this->runMigrationsUpTo(24);
        $this->runMigration('025_add_tenant_id_to_events.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM events LIKE 'tenant_id'");
        $this->assertNotFalse($stmt);
        $col = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($col);
        $this->assertSame('NO', $col['Null']);
    }
}
