<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDOException;

final class Migration069Test extends MigrationTestCase
{
    public function testTenantModulesTableExistsWithExpectedColumns(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('069_create_tenant_modules_table.sql');

        $columns = $this->columnsOf('tenant_modules');
        $expected = [
            'id',
            'tenant_id',
            'module_slug',
            'available_at',
            'available_by',
            'enabled_at',
            'enabled_by',
            'disabled_at',
            'created_at',
            'updated_at',
        ];
        foreach ($expected as $column) {
            $this->assertContains($column, $columns, "expected column {$column}");
        }
    }

    public function testForeignKeyToTenantsExists(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('069_create_tenant_modules_table.sql');

        $fks = $this->foreignKeysOf('tenant_modules');
        $this->assertContains('fk_tm_tenant', $fks);
    }

    public function testForeignKeyToUsersExists(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('069_create_tenant_modules_table.sql');

        $fks = $this->foreignKeysOf('tenant_modules');
        $this->assertContains('fk_tm_avail_by', $fks);
        $this->assertContains('fk_tm_enab_by', $fks);
    }

    public function testUniqueConstraintOnTenantIdAndModuleSlug(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('069_create_tenant_modules_table.sql');

        $tenantId = $this->pdo()->query("SELECT id FROM tenants LIMIT 1")?->fetchColumn();
        $this->assertIsString($tenantId);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo()->exec(
            "INSERT INTO tenant_modules (id, tenant_id, module_slug, created_at, updated_at)
             VALUES ('11111111-1111-1111-1111-111111111111', '{$tenantId}', 'forum', '{$now}', '{$now}')"
        );

        $this->expectException(PDOException::class);
        $this->pdo()->exec(
            "INSERT INTO tenant_modules (id, tenant_id, module_slug, created_at, updated_at)
             VALUES ('22222222-2222-2222-2222-222222222222', '{$tenantId}', 'forum', '{$now}', '{$now}')"
        );
    }

    public function testCascadeDeleteFromTenants(): void
    {
        $this->runMigrationsUpTo(68);
        $this->runMigration('069_create_tenant_modules_table.sql');

        // Insert a fresh tenant with NO tenant_domains rows so it can be deleted.
        // The seeded daems/sahegroup tenants have tenant_domains rows with
        // ON DELETE RESTRICT from migration 019, so we use a clean tenant id.
        $tenantId = '99999999-9999-7000-8000-999999999999';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo()->exec(
            "INSERT INTO tenants (id, slug, name, created_at, updated_at)
             VALUES ('{$tenantId}', 'casc-test', 'Cascade Test', '{$now}', '{$now}')"
        );

        $this->pdo()->exec(
            "INSERT INTO tenant_modules (id, tenant_id, module_slug, created_at, updated_at)
             VALUES ('33333333-3333-3333-3333-333333333333', '{$tenantId}', 'forum', '{$now}', '{$now}')"
        );

        $this->pdo()->exec("DELETE FROM tenants WHERE id = '{$tenantId}'");

        $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM tenant_modules WHERE tenant_id = '{$tenantId}'")?->fetchColumn();
        $this->assertSame(0, $count, 'cascade delete should remove tenant_modules rows');
    }
}
