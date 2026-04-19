<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;
use PDOException;

final class Migration019Test extends MigrationTestCase
{
    public function testTenantsTableExistsWithExpectedColumns(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $columns = $this->columnsOf('tenants');
        $this->assertContains('id', $columns);
        $this->assertContains('slug', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    public function testTenantDomainsTableHasFkToTenants(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $fks = $this->foreignKeysOf('tenant_domains');
        $this->assertContains('fk_tenant_domains_tenant', $fks);
    }

    public function testSeedInsertsDaemsAndSahegroup(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $stmt = $this->pdo()->query('SELECT slug FROM tenants ORDER BY slug');
        $this->assertNotFalse($stmt);
        $slugs = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['daems', 'sahegroup'], $slugs);
    }

    public function testSeedInsertsPrimaryDomainForEachTenant(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $stmt = $this->pdo()->query(
            "SELECT domain FROM tenant_domains td
             JOIN tenants t ON td.tenant_id = t.id
             WHERE t.slug = 'daems' AND td.is_primary = TRUE"
        );
        $this->assertNotFalse($stmt);
        $daemsDomains = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('daems.fi', $daemsDomains);
    }

    public function testDomainIsPrimaryKeyPreventingDuplicates(): void
    {
        $this->runMigration('019_create_tenants_and_tenant_domains.sql');

        $this->expectException(PDOException::class);
        $this->pdo()->exec(
            "INSERT INTO tenant_domains (domain, tenant_id, is_primary)
             VALUES ('daems.fi', '01958000-0000-7000-8000-000000000002', FALSE)"
        );
    }
}
