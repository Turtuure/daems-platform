<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership;

use Daems\Domain\Membership\Exception\InvalidSubTierAppliesTo;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Membership\TenantMembershipSubTier;
use Daems\Domain\Membership\TenantMembershipSubTierId;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class TenantMembershipSubTierTest extends TestCase
{
    public function test_construct_with_supporting(): void
    {
        $st = new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'bronze',
            name:       'Bronze',
            rankOrder:  1,
            appliesTo:  MembershipType::Supporting,
        );

        self::assertSame('bronze', $st->slug);
        self::assertSame(MembershipType::Supporting, $st->appliesTo);
    }

    public function test_construct_with_basic(): void
    {
        $st = new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'gold',
            name:       'Gold',
            rankOrder:  3,
            appliesTo:  MembershipType::Basic,
        );

        self::assertSame(MembershipType::Basic, $st->appliesTo);
    }

    public function test_rejects_full_applies_to(): void
    {
        $this->expectException(InvalidSubTierAppliesTo::class);
        new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'bronze',
            name:       'Bronze',
            rankOrder:  1,
            appliesTo:  MembershipType::Full,
        );
    }

    public function test_rejects_honorary_applies_to(): void
    {
        $this->expectException(InvalidSubTierAppliesTo::class);
        new TenantMembershipSubTier(
            id:         TenantMembershipSubTierId::generate(),
            tenantId:   TenantId::generate(),
            slug:       'bronze',
            name:       'Bronze',
            rankOrder:  1,
            appliesTo:  MembershipType::Honorary,
        );
    }
}
