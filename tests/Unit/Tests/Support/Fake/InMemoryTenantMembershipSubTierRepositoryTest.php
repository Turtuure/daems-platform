<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Tests\Support\Fake;

use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryTenantMembershipSubTierRepositoryTest extends TestCase
{
    public function test_save_and_list(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $st = new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'bronze', 'Bronze', 1, MembershipType::Supporting,
        );
        $repo->save($st);

        $list = $repo->listForTenant($tenantId);
        self::assertCount(1, $list);
        self::assertSame('bronze', $list[0]->slug);
    }

    public function test_find_by_slug_returns_match(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $st = new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'gold', 'Gold', 3, MembershipType::Basic,
        );
        $repo->save($st);

        $found = $repo->findBySlug($tenantId, MembershipType::Basic, 'gold');
        self::assertNotNull($found);
        self::assertSame('Gold', $found->name);
    }

    public function test_find_by_slug_returns_null_when_wrong_applies_to(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $st = new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'gold', 'Gold', 3, MembershipType::Basic,
        );
        $repo->save($st);

        self::assertNull(
            $repo->findBySlug($tenantId, MembershipType::Supporting, 'gold')
        );
    }

    public function test_list_isolates_by_tenant(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantA = TenantId::generate();
        $tenantB = TenantId::generate();
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantA, 'bronze', 'Bronze', 1, MembershipType::Supporting,
        ));
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantB, 'silver', 'Silver', 2, MembershipType::Supporting,
        ));

        self::assertCount(1, $repo->listForTenant($tenantA));
        self::assertCount(1, $repo->listForTenant($tenantB));
    }

    public function test_delete_removes(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $id = TenantMembershipSubTierId::generate();
        $tenantId = TenantId::generate();
        $repo->save(new TenantMembershipSubTier(
            $id, $tenantId, 'gold', 'Gold', 3, MembershipType::Basic,
        ));
        $repo->delete($id);
        self::assertSame([], $repo->listForTenant($tenantId));
    }
}
