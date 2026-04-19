<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration027Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedWithFk(): void
    {
        $this->runMigrationsUpTo(26);
        $this->pdo()->exec(
            "INSERT INTO projects (id, slug, title, category, summary, description) VALUES ('01958000-0000-7000-8000-00000000c003', 'test-project', 'Test Project', 'tech', 'Summary', 'Description')"
        );

        $this->runMigration('027_add_tenant_id_to_projects.sql');

        $this->assertContains('tenant_id', $this->columnsOf('projects'));
        $this->assertContains('fk_projects_tenant', $this->foreignKeysOf('projects'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM projects WHERE id = '01958000-0000-7000-8000-00000000c003'");
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
        $this->runMigrationsUpTo(26);
        $this->runMigration('027_add_tenant_id_to_projects.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM projects LIKE 'tenant_id'");
        $this->assertNotFalse($stmt);
        $col = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($col);
        $this->assertSame('NO', $col['Null']);
    }
}
