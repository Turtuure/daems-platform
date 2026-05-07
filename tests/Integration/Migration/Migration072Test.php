<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Migration;

use Daems\Tests\Integration\MigrationTestCase;

final class Migration072Test extends MigrationTestCase
{
    public function testSeedsFiveModulesPerExistingTenant(): void
    {
        $this->runMigrationsUpTo(71);
        $this->runMigration('072_seed_tenant_modules.sql');

        $tenantCount = (int) $this->pdo()->query("SELECT COUNT(*) FROM tenants")?->fetchColumn();
        $expected = $tenantCount * 5;

        $count = (int) $this->pdo()->query("SELECT COUNT(*) FROM tenant_modules")?->fetchColumn();
        $this->assertSame($expected, $count, "expected 5 modules x {$tenantCount} tenants");
    }

    public function testSeedsAllFiveExpectedSlugs(): void
    {
        $this->runMigrationsUpTo(71);
        $this->runMigration('072_seed_tenant_modules.sql');

        $slugs = $this->pdo()->query(
            "SELECT DISTINCT module_slug FROM tenant_modules ORDER BY module_slug"
        )?->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame(['events', 'forum', 'insights', 'members', 'projects'], $slugs);
    }

    public function testSeededRowsHaveAvailableAndEnabledTimestamps(): void
    {
        $this->runMigrationsUpTo(71);
        $this->runMigration('072_seed_tenant_modules.sql');

        $row = $this->pdo()->query(
            "SELECT available_at, enabled_at, available_by, enabled_by FROM tenant_modules LIMIT 1"
        )?->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertNotNull($row['available_at']);
        $this->assertNotNull($row['enabled_at']);
        $this->assertNull($row['available_by'], 'system seed has no actor user');
        $this->assertNull($row['enabled_by']);
    }

    public function testIdempotentOnRerun(): void
    {
        $this->runMigrationsUpTo(71);
        $this->runMigration('072_seed_tenant_modules.sql');

        $first = (int) $this->pdo()->query("SELECT COUNT(*) FROM tenant_modules")?->fetchColumn();

        // Re-apply: WHERE NOT EXISTS should keep counts stable.
        $this->runMigration('072_seed_tenant_modules.sql');

        $second = (int) $this->pdo()->query("SELECT COUNT(*) FROM tenant_modules")?->fetchColumn();
        $this->assertSame($first, $second, 'second run should not insert duplicates');
    }

    public function testNewTenantAddedAfterMigrationDoesNotAutoSeed(): void
    {
        // The migration only seeds existing tenants; future tenants are seeded
        // by the CreateTenant use case, not by this migration.
        $this->runMigrationsUpTo(71);
        $this->runMigration('072_seed_tenant_modules.sql');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo()->exec(
            "INSERT INTO tenants (id, slug, name, created_at, updated_at)
             VALUES ('55555555-5555-5555-5555-555555555555', 'lateco', 'Late Co', '{$now}', '{$now}')"
        );

        $count = (int) $this->pdo()->query(
            "SELECT COUNT(*) FROM tenant_modules WHERE tenant_id = '55555555-5555-5555-5555-555555555555'"
        )?->fetchColumn();
        $this->assertSame(0, $count, 'migration does not back-seed tenants created post-migration');
    }
}
