<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration028Test extends MigrationTestCase
{
    public function testTenantIdColumnAddedWithFk(): void
    {
        $this->runMigrationsUpTo(27);
        $this->pdo()->exec(
            "INSERT INTO member_applications (id, name, email, date_of_birth, motivation) VALUES ('01958000-0000-7000-8000-00000000c004', 'Test User', 'test@example.com', '1990-01-01', 'I want to join')"
        );

        $this->runMigration('028_add_tenant_id_to_member_applications.sql');

        $this->assertContains('tenant_id', $this->columnsOf('member_applications'));
        $this->assertContains('fk_member_applications_tenant', $this->foreignKeysOf('member_applications'));

        $stmt = $this->pdo()->query("SELECT tenant_id FROM member_applications WHERE id = '01958000-0000-7000-8000-00000000c004'");
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
        $this->runMigrationsUpTo(27);
        $this->runMigration('028_add_tenant_id_to_member_applications.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM member_applications LIKE 'tenant_id'");
        $this->assertNotFalse($stmt);
        $col = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($col);
        $this->assertSame('NO', $col['Null']);
    }
}
