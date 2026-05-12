<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Membership;

use Daems\Domain\Membership\IsEligibleForFullMembership;
use Daems\Domain\Membership\MembershipType;
use PHPUnit\Framework\TestCase;

final class IsEligibleForFullMembershipTest extends TestCase
{
    public function test_exactly_12_months_is_eligible(): void
    {
        $now      = new \DateTimeImmutable('2027-05-12');
        $joinedAt = new \DateTimeImmutable('2026-05-12');
        $svc      = new IsEligibleForFullMembership();
        $this->assertTrue($svc->check(MembershipType::Basic, 'active', $joinedAt, $now));
    }

    public function test_one_day_short_of_12_months_is_not_eligible(): void
    {
        $now      = new \DateTimeImmutable('2027-05-11');
        $joinedAt = new \DateTimeImmutable('2026-05-12');
        $svc      = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Basic, 'active', $joinedAt, $now));
    }

    public function test_supporting_is_not_eligible_even_after_12_months(): void
    {
        $now      = new \DateTimeImmutable('2027-05-12');
        $joinedAt = new \DateTimeImmutable('2026-05-12');
        $svc      = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Supporting, 'active', $joinedAt, $now));
    }

    public function test_non_active_status_is_not_eligible(): void
    {
        $now      = new \DateTimeImmutable('2027-05-12');
        $joinedAt = new \DateTimeImmutable('2026-05-12');
        $svc      = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Basic, 'suspended', $joinedAt, $now));
    }

    public function test_null_join_date_is_not_eligible(): void
    {
        $svc = new IsEligibleForFullMembership();
        $this->assertFalse($svc->check(MembershipType::Basic, 'active', null, new \DateTimeImmutable()));
    }
}
