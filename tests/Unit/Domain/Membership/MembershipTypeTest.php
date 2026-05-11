<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership;

use Daems\Domain\Membership\MembershipType;
use PHPUnit\Framework\TestCase;

final class MembershipTypeTest extends TestCase
{
    public function test_only_full_has_voting_rights(): void
    {
        self::assertFalse(MembershipType::Supporting->hasVotingRights());
        self::assertFalse(MembershipType::Basic->hasVotingRights());
        self::assertTrue(MembershipType::Full->hasVotingRights());
        self::assertFalse(MembershipType::Honorary->hasVotingRights());
    }

    public function test_only_full_is_eligible_for_board(): void
    {
        self::assertFalse(MembershipType::Supporting->isEligibleForBoard());
        self::assertFalse(MembershipType::Basic->isEligibleForBoard());
        self::assertTrue(MembershipType::Full->isEligibleForBoard());
        self::assertFalse(MembershipType::Honorary->isEligibleForBoard());
    }

    public function test_membership_fee_payers(): void
    {
        self::assertFalse(MembershipType::Supporting->paysMembershipFee());
        self::assertTrue(MembershipType::Basic->paysMembershipFee());
        self::assertTrue(MembershipType::Full->paysMembershipFee());
        self::assertFalse(MembershipType::Honorary->paysMembershipFee());
    }

    public function test_supporter_fee_payer(): void
    {
        self::assertTrue(MembershipType::Supporting->paysSupporterFee());
        self::assertFalse(MembershipType::Basic->paysSupporterFee());
        self::assertFalse(MembershipType::Full->paysSupporterFee());
        self::assertFalse(MembershipType::Honorary->paysSupporterFee());
    }

    public function test_sub_tier_only_for_supporting_and_basic(): void
    {
        self::assertTrue(MembershipType::Supporting->allowsSubTier());
        self::assertTrue(MembershipType::Basic->allowsSubTier());
        self::assertFalse(MembershipType::Full->allowsSubTier());
        self::assertFalse(MembershipType::Honorary->allowsSubTier());
    }

    /** @dataProvider legacyMappingCases */
    public function test_from_legacy_string(string $legacy, MembershipType $expected): void
    {
        self::assertSame($expected, MembershipType::fromLegacyString($legacy));
    }

    /** @return iterable<string, array{string, MembershipType}> */
    public static function legacyMappingCases(): iterable
    {
        yield 'supporter'   => ['supporter',   MembershipType::Supporting];
        yield 'basic'       => ['basic',       MembershipType::Basic];
        yield 'individual'  => ['individual',  MembershipType::Basic];
        yield 'full'        => ['full',        MembershipType::Full];
        yield 'honorary'    => ['honorary',    MembershipType::Honorary];
        yield 'uppercased'  => ['Supporter',   MembershipType::Supporting];
    }

    public function test_from_legacy_string_rejects_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MembershipType::fromLegacyString('platinum-elite');
    }
}
