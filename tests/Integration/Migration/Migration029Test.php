<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration029Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedWithFk(): void
    {
        $this->runMigrationsUpTo(28);
        $this->pdo()->exec(
            "INSERT INTO supporter_applications (id, org_name, contact_person, email, motivation) VALUES ('01958000-0000-7000-8000-00000000c005', 'Test Org', 'Contact Person', 'contact@example.com', 'We want to support')"
        );

        $this->runMigration('029_add_tenant_id_to_supporter_applications.sql');

        $this->assertContains('tenant_id', $this->columnsOf('supporter_applications'));
        $this->assertContains('fk_supporter_applications_tenant', $this->foreignKeysOf('supporter_applications'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM supporter_applications WHERE id = '01958000-0000-7000-8000-00000000c005'");
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
        $this->runMigrationsUpTo(28);
        $this->runMigration('029_add_tenant_id_to_supporter_applications.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM supporter_applications LIKE 'tenant_id'");
        $this->assertNotFalse($stmt);
        $col = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($col);
        $this->assertSame('NO', $col['Null']);
    }
}
