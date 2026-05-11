<?php
declare(strict_types=1);

namespace Daems\Tests\Isolation;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlTenantMembershipSubTierRepository;

final class TenantMembershipSubTierIsolationTest extends IsolationTestCase
{
    private SqlTenantMembershipSubTierRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SqlTenantMembershipSubTierRepository($this->pdo());
    }

    public function test_subtier_added_to_tenant_a_invisible_to_tenant_b(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $this->repo->save(new TenantMembershipSubTier(
            id:        TenantMembershipSubTierId::generate(),
            tenantId:  $tenantA,
            slug:      'diamond-A',
            name:      'Diamond (A only)',
            rankOrder: 5,
            appliesTo: MembershipType::Basic,
        ));

        $aSlugs = array_map(fn($s) => $s->slug, $this->repo->listForTenant($tenantA));
        $bSlugs = array_map(fn($s) => $s->slug, $this->repo->listForTenant($tenantB));

        self::assertContains('diamond-A', $aSlugs);
        self::assertNotContains('diamond-A', $bSlugs, 'tenant-A sub-tier must not leak to tenant-B');
    }

    public function test_find_by_slug_isolates_by_tenant(): void
    {
        $tenantA = $this->tenantId('daems');
        $tenantB = $this->tenantId('sahegroup');

        $this->repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantA, 'only-A', 'Only A', 9, MembershipType::Supporting,
        ));

        self::assertNotNull($this->repo->findBySlug($tenantA, MembershipType::Supporting, 'only-A'));
        self::assertNull($this->repo->findBySlug($tenantB, MembershipType::Supporting, 'only-A'));
    }
}
