<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership;

use Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\Fake\InMemoryTenantMembershipSubTierRepository;
use PHPUnit\Framework\TestCase;

final class ListMembershipSubTiersTest extends TestCase
{
    public function test_returns_seeded_sub_tiers_for_tenant(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $tenantId = TenantId::generate();
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'bronze', 'Bronze', 1, MembershipType::Supporting,
        ));
        $repo->save(new TenantMembershipSubTier(
            TenantMembershipSubTierId::generate(),
            $tenantId, 'silver', 'Silver', 2, MembershipType::Supporting,
        ));

        $uc = new ListMembershipSubTiers($repo);
        $output = $uc->execute($tenantId);

        self::assertCount(2, $output->items);
        self::assertSame('bronze', $output->items[0]->slug);
        self::assertSame('silver', $output->items[1]->slug);
    }

    public function test_returns_empty_for_unseeded_tenant(): void
    {
        $repo = new InMemoryTenantMembershipSubTierRepository();
        $uc = new ListMembershipSubTiers($repo);

        $output = $uc->execute(TenantId::generate());

        self::assertSame([], $output->items);
    }
}
