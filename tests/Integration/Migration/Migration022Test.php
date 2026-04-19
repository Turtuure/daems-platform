<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDO;

final class Migration022Test extends MigrationTestCase
{
    public function testUserTenantsTableCreatedWithCompositePk(): void
    {
        $this->runMigrationsUpTo(21);
        $this->runMigration('022_create_user_tenants_pivot.sql');

        $columns = $this->columnsOf('user_tenants');
        $this->assertSame(['user_id', 'tenant_id', 'role', 'joined_at', 'left_at'], $columns);

        $fks = $this->foreignKeysOf('user_tenants');
        $this->assertContains('fk_user_tenants_user', $fks);
        $this->assertContains('fk_user_tenants_tenant', $fks);
    }

    public function testRoleEnumContainsExpectedValues(): void
    {
        $this->runMigrationsUpTo(21);
        $this->runMigration('022_create_user_tenants_pivot.sql');

        $stmt = $this->pdo()->query("SHOW COLUMNS FROM user_tenants LIKE 'role'");
        $this->assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $type = (string) $row['Type'];
        $this->assertStringContainsString("'admin'", $type);
        $this->assertStringContainsString("'moderator'", $type);
        $this->assertStringContainsString("'member'", $type);
        $this->assertStringContainsString("'supporter'", $type);
        $this->assertStringContainsString("'registered'", $type);
        $this->assertStringNotContainsString('global_system_administrator', $type);
    }
}
