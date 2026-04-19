<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration032Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedWithFk(): void
    {
        $this->runMigrationsUpTo(31);

        $daemsId = (string) $this->pdo()->query("SELECT id FROM tenants WHERE slug='daems'")->fetchColumn();
        $this->pdo()->exec(
            "INSERT INTO users (id, name, email, password_hash, date_of_birth) VALUES ('01958000-0000-7000-8000-00000000u002', 'Test User', 'user2@example.com', 'hash', '1990-01-01')"
        );
        $this->pdo()->exec(
            "INSERT INTO events (id, tenant_id, slug, title, type, event_date) VALUES ('01958000-0000-7000-8000-00000000e001', '{$daemsId}', 'test-ev', 'Test Event', 'upcoming', '2026-04-19')"
        );
        $this->pdo()->exec(
            "INSERT INTO event_registrations (id, event_id, user_id) VALUES ('01958000-0000-7000-8000-00000000c030', '01958000-0000-7000-8000-00000000e001', '01958000-0000-7000-8000-00000000u002')"
        );

        $this->runMigration('032_add_tenant_id_to_event_registrations.sql');

        $this->assertContains('tenant_id', $this->columnsOf('event_registrations'));
        $this->assertContains('fk_event_registrations_tenant', $this->foreignKeysOf('event_registrations'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM event_registrations WHERE id = '01958000-0000-7000-8000-00000000c030'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame($daemsId, $row['tenant_id']);
    }

    public function testTenantIdIsNotNullAfterMigration(): void
    {
        $this->runMigrationsUpTo(31);
        $this->runMigration('032_add_tenant_id_to_event_registrations.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM event_registrations LIKE 'tenant_id'");
        $this->assertNotFalse($stmt);
        $col = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($col);
        $this->assertSame('NO', $col['Null']);
    }
}
