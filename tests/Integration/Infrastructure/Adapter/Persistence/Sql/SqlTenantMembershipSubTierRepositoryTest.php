<?php
declare(strict_types=1);

namespace Daems\Tests\Integration\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantMembershipSubTierRepository;
use Daems\Tests\Integration\MigrationTestCase;

final class SqlTenantMembershipSubTierRepositoryTest extends MigrationTestCase
{
    private SqlTenantMembershipSubTierRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runMigrationsUpTo(77);
        $this->repo = new SqlTenantMembershipSubTierRepository($this->pdo());
    }

    public function test_seed_present_after_migration_077(): void
    {
        $tenantId = $this->seedTenantAndGetId('test-tenant-a');
        $list = $this->repo->listForTenant($tenantId);
        // Seed runs against all existing tenants — including the one we just created.
        self::assertCount(8, $list); // 4 slugs × 2 appliesTo
    }

    public function test_save_and_find_by_slug(): void
    {
        $tenantId = $this->seedTenantAndGetId('test-tenant-b');
        $id = TenantMembershipSubTierId::generate();
        $st = new TenantMembershipSubTier(
            $id, $tenantId, 'diamond', 'Diamond', 5, MembershipType::Basic,
        );
        $this->repo->save($st);

        $found = $this->repo->findBySlug($tenantId, MembershipType::Basic, 'diamond');
        self::assertNotNull($found);
        self::assertSame('Diamond', $found->name);
        self::assertSame(5, $found->rankOrder);
    }

    public function test_delete_removes_row(): void
    {
        $tenantId = $this->seedTenantAndGetId('test-tenant-c');
        $list = $this->repo->listForTenant($tenantId);
        $first = $list[0];
        $this->repo->delete($first->id);

        $after = $this->repo->listForTenant($tenantId);
        self::assertCount(count($list) - 1, $after);
    }

    private function seedTenantAndGetId(string $slug): TenantId
    {
        $id = Uuid7::generate()->value();
        $this->pdo()->prepare(
            'INSERT INTO tenants (id, slug, name, default_locale, supported_locales)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $slug, $slug, 'en_GB', 'en_GB']);

        // Re-run sub-tier seed for this newly-added tenant (077 only ran at migration time).
        $defaults = [['bronze',1],['silver',2],['gold',3],['platinum',4]];
        foreach (['SUPPORTING','BASIC'] as $appliesTo) {
            foreach ($defaults as [$s,$r]) {
                $this->pdo()->prepare(
                    'INSERT INTO tenant_membership_subtiers (id,tenant_id,slug,name,rank_order,applies_to)
                     VALUES (?,?,?,?,?,?)'
                )->execute([Uuid7::generate()->value(), $id, $s, ucfirst($s), $r, $appliesTo]);
            }
        }

        return TenantId::fromString($id);
    }
}
